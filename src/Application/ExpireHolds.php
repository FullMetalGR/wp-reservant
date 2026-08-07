<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
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

	/**
	 * Sweep a batch of lapsed holds.
	 *
	 * **A booking whose mutex is busy is SKIPPED, not fatal.** One contended row must not stop the
	 * sweep: a lock lost this minute is one the next run will very likely take, and every other
	 * booking in the batch is independent of it. AGENTS.md section 2.1 permits this in both
	 * directions - correctness never depends on the sweeper having run, and a lapsed hold is already
	 * free by time comparison in every query - so aborting and skipping are equally safe, and
	 * skipping does strictly more work.
	 *
	 * The catch is narrowed to `lock_unavailable` on purpose. Anything else - a failed write, an
	 * unclassified refusal, a listener that threw - is a genuine bug, and a sweeper nobody watches is
	 * the worst possible place to swallow one. `expireByUuid()` itself is deliberately NOT given this
	 * catch: it targets exactly one booking (`Jobs::timeout()`), where there is no rest-of-the-batch
	 * to protect and the right answer is to let Action Scheduler see the failure and retry.
	 *
	 * @return int bookings actually moved to expired
	 */
	public function run( int $batch = 50 ): int {
		$processed = 0;
		foreach ( $this->bookings->expiredHeldIds( $batch ) as $id ) {
			$booking = $this->bookings->findById( $id );
			if ( null === $booking ) {
				continue;
			}
			try {
				if ( null !== $this->expireByUuid( (string) $booking['uuid'] ) ) {
					++$processed;
				}
			} catch ( \RuntimeException $e ) {
				if ( 'lock_unavailable' !== $e->getMessage() ) {
					throw $e;
				}
				// Somebody else holds this slot's mutex right now. Leave the row exactly as it is and
				// let the next sweep have it - the hold is already non-blocking by time comparison.
			}
		}
		return $processed;
	}

	/**
	 * Expire a single booking by uuid, through the same terminal transition `run()` batches over.
	 * Extracted so `Jobs::TIMEOUT` (AGENTS.md "Approval holds", `on_approval_timeout = 'expire'`)
	 * can target exactly the one booking a timer fired for, without duplicating the
	 * reap/lock/transition sequence. A `null` return is not an error - the booking may already be
	 * confirmed, rejected, or expired by the time this runs.
	 *
	 * @return array<string, mixed>|null the post-expiry snapshot, or null if nothing happened.
	 */
	public function expireByUuid( string $uuid ): ?array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			return null;
		}
		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		$keys  = HoldBooking::lockKeysForItems( $items );
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run( fn (): ?array => $this->expire( $keys, $uuid ) );
		if ( null !== $snapshot ) {
			do_action( 'reservant/hold/expired', BookingSnapshot::fromArray( $snapshot ) );
		}
		return $snapshot;
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
