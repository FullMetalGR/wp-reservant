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
		if ( null === $row ) {
			return null;
		}
		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}
}
