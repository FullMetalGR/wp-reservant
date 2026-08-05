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
}
