<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * The hold sweeper. Correctness never depends on it having run (AGENTS.md section 2.1): expired holds
 * are already free by time comparison in every query. This only tidies rows and fires the
 * notification hook.
 */
final class ExpireHolds {

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

	/** @return int bookings actually moved to expired */
	public function run( int $batch = 50 ): int {
		$processed = 0;
		foreach ( $this->bookings->expiredHeldIds( $batch ) as $id ) {
			$booking = $this->bookings->findById( $id );
			if ( null === $booking ) {
				continue;
			}
			$uuid = (string) $booking['uuid'];
			/** @var list<array<string, mixed>> $items */
			$items = $booking['items'];
			$keys  = HoldBooking::lockKeysForItems( $items );
			$this->resourceDays->ensure( $keys );

			$snapshot = $this->txn->run( fn (): ?array => $this->expire( $keys, $uuid ) );
			if ( null === $snapshot ) {
				continue;
			}
			++$processed;
			do_action( 'reservant/hold/expired', $snapshot );
		}
		return $processed;
	}

	/**
	 * Re-read and re-check under the lock - the row may have been confirmed or cancelled
	 * between the batch query and here.
	 *
	 * @param list<\Reservant\Infrastructure\Db\LockKey> $keys
	 * @return array<string, mixed>|null
	 */
	private function expire( array $keys, string $uuid ): ?array {
		$this->locks->acquire( $keys );
		$fresh = $this->bookings->findByUuid( $uuid );
		if ( null === $fresh ) {
			return null;
		}
		$from = BookingStatus::from( (string) $fresh['status'] );
		if ( ! $from->isHeld() ) {
			return null;
		}
		if ( null === $fresh['hold_expires_at'] || (string) $fresh['hold_expires_at'] > gmdate( 'Y-m-d H:i:s' ) ) {
			return null;
		}
		if ( ! $this->bookings->transition( (int) $fresh['id'], $from, BookingStatus::Expired ) ) {
			return null;
		}
		$this->bookings->releaseSeatClaims( (int) $fresh['id'] );
		$this->resourceDays->bumpRev( $keys );
		$this->audit->record( (int) $fresh['id'], 'system', 'expired' );

		/** @var array<string, mixed> $stored */
		$stored = $this->bookings->findByUuid( $uuid );
		return $stored;
	}
}
