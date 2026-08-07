<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Row-lock acquisition in deterministic global order. Call only inside TransactionRunner::run(). */
final class LockManager {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Take every mutex row named by `$keys`, in the global order `LockKey::sorted()` defines.
	 *
	 * **Both statements are checked, exactly as `BookingRepository::deleteItems()` checks its own.**
	 * `$wpdb->query()` answers `false` on a DB-level failure and neither throws nor surfaces anything
	 * while `WP_DEBUG` is off, so an unchecked acquisition returns normally and leaves the caller
	 * believing it holds a lock it does not. Both MariaDB failures end in a committed write the
	 * section 2.2 protocol was written to make impossible:
	 *
	 *  - **1205, lock-wait timeout.** `innodb_rollback_on_timeout` is OFF by default, so only the
	 *    STATEMENT is rolled back. The transaction stays open WITHOUT the lock and execution walks on
	 *    into the capacity re-validation and COMMIT - a committed capacity write outside the mutex,
	 *    decided against a snapshot no rival was ever serialised against.
	 *  - **1213, deadlock.** The server rolls the whole transaction back, so every later statement runs
	 *    under restored autocommit and commits INDIVIDUALLY: in a reschedule the item DELETE lands
	 *    alone, then the INSERT lands alone, and `TransactionRunner`'s eventual ROLLBACK is a no-op.
	 *    `RescheduleBooking`'s "atomic release + re-hold; partial success is impossible" would be a
	 *    promise the code cannot keep, and the request would still answer 200.
	 *
	 * The refusal is `lock_unavailable` (`Rest\Errors::KNOWN_REASONS`, a 409), and it is deliberately
	 * NOT `stale_state`. `stale_state` means "a rival moved this booking between the plan and the
	 * transaction" - a benign no-op to a caller that only wanted the booking decided, which is why
	 * `Admin\ApprovalActionEndpoint` lists it among its benign refusals and answers it with "may
	 * already have been handled". A lock that could not be taken is the opposite claim: nothing
	 * happened, the row is untouched, and the request is worth repeating verbatim. One reason cannot
	 * carry both meanings without lying about one of them.
	 *
	 * The message is the bare reason, with `$this->db->last_error` deliberately NOT appended -
	 * unlike `deleteItems()`, which does append it. `Rest\Errors::failure()` matches `KNOWN_REASONS`
	 * by exact `in_array`, so a decorated `lock_unavailable: <last_error>` would miss the list, fall
	 * to the opaque 500 arm, and destroy the 409 retry signal that is the entire point of naming this
	 * reason. The DB text is not lost: that same 500 arm fires `reservant/error` with the exception.
	 *
	 * Locking ZERO rows is not a failure and is deliberately not guarded here:
	 * `0` is an honest answer for a key whose entity is gone, and the guards that read after this
	 * refuse it as `not_found` on their own. Only `false` is a failure - `deleteItems()`'s convention,
	 * not a second one.
	 *
	 * @param list<LockKey> $keys
	 * @throws \RuntimeException `lock_unavailable` when a lock statement failed at the DB level.
	 */
	public function acquire( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( LockKey::sorted( $keys ) as $key ) {
			if ( 'resource_day' === $key->type ) {
				$ok = $this->db->query(
					$this->db->prepare(
						"SELECT resource_id FROM {$p}reservant_resource_days WHERE resource_id = %d AND day_utc = %s FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL
						$key->id,
						$key->day
					)
				);
			} else {
				$ok = $this->db->query(
					$this->db->prepare(
						"SELECT id FROM {$p}reservant_occurrences WHERE id = %d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL
						$key->id
					)
				);
			}
			if ( false === $ok ) {
				throw new \RuntimeException( 'lock_unavailable' );
			}
		}
	}
}
