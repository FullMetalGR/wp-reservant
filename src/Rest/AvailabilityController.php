<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\AvailabilityQuery;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * The public read surface: the service catalogue, advisory availability, and the seat grid of an
 * occurrence. Nothing here is authoritative - a start it offers may be gone 200 ms later, and only
 * `POST /holds` decides (AGENTS.md section 2.4 step 5, section 5).
 */
final class AvailabilityController {

	private const DEFAULT_GRANULARITY = 5;
	private const MAX_SEGMENTS        = 5;
	/** A window wide enough for any calendar UI, narrow enough that one request cannot be a DoS. */
	private const MAX_WINDOW_DAYS = 62;
	/** Never on the wire: the WooCommerce mirror is an implementation detail of the bridge (section 6). */
	private const INTERNAL_SERVICE_COLUMNS = array( 'wc_product_id' );

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /services/{id} */
	public function service( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$service = ( new ServiceRepository( $this->db ) )->find( (int) $request->get_param( 'id' ) );
		if ( null === $service || 'active' !== $service['status'] ) {
			return Errors::notFound();
		}
		foreach ( self::INTERNAL_SERVICE_COLUMNS as $column ) {
			unset( $service[ $column ] );
		}
		$service['requires_approval'] = (bool) $service['requires_approval'];
		return new \WP_REST_Response( $service );
	}

	/**
	 * GET /availability - the whole chain in, feasible starts out.
	 *
	 * Event services answer with their occurrences instead: an occurrence's start is authored by
	 * the owner, so "when could this run" is a fixed list, and what the customer needs to know is
	 * how many places are left.
	 */
	public function availability( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$items = self::decodeItems( $request->get_param( 'items' ) );
		if ( null === $items ) {
			return Errors::badRequest(
				sprintf(
					/* translators: %d: maximum number of chain segments. */
					__( '"items" must be a JSON list of at most %d objects, each with a numeric service_id.', 'reservant' ),
					self::MAX_SEGMENTS
				)
			);
		}

		$window = self::window( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) );
		if ( null === $window ) {
			return Errors::badRequest(
				sprintf(
					/* translators: %d: maximum window width in days. */
					__( '"to" must be after "from" and at most %d days later.', 'reservant' ),
					self::MAX_WINDOW_DAYS
				)
			);
		}

		$service = ( new ServiceRepository( $this->db ) )->find( $items[0]->serviceId );
		if ( null === $service || 'active' !== $service['status'] ) {
			return Errors::notFound();
		}

		if ( ServiceType::Event->value === $service['type'] ) {
			if ( 1 !== count( $items ) ) {
				return Errors::badRequest( __( 'Events are booked one at a time - send a single item.', 'reservant' ) );
			}
			return $this->occurrences( (int) $service['id'], $window[0], $window[1] );
		}

		try {
			$starts = AvailabilityQuery::make( $this->db )->appointmentStarts(
				$items,
				$window[0],
				$window[1],
				$this->now(),
				(bool) $request->get_param( 'same_staff' )
			);
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		}

