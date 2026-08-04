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
}
