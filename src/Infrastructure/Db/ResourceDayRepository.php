<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

final class ResourceDayRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * INSERT IGNORE mutex rows. Call BEFORE the transaction opens.
	 *
	 * @param list<LockKey> $keys
	 */
	public function ensure( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( $keys as $key ) {
			if ( 'resource_day' !== $key->type ) {
				continue;
			}
			$this->db->query(
				$this->db->prepare(
					"INSERT IGNORE INTO {$p}reservant_resource_days (resource_id, day_utc, rev) VALUES (%d, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL
					$key->id,
					$key->day
				)
			);
		}
	}

	/**
	 * Bump the mask-cache revision. Call INSIDE the transaction, after a capacity write.
	 *
	 * @param list<LockKey> $keys
	 */
	public function bumpRev( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( $keys as $key ) {
			if ( 'resource_day' !== $key->type ) {
				continue;
			}
			$this->db->query(
				$this->db->prepare(
					"UPDATE {$p}reservant_resource_days SET rev = rev + 1 WHERE resource_id = %d AND day_utc = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$key->id,
					$key->day
				)
			);
		}
	}
}
