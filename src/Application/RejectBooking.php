<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;

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
 * FIRST, the bookings row (`findByUuidForUpdate()`) after. `GuardedWrite` owns both that order and
 * the `bumpRev()` this class's docblock argues for, so the near-miss described above is now
 * structurally impossible rather than merely remembered.
 */
final class RejectBooking {

	public function __construct(
		private readonly GuardedWrite $guarded,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( GuardedWrite::make( $db ), new BookingRepository( $db ) );
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

		return $this->guarded->transition(
			LockKey::forItems( $items ),
			$uuid,
			BookingStatus::Rejected,
			// Re-read under FOR UPDATE and re-decided: a lapsed hold or a rival decision
			// (approve/cancel) may have landed between the unlocked read above and the transaction
			// opening, and the row this acts on is the one read there.
			static function ( array $fresh ) use ( $nowUtc ): void {
				if ( ! self::approvable( $fresh, $nowUtc ) ) {
					throw new TransitionRefused( 'not_approvable' );
				}
			},
			'not_approvable',
			$actor,
			'reject',
			'reservant/booking/rejected',
			array( 'rejection_reason' => $reason )
		);
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
