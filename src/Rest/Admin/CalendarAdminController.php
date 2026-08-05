<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Rest\Errors;
use Reservant\Rest\Routes;

/**
 * GET /admin/calendar (AGENTS.md Task 10): the owner's whole schedule, or - for a staff member who
 * only holds `reservant_view_own_calendar` - their own resource's rows only, with the customer's
 * email/phone stripped down to a bare name. Occurrences (events) name no staff member, so they are
 * never scoped: everyone who can reach this endpoint sees the same occurrence list.
 */
final class CalendarAdminController {

	private const MAX_WINDOW_DAYS = 62;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/calendar */
	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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
		list( $from, $to ) = $window;
		$occurrences       = self::occurrences( $this->db, $from, $to );

		$manages = current_user_can( Routes::CAP_MANAGE );
		if ( $manages ) {
			$requested  = (int) $request->get_param( 'resource_id' );
			$resourceId = $requested > 0 ? $requested : null;
		} else {
			// A staff-only viewer's scope is their own resource, whatever `resource_id` was sent -
			// it is never taken from the request for them.
			$own = ( new ResourceRepository( $this->db ) )->findByWpUser( get_current_user_id() );
			if ( null === $own ) {
				return new \WP_REST_Response(
					array(
						'bookings'    => array(),
						'occurrences' => $occurrences,
					)
				);
			}
			$resourceId = (int) $own['id'];
		}

		$rows     = ( new BookingRepository( $this->db ) )->calendarRows( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), $resourceId );
		$bookings = self::group( $rows, $manages );

		return new \WP_REST_Response(
			array(
				'bookings'    => $bookings,
				'occurrences' => $occurrences,
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows one row per item - `BookingRepository::calendarRows()`
	 * @return list<array<string, mixed>>
	 */
	private static function group( array $rows, bool $includeContact ): array {
		$byUuid = array();
		foreach ( $rows as $row ) {
			$uuid = (string) $row['uuid'];
			if ( ! isset( $byUuid[ $uuid ] ) ) {
				$byUuid[ $uuid ] = array(
					'uuid'          => $uuid,
					'status'        => (string) $row['status'],
					'customer_name' => (string) $row['customer_name'],
					'items'         => array(),
				);
				// A staff-only viewer sees a name only - never the customer's email or phone
				// (AGENTS.md Task 10).
				if ( $includeContact ) {
					$byUuid[ $uuid ]['customer_email'] = (string) $row['customer_email'];
					$byUuid[ $uuid ]['customer_phone'] = (string) $row['customer_phone'];
				}
			}
			$byUuid[ $uuid ]['items'][] = array(
				'service_id'          => (int) $row['service_id'],
				'service_name'        => $row['service_name'],
				'resource_id'         => $row['resource_id'],
				'resource_name'       => $row['resource_name'],
				'start_utc'           => (string) $row['start_utc'],
				'end_utc'             => (string) $row['end_utc'],
				'block_start_utc'     => (string) $row['block_start_utc'],
				'block_end_utc'       => (string) $row['block_end_utc'],
				'processing_ends_utc' => $row['processing_ends_utc'],
			);
		}
		return array_values( $byUuid );
	}

	/** @return list<array<string, mixed>> */
	private static function occurrences( \wpdb $db, \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$occurrences = new OccurrenceRepository( $db );
		$rows        = $occurrences->findInRange( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) );
		$taken       = $occurrences->blockingSeatSums( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );

		return array_map(
			static fn ( array $row ): array => array(
				'id'           => (int) $row['id'],
				'service_id'   => (int) $row['service_id'],
				'service_name' => $row['service_name'],
				'start_utc'    => (string) $row['start_utc'],
				'end_utc'      => (string) $row['end_utc'],
				'capacity'     => (int) $row['capacity'],
				'remaining'    => max( 0, (int) $row['capacity'] - ( $taken[ (int) $row['id'] ] ?? 0 ) ),
			),
			$rows
		);
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
}