		$display = self::displayZone( (string) $request->get_param( 'tz' ) );
		return new \WP_REST_Response(
			array(
				'granularity_min' => self::granularity(),
				'starts'          => array_map(
					static fn ( \DateTimeImmutable $start ): array => array(
						// `utc` is exactly the string POST /holds expects back, so a client can
						// round-trip a chosen slot without reformatting it.
						'utc'   => $start->format( 'Y-m-d H:i:s' ),
						'local' => $start->setTimezone( $display )->format( 'c' ),
					),
					$starts
				),
			)
		);
	}

	/** GET /occurrences/{id}/seats */
	public function seats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$occurrences  = new OccurrenceRepository( $this->db );
		$occurrenceId = (int) $request->get_param( 'id' );

		$occurrence = $occurrences->find( $occurrenceId );
		if ( null === $occurrence || 'active' !== $occurrence['status'] ) {
			return Errors::notFound();
		}
		$service = ( new ServiceRepository( $this->db ) )->find( (int) $occurrence['service_id'] );
		if ( null === $service || null === $service['seat_map_id'] ) {
			// Capacity-only: there is no grid to draw, and saying so is more honest than an
			// empty one (an event is a seat map or plain capacity, never both - section 10).
			return new \WP_Error(
				'reservant_no_seat_map',
				'no_seat_map',
				array(
					'status' => 404,
					'detail' => __( 'This event has open seating.', 'reservant' ),
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'occurrence_id' => $occurrenceId,
				'seat_map_id'   => (int) $service['seat_map_id'],
				'capacity'      => (int) $occurrence['capacity'],
				'seats'         => ( new SeatMapRepository( $this->db ) )->seatsForMap( (int) $service['seat_map_id'] ),
				'claimed'       => $occurrences->claimedSeatIds( $occurrenceId ),
			)
		);
	}

	/** Occurrences with the places left on each, computed from the blocking predicate (section 2.1). */
	private function occurrences( int $serviceId, \DateTimeImmutable $from, \DateTimeImmutable $to ): \WP_REST_Response {
		$repository = new OccurrenceRepository( $this->db );
		$rows       = $repository->findForService( $serviceId, $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) );
		$taken      = $repository->blockingSeatSums( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );

		return new \WP_REST_Response(
			array(
				'occurrences' => array_map(
					static fn ( array $row ): array => array(
						'id'        => (int) $row['id'],
						'start_utc' => (string) $row['start_utc'],
						'end_utc'   => (string) $row['end_utc'],
						'remaining' => max( 0, (int) $row['capacity'] - ( $taken[ (int) $row['id'] ] ?? 0 ) ),
					),
					$rows
				),
			)
		);
	}

	/**
	 * The chain, as an ordered list of segment choices.
	 *
	 * @param mixed $raw the `items` query parameter - a JSON string
	 * @return list<SegmentChoice>|null null when the shape is wrong
	 */
	private static function decodeItems( mixed $raw ): ?array {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$decoded = json_decode( wp_unslash( $raw ), true );
		if ( ! is_array( $decoded ) || array() === $decoded || count( $decoded ) > self::MAX_SEGMENTS ) {
			return null;
		}

		$items = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				return null;
			}
			$serviceId = Input::posInt( $entry['service_id'] ?? null );
			if ( null === $serviceId ) {
				return null;
			}
			// A present-but-unusable `resource_id` is a bad request, not "any staff": answering a
			// different question than the one asked would be worse than refusing.
			$pinned = $entry['resource_id'] ?? null;
			if ( null !== $pinned && null === Input::posInt( $pinned ) ) {
				return null;
			}
			$items[] = new SegmentChoice( $serviceId, null === $pinned ? null : Input::posInt( $pinned ) );
		}
		return $items;
	}

	/**
	 * `from`/`to` are `Y-m-d` business dates read as UTC midnights; `to` is exclusive, matching
	 * `SlotGenerator`.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
	 */
	private static function window( string $from, string $to ): ?array {
		$utc = new \DateTimeZone( 'UTC' );
		try {
			$start = new \DateTimeImmutable( $from . ' 00:00:00', $utc );
			$end   = new \DateTimeImmutable( $to . ' 00:00:00', $utc );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( $end <= $start || $end > $start->modify( '+' . self::MAX_WINDOW_DAYS . ' days' ) ) {
			return null;
		}
		return array( $start, $end );
	}

	/** An unknown or empty `tz` falls back to the business timezone rather than failing the read. */
	private static function displayZone( string $tz ): \DateTimeZone {
		if ( '' === $tz || ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( $tz );
	}

	private static function granularity(): int {
		return max( 1, (int) apply_filters( 'reservant/granularity_min', self::DEFAULT_GRANULARITY ) );
	}

	/** The request's single "now" (AGENTS.md section 7): materialized once, reused by every read. */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
