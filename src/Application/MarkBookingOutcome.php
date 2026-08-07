<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * Attendance bookkeeping on a booking that already happened (AGENTS.md status diagram: `confirmed`
 * -> `completed`/`no_show`). Like `ConfirmBooking`, this is one guarded UPDATE - `transition()` is
 * itself the atomic, race-safe step, so no lock is needed to serialise it against a rival writer.
 */
final class MarkBookingOutcome {

	private const OUTCOMES = array( 'completed', 'no_show' );

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( new BookingRepository( $db ), new AuditLog( $db ) );
	}

	/** @return array<string, mixed> */
	public function execute( string $uuid, string $outcome, string $actor ): array {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			throw new \RuntimeException( 'bad_outcome' );
		}
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		$from = BookingStatus::from( (string) $booking['status'] );
		if ( BookingStatus::Confirmed !== $from ) {
			throw new \RuntimeException( 'stale_state' );
		}

		$to = 'completed' === $outcome ? BookingStatus::Completed : BookingStatus::NoShow;
		if ( ! $this->bookings->transition( (int) $booking['id'], $from, $to ) ) {
			throw new \RuntimeException( 'stale_state' );
		}
		$this->audit->record( (int) $booking['id'], $actor, $outcome );

		/** @var array<string, mixed> $snapshot */
		$snapshot = $this->bookings->findByUuid( $uuid );
		do_action( 'reservant/booking/' . $outcome, BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}
}
