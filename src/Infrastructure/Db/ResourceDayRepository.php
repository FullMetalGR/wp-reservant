<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

final class ResourceDayRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * INSERT IGNORE mutex rows. Call BEFORE the transaction opens.
	 *
	 * **Checked, because this statement is what makes the mutex exist at all.** `SELECT ... FOR
	 * UPDATE` cannot lock a row that is not there, and it does not complain about locking nothing -
	 * it reports zero rows, which for most tables is an honest answer. So a silently failed INSERT
	 * IGNORE here used to yield a lock over the empty set: `LockManager::acquire()` returned happily,
	 * every guard passed, and the capacity write ran with no mutex held whatsoever. Nothing in this
	 * codebase ever READS a `reservant_resource_days` row, so unlike an occurrence key there is no
	 * later `not_found` guard to catch it either. `acquire()` now refuses zero rows for resource-day
	 * keys as well; this is the other half of the same fix, refusing at the point of failure rather
	 * than one statement later.
	 *
	 * Zero rows is normal and correct here - INSERT IGNORE reports `0` when the row already exists,
	 * which is the usual case. Only `false` is a failure.
	 *
	 * @param list<LockKey> $keys
	 * @throws \RuntimeException `lock_unavailable` when a mutex row could not be created.
	 */
	public function ensure( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( $keys as $key ) {
			if ( 'resource_day' !== $key->type ) {
				continue;
			}
			$ok = $this->db->query(
				$this->db->prepare(
					"INSERT IGNORE INTO {$p}reservant_resource_days (resource_id, day_utc, rev) VALUES (%d, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL
					$key->id,
					$key->day
				)
			);
			if ( false === $ok ) {
				throw new \RuntimeException( 'lock_unavailable' );
			}
		}
	}

	/**
	 * Bump the mask-cache revision. Call INSIDE the transaction, after a capacity write.
	 *
	 * **The return value is checked, exactly as `LockManager::acquire()` and
	 * `BookingRepository::deleteItems()` check their own.** `$wpdb->query()` answers `false` on a
	 * DB-level failure - a 1213 deadlock (the whole transaction rolled back server-side, so every
	 * later statement commits individually under restored autocommit) or a 1205 lock-wait timeout
	 * (only the STATEMENT rolled back, `innodb_rollback_on_timeout` being OFF by default, so the
	 * transaction carries on without the row) - and neither throws nor surfaces anything while
	 * `WP_DEBUG` is off.
	 *
	 * Every caller acquires the same keys through `LockManager::acquire()` first, so a 1205 cannot
	 * arise here in practice, and `rev` has no reader yet - the mask cache is unimplemented - so this
	 * guard is inert today. It is here anyway: a silent write failure in this transaction family
	 * should not be a judgement call each time somebody reads it, and the moment `rev` acquires a
	 * reader an unnoticed bump becomes a stale mask that keeps selling a slot already taken. Refused
	 * as `lock_unavailable`, the reason `LockManager::acquire()` uses and for the same reason it uses
	 * it, so the two failures answer alike - see that docblock for why this is not `stale_state` and
	 * why `last_error` is deliberately not appended to the message.
	 *
	 * @param list<LockKey> $keys
	 * @throws \RuntimeException `lock_unavailable` when the bump failed at the DB level.
	 */
	public function bumpRev( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( $keys as $key ) {
			if ( 'resource_day' !== $key->type ) {
				continue;
			}
			$ok = $this->db->query(
				$this->db->prepare(
					"UPDATE {$p}reservant_resource_days SET rev = rev + 1 WHERE resource_id = %d AND day_utc = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$key->id,
					$key->day
				)
			);
			if ( false === $ok ) {
				throw new \RuntimeException( 'lock_unavailable' );
			}
		}
	}
}
