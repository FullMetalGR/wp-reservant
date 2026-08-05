<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Owner refusal of a booking that required approval (AGENTS.md "Approval queue"). Releases the
 * seat claims a rejected booking was holding, the same as a cancellation does.
 */
final class RejectBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( new TransactionRunner( $db ), new BookingRepository( $db ), new AuditLog( $db ) );
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

		$snapshot = $this->txn->run(
			function () use ( $uuid, $nowUtc, $reason, $actor ): array {
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
