<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Owner refusal of a booking that required approval (AGENTS.md "Approval queue"). Releases the
 * seat claims a rejected booking was holding, the same as a cancellation does.
 *
 * Which is why it runs under the same section-2.2 locks and bumps the same revision as
 * `CancelBooking` and `ExpireHolds`: `awaiting_approval` -> `rejected` moves the booking out of the
 * blocking predicate (section 2.1) and frees its seat claims, so it is a capacity RELEASE, and a
 * release is a write to the slot exactly as an acquisition is.
 *
 * Nothing here can overbook - freeing capacity can only make a concurrent hold more conservative
 * than it needed to be, and the unique `(occurrence_id, seat_claim)` index backstops the seats
 * regardless. The exposure is the free/busy mask: `reservant_resource_days.rev` is its cache key
 * (AGENTS.md section 2.4 step 6), so a rejection that does not bump it leaves the released slot
 * cached busy - the owner refuses a 10:00 request and 10:00 never comes back on the widget. `rev`
 * has no reader yet, which makes this latent rather than live today, and precisely the kind of
 * defect that is unfindable once the reader lands three releases later.
 *
 * Lock order is the codebase-wide one: resource_days/occurrences via `LockManager::acquire()`
 * FIRST, the bookings row (`findByUuidForUpdate()`) after.
 */
final class RejectBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new TransactionRunner( $db ),
			new LockManager( $db ),
			new ResourceDayRepository( $db ),
			new BookingRepository( $db ),
			new AuditLog( $db )
		);
	}

	/** @return array<string, mixed> */
	public function execute( string $uuid, string $reason, \DateTimeImmutable $nowUtc, string $actor ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		// Cheap refusal on the unlocked read (CancelBooking's pattern): re-decided under the lock
		// below, which is what actually binds.
		if ( ! self::approvable( $booking, $nowUtc ) ) {
			throw new \RuntimeException( 'not_approvable' );
		}

		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		$keys  = LockKey::forItems( $items );
		// Mutex rows must exist before the transaction opens - SELECT ... FOR UPDATE cannot lock a
		// row that is not there (AGENTS.md section 2.2).
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run(
			function () use ( $keys, $uuid, $nowUtc, $reason, $actor ): array {
				// The slot mutexes FIRST, the booking row after - the codebase-wide lock order.
				$this->locks->acquire( $keys );

				// Re-read under FOR UPDATE: a lapsed hold or a rival decision (approve/cancel) may
				// have landed between the unlocked read above and this transaction opening, and the
				// row this one acts on is the one read here.
				$fresh = $this->bookings->findByUuidForUpdate( $uuid );
				if ( null === $fresh ) {
					throw new \RuntimeException( 'stale_state' );
				}
				if ( ! self::approvable( $fresh, $nowUtc ) ) {
					throw new \RuntimeException( 'not_approvable' );
				}

				if ( ! $this->bookings->transition(
					(int) $fresh['id'],
					BookingStatus::AwaitingApproval,
					BookingStatus::Rejected,
					array( 'rejection_reason' => $reason )
				) ) {
					throw new \RuntimeException( 'not_approvable' );
				}
				$this->bookings->releaseSeatClaims( (int) $fresh['id'] );
				$this->resourceDays->bumpRev( $keys );
				$this->audit->record( (int) $fresh['id'], $actor, 'reject' );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		do_action( 'reservant/booking/rejected', BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}

	/**
	 * `awaiting_approval` and the hold has not lapsed - the same window the customer's approval
	 * email is still valid for.
	 *
	 * @param array<string, mixed> $booking
	 */
	private static function approvable( array $booking, \DateTimeImmutable $nowUtc ): bool {
		if ( BookingStatus::AwaitingApproval->value !== $booking['status'] ) {
			return false;
		}
		return null !== $booking['hold_expires_at'] && (string) $booking['hold_expires_at'] > $nowUtc->format( 'Y-m-d H:i:s' );
	}
}
