<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

use Reservant\Domain\Seating\Seat;

/** Thin \wpdb wrapper over reservant_seat_maps and reservant_seats. */
final class SeatMapRepository {

	private const INT_COLUMNS = array( 'id', 'seat_map_id', 'sort_row', 'sort_col' );

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * **Checked**: an unchecked failure here left `$this->db->insert_id` holding whatever the PREVIOUS
	 * successful insert on this connection set it to (or `0` if there had been none), and the caller
	 * (`SeatMapsAdminController::create()`) fed that id straight into `insertSeats()` - seat rows
	 * attached to the wrong map, or to no map at all.
	 *
	 * @throws \RuntimeException `lock_unavailable` when the insert failed at the DB level.
	 */
	public function insert( string $name, string $spec ): int {
		$ok = $this->db->insert(
			"{$this->db->prefix}reservant_seat_maps",
			array(
				'name' => $name,
				'spec' => $spec,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Persist a parsed grid. Aisles and blocked cells are stored too - they are geometry the
	 * picker needs to draw, and `OccurrenceRepository::validSeatIds()` filters them out of what a
	 * customer may claim.
	 *
	 * **Checked**, the other half of `deleteSeats()`: a rewrite that inserted only part of the new grid
	 * and committed would leave a map whose seats are neither the old set nor the new one.
	 *
	 * @param list<Seat> $seats
	 * @return list<int> ids in the order given
	 * @throws \RuntimeException `lock_unavailable` when a seat insert failed at the DB level.
	 */
	public function insertSeats( int $seatMapId, array $seats ): array {
		$ids = array();
		foreach ( $seats as $seat ) {
			$ok = $this->db->insert(
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
			if ( false === $ok ) {
				throw new \RuntimeException( 'lock_unavailable' );
			}
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
	 * The seat map catalog (AGENTS.md Task 12): the `reservant_seat_maps` columns only - id, name
	 * and the spec text.
	 *
	 * NOT the wire shape. `SeatMapsAdminController::index()` runs every row returned here through
	 * the same `present()` the single-map routes use, which attaches that map's own `seatsForMap()`
	 * grid: `GET /admin/seat-maps` answers rows carrying `seats`, exactly like
	 * `GET /admin/seat-maps/{id}` does. Nothing may read a row straight out of this method onto the
	 * wire - that is precisely the drift that shipped a seats-less list and crashed the seat map
	 * screen (`map.seats.length` on `undefined`).
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

	/**
	 * Re-parsed spec text plus its (possibly renamed) map row - the seat rows are replaced
	 * separately. Returns whether the write itself succeeded (review round 1 fix, AGENTS.md Task 12):
	 * `$wpdb->update()` returns `int|false` - `false` only on a genuine driver-level failure, while a
	 * matched-but-unchanged row (every column already held the value being written) legitimately
	 * reports `0` rows "affected" without being an error. The caller's own existence recheck
	 * (`lockForUpdate()`) is what actually proves the row is still there before this runs; this
	 * method's return is a secondary signal for "did the write itself fail", not "did anything
	 * change".
	 */
	public function updateSpec( int $id, string $name, string $spec ): bool {
		$result = $this->db->update(
			"{$this->db->prefix}reservant_seat_maps",
			array(
				'name' => $name,
				'spec' => $spec,
			),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/**
	 * A locking existence recheck (review round 1 fix, AGENTS.md Task 12): `SELECT ... FOR UPDATE`,
	 * only meaningful inside a transaction. Proves the row is still there right before a guarded
	 * write mutates it, and - by taking the row lock - keeps it there for the rest of that
	 * transaction, so a concurrent DELETE cannot remove it out from under `updateSpec()`/
	 * `deleteSeats()`/`insertSeats()` and leave orphaned seat rows pointing at a vanished map.
	 */
	/**
	 * @throws \RuntimeException `lock_unavailable` when the locking read failed at the DB level -
	 *                           `get_var()` answers `null` both for a row that is gone and for a query
	 *                           that failed, and the caller reads `false` here as "gone" and throws
	 *                           `update_conflict`, which is NOT in `Rest\Errors::KNOWN_REASONS` and so
	 *                           lands on the opaque 500 arm. A retryable contention failure must not
	 *                           be reported as an internal error.
	 */
	public function lockForUpdate( int $id ): bool {
		$p     = $this->db->prefix;
		$found = $this->db->get_var(
			$this->db->prepare( "SELECT id FROM {$p}reservant_seat_maps WHERE id = %d FOR UPDATE", $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
		return null !== $found;
	}

	/**
	 * Every seat row of one map, physically removed - only ever called alongside `insertSeats()` (a
	 * replace) or `delete()` (a teardown), both inside a transaction.
	 *
	 * **Checked**: a rewrite is this DELETE followed by `insertSeats()`, so a failure here that was
	 * walked past would let the insert land ON TOP of the rows that should have gone, committing a map
	 * holding every seat twice.
	 *
	 * @throws \RuntimeException `lock_unavailable` when the delete failed at the DB level.
	 */
	public function deleteSeats( int $seatMapId ): void {
		$p  = $this->db->prefix;
		$ok = $this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_seats WHERE seat_map_id = %d", $seatMapId ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( false === $ok ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
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
