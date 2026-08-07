<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Application\AvailabilityQuery;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;

/**
 * GET /admin/availability (AGENTS.md Task 10): the same request shape as the public endpoint, but
 * always in admin mode - `AvailabilityQuery::appointmentStarts()` is called with `$ignoreWindow =
 * true`, exactly the flag `HoldRequest::$admin` sets for `HoldBooking`. Every start this endpoint
 * offers is therefore a start `POST /admin/bookings` accepts, and every start it withholds
 * (overlap, capacity, outside hours, seats) is refused there too - the differential property the
 * manual booking drawer depends on.
 */
final class AvailabilityAdminController {

	private const MAX_SEGMENTS    = 5;
	private const MAX_WINDOW_DAYS = 62;

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/availability */
	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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
			// Occurrences are never lead-time/horizon clamped in the first place (the public
			// endpoint applies no such filter to them either), so there is no admin relaxation to
			// apply here - the listing is identical.
			return $this->occurrences( (int) $service['id'], $window[0], $window[1] );
		}

		try {
			$starts = AvailabilityQuery::make( $this->db )->appointmentStarts(
				$items,
				$window[0],
				$window[1],
				$this->now(),
				(bool) $request->get_param( 'same_staff' ),
				true // ignoreWindow: the admin relaxation this endpoint exists to offer.
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
						'utc'   => $start->format( 'Y-m-d H:i:s' ),
						'local' => $start->setTimezone( $display )->format( 'c' ),
					),
					$starts
				),
			)
		);
	}

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
			$pinned = $entry['resource_id'] ?? null;
			if ( null !== $pinned && null === Input::posInt( $pinned ) ) {
				return null;
			}
			$items[] = new SegmentChoice( $serviceId, null === $pinned ? null : Input::posInt( $pinned ) );
		}
		return $items;
	}

	/**
	 * `from`/`to` are `Y-m-d` business dates read as UTC midnights; `to` is exclusive.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
	 */
	private static function window( string $from, string $to ): ?array {
		$utc = new \DateTimeZone( 'UTC' );
		try {
			$start = new \DateTimeImmutable( $from . ' 00:00:00', $utc );
			$end   = new \DateTimeImmutable( $to . ' 00:00:00', $utc );
		} catch ( \Exception $exception ) {
			return null;
		}
		if ( $end <= $start || $end > $start->modify( '+' . self::MAX_WINDOW_DAYS . ' days' ) ) {
			return null;
		}
		return array( $start, $end );
	}

	private static function displayZone( string $tz ): \DateTimeZone {
		if ( '' === $tz || ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( $tz );
	}

	private static function granularity(): int {
		return max( 1, (int) apply_filters( 'reservant/granularity_min', 5 ) );
	}

	/** The request's single "now" (AGENTS.md section 7): materialized once, reused by every read. */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
