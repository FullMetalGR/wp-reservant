<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Owner approval of a booking that required it (AGENTS.md "Approval queue"). Free/onsite only:
 * the approve -> payment-link step for a paid service is the WooCommerce bridge's job, not this
 * use case's - it always lands the booking `confirmed`.
 */
final class ApproveBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( new TransactionRunner( $db ), new BookingRepository( $db ), new AuditLog( $db ) );
	}

	/**
	 * @param string   $actor       Free-form audit actor label (e.g. 'admin', a signed-link token
	 *                              name). Recorded on the audit row only.
	 * @param int|null $actorUserId The approving WP user id, when one exists. `approved_by` is a
	 *                              BIGINT UNSIGNED FK (Migrations::run()) - it takes this, never
	 *                              the free-form `$actor` string, and is left a real SQL NULL for
	 *                              system/signed-link approvals that have no WP user behind them.
	 * @return array<string, mixed>
	 */
	public function execute( string $uuid, \DateTimeImmutable $nowUtc, string $actor, ?int $actorUserId = null ): array {
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
			function () use ( $uuid, $nowUtc, $actor, $actorUserId ): array {
				// Re-read under FOR UPDATE: a lapsed hold or a rival decision (reject/cancel) may
				// have landed between the unlocked read above and this transaction opening, and the
				// row this one acts on is the one read here.
				$fresh = $this->bookings->findByUuidForUpdate( $uuid );
				if ( null === $fresh ) {
					throw new \RuntimeException( 'stale_state' );
				}
				if ( ! self::approvable( $fresh, $nowUtc ) ) {
					throw new \RuntimeException( 'not_approvable' );
				}

				$extra = array(
					'hold_class'      => null,
					'hold_expires_at' => null,
					'approved_at'     => $nowUtc->format( 'Y-m-d H:i:s' ),
					'approved_by'     => $actorUserId,
				);
				if ( ! $this->bookings->transition( (int) $fresh['id'], BookingStatus::AwaitingApproval, BookingStatus::Confirmed, $extra ) ) {
					throw new \RuntimeException( 'not_approvable' );
				}
				$this->audit->record( (int) $fresh['id'], $actor, 'approve' );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		do_action( 'reservant/booking/approved', BookingSnapshot::fromArray( $snapshot ) );
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
