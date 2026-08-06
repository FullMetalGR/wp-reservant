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

	/**
	 * The seat map catalog (AGENTS.md Task 12): id/name/spec only - the admin listing shows one row
	 * per map, not every seat of every map.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results( "SELECT id, name, spec FROM {$p}reservant_seat_maps ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map(
			static function ( array $row ): array {
				$row['id'] = (int) $row['id'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT id, name, spec FROM {$p}reservant_seat_maps WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( null === $row ) {
			return null;
		}
		$row['id'] = (int) $row['id'];
		return $row;
	}

	/** Re-parsed spec text plus its (possibly renamed) map row - the seat rows are replaced separately. */
	public function updateSpec( int $id, string $name, string $spec ): void {
		$this->db->update(
			"{$this->db->prefix}reservant_seat_maps",
			array(
				'name' => $name,
				'spec' => $spec,
			),
			array( 'id' => $id )
		);
	}

	/** Every seat row of one map, physically removed - only ever called alongside `insertSeats()` (a replace) or `delete()` (a teardown), both inside a transaction. */
	public function deleteSeats( int $seatMapId ): void {
		$p = $this->db->prefix;
		$this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_seats WHERE seat_map_id = %d", $seatMapId ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Only reachable once `hasClaims()` is false - the caller enforces that, not this method.
	 * Returns whether a row was actually removed (Task 11 fix round 1 idiom, AGENTS.md): see
	 * `ServiceRepository::delete()`'s docblock for why "not exactly one row removed" must never be
	 * read as success by the caller.
	 */
	public function delete( int $id ): bool {
		$p      = $this->db->prefix;
		$result = $this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_seat_maps WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Whether any seat of this map is currently claimed by a blocking booking item (AGENTS.md Task 12):
	 * the PUT/DELETE `referenced` guard. `seat_claim` names a `reservant_seats.id`, so this joins
	 * through that id rather than through `occurrence_id` - a map can be reused across occurrences of
	 * the same seat-mapped service (AGENTS.md section 4), and a claim on any of them must block.
	 */
	public function hasClaims( int $mapId ): bool {
		$p     = $this->db->prefix;
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*)
				 FROM {$p}reservant_seats se
				 INNER JOIN {$p}reservant_booking_items i ON i.seat_claim = se.id
				 INNER JOIN {$p}reservant_bookings b ON b.id = i.booking_id
				 WHERE se.seat_map_id = %d
				 AND " . BookingRepository::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$mapId
			)
		);
		return (int) $count > 0;
	}
}
