<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

final class AuditLog {

	public function __construct( private readonly \wpdb $db ) {}

	/** @param array<string, mixed> $payload */
	public function record( int $bookingId, string $actor, string $action, array $payload = array() ): void {
		$this->db->insert(
			$this->db->prefix . 'reservant_audit_log',
			array(
				'booking_id'   => $bookingId,
				'actor'        => $actor,
				'action'       => $action,
				'payload_json' => wp_json_encode( $payload ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * The full trail for one booking, oldest first - the admin detail view (AGENTS.md Task 10).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forBooking( int $bookingId ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, actor, action, payload_json, created_at FROM {$p}reservant_audit_log WHERE booking_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$bookingId
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['id']      = (int) $row['id'];
				$decoded        = null === $row['payload_json'] ? array() : json_decode( (string) $row['payload_json'], true );
				$row['payload'] = is_array( $decoded ) ? $decoded : array();
				unset( $row['payload_json'] );
				return $row;
			},
			$rows
		);
	}
}
