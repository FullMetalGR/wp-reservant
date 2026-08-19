<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Domain\Booking\CancellationPolicy;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * Whole-booking cancellation (AGENTS.md section 1: items have no independent lifecycle). Releases the
 * slot under the same locks a hold takes; refunds are flagged for the owner, never automatic.
 *
 * The lock/re-read/transition/audit/hook sequence lives in `GuardedWrite`; what stays here is what is
 * particular to cancelling - the policy window, and which statuses the caller will still accept.
 */
final class CancelBooking {

	public function __construct(
		private readonly GuardedWrite $guarded,
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			GuardedWrite::make( $db ),
			new BookingRepository( $db ),
			new ServiceRepository( $db )
		);
	}

	/**
	 * @param bool                     $force            Skip the policy window (owner/admin action).
	 * @param list<BookingStatus>|null $onlyFromStatuses When given, the booking must STILL be in one
	 *                                                   of these statuses once the lock is taken, or
	 *                                                   the cancellation is refused as `not_held`.
	 *                                                   `DELETE /holds` passes the held statuses:
	 *                                                   releasing a reservation nobody has paid for
	 *                                                   is free, cancelling a confirmed booking is
	 *                                                   policy-bound, and losing the race against a
	 *                                                   confirm must not silently turn one act into
	 *                                                   the other.
	 * @return array<string, mixed>
	 */
	public function execute( string $uuid, \DateTimeImmutable $nowUtc, bool $force = false, ?array $onlyFromStatuses = null ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		// Cheap refusals on the unlocked read: they save a transaction in the common case and are
		// re-decided under the lock below, which is what actually binds.
		if ( ! BookingStatus::from( (string) $booking['status'] )->canTransitionTo( BookingStatus::Cancelled ) ) {
			throw new \RuntimeException( 'not_cancellable' );
		}

		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		if ( ! $force && ! $this->allowed( $booking, $items, $nowUtc ) ) {
			throw new \RuntimeException( 'window_closed' );
		}

		return $this->guarded->transition(
			LockKey::forItems( $items ),
			$uuid,
			BookingStatus::Cancelled,
			// Re-decided under the lock, which is what actually binds. Both refusals below were
			// already checked on the unlocked read above; a rival confirm or expiry landing in
			// between is exactly what this second pass exists to catch.
			static function ( array $fresh, BookingStatus $from ) use ( $onlyFromStatuses ): void {
				if ( null !== $onlyFromStatuses && ! in_array( $from, $onlyFromStatuses, true ) ) {
					throw new TransitionRefused( 'not_held' );
				}
				if ( ! $from->canTransitionTo( BookingStatus::Cancelled ) ) {
					throw new TransitionRefused( 'not_cancellable' );
				}
			},
			'stale_state',
			'customer',
			'cancelled',
			'reservant/booking/cancelled',
			array(
				'hold_expires_at' => null,
				'hold_class'      => null,
			),
			// Plain re-read, as this use case has always done: the guarded compare-and-set decides
			// the race, and `findByUuid()` raises `lock_unavailable` rather than returning null if
			// the read itself fails. See `GuardedWrite`'s docblock on why this is not unified.
			false
		);
	}

	/**
	 * Policy comes from the FIRST item's service - a chain cancels as one unit.
	 *
	 * @param array<string, mixed>       $booking
	 * @param list<array<string, mixed>> $items
	 */
	private function allowed( array $booking, array $items, \DateTimeImmutable $nowUtc ): bool {
		if ( array() === $items ) {
			return true;
		}
		$service = $this->services->find( (int) $items[0]['service_id'] );
		$policy  = new CancellationPolicy(
			null === $service ? 0 : (int) $service['cancel_window_hours'],
			null === $service ? 0 : (int) $service['reschedule_window_hours']
		);
		$start   = new \DateTimeImmutable( (string) $items[0]['start_utc'], new \DateTimeZone( 'UTC' ) );
		return (bool) apply_filters( 'reservant/booking/can_cancel', $policy->canCancel( $nowUtc, $start ), $booking, $nowUtc );
	}
}
