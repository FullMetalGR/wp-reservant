<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Row-lock acquisition in deterministic global order. Call only inside TransactionRunner::run(). */
final class LockManager {

	public function __construct( private readonly \wpdb $db ) {}

	/** @param list<LockKey> $keys */
	public function acquire( array $keys ): void {
		$p = $this->db->prefix;
		foreach ( LockKey::sorted( $keys ) as $key ) {
			if ( 'resource_day' === $key->type ) {
				$this->db->query(
					$this->db->prepare(
						"SELECT resource_id FROM {$p}reservant_resource_days WHERE resource_id = %d AND day_utc = %s FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL
						$key->id,
						$key->day
					)
				);
			} else {
				$this->db->query(
					$this->db->prepare(
						"SELECT id FROM {$p}reservant_occurrences WHERE id = %d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL
						$key->id
					)
				);
			}
		}
	}
}
