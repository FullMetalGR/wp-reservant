<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

final class AuditLog {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * **Checked, on the write convention every guarded transaction in this codebase follows
	 * (`false === $wpdb->insert()`'s return).** `record()` runs as the LAST statement inside every one
	 * of `HoldBooking`, `CancelBooking`, `ExpireHolds`, `RejectBooking`, `ApproveBooking`,
	 * `ConfirmBooking`, `MarkBookingOutcome` and `RescheduleBooking`'s transactions - immediately before
	 * the post-write `findByUuid()`/`findById()` re-read that becomes the 200 response. On a 1213
	 * deadlock the transaction is already dead server-side by the time this statement runs, and a
	 * discarded `false` used to let execution walk on into that re-read: it sees the row as it stood
	 * before the transaction ever started (the deadlock rolled back everything, including the real
	 * status transition a few lines above), so the caller gets a 200 carrying a snapshot of a change
	 * that never happened. Refusing here, like every other write on these paths, is what makes the
	 * transaction's own ROLLBACK visible to the request instead of silently swallowed.
	 *
	 * @param array<string, mixed> $payload
	 * @throws \RuntimeException `lock_unavailable` when the insert failed at the DB level.
	 */
	public function record( int $bookingId, string $actor, string $action, array $payload = array() ): void {
		$ok = $this->db->insert(
			$this->db->prefix . 'reservant_audit_log',
			array(
				'booking_id'   => $bookingId,
				'actor'        => $actor,
				'action'       => $action,
				'payload_json' => wp_json_encode( $payload ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
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
