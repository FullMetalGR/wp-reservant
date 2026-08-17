<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * The free / pay-on-site confirmation path (AGENTS.md section 2.3). Online payments are confirmed by
 * the WooCommerce bridge once the order is paid, never here.
 *
 * **This use case has NO transaction and takes no lock: it is one guarded UPDATE**, and
 * `BookingRepository::reapExpiredTouching()` and `CancelBooking::execute()` both build their own
 * correctness arguments on exactly that. `transition()` is itself the atomic, race-safe step, so
 * there is nothing for a transaction to make atomic *with*.
 *
 * That has a consequence for everything after it: once `transition()` returns true, the change is
 * COMMITTED - there is no open transaction to roll back. Every statement below that line is
 * therefore post-commit, and none of them may refuse (`AuditLog::record()`'s docblock states the
 * pre-decision / post-commit split in full). A refusal there would answer 409 for a booking that IS
 * confirmed, skip `reservant/booking/confirmed` so the confirmation email never goes out, and leave
 * the retry it invites answering `not_confirmable` forever. Hence `recordAfterCommit()` and
 * `findByUuidAfterCommit()` below - the post-commit halves of the two statements that used to throw.
 */
final class ConfirmBooking {

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( new BookingRepository( $db ), new AuditLog( $db ) );
	}

	/** @return array<string, mixed> */
	public function execute( string $uuid, \DateTimeImmutable $nowUtc ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		$from = BookingStatus::from( (string) $booking['status'] );
		// Only the checkout hold ends here. awaiting_approval needs a human (ApproveBooking) and
		// awaiting_payment needs the money (the WooCommerce bridge) - neither may shortcut to
		// confirmed through this path.
		if ( BookingStatus::Pending !== $from ) {
			throw new \RuntimeException( BookingStatus::AwaitingApproval === $from ? 'approval_required' : 'not_confirmable' );
		}
		if ( PaymentMode::Online->value === $booking['payment_mode'] && ! apply_filters( 'reservant/allow_direct_confirm', false, $booking ) ) {
			throw new \RuntimeException( 'online_payment_required' );
		}
		// The hold TTL is the authority: a checkout that outlives it loses the slot (section 6).
		if ( null !== $booking['hold_expires_at'] && (string) $booking['hold_expires_at'] <= $nowUtc->format( 'Y-m-d H:i:s' ) ) {
			throw new \RuntimeException( 'hold_expired' );
		}

		$released = array(
			'hold_expires_at' => null,
			'hold_class'      => null,
		);
		if ( ! $this->bookings->transition( (int) $booking['id'], $from, BookingStatus::Confirmed, $released ) ) {
			throw new \RuntimeException( 'stale_state' );
		}
		// ---- Everything below this line runs AFTER the transition committed. See the class docblock:
		// ---- no statement here may turn that committed transition into a failure report.
		$this->audit->recordAfterCommit( (int) $booking['id'], 'customer', 'confirmed' );

		$snapshot = $this->bookings->findByUuidAfterCommit(
			$uuid,
			array( 'status' => BookingStatus::Confirmed->value ) + $released,
			$booking
		);
		do_action( 'reservant/booking/confirmed', BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}
}
