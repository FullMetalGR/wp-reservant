<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

use Reservant\Domain\Seating\Seat;

/** Thin \wpdb wrapper over reservant_seat_maps and reservant_seats. */
final class SeatMapRepository {

	private const INT_COLUMNS = array( 'id', 'seat_map_id', 'sort_row', 'sort_col' );

	public function __construct( private readonly \wpdb $db ) {}

	public function insert( string $name, string $spec ): int {
		$this->db->insert(
			"{$this->db->prefix}reservant_seat_maps",
			array(
				'name' => $name,
				'spec' => $spec,
			)
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * Persist a parsed grid. Aisles and blocked cells are stored too - they are geometry the
	 * picker needs to draw, and `OccurrenceRepository::validSeatIds()` filters them out of what a
	 * customer may claim.
	 *
	 * @param list<Seat> $seats
	 * @return list<int> ids in the order given
	 */
	public function insertSeats( int $seatMapId, array $seats ): array {
		$ids = array();
		foreach ( $seats as $seat ) {
			$this->db->insert(
				"{$this->db->prefix}reservant_seats",
				array(
					'seat_map_id' => $seatMapId,
					'row_label'   => $seat->rowLabel,
					'seat_label'  => $seat->seatLabel,
					'sort_row'    => $seat->sortRow,
					'sort_col'    => $seat->sortCol,
					'kind'        => $seat->kind,
				)
			);
			$ids[] = (int) $this->db->insert_id;
		}
		return $ids;
	}

	/**
	 * The whole grid in reading order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function seatsForMap( int $seatMapId ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, seat_map_id, row_label, seat_label, sort_row, sort_col, kind
				 FROM {$p}reservant_seats
				 WHERE seat_map_id = %d
				 ORDER BY sort_row ASC, sort_col ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$seatMapId
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				foreach ( self::INT_COLUMNS as $column ) {
					$row[ $column ] = (int) $row[ $column ];
				}
				return $row;
			},
			$rows
		);
	}
}
