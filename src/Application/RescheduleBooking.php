<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Booking\CancellationPolicy;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Move an existing booking to a new time (appointments and chains) or to a new occurrence (events) -
 * AGENTS.md section 5: "Reschedule moves ALL segments as one atomic release + re-hold; partial
 * success is impossible."
 *
 * The target is held to the same rules a fresh hold is held to, through `HoldBooking`'s own
 * assertions rather than a second copy of them: the granularity grid, the notice period, the booking
 * horizon and the staff member's working hours. A slot the hold protocol would have refused is not a
 * slot a reschedule may land on - the business cannot serve it either way.
 *
 * Atomic by construction. The release and the re-hold happen inside ONE transaction, under the union
 * of the locks guarding both the slots being freed and the slots being taken. If the new placement is
 * refused, the transaction rolls back and the customer keeps the booking they already had - a
 * reschedule may never degrade into a cancellation. That is the contract, not an implementation
 * detail: a customer who asked to move to a slot somebody else took must still own their original
 * slot afterwards.
 *
 * The lock union is handed to `LockKey::sorted()`, the same global ordering the hold protocol uses, so
 * two customers rescheduling into each other's slots cannot deadlock. No new ordering rule is invented
 * here, and none should be - `sorted()` deduplicates too, which matters because a move within one day
 * on one resource names the same key twice.
 *
 * Lock order is the codebase-wide one (AGENTS.md section 2.2): resource_days/occurrences via
 * `LockManager::acquire()` FIRST, the bookings row (`findByUuidForUpdate()`) after.
 *
 * Everything is decided on a read taken AFTER the locks are held. Under REPEATABLE READ, InnoDB
 * assigns a transaction's snapshot at its first CONSISTENT read; `SELECT ... FOR UPDATE` is a locking
 * read and does not open one. The transaction's first statements are therefore the lock acquisitions,
 * which means every plain read below - the fresh booking's items, the service rows, the occurrence
 * row, `overlapCount()`, `blockingSeatSum()` - sees everything a rival writer committed before this
 * transaction won the mutex. The unlocked checks at the top of `execute()` are an optimisation that
 * saves a transaction in the common case; they never bind.
 *
 * The release precedes the claim inside the same transaction, and that ordering is load-bearing
 * rather than tidy: a chain moved forward by less than its own length overlaps itself, and a set of
 * seats moved between occurrences would be counted twice against the target if the target happened to
 * be the source. Once the old rows are gone, `overlapCount()` and `blockingSeatSum()` need no
 * "except this booking" clause - they simply read a world in which the booking has let go.
 *
 * WHAT THE LOCK DOES NOT COVER - stated in full, because a guarantee whose edges are vague is worse
 * than a narrow one:
 *
 *  - It serialises this use case against every writer that takes the same resource-day/occurrence
 *    mutex (`HoldBooking`, `CancelBooking`, `ApproveBooking`, `RejectBooking`, `ExpireHolds`,
 *    `OccurrencesAdminController`) and, through the booking row lock, against a second reschedule or a
 *    cancellation of the SAME booking. It does not reach a writer that takes neither.
 *  - `ConfirmBooking` takes no lock at all - it is one guarded UPDATE. A confirm committing between
 *    the pre-lock read and this transaction is therefore seen (the locking re-read is after the
 *    mutexes), but a confirm committing between this transaction's re-read and its COMMIT blocks on
 *    the booking row until then. Neither ordering can lose an item: the item rows are only ever
 *    rewritten here, under that same row lock.
 *  - It does not extend to the booking's OWN items being moved by a rival reschedule between the
 *    pre-lock plan and the transaction. The key union was computed from the old plan, so the locks
 *    held may not cover the slots the booking now occupies. That is why the key set is recomputed from
 *    the locked re-read and compared: a mismatch is refused `stale_state` (retryable) rather than
 *    released under a mutex nobody holds. Acquiring the missing keys mid-transaction is NOT the fix -
 *    it would take a key out of global order and reintroduce the deadlock `sorted()` exists to prevent.
 *  - The window rules it enforces - the granularity grid, the notice period, the horizon, opening
 *    hours - are asserted against the availability tables as they read INSIDE the transaction, but
 *    those tables are not under the slot mutex: an owner editing a working-hours rule takes no
 *    resource-day lock at all. A move validated against hours the owner narrows moments later is
 *    therefore possible, exactly as it already is for `HoldBooking`, which reads the same tables
 *    under the same locks. This is not a case the lock reaches, and it is not one this class makes
 *    worse; capacity, which is what the mutex exists for, is unaffected either way.
 *  - Seat-mapped (grid) event bookings are refused `not_reschedulable`. That is a DELIBERATE limit,
 *    not an oversight or an unimplemented branch: moving named seats is a re-pick, not a move, and
 *    this signature carries no seat choice to re-pick with. Silently transplanting the old seat ids
 *    onto another occurrence would be a decision the customer never made, and refusing is the
 *    smaller harm. The seat picker is v1.2; until it exists, capacity-only event bookings move
 *    freely and grid ones do not move at all.
 *
 * The booking's own commercial terms are carried over untouched: `price_minor` per item and the
 * container's `total_minor` are what the customer agreed to, and moving an appointment is not a
 * re-sale. Only the geometry is recomputed - from `HoldBooking::segmentGeometry()`, so a moved chain
 * lands on exactly the math a fresh hold would have used.
 */
