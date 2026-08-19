<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;

/**
 * The hold sweeper. Correctness never depends on it having run (AGENTS.md section 2.1): expired holds
 * are already free by time comparison in every query. This only tidies rows and fires the
 * notification hook.
 */
final class ExpireHolds {

	public function __construct(
		private readonly GuardedWrite $guarded,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( GuardedWrite::make( $db ), new BookingRepository( $db ) );
	}

	/**
	 * Sweep a batch of lapsed holds.
	 *
	 * **A booking this iteration could not take is SKIPPED, not fatal.** One bad row must not stop the
	 * sweep: whatever went wrong this minute is very likely fine on the next run, and every other
	 * booking in the batch is independent of it. AGENTS.md section 2.1 permits this in both
	 * directions - correctness never depends on the sweeper having run, and a lapsed hold is already
	 * free by time comparison in every query - so aborting and skipping are equally safe, and
	 * skipping does strictly more work.
	 *
	 * The catch is narrowed to `lock_unavailable` on purpose. Anything else - an unclassified refusal, a
	 * listener that threw - is a genuine bug, and a sweeper nobody watches is the worst possible place
	 * to swallow one.
	 *
	 * **What is actually swallowed here is wider than "the mutex was busy" - and narrower than the name
	 * suggests, because `acquire()` never refuses for ordinary contention at all.** `LockManager::acquire()`
	 * takes its mutex with a plain, blocking `SELECT ... FOR UPDATE`: a row somebody else already holds
	 * makes this transaction WAIT, not fail. So `lock_unavailable` out of `acquire()` means one of only
	 * two things - the wait itself ended badly (1205 lock-wait timeout, or 1213 deadlock, both of which
	 * come back as `false`), or the resource-day mutex row was not there to lock (see that method's
	 * docblock on why zero rows is refused for a resource-day key). And `acquire()` is not even the
	 * main source: `lock_unavailable` is also the message every guarded write and locking read on this
	 * transaction's path answers a genuine DB-level failure with - `releaseSeatClaims()`, `bumpRev()`,
	 * `ensure()` and `findById()` all refuse this way too, and this catch cannot tell any of them apart
	 * from the others. That is the one real cost of narrowing the catch to a single string
	 * rather than a richer signal: a genuine DB fault on this path becomes completely invisible - no
	 * exception here, and (because `Rest\Errors::failure()` is never reached; nothing in this call chain
	 * is a REST response) no `reservant/error` action either, even after this repair adds one for every
	 * other `lock_unavailable` refusal in the codebase. The consequence stays bounded regardless:
	 * `TransactionRunner` has already rolled back whatever the failed statement was mid-transaction, so
	 * nothing corrupts - the row simply stays held-and-lapsed, exactly as it was before this iteration,
	 * and the next sweep (or a fresh hold's own inline reap) tries it again. AGENTS.md section 2.1 is
	 * why that is an acceptable place to land: correctness never depends on the sweeper having run.
	 *
	 * `expireByUuid()` is deliberately NOT given this catch: it targets exactly one booking, so there
	 * is no rest-of-the-batch to protect and a swallowed failure would simply be a lost one. Note what
	 * that means for its caller: `Jobs::timeout()` is scheduled with `as_schedule_single_action()`, a
	 * ONE-OFF, and Action Scheduler marks a throwing action failed without re-running it - so the
	 * backstop is this five-minute sweep, not a retry of the timer. The consequence worth naming: for
	 * `on_approval_timeout = 'auto_approve'`, a `lock_unavailable` out of `ApproveBooking` fails that
	 * action for good, and the sweeper later EXPIRES the booking instead of auto-approving it. The
	 * hold is not lost or double-sold, but the owner's chosen timeout policy is not what runs.
	 *
	 * @return int bookings actually moved to expired
	 */
	public function run( int $batch = 50 ): int {
		$processed = 0;
		foreach ( $this->bookings->expiredHeldIds( $batch ) as $id ) {
			// The batch read (`findById()`) is inside the same try as the reap it feeds: guarding
			// `findById()` for uniformity (this repair's item 6) means a DB failure on THIS read now
			// refuses `lock_unavailable` too, rather than silently returning null. It must be caught by
			// the very same "skip, not fatal" rule or one bad read would abort the whole batch instead
			// of just the one row it belongs to.
			try {
				$booking = $this->bookings->findById( $id );
				if ( null === $booking ) {
					continue;
				}
				if ( null !== $this->expireByUuid( (string) $booking['uuid'] ) ) {
					++$processed;
				}
			} catch ( \RuntimeException $e ) {
				if ( 'lock_unavailable' !== $e->getMessage() ) {
					throw $e;
				}
				// A lock statement, a guarded write or this row's own read just failed at the DB level
				// (ordinary contention would have WAITED, not landed here - see the docblock). Either
				// way, leave the row exactly as it is and let the next sweep have it: the hold is
				// already non-blocking by time comparison.
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

		// The `null` half of this class's contract, and the reason `TransitionRefused` is its own
		// type. Every refusal below - the row gone, no longer held, not actually lapsed yet, or the
		// compare-and-set losing to a rival - is a benign "somebody else already decided this one",
		// which is the sweeper's ordinary path rather than an error. Catching the narrow type keeps
		// that conversion from also swallowing `lock_unavailable`, which `run()` handles under its
		// own deliberately narrower rule, or a genuine bug in a post-commit listener.
		try {
			return $this->guarded->transition(
				LockKey::forItems( $items ),
				$uuid,
				BookingStatus::Expired,
				static function ( array $fresh, BookingStatus $from ): void {
					if ( ! $from->isHeld() ) {
						throw new TransitionRefused( 'stale_state' );
					}
					if ( null === $fresh['hold_expires_at'] || (string) $fresh['hold_expires_at'] > gmdate( 'Y-m-d H:i:s' ) ) {
						throw new TransitionRefused( 'stale_state' );
					}
				},
				'stale_state',
				'system',
				'expired',
				'reservant/hold/expired',
				array(),
				// Plain re-read, as this sweeper has always done - see `GuardedWrite`'s docblock on
				// why the locking/non-locking split is preserved rather than unified.
				false
			);
		} catch ( TransitionRefused ) {
			return null;
		}
	}
}
