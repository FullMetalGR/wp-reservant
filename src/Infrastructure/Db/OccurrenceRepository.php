<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Thin \wpdb wrapper over reservant_occurrences. */
final class OccurrenceRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$this->db->insert( "{$this->db->prefix}reservant_occurrences", $data );
		return (int) $this->db->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_occurrences WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( null === $row ) {
			return null;
		}
		foreach ( array( 'id', 'service_id', 'capacity', 'booked_seats' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}

	/**
	 * Active occurrences of one event service whose start falls in the half-open window.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findForService( int $serviceId, string $fromUtc, string $toUtc ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, service_id, start_utc, end_utc, capacity, status
				 FROM {$p}reservant_occurrences
				 WHERE service_id = %d AND status = 'active' AND start_utc >= %s AND start_utc < %s
				 ORDER BY start_utc ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId,
				$fromUtc,
				$toUtc
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				foreach ( array( 'id', 'service_id', 'capacity' ) as $column ) {
					$row[ $column ] = (int) $row[ $column ];
				}
				return $row;
			},
			$rows
		);
	}

	/**
	 * Active occurrences of ANY event service overlapping the window, joined to their service name
	 * (AGENTS.md Task 10 - the admin calendar's `occurrences` list, always shown regardless of the
	 * resource scoping applied to `bookings`: an occurrence names no staff member).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findInRange( string $fromUtc, string $toUtc ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT o.id, o.service_id, s.name AS service_name, o.start_utc, o.end_utc, o.capacity
				 FROM {$p}reservant_occurrences o
				 JOIN {$p}reservant_services s ON s.id = o.service_id
				 WHERE o.status = 'active' AND o.start_utc < %s AND o.end_utc > %s
				 ORDER BY o.start_utc ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$toUtc,
				$fromUtc
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				foreach ( array( 'id', 'service_id', 'capacity' ) as $column ) {
					$row[ $column ] = (int) $row[ $column ];
				}
				return $row;
			},
			$rows
		);
	}

	/**
	 * The seat ids a customer may actually claim on a map: aisles and blocked cells are grid
	 * geometry, not seats.
	 *
	 * @return list<int> ascending
	 */
	public function validSeatIds( int $seatMapId ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$p}reservant_seats WHERE seat_map_id = %d AND kind = 'seat' ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$seatMapId
			)
		);
		return array_map( 'intval', $ids );
	}

	/** COALESCE(SUM(i.seats),0) over items joined to bookings passing the blocking predicate. */
	public function blockingSeatSum( int $occurrenceId ): int {
		$p   = $this->db->prefix;
		$sum = $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(SUM(i.seats), 0)
				 FROM {$p}reservant_booking_items i
				 INNER JOIN {$p}reservant_bookings b ON b.id = i.booking_id
				 WHERE i.occurrence_id = %d
				 AND " . BookingRepository::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$occurrenceId
			)
		);
		return (int) $sum;
	}

	/**
	 * `blockingSeatSum()` for a whole list, in one query - the availability endpoint asks about
	 * every occurrence in a window and must not do that one round trip at a time.
	 *
	 * @param list<int> $occurrenceIds
	 * @return array<int, int> keyed by occurrence id, zero-filled
	 */
	public function blockingSeatSums( array $occurrenceIds ): array {
		$occurrenceIds = array_values( array_unique( $occurrenceIds ) );
		$sums          = array_fill_keys( $occurrenceIds, 0 );
		if ( array() === $occurrenceIds ) {
			return $sums;
		}
		$p    = $this->db->prefix;
		$in   = implode( ',', array_map( 'intval', $occurrenceIds ) );
		$rows = $this->db->get_results(
			"SELECT i.occurrence_id, COALESCE(SUM(i.seats), 0) AS taken
			 FROM {$p}reservant_booking_items i
			 INNER JOIN {$p}reservant_bookings b ON b.id = i.booking_id
			 WHERE i.occurrence_id IN ({$in})
			 AND " . BookingRepository::BLOCKING_SQL . '
			 GROUP BY i.occurrence_id', // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$sums[ (int) $row['occurrence_id'] ] = (int) $row['taken'];
		}
		return $sums;
	}

	/**
	 * Blocking claims only (seat_claim IS NOT NULL).
	 *
	 * @return list<int>
	 */
	public function claimedSeatIds( int $occurrenceId ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT i.seat_claim
				 FROM {$p}reservant_booking_items i
				 INNER JOIN {$p}reservant_bookings b ON b.id = i.booking_id
				 WHERE i.occurrence_id = %d
				 AND i.seat_claim IS NOT NULL
				 AND " . BookingRepository::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$occurrenceId
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Every occurrence of one service, any status, admin listing order (AGENTS.md Task 12) - not
	 * `findForService()`'s customer-facing `status = 'active'` window filter, since the admin needs
	 * to see a cancelled occurrence too. `booked` is `blockingSeatSum()` per row via one batched
	 * query (`blockingSeatSums()`), never the stale `booked_seats` column.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forService( int $serviceId ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, service_id, start_utc, end_utc, capacity, status
				 FROM {$p}reservant_occurrences
				 WHERE service_id = %d
				 ORDER BY start_utc ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$serviceId
			),
			ARRAY_A
		);
		$rows = array_map(
			static function ( array $row ): array {
				foreach ( array( 'id', 'service_id', 'capacity' ) as $column ) {
					$row[ $column ] = (int) $row[ $column ];
				}
				return $row;
			},
			$rows
		);
		$sums = $this->blockingSeatSums( array_column( $rows, 'id' ) );
		return array_map(
			static function ( array $row ) use ( $sums ): array {
				$row['booked'] = $sums[ $row['id'] ] ?? 0;
				return $row;
			},
			$rows
		);
	}

	/**
	 * A partial column update - only the given fields change (AGENTS.md Task 12, mirrors
	 * `ServiceRepository::update()`).
	 *
	 * **Checked**, because it runs inside the occurrence-mutex transaction and its caller
	 * (`Rest\Admin\OccurrencesAdminController::update()`) re-reads the row straight afterwards and
	 * presents it. A silently failed UPDATE therefore returned the UNCHANGED row with a 200: the owner
	 * is told the edit applied while the database still holds the old value. Zero rows is honest here
	 * - writing the values a row already holds legitimately affects nothing - so only `false` refuses.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \RuntimeException `lock_unavailable` when the update failed at the DB level.
	 */
	public function update( int $id, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}
		$ok = $this->db->update( "{$this->db->prefix}reservant_occurrences", $fields, array( 'id' => $id ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
	}

	/**
	 * Soft-cancel: `status = 'cancelled'`, never a physical delete (AGENTS.md Task 12) - a cancelled
	 * occurrence's row must survive for any booking history that already names it, unlike the
	 * catalog's `referenced` guard on services/resources, which refuses deletion outright instead.
	 * `WHERE status <> 'cancelled'` doubles as the affected-rows idempotency check the caller relies
	 * on (Task 11 fix round 1 idiom): cancelling an already-cancelled row reports zero rows changed,
	 * exactly like `ServiceRepository::delete()` reports `false` on a second call.
	 */
	public function cancel( int $id ): bool {
		$p      = $this->db->prefix;
		$result = $this->db->query(
			$this->db->prepare(
				"UPDATE {$p}reservant_occurrences SET status = 'cancelled' WHERE id = %d AND status <> 'cancelled'", // phpcs:ignore WordPress.DB.PreparedSQL
				$id
			)
		);
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Distinct bookings currently blocking this occurrence (AGENTS.md Task 12): the PUT/DELETE
	 * `referenced` guard's authority. `BLOCKING_SQL` already ORs `confirmed` into its own definition
	 * (AGENTS.md section 2.1), so this is the same predicate `blockingSeatSum()` uses, counted by
	 * booking rather than summed by seat - a grid booking can spread several items (one per claimed
	 * seat) across one booking row, and this must count that as one active booking, not several.
	 */
	public function activeBookingCount( int $occurrenceId ): int {
		$p     = $this->db->prefix;
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(DISTINCT i.booking_id)
				 FROM {$p}reservant_booking_items i
				 INNER JOIN {$p}reservant_bookings b ON b.id = i.booking_id
				 WHERE i.occurrence_id = %d
				 AND " . BookingRepository::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$occurrenceId
			)
		);
		return (int) $count;
	}
}
