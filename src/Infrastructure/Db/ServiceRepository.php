<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Thin \wpdb wrapper over reservant_services. Read-side + insert only. */
final class ServiceRepository {

	/** @var list<string> columns cast to int on read */
	private const INT_COLUMNS = array(
		'id',
		'duration_min',
		'processing_time_min',
		'buffer_before_min',
		'buffer_after_min',
		'capacity',
		'seat_map_id',
		'price_minor',
		'requires_approval',
		'approval_hold_hours',
		'cancel_window_hours',
		'reschedule_window_hours',
		'lead_time_min',
		'horizon_days',
		'wc_product_id',
	);

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$now                = gmdate( 'Y-m-d H:i:s' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$this->db->insert( "{$this->db->prefix}reservant_services", $data );
		return (int) $this->db->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_services WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return null === $row ? null : self::castRow( $row );
	}

	/**
	 * The catalog listing (AGENTS.md Task 11): `$includeInactive = false` hides a deactivated service -
	 * the state a `referenced` service is steered towards instead of deletion.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all( bool $includeInactive = true ): array {
		$p   = $this->db->prefix;
		$sql = "SELECT * FROM {$p}reservant_services";
		if ( ! $includeInactive ) {
			$sql .= " WHERE status <> 'inactive'";
		}
		$sql .= ' ORDER BY id ASC';
		/** @var list<array<string, mixed>> $rows */
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( self::class, 'castRow' ), $rows );
	}

	/**
	 * A partial column update - only the given fields change, plus `updated_at`. Used for both
	 * ordinary edits and the `setStatus()` deactivate shortcut.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->db->update( "{$this->db->prefix}reservant_services", $fields, array( 'id' => $id ) );
	}

	public function setStatus( int $id, string $status ): void {
		$this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * Whether any booking item - of any status, past or present - names this service. The `referenced`
	 * delete guard (AGENTS.md Task 11): a booking's history must never dangle a foreign id, so deletion is
	 * refused in favour of deactivation whenever this is true.
	 */
	public function isReferenced( int $id ): bool {
		$p     = $this->db->prefix;
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$p}reservant_booking_items WHERE service_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$id
			)
		);
		return $count > 0;
	}

	/**
	 * Only reachable once `isReferenced()` is false - the caller enforces that, not this method.
	 *
	 * Returns whether a row was actually removed (AGENTS.md Task 11 fix round 1): `$wpdb->query()`
	 * reports the number of affected rows on success, or `false` on a driver-level failure - either
	 * way, "not exactly one row removed" must not be read by the caller as success. The caller is
	 * expected to run this inside the same transaction as its own fresh `isReferenced()` recheck, so
	 * a `false` here means the row vanished (or a write genuinely failed) in the gap between the
	 * caller's outer check and this call, not that the guard was bypassed.
	 */
	public function delete( int $id ): bool {
		$p      = $this->db->prefix;
		$result = $this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_services WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_int( $result ) && $result > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function castRow( array $row ): array {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}
}
