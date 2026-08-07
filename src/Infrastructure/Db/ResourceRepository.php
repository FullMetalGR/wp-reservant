<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Thin \wpdb wrapper over reservant_resources and reservant_service_resource. */
final class ResourceRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$this->db->insert( "{$this->db->prefix}reservant_resources", $data );
		return (int) $this->db->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_resources WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return null === $row ? null : self::castRow( $row );
	}

	/**
	 * The staff listing (AGENTS.md Task 11): `$includeInactive = false` hides a deactivated resource.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all( bool $includeInactive = true ): array {
		$p   = $this->db->prefix;
		$sql = "SELECT * FROM {$p}reservant_resources";
		if ( ! $includeInactive ) {
			$sql .= " WHERE status <> 'inactive'";
		}
		$sql .= ' ORDER BY id ASC';
		/** @var list<array<string, mixed>> $rows */
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( self::class, 'castRow' ), $rows );
	}

	/**
	 * A partial column update - only the given fields change. Used for both ordinary edits and the
	 * `setStatus()` deactivate shortcut.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}
		$this->db->update( "{$this->db->prefix}reservant_resources", $fields, array( 'id' => $id ) );
	}

	public function setStatus( int $id, string $status ): void {
		$this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * Whether any booking item - of any status, past or present - names this resource. The `referenced`
	 * delete guard (AGENTS.md Task 11): a booking's history must never dangle a foreign id, so deletion is
	 * refused in favour of deactivation whenever this is true.
	 */
	public function isReferenced( int $id ): bool {
		$p     = $this->db->prefix;
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$p}reservant_booking_items WHERE resource_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$id
			)
		);
		return $count > 0;
	}

	/**
	 * Only reachable once `isReferenced()` is false - the caller is also expected to have already
	 * unlinked services and cleared availability rules/exceptions via `AvailabilityRepository`.
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
		$result = $this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_resources WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_int( $result ) && $result > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function castRow( array $row ): array {
		$row['id'] = (int) $row['id'];
		if ( isset( $row['wp_user_id'] ) ) {
			$row['wp_user_id'] = (int) $row['wp_user_id'];
		}
		return $row;
	}

	/**
	 * The active staff record for a WordPress user, if any (AGENTS.md Task 10): the "own calendar" /
	 * "own bookings" scope a staff member is confined to. A deactivated resource is deliberately
	 * excluded - a former staff member's WP login must not still unlock somebody else's schedule.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findByWpUser( int $wpUserId ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$p}reservant_resources WHERE wp_user_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL
				$wpUserId
			),
			ARRAY_A
		);
		if ( null === $row ) {
			return null;
		}
		$row['id']         = (int) $row['id'];
		$row['wp_user_id'] = null === $row['wp_user_id'] ? null : (int) $row['wp_user_id'];
		return $row;
	}

	public function linkService( int $serviceId, int $resourceId ): void {
		$p = $this->db->prefix;
		$this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$p}reservant_service_resource (service_id, resource_id) VALUES (%d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId,
				$resourceId
			)
		);
	}

	/** The other half of `linkService()` - a resource save replacing its `service_ids` (AGENTS.md Task 11). */
	public function unlinkService( int $serviceId, int $resourceId ): void {
		$p = $this->db->prefix;
		$this->db->query(
			$this->db->prepare(
				"DELETE FROM {$p}reservant_service_resource WHERE service_id = %d AND resource_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId,
				$resourceId
			)
		);
	}

	/**
	 * Bookable staff only - a deactivated resource keeps its links but takes no new work.
	 *
	 * @return list<int> ascending
	 */
	public function activeIdsForService( int $serviceId ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT sr.resource_id
				 FROM {$p}reservant_service_resource sr
				 JOIN {$p}reservant_resources r ON r.id = sr.resource_id
				 WHERE sr.service_id = %d AND r.status = 'active'
				 ORDER BY sr.resource_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * @return list<int> ascending
	 */
	public function idsForService( int $serviceId ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT resource_id FROM {$p}reservant_service_resource WHERE service_id = %d ORDER BY resource_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId
			)
		);
		return array_map( 'intval', $ids );
	}

	/** The mirror of `idsForService()` - which services a given resource performs (AGENTS.md Task 11).
	 *
	 * @return list<int> ascending
	 */
	public function serviceIdsForResource( int $resourceId ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT service_id FROM {$p}reservant_service_resource WHERE resource_id = %d ORDER BY service_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$resourceId
			)
		);
		return array_map( 'intval', $ids );
	}
}
