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
		$this->audit->record( (int) $booking['id'], 'customer', 'confirmed' );

		/** @var array<string, mixed> $snapshot */
		$snapshot = $this->bookings->findByUuid( $uuid );
		do_action( 'reservant/booking/confirmed', BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}
}