final class RescheduleBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
		private readonly OccurrenceRepository $occurrences,
		private readonly AvailabilityRepository $availability,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new TransactionRunner( $db ),
			new LockManager( $db ),
			new ResourceDayRepository( $db ),
			new BookingRepository( $db ),
			new ServiceRepository( $db ),
			new OccurrenceRepository( $db ),
			new AvailabilityRepository( $db ),
			new AuditLog( $db )
		);
	}

	/**
	 * @param \DateTimeImmutable $newStartUtc      The chain's new customer-facing start. Ignored for an
	 *                                             event move: an occurrence's start is authored by the
	 *                                             owner, so the target occurrence's own `start_utc`
	 *                                             wins and this argument is advisory there.
	 * @param int|null           $newOccurrenceId  The target occurrence for an event move, `null` for an
	 *                                             appointment move. It must match the booking's own
	 *                                             shape - an appointment cannot be moved onto an
	 *                                             occurrence, nor an event off one.
	 * @param bool               $force            Owner/admin action. Skips the SALES windows and
	 *                                             nothing else: the service's reschedule window, the
	 *                                             lead time and the horizon - backdating included,
	 *                                             this project's standing ruling for admin-mode holds.
	 *                                             It does NOT skip the granularity grid or opening
	 *                                             hours (`HoldBooking`'s admin flag does not either),
	 *                                             and it never skips overlap or capacity, which are
	 *                                             integrity rather than policy.
	 * @return array<string, mixed> The updated `BookingRepository::findByUuid()` row.
	 * @throws SlotConflict `not_found` (the booking, a named service, or the target occurrence),
	 *                      `not_reschedulable` (status, shape, or a seat-mapped event booking),
	 *                      `bad_time` (off the granularity grid), `lead_time`, `horizon`,
	 *                      `outside_hours`, `overlap` (a blocking item already covers the target
	 *                      range), `capacity` (the target occurrence has no room).
	 * @throws \RuntimeException `window_closed` when the service's reschedule window has closed and the
	 *                      caller did not force - `CancelBooking`'s convention, so the two guest-facing
	 *                      policy refusals answer with one status. `stale_state` when the booking moved
	 *                      under this call - retryable, never a partial write.
	 */
	public function execute( string $uuid, \DateTimeImmutable $newStartUtc, ?int $newOccurrenceId, \DateTimeImmutable $nowUtc, bool $force = false ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		// Cheap unlocked refusals first - they save a transaction in the common case, and every one of
		// them is decided again under the lock below, which is what actually binds.
		$this->assertReschedulable( $booking, $nowUtc, $newOccurrenceId );
		if ( ! $force && ! $this->policyAllows( $booking, $nowUtc ) ) {
			throw new \RuntimeException( 'window_closed' );
		}

		$plan = $this->plan( $booking, $newStartUtc, $newOccurrenceId );
		self::assertWindow( $plan, $nowUtc, $force );
		$keys = self::union( $booking, $plan );
		// Mutex rows must exist before the transaction opens - SELECT ... FOR UPDATE cannot lock a row
		// that is not there (AGENTS.md section 2.2). Both halves of the union, not just the new one.
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run(
			function () use ( $uuid, $keys, $newStartUtc, $newOccurrenceId, $nowUtc, $force ): array {
				// The slot mutexes FIRST, the booking row after - the codebase-wide lock order. Both are
				// locking reads, so the consistent-read snapshot every check below runs on is taken here,
				// after the locks, not before them.
				$this->locks->acquire( $keys );

				$fresh = $this->bookings->findByUuidForUpdate( $uuid );
				if ( null === $fresh ) {
					throw new \RuntimeException( 'stale_state' );
				}
				$this->assertReschedulable( $fresh, $nowUtc, $newOccurrenceId );
				if ( ! $force && ! $this->policyAllows( $fresh, $nowUtc ) ) {
					throw new \RuntimeException( 'window_closed' );
				}

				$plan = $this->plan( $fresh, $newStartUtc, $newOccurrenceId );
				if ( self::signature( self::union( $fresh, $plan ) ) !== self::signature( $keys ) ) {
					// The booking moved between the unlocked plan and this transaction, so the locks held
					// are not the locks this write needs. Refuse rather than write outside the mutex.
					throw new \RuntimeException( 'stale_state' );
				}
				// The target must be one the business can actually serve, decided here rather than on
				// the unlocked read above: the grid and the clamps are pure input, but opening hours
				// read the availability tables, and every read this decision rests on is taken with the
				// mutexes held.
				self::assertWindow( $plan, $nowUtc, $force );
				if ( null === $plan['occurrence'] ) {
					HoldBooking::assertItemsWithinOpeningHours( $this->availability, $plan['items'] );
				}

				$bookingId = (int) $fresh['id'];
				/** @var list<array<string, mixed>> $items */
				$items = $fresh['items'];
				$from  = (string) $items[0]['start_utc'];

				// Release, then claim. Every count below now reads a world this booking has let go of,
				// so a chain that moves onto itself does not collide with its own old rows.
				$this->bookings->deleteItems( $bookingId );
				$this->assertTargetIsFree( $plan );
				$this->bookings->insertItems( $bookingId, $plan['items'] );

				// A moved booking changes which minutes are free: the mask cache key must move with it,
				// on BOTH halves of the union, or a stale mask keeps selling the slot just vacated and
				// keeps hiding the one just taken.
				$this->resourceDays->bumpRev( $keys );
				$this->audit->record(
					$bookingId,
					'customer',
					'rescheduled',
					array(
						'from'   => $from,
						'to'     => (string) $plan['items'][0]['start_utc'],
						'forced' => $force,
					)
				);

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		do_action( 'reservant/booking/rescheduled', BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}

	/**
	 * The union of the keys guarding the slots being freed and the slots being taken, deduplicated and
	 * globally ordered by `LockKey::sorted()`.
	 *
	 * Both halves come from `LockKey::forItems()`, so a reschedule locks precisely what a
	 * hold of either placement would have locked - never a set derived some other way.
	 *
	 * @param array<string, mixed>                                                 $booking
	 * @param array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int} $plan
	 * @return list<LockKey>
	 */
	private static function union( array $booking, array $plan ): array {
		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		return LockKey::sorted( array_merge( LockKey::forItems( $items ), LockKey::forItems( $plan['items'] ) ) );
	}

	/**
	 * A comparable rendering of an ordered key list - the guard that says "the locks I hold are still
	 * the locks this write needs".
	 *
	 * @param list<LockKey> $keys
	 */
	private static function signature( array $keys ): string {
		return implode( ',', array_map( static fn ( LockKey $key ): string => $key->type . '|' . $key->id . '|' . $key->day, $keys ) );
	}

	/**
	 * Where the booking is going, as booking_items rows ready to insert.
	 *
	 * Called twice: once unlocked, to work out which keys to take, and once inside the transaction on
	 * the locked re-read, which is the one that decides. Nothing here writes.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int}
	 */
	private function plan( array $booking, \DateTimeImmutable $newStartUtc, ?int $newOccurrenceId ): array {
		/** @var list<array<string, mixed>> $current */
		$current = $booking['items'];
		return null === $newOccurrenceId
			? $this->planAppointment( $current, $newStartUtc )
			: $this->planEvent( $current, $newOccurrenceId );
	}

	/**
	 * The same chain, the same staff, the same prices - at a new start.
	 *
	 * Geometry is recomputed from the CURRENT service rows through `HoldBooking::segmentGeometry()`, so
	 * a duration or buffer the owner has changed since the sale is honoured, and the processing gap
	 * between segments is reproduced rather than copied from the old offsets.
	 *
	 * The service's `status` is deliberately not re-checked. A deactivated service means "no new
	 * bookings", and a reschedule is not a new booking: a customer holding one must be able to move it
	 * rather than be forced to cancel it. The same reasoning covers the pinned resource, which is kept
	 * as sold rather than re-picked - a reschedule that silently changed the customer's staff member
	 * would be a different sale.
	 *
	 * @param list<array<string, mixed>> $current
	 * @return array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int}
	 */
	private function planAppointment( array $current, \DateTimeImmutable $newStartUtc ): array {
		// Normalise to UTC first: adding minutes inside a DST-observing zone shifts the instant.
		$chainStart  = $newStartUtc->setTimezone( new \DateTimeZone( 'UTC' ) );
		$offsetMin   = 0;
		$items       = array();
		$leadMin     = 0;
		$horizonDays = null;

		foreach ( $current as $index => $item ) {
			$service = $this->services->find( (int) $item['service_id'] );
			if ( null === $service ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'not_found', $index );
			}
			$geometry   = HoldBooking::segmentGeometry( $service, $chainStart, $offsetMin );
			$offsetMin += (int) $geometry['advance_min'];

			// The whole chain must satisfy every segment's booking window: the longest notice and the
			// nearest horizon win - `planAppointment()`'s rule in `HoldBooking`, unchanged.
			$leadMin     = max( $leadMin, (int) $service['lead_time_min'] );
			$horizonDays = null === $horizonDays ? (int) $service['horizon_days'] : min( $horizonDays, (int) $service['horizon_days'] );

			$items[] = array(
				'service_id'          => (int) $item['service_id'],
				'resource_id'         => (int) $item['resource_id'],
				'occurrence_id'       => null,
				'start_utc'           => $geometry['start_utc'],
				'end_utc'             => $geometry['end_utc'],
				'block_start_utc'     => $geometry['block_start_utc'],
				'block_end_utc'       => $geometry['block_end_utc'],
				'processing_ends_utc' => $geometry['processing_ends_utc'],
				'seats'               => (int) $item['seats'],
				'seat_claim'          => null,
				'price_minor'         => (int) $item['price_minor'],
			);
		}
		return array(
			'items'         => $items,
			'occurrence'    => null,
			'seats'         => 0,
			'lead_time_min' => $leadMin,
			'horizon_days'  => (int) $horizonDays,
		);
	}

	/**
	 * The same seats on a different occurrence of the SAME service.
	 *
	 * The target must exist, be active, and belong to the service already booked: an occurrence of some
	 * other service is not the thing the customer bought, and moving onto a cancelled one would put
	 * them in a room nobody is opening.
	 *
	 * @param list<array<string, mixed>> $current
	 * @return array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int}
	 */
	private function planEvent( array $current, int $newOccurrenceId ): array {
		$occurrence = $this->occurrences->find( $newOccurrenceId );
		if ( null === $occurrence || 'active' !== $occurrence['status'] ) {
			throw new SlotConflict( 'not_found' );
		}
		if ( (int) $occurrence['service_id'] !== (int) $current[0]['service_id'] ) {
			throw new SlotConflict( 'not_found' );
		}
		// The window clamps are the service's, exactly as `HoldBooking::validateEvent()` reads them.
		// `status` is not re-checked here for the reason `planAppointment()` gives.
		$service = $this->services->find( (int) $occurrence['service_id'] );
		if ( null === $service ) {
			throw new SlotConflict( 'not_found' );
		}

		$items = array();
		$seats = 0;
		foreach ( $current as $item ) {
			$seats  += (int) $item['seats'];
			$items[] = array(
				'service_id'          => (int) $item['service_id'],
				'resource_id'         => null,
				'occurrence_id'       => (int) $occurrence['id'],
				'start_utc'           => (string) $occurrence['start_utc'],
				'end_utc'             => (string) $occurrence['end_utc'],
				'block_start_utc'     => (string) $occurrence['start_utc'],
				'block_end_utc'       => (string) $occurrence['end_utc'],
				'processing_ends_utc' => null,
				'seats'               => (int) $item['seats'],
				'seat_claim'          => null,
				'price_minor'         => (int) $item['price_minor'],
			);
		}
		return array(
			'items'         => $items,
			'occurrence'    => $occurrence,
			'seats'         => $seats,
			'lead_time_min' => (int) $service['lead_time_min'],
			'horizon_days'  => (int) $service['horizon_days'],
		);
	}

	/**
	 * The target must be a time the business can actually serve, not merely a free one.
	 *
	 * `HoldBooking`'s own window assertions, called on the planned placement: the granularity grid, the
	 * notice period and the booking horizon for a chain; the notice period and the horizon for an
	 * occurrence, whose start the owner authored and which is therefore never grid-checked. Reusing them
	 * is the point - a moved booking that a fresh hold would have refused is a booking nobody can serve.
	 *
	 * `$force` is passed straight through as `HoldBooking`'s admin flag, so it skips the lead-time and
	 * horizon clamps (backdating included) and nothing else. Opening hours are asserted by the caller,
	 * under the lock, and are not gated on `$force` at all - see
	 * `HoldBooking::assertItemsWithinOpeningHours()`.
	 *
	 * @param array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int} $plan
	 * @throws SlotConflict `bad_time`, `lead_time` or `horizon`.
	 */
	private static function assertWindow( array $plan, \DateTimeImmutable $nowUtc, bool $force ): void {
		$utc        = new \DateTimeZone( 'UTC' );
		$occurrence = $plan['occurrence'];
		if ( null !== $occurrence ) {
			HoldBooking::assertOccurrenceWindow(
				new \DateTimeImmutable( (string) $occurrence['start_utc'], $utc ),
				$nowUtc,
				$plan['lead_time_min'],
				$plan['horizon_days'],
				$force
			);
			return;
		}
		HoldBooking::assertChainWindow(
			new \DateTimeImmutable( (string) $plan['items'][0]['start_utc'], $utc ),
			$nowUtc,
			$plan['lead_time_min'],
			$plan['horizon_days'],
			$force
		);
	}

	/**
	 * The integrity half of the decision, and the only thing `$force` may never skip.
	 *
	 * Call INSIDE the transaction, after the locks and after the old items are gone. For an event move
	 * the capacity re-check reads `blockingSeatSum()` while `LockKey::occurrence()` is held on the
	 * target - the P4 review found this exact guard re-checking WITHOUT the row lock in
	 * `OccurrencesAdminController`, where two concurrent requests both passed it. Without the lock this
	 * check closes nothing at all.
	 *
	 * @param array{items: list<array<string, mixed>>, occurrence: array<string, mixed>|null, seats: int, lead_time_min: int, horizon_days: int} $plan
	 */
	private function assertTargetIsFree( array $plan ): void {
		$occurrence = $plan['occurrence'];
		if ( null !== $occurrence ) {
			if ( $this->occurrences->blockingSeatSum( (int) $occurrence['id'] ) + $plan['seats'] > (int) $occurrence['capacity'] ) {
				throw new SlotConflict( 'capacity' );
			}
			return;
		}
		foreach ( $plan['items'] as $index => $item ) {
			if ( 0 !== $this->bookings->overlapCount( (int) $item['resource_id'], (string) $item['block_start_utc'], (string) $item['block_end_utc'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'overlap', $index );
			}
		}
	}

	/**
	 * Is there a slot here to move at all, and is it the shape the caller asked to move?
	 *
	 * "Blocking" is the section 2.1 predicate expressed on one row: `confirmed`, or a held status whose
	 * TTL has not lapsed. That is exactly the set of bookings that occupy capacity, and therefore the
	 * only set with something to release. A lapsed hold, a cancellation, a rejection, an expiry and a
	 * finished booking all have nothing to give back.
	 *
	 * @param array<string, mixed> $booking
	 * @throws SlotConflict `not_reschedulable`.
	 */
	private function assertReschedulable( array $booking, \DateTimeImmutable $nowUtc, ?int $newOccurrenceId ): void {
		$status = BookingStatus::from( (string) $booking['status'] );
		$live   = $status->isHeld()
			&& null !== $booking['hold_expires_at']
			&& (string) $booking['hold_expires_at'] > $nowUtc->format( 'Y-m-d H:i:s' );
		if ( BookingStatus::Confirmed !== $status && ! $live ) {
			throw new SlotConflict( 'not_reschedulable' );
		}

		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		if ( array() === $items ) {
			throw new SlotConflict( 'not_reschedulable' );
		}
		$isEvent  = null !== $items[0]['occurrence_id'];
		$wantsOcc = null !== $newOccurrenceId;
		if ( $wantsOcc !== $isEvent ) {
			// An appointment cannot be moved onto an occurrence, nor an event off one.
			throw new SlotConflict( 'not_reschedulable' );
		}
		foreach ( $items as $item ) {
			if ( ( null !== $item['occurrence_id'] ) !== $isEvent ) {
				throw new SlotConflict( 'not_reschedulable' ); // Mixed container - not a shape this can move.
			}
			if ( $isEvent && null !== $item['seat_claim'] ) {
				throw new SlotConflict( 'not_reschedulable' ); // Grid seats need a seat choice; see the class docblock.
			}
			if ( ! $isEvent && null === $item['resource_id'] ) {
				throw new SlotConflict( 'not_reschedulable' );
			}
		}
	}

	/**
	 * `CancellationPolicy`'s reschedule arm, read from the FIRST item's service - a chain moves as one
	 * unit, so it answers to one window, exactly as `CancelBooking` does for cancellation.
	 *
	 * The `reservant/booking/can_reschedule` filter mirrors `reservant/booking/can_cancel`: a site may
	 * widen or narrow the window, and `$force` bypasses both.
	 *
	 * The caller refuses with `\RuntimeException('window_closed')`, not a `SlotConflict` - the same
	 * signal `CancelBooking` raises for the same class of refusal, so both guest-facing policy
	 * refusals reach `Errors::failure()` and answer 403 with one sentence rather than two conventions.
	 *
	 * @param array<string, mixed> $booking
	 */
	private function policyAllows( array $booking, \DateTimeImmutable $nowUtc ): bool {
		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		if ( array() === $items ) {
			return true;
		}
		$service = $this->services->find( (int) $items[0]['service_id'] );
		$policy  = new CancellationPolicy(
			null === $service ? 0 : (int) $service['cancel_window_hours'],
			null === $service ? 0 : (int) $service['reschedule_window_hours']
		);
		$start   = new \DateTimeImmutable( (string) $items[0]['start_utc'], new \DateTimeZone( 'UTC' ) );
		return (bool) apply_filters( 'reservant/booking/can_reschedule', $policy->canReschedule( $nowUtc, $start ), $booking, $nowUtc );
	}
}
