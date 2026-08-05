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
		if ( null === $row ) {
			return null;
		}
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
}
