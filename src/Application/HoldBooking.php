<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Domain\Availability\RuleExpander;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\HoldClass;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Settings;

/**
 * The locked write protocol of AGENTS.md section 2.2 - the only authority on capacity.
 *
 * Whatever the client believed about availability is discarded: times are recomputed from the
 * service rows, every contended key is locked in deterministic global order, expired holds are
 * reaped, and each item is re-validated under that lock before a single row is written.
 *
 * "Re-validated" means the full booking policy, not just capacity - a request that never consulted
 * the advisory availability endpoint must be refused just the same. Off-grid starts, spans outside
 * the chosen resource's working hours, inactive services or staff, starts inside the lead window or
 * beyond the horizon, and seat ids that name aisles or foreign maps all land as `SlotConflict`.
 */
final class HoldBooking {

	private const DEFAULT_GRANULARITY = 5;

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
		private readonly ResourceRepository $resources,
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
			new ResourceRepository( $db ),
			new OccurrenceRepository( $db ),
			new AvailabilityRepository( $db ),
			new AuditLog( $db )
		);
	}

	/**
	 * @param \DateTimeImmutable $nowUtc Domain clock. It does NOT govern `hold_expires_at`: the
	 *                                   TTL is anchored to `max($nowUtc, wall-clock UTC)` because
	 *                                   the blocking predicate (section 2.1) compares the stored expiry
	 *                                   against the database's `UTC_TIMESTAMP()`. A `$nowUtc`
	 *                                   behind the DB clock would otherwise mint a hold that is
	 *                                   already expired and holds nothing. In production the two
	 *                                   clocks coincide; see `holdExpiresAt()`.
	 * @return array<string, mixed> BookingRepository::findByUuid() shape + 'manage_token'
	 *                              (the plaintext secret, returned exactly once).
	 * @throws SlotConflict When any item fails re-validation - the whole chain rolls back.
	 */
	public function execute( HoldRequest $request, \DateTimeImmutable $nowUtc ): array {
		$plan = null === $request->appointment
			? $this->planEvent( $request->event )
			: $this->planAppointment( $request->appointment );

		if ( null !== $request->appointment ) {
			// Pure-input refusals, cheapest first: they read nothing contended, so they need no
			// lock and should not pay for one. Everything that reads state runs in the transaction.
			$this->validateChainWindow( $request->appointment, $plan, $nowUtc );
		}

		/** @var list<array<string, mixed>> $items */
		$items = $plan['items'];
		/** @var list<list<int>> $candidates */
		$candidates = $plan['candidates'];
		/** @var list<LockKey> $planKeys */
		$planKeys = $plan['keys'];
		$keys     = LockKey::sorted( $planKeys );

		$status                = true === $plan['requires_approval'] ? BookingStatus::AwaitingApproval : BookingStatus::Pending;
		$holdClass             = HoldClass::forStatus( $status );
		list( $secret, $hash ) = ManageToken::issue();

		$booking = array(
			'uuid'              => wp_generate_uuid4(),
			'status'            => $status->value,
			'hold_class'        => null === $holdClass ? null : $holdClass->value,
			'hold_expires_at'   => $this->holdExpiresAt( $status, $nowUtc, (int) $plan['approval_hold_hours'] ),
			'customer_name'     => $request->customer->name,
			'customer_email'    => $request->customer->email,
			'customer_phone'    => $request->customer->phone,
			'total_minor'       => (int) $plan['total_minor'],
			'currency'          => (string) $plan['currency'],
			'payment_mode'      => (string) $plan['payment_mode'],
			'requires_approval' => true === $plan['requires_approval'] ? 1 : 0,
			'manage_token_hash' => $hash,
		);

		// Mutex rows must exist before the transaction opens - SELECT ... FOR UPDATE cannot
		// lock a row that is not there (AGENTS.md section 2.2).
		$this->resourceDays->ensure( $keys );

		/** @var array{booking: array<string, mixed>, reaped: list<array<string, mixed>>} $result */
		$result = $this->txn->run(
			function () use ( $keys, $items, $candidates, $booking, $request, $nowUtc ): array {
				$this->locks->acquire( $keys );
				$reaped = $this->reap( $keys );

				if ( null === $request->appointment ) {
					$this->validateEvent( $request->event, $nowUtc );
				} else {
					$items = $this->assignResources(
						$items,
						$candidates,
						$request->appointment->sameStaff,
						$this->openIntervals( $items, $candidates )
					);
				}

				try {
					$bookingId = $this->bookings->insertWithItems( $booking, $items );
				} catch ( \RuntimeException $e ) {
					if ( 'seat_taken' === $e->getMessage() ) {
						throw new SlotConflict( 'seat_taken' ); // Unique-index backstop.
					}
					throw $e;
				}

				$this->resourceDays->bumpRev( $keys );
				$this->audit->record( $bookingId, 'customer', 'held' );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( (string) $booking['uuid'] );
				return array(
					'booking' => $stored,
					'reaped'  => $reaped,
				);
			}
		);

		foreach ( $result['reaped'] as $expired ) {
			do_action( 'reservant/hold/expired', $expired );
		}
		$snapshot = $result['booking'];
		do_action( 'reservant/booking/held', $snapshot );
		$snapshot['manage_token'] = $secret;
		return $snapshot;
	}

	/**
	 * Reap expired holds on the locked keys and give each one the bookkeeping the bulk UPDATE
	 * cannot: an audit row now, and a post-commit snapshot for `reservant/hold/expired`. These
	 * rows leave the held statuses here, so ExpireHolds will never see them again - if this does
	 * not record the transition, nothing does.
	 *
	 * @param list<LockKey> $keys
	 * @return list<array<string, mixed>> post-expiry snapshots
	 */
	private function reap( array $keys ): array {
		$snapshots = array();
		foreach ( $this->bookings->reapExpiredTouching( $keys ) as $bookingId ) {
			$this->audit->record( $bookingId, 'system', 'expired' );
			$snapshot = $this->bookings->findById( $bookingId );
			if ( null !== $snapshot ) {
				$snapshots[] = $snapshot;
			}
		}
		return $snapshots;
	}

	/**
	 * Concrete staff, chosen under the lock (AGENTS.md section 2.4 step 5).
	 *
	 * With `sameStaff` the choice is per-chain, not per-segment: every resource eligible for
	 * ALL segments is tried in ascending order and the first that is free throughout wins -
	 * the "union outside the intersection" form of section 2.4, and exactly what AvailabilityQuery
	 * offered the customer. Picking greedily per segment would reject chains that availability
	 * legitimately advertised (segment 0 grabs A, then A is busy later, while B was free for
	 * the whole chain).
	 *
	 * A candidate whose working hours do not cover a segment is skipped exactly like a busy one:
	 * "any staff" therefore lands on the first candidate that is both free and open, and only when
	 * nobody survives does the failure surface - as `outside_hours` if hours were what disqualified
	 * a candidate, otherwise as `overlap`.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param list<list<int>>            $candidates parallel to $items
	 * @param array<string, list<array{\DateTimeImmutable,\DateTimeImmutable}>> $open by "resourceId|Y-m-d"
	 * @return list<array<string, mixed>> items with resource_id filled in
	 */
	private function assignResources( array $items, array $candidates, bool $sameStaff, array $open ): array {
		if ( ! $sameStaff ) {
			foreach ( $items as $index => $item ) {
				$items[ $index ]['resource_id'] = $this->pickResource( $candidates[ $index ], $item, $open, $index );
			}
			return $items;
		}

		$common = array();
		foreach ( $candidates as $index => $pool ) {
			$common = 0 === $index ? $pool : array_values( array_intersect( $common, $pool ) );
			if ( array() === $common ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'no_staff', $index );
			}
		}

		$overlapIndex = 0;
		$hoursIndex   = null;
		foreach ( $common as $resourceId ) {
			$blockedAt = null;
			foreach ( $items as $index => $item ) {
				if ( ! self::coversSpan( $open, $resourceId, $item ) ) {
					$blockedAt  = $index;
					$hoursIndex = $hoursIndex ?? $index;
					break;
				}
				if ( 0 !== $this->bookings->overlapCount( $resourceId, (string) $item['block_start_utc'], (string) $item['block_end_utc'] ) ) {
					$blockedAt    = $index;
					$overlapIndex = $index;
					break;
				}
			}
			if ( null === $blockedAt ) {
				foreach ( array_keys( $items ) as $index ) {
					$items[ $index ]['resource_id'] = $resourceId;
				}
				return $items;
			}
		}
		if ( null !== $hoursIndex ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new SlotConflict( 'outside_hours', $hoursIndex );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new SlotConflict( 'overlap', $overlapIndex );
	}

	/**
	 * Lock keys for an already-planned or persisted set of items: every UTC day the block range
	 * touches, per resource, plus the occurrence row for event items.
	 *
	 * @param list<array<string, mixed>> $items
	 * @return list<LockKey>
	 */
	public static function lockKeysForItems( array $items ): array {
		$keys = array();
		foreach ( $items as $item ) {
			if ( null !== ( $item['occurrence_id'] ?? null ) ) {
				$keys[] = LockKey::occurrence( (int) $item['occurrence_id'] );
				continue;
			}
			if ( null === ( $item['resource_id'] ?? null ) ) {
				continue;
			}
			foreach ( self::daysTouched( (string) $item['block_start_utc'], (string) $item['block_end_utc'] ) as $day ) {
				$keys[] = LockKey::resourceDay( (int) $item['resource_id'], $day );
			}
		}
		return LockKey::sorted( $keys );
	}

	/**
	 * First candidate (ascending id) that is open and has no blocking overlap wins -
	 * deterministic, so a failure reproduces in tests.
	 *
	 * @param list<int>            $candidates
	 * @param array<string, mixed> $item
	 * @param array<string, list<array{\DateTimeImmutable,\DateTimeImmutable}>> $open
	 */
	private function pickResource( array $candidates, array $item, array $open, int $index ): int {
		$closed = false;
		foreach ( $candidates as $resourceId ) {
			if ( ! self::coversSpan( $open, $resourceId, $item ) ) {
				$closed = true;
				continue;
			}
			if ( 0 === $this->bookings->overlapCount( $resourceId, (string) $item['block_start_utc'], (string) $item['block_end_utc'] ) ) {
				return $resourceId;
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new SlotConflict( $closed ? 'outside_hours' : 'overlap', $index );
	}

	/**
	 * Open intervals for every (candidate resource, UTC day) an appointment item's service span
	 * touches. Read under the lock but not contended; one query pair feeds the whole chain.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param list<list<int>>            $candidates parallel to $items
	 * @return array<string, list<array{\DateTimeImmutable,\DateTimeImmutable}>> keyed "resourceId|Y-m-d"
	 */
	private function openIntervals( array $items, array $candidates ): array {
		$resourceIds = array();
		foreach ( $candidates as $pool ) {
			$resourceIds = array_merge( $resourceIds, $pool );
		}
		$resourceIds = array_values( array_unique( $resourceIds ) );
		if ( array() === $resourceIds ) {
			return array();
		}

		$rules      = $this->availability->rulesForResources( $resourceIds );
		$exceptions = $this->availability->exceptionsForResources( $resourceIds );
		$expander   = new RuleExpander( wp_timezone() );
		$utc        = new \DateTimeZone( 'UTC' );

		$open = array();
		foreach ( $items as $index => $item ) {
			foreach ( $candidates[ $index ] as $resourceId ) {
				foreach ( self::daysTouched( (string) $item['start_utc'], (string) $item['end_utc'] ) as $day ) {
					$key = $resourceId . '|' . $day;
					if ( ! isset( $open[ $key ] ) ) {
						$open[ $key ] = $expander->openIntervalsForUtcDay(
							new \DateTimeImmutable( $day . ' 00:00:00', $utc ),
							$rules[ $resourceId ] ?? array(),
							$exceptions[ $resourceId ] ?? array()
						);
					}
				}
			}
		}
		return $open;
	}

	/**
	 * Is every minute of the item's service span open for this resource?
	 *
	 * The *service* span, not the buffer-widened block: buffers are contention, not opening hours,
	 * and a service that ends exactly at closing time is legal. Intervals arrive clipped per UTC
	 * day, so a span crossing midnight is tested against both days concatenated - two touching
	 * intervals across the boundary cover it, a gap does not.
	 *
	 * @param array<string, list<array{\DateTimeImmutable,\DateTimeImmutable}>> $open
	 * @param array<string, mixed> $item
	 */
	private static function coversSpan( array $open, int $resourceId, array $item ): bool {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( (string) $item['start_utc'], $utc );
		$end   = new \DateTimeImmutable( (string) $item['end_utc'], $utc );
		if ( $start >= $end ) {
			return true;
		}

		$intervals = array();
		foreach ( self::daysTouched( (string) $item['start_utc'], (string) $item['end_utc'] ) as $day ) {
			foreach ( $open[ $resourceId . '|' . $day ] ?? array() as $interval ) {
				$intervals[] = $interval;
			}
		}
		usort( $intervals, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );

		$cursor = $start;
		foreach ( $intervals as $interval ) {
			if ( $interval[0] > $cursor ) {
				break; // A gap the span would fall into.
			}
			if ( $interval[1] > $cursor ) {
				$cursor = $interval[1];
			}
			if ( $cursor >= $end ) {
				return true;
			}
		}
		return $cursor >= $end;
	}

	/**
	 * Occurrence-side re-validation.
	 *
	 * The granularity grid does not apply here - an occurrence's start is authored by the owner,
	 * not chosen by the customer, so refusing it for sitting off-grid would reject the shop's own
	 * calendar. Lead time and horizon do: they answer "when may this be registered for", which is
	 * as much an event question as an appointment one. Because lead time is never negative, this
	 * is also what stops a seminar that has already started from being bookable.
	 */
	private function validateEvent( EventRequest $event, \DateTimeImmutable $nowUtc ): void {
		$occurrence = $this->occurrences->find( $event->occurrenceId );
		if ( null === $occurrence || 'active' !== $occurrence['status'] ) {
			throw new SlotConflict( 'not_found' );
		}
		$service = $this->services->find( (int) $occurrence['service_id'] );
		if ( null === $service || 'active' !== $service['status'] ) {
			throw new SlotConflict( 'not_found' );
		}
		self::assertWithinWindow(
			new \DateTimeImmutable( (string) $occurrence['start_utc'], new \DateTimeZone( 'UTC' ) ),
			self::anchor( $nowUtc ),
			(int) $service['lead_time_min'],
			(int) $service['horizon_days']
		);
		$this->validateSeatSelection( $event, $service );

		if ( $this->occurrences->blockingSeatSum( $event->occurrenceId ) + $event->seats > (int) $occurrence['capacity'] ) {
			throw new SlotConflict( 'capacity' );
		}
		if ( array() !== $event->seatIds ) {
			$taken = array_intersect( $event->seatIds, $this->occurrences->claimedSeatIds( $event->occurrenceId ) );
			if ( array() !== $taken ) {
				throw new SlotConflict( 'seat_taken' );
			}
		}
	}

	/**
	 * A service is either a grid or capacity-only and never both (AGENTS.md section 10): seat ids on a
	 * capacity-only service are as wrong as an unnamed seat on a grid one, and a named seat must be
	 * a real seat - of this map, and not an aisle or a blocked cell.
	 *
	 * @param array<string, mixed> $service
	 */
	private function validateSeatSelection( EventRequest $event, array $service ): void {
		$seatMapId = null === $service['seat_map_id'] ? null : (int) $service['seat_map_id'];

		if ( null === $seatMapId ) {
			if ( array() !== $event->seatIds ) {
				throw new SlotConflict( 'bad_seat' );
			}
			return;
		}
		// Every seat on a grid service is claimed by name: booking one "anonymously" would consume
		// capacity the seat map also counts, which is the both-modes-at-once section 10 forbids.
		if ( count( $event->seatIds ) !== $event->seats ) {
			throw new SlotConflict( 'bad_seat' );
		}

		$valid = $this->occurrences->validSeatIds( $seatMapId );
		foreach ( $event->seatIds as $seatId ) {
			if ( ! in_array( $seatId, $valid, true ) ) {
				throw new SlotConflict( 'bad_seat' );
			}
		}
	}

	/**
	 * Grid, lead time and horizon - the checks that need no state at all.
	 *
	 * @param array<string, mixed> $plan
	 */
	private function validateChainWindow( AppointmentRequest $appointment, array $plan, \DateTimeImmutable $nowUtc ): void {
		$start = $appointment->startUtc->setTimezone( new \DateTimeZone( 'UTC' ) );

		$secondsIntoDay = $start->getTimestamp() - $start->setTime( 0, 0 )->getTimestamp();
		if ( 0 !== $secondsIntoDay % ( self::granularity() * 60 ) ) {
			throw new SlotConflict( 'bad_time' );
		}

		self::assertWithinWindow( $start, self::anchor( $nowUtc ), (int) $plan['lead_time_min'], (int) $plan['horizon_days'] );
	}

	/**
	 * The booking window: late enough to respect the notice period, near enough to be on sale.
	 *
	 * @throws SlotConflict `lead_time` or `horizon`.
	 */
	private static function assertWithinWindow( \DateTimeImmutable $start, \DateTimeImmutable $anchor, int $leadTimeMin, int $horizonDays ): void {
		if ( $start < $anchor->add( new \DateInterval( 'PT' . max( 0, $leadTimeMin ) . 'M' ) ) ) {
			throw new SlotConflict( 'lead_time' );
		}
		if ( $start > $anchor->add( new \DateInterval( 'P' . max( 0, $horizonDays ) . 'D' ) ) ) {
			throw new SlotConflict( 'horizon' );
		}
	}

	/**
	 * Whichever of the caller's clock and the wall clock is later.
	 *
	 * A caller passing a clock behind the real one must not be able to book into the past, and one
	 * passing a clock ahead of it is simply held to the stricter bound. `holdExpiresAt()` needs the
	 * same anchor for a related reason: the blocking predicate compares the stored expiry against
	 * the database's UTC_TIMESTAMP(), so a hold minted against a lagging clock holds nothing.
	 */
	private static function anchor( \DateTimeImmutable $nowUtc ): \DateTimeImmutable {
		return max( $nowUtc, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Server-computed chain geometry (AGENTS.md section 2.2): the staff member is occupied for
	 * `duration` only - the processing gap that follows blocks nothing - and buffers widen the
	 * block range without moving the customer-facing times.
	 *
	 * @return array<string, mixed>
	 */
	private function planAppointment( AppointmentRequest $appointment ): array {
		$granularity = self::granularity();
		$items       = array();
		$candidates  = array();
		$keys        = array();
		$total       = 0;
		$currency    = null;
		$modes       = array();
		$approval    = false;
		$holdHours   = 0;
		$offsetMin   = 0;
		$leadMin     = 0;
		$horizonDays = null;
		// Normalise to UTC first: adding minutes inside a DST-observing zone shifts the instant.
		$chainStart = $appointment->startUtc->setTimezone( new \DateTimeZone( 'UTC' ) );

		foreach ( $appointment->segments as $index => $choice ) {
			$service = $this->services->find( $choice->serviceId );
			if ( null === $service || ServiceType::Appointment->value !== $service['type'] || 'active' !== $service['status'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'not_found', $index );
			}

			$duration   = self::roundUp( (int) $service['duration_min'], $granularity );
			$processing = self::roundUp( (int) $service['processing_time_min'], $granularity );
			$before     = self::roundUp( (int) $service['buffer_before_min'], $granularity );
			$after      = self::roundUp( (int) $service['buffer_after_min'], $granularity );

			$start      = $chainStart->add( new \DateInterval( 'PT' . $offsetMin . 'M' ) );
			$end        = $start->add( new \DateInterval( 'PT' . $duration . 'M' ) );
			$blockStart = $start->sub( new \DateInterval( 'PT' . $before . 'M' ) );
			$blockEnd   = $end->add( new \DateInterval( 'PT' . $after . 'M' ) );
			$offsetMin += $duration + $processing;

			// Only active staff are candidates, so a pinned resource that has been deactivated
			// fails as `no_staff` rather than quietly taking the booking.
			$eligible = $this->resources->activeIdsForService( $choice->serviceId );
			if ( array() === $eligible || ( null !== $choice->resourceId && ! in_array( $choice->resourceId, $eligible, true ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'no_staff', $index );
			}
			$segmentCandidates = null === $choice->resourceId ? $eligible : array( $choice->resourceId );

			$items[]      = array(
				'service_id'          => (int) $service['id'],
				'resource_id'         => null, // Chosen under the lock, never before.
				'occurrence_id'       => null,
				'start_utc'           => self::sql( $start ),
				'end_utc'             => self::sql( $end ),
				'block_start_utc'     => self::sql( $blockStart ),
				'block_end_utc'       => self::sql( $blockEnd ),
				'processing_ends_utc' => 0 === $processing ? null : self::sql( $end->add( new \DateInterval( 'PT' . $processing . 'M' ) ) ),
				'seats'               => 1,
				'seat_claim'          => null,
				'price_minor'         => (int) $service['price_minor'],
			);
			$candidates[] = $segmentCandidates;

			foreach ( $segmentCandidates as $resourceId ) {
				foreach ( self::daysTouched( self::sql( $blockStart ), self::sql( $blockEnd ) ) as $day ) {
					$keys[] = LockKey::resourceDay( $resourceId, $day );
				}
			}

			// The whole chain must satisfy every segment's booking window: the longest notice and
			// the nearest horizon win.
			$leadMin     = max( $leadMin, (int) $service['lead_time_min'] );
			$horizonDays = null === $horizonDays ? (int) $service['horizon_days'] : min( $horizonDays, (int) $service['horizon_days'] );

			$total   += (int) $service['price_minor'];
			$currency = self::mergeCurrency( $currency, (string) $service['currency'] );
			$modes[]  = (string) $service['payment_mode'];
			if ( 1 === (int) $service['requires_approval'] ) {
				$approval  = true;
				$holdHours = max( $holdHours, (int) $service['approval_hold_hours'] );
			}
		}

		return array(
			'items'               => $items,
			'candidates'          => $candidates,
			'keys'                => $keys,
			'total_minor'         => $total,
			'currency'            => (string) $currency,
			'payment_mode'        => self::dominantMode( $modes ),
			'requires_approval'   => $approval,
			'approval_hold_hours' => $holdHours,
			'lead_time_min'       => $leadMin,
			'horizon_days'        => (int) $horizonDays,
		);
	}

	/** @return array<string, mixed> */
	private function planEvent( EventRequest $event ): array {
		$occurrence = $this->occurrences->find( $event->occurrenceId );
		if ( null === $occurrence ) {
			throw new SlotConflict( 'not_found' );
		}
		$service = $this->services->find( (int) $occurrence['service_id'] );
		if ( null === $service || ServiceType::Event->value !== $service['type'] || 'active' !== $service['status'] ) {
			throw new SlotConflict( 'not_found' );
		}

		$price = (int) $service['price_minor'];
		$base  = array(
			'service_id'          => (int) $service['id'],
			'resource_id'         => null,
			'occurrence_id'       => (int) $occurrence['id'],
			'start_utc'           => (string) $occurrence['start_utc'],
			'end_utc'             => (string) $occurrence['end_utc'],
			'block_start_utc'     => (string) $occurrence['start_utc'],
			'block_end_utc'       => (string) $occurrence['end_utc'],
			'processing_ends_utc' => null,
			'seat_claim'          => null,
		);

		if ( array() === $event->seatIds ) {
			// Capacity-only: one item carrying every seat.
			$items = array(
				$base + array(
					'seats'       => $event->seats,
					'price_minor' => $price * $event->seats,
				),
			);
		} else {
			// Assigned grid: one item per seat so the unique index can backstop each claim.
			$items = array();
			foreach ( $event->seatIds as $seatId ) {
				$items[] = array(
					'seats'       => 1,
					'price_minor' => $price,
					'seat_claim'  => $seatId,
				) + $base;
			}
		}

		return array(
			'items'               => $items,
			'candidates'          => array(),
			'keys'                => array( LockKey::occurrence( (int) $occurrence['id'] ) ),
			'total_minor'         => $price * $event->seats,
			'currency'            => (string) $service['currency'],
			'payment_mode'        => self::dominantMode( array( (string) $service['payment_mode'] ) ),
			'requires_approval'   => 1 === (int) $service['requires_approval'],
			'approval_hold_hours' => (int) $service['approval_hold_hours'],
		);
	}

	/**
	 * The TTL is enforced by the DB clock (the blocking predicate compares against
	 * UTC_TIMESTAMP()), so the hold is anchored to whichever clock is later: a lagging injected
	 * "now" would otherwise mint a hold that is already dead. In production the two coincide.
	 */
	private function holdExpiresAt( BookingStatus $status, \DateTimeImmutable $nowUtc, int $approvalHoldHours ): string {
		$anchor = self::anchor( $nowUtc );
		if ( BookingStatus::AwaitingApproval === $status ) {
			$hours = $approvalHoldHours > 0 ? $approvalHoldHours : 48;
			return self::sql( $anchor->add( new \DateInterval( 'PT' . $hours . 'H' ) ) );
		}
		$minutes = (int) apply_filters( 'reservant/hold_ttl_minutes', Settings::make()->checkoutTtlMin() );
		return self::sql( $anchor->add( new \DateInterval( 'PT' . max( 1, $minutes ) . 'M' ) ) );
	}

	private static function mergeCurrency( ?string $current, string $next ): string {
		if ( null !== $current && $current !== $next ) {
			throw new \RuntimeException( 'currency_mismatch' );
		}
		return $next;
	}

	/**
	 * Highest-precedence mode wins: online > onsite > free.
	 *
	 * @param list<string> $modes
	 */
	private static function dominantMode( array $modes ): string {
		if ( in_array( PaymentMode::Online->value, $modes, true ) ) {
			return PaymentMode::Online->value;
		}
		if ( in_array( PaymentMode::Onsite->value, $modes, true ) ) {
			return PaymentMode::Onsite->value;
		}
		return PaymentMode::Free->value;
	}

	/** @return list<string> Y-m-d of every UTC day the half-open range touches. */
	private static function daysTouched( string $blockStartUtc, string $blockEndUtc ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $blockStartUtc, $utc );
		$end   = new \DateTimeImmutable( $blockEndUtc, $utc );
		$last  = max( $start, $end->modify( '-1 second' ) );

		$days = array();
		for ( $day = $start->setTime( 0, 0 ); $day <= $last; $day = $day->modify( '+1 day' ) ) {
			$days[] = $day->format( 'Y-m-d' );
		}
		return $days;
	}

	private static function roundUp( int $minutes, int $granularity ): int {
		return (int) ( ceil( $minutes / $granularity ) * $granularity );
	}

	private static function granularity(): int {
		return max( 1, (int) apply_filters( 'reservant/granularity_min', self::DEFAULT_GRANULARITY ) );
	}

	private static function sql( \DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
