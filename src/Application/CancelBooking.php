<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Domain\Booking\CancellationPolicy;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Whole-booking cancellation (AGENTS.md section 1: items have no independent lifecycle). Releases the
 * slot under the same locks a hold takes; refunds are flagged for the owner, never automatic.
 */
final class CancelBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new TransactionRunner( $db ),
			new LockManager( $db ),
			new ResourceDayRepository( $db ),
			new BookingRepository( $db ),
			new ServiceRepository( $db ),
			new AuditLog( $db )
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

		$keys     = HoldBooking::lockKeysForItems( $items );
		$released = array(
			'hold_expires_at' => null,
			'hold_class'      => null,
		);
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run(
			function () use ( $keys, $booking, $uuid, $released, $onlyFromStatuses ): array {
				$this->locks->acquire( $keys );

				// Re-read inside the transaction. Every check above ran on an unlocked snapshot, and
				// ConfirmBooking takes no lock at all - it is one guarded UPDATE - so the booking may
				// have moved on since. The status this transaction acts on is the one read here, and
				// the transition below is guarded by it, so the residual window between the two is a
				// `stale_state` refusal rather than a wrong outcome.
				$fresh = $this->bookings->findById( (int) $booking['id'] );
				if ( null === $fresh ) {
					throw new \RuntimeException( 'stale_state' );
				}
				$from = BookingStatus::from( (string) $fresh['status'] );
				if ( null !== $onlyFromStatuses && ! in_array( $from, $onlyFromStatuses, true ) ) {
					throw new \RuntimeException( 'not_held' );
				}
				if ( ! $from->canTransitionTo( BookingStatus::Cancelled ) ) {
					throw new \RuntimeException( 'not_cancellable' );
				}
				if ( ! $this->bookings->transition( (int) $booking['id'], $from, BookingStatus::Cancelled, $released ) ) {
					throw new \RuntimeException( 'stale_state' );
				}
				$this->bookings->releaseSeatClaims( (int) $booking['id'] );
				$this->resourceDays->bumpRev( $keys );
				$this->audit->record( (int) $booking['id'], 'customer', 'cancelled' );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		do_action( 'reservant/booking/cancelled', $snapshot );
		return $snapshot;
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
