<?php
declare( strict_types=1 );

namespace Reservant\Cli;

use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * `wp reservant fixture` - the demo salon, seeded in one call.
 *
 * The concurrency and smoke scripts pipe this straight into `jq`, so it prints the id map as JSON
 * and nothing else, and it is idempotent: the ids are remembered in an option and re-emitted as
 * long as every row they name still exists. Running it twice must not double the salon.
 */
final class FixtureCommand {

	private const OPTION = 'reservant_fixture_ids';
	/** Comfortably inside the default 60-day horizon, comfortably outside any lead time. */
	private const EVENT_DAYS_AHEAD = 30;
	private const GRID_SPEC        = 'rows A-B, 4 per row';

	/**
	 * Seed (or re-emit) the demo dataset.
	 *
	 * ## EXAMPLES
	 *
	 *     wp reservant fixture
	 *
	 * @param list<string>          $args      Positional arguments (unused).
	 * @param array<string, string> $assocArgs Flags (unused).
	 */
	public function fixture( array $args = array(), array $assocArgs = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI hands every command both, whether it wants them or not.
		global $wpdb;
		\WP_CLI::line( (string) wp_json_encode( self::ensure( $wpdb ) ) );
	}

	/**
	 * @return array<string, mixed> {cut, colour, staff_a, staff_b, seminar_occ, grid_occ, grid_seats}
	 */
	public static function ensure( \wpdb $db ): array {
		$stored = self::stored( $db );
		if ( null !== $stored ) {
			return $stored;
		}
		$ids = self::build( $db );
		update_option( self::OPTION, $ids, false );
		return $ids;
	}

	/**
	 * The remembered id map, or null when the fixture was never seeded or its rows are gone.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function stored( \wpdb $db ): ?array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && self::intact( $db, $stored ) ? $stored : null;
	}

	/**
	 * Are the remembered rows still there? A truncated or half-cleaned database must rebuild
	 * rather than hand out ids that resolve to nothing.
	 *
	 * @param array<string, mixed> $ids
	 */
	private static function intact( \wpdb $db, array $ids ): bool {
		foreach ( array( 'cut', 'colour', 'staff_a', 'staff_b', 'seminar_occ', 'grid_occ', 'grid_seats' ) as $key ) {
			if ( ! isset( $ids[ $key ] ) ) {
				return false;
			}
		}
		$services    = new ServiceRepository( $db );
		$resources   = new ResourceRepository( $db );
		$occurrences = new OccurrenceRepository( $db );

		return null !== $services->find( (int) $ids['cut'] )
			&& null !== $services->find( (int) $ids['colour'] )
			&& null !== $resources->find( (int) $ids['staff_a'] )
			&& null !== $resources->find( (int) $ids['staff_b'] )
			&& null !== $occurrences->find( (int) $ids['seminar_occ'] )
			&& null !== $occurrences->find( (int) $ids['grid_occ'] );
	}

	/** @return array<string, mixed> */
	private static function build( \wpdb $db ): array {
		$services     = new ServiceRepository( $db );
		$resources    = new ResourceRepository( $db );
		$availability = new AvailabilityRepository( $db );
		$occurrences  = new OccurrenceRepository( $db );
		$seatMaps     = new SeatMapRepository( $db );

		$cut    = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2000,
				'currency'     => 'EUR',
				'payment_mode' => 'onsite',
			)
		);
		$colour = $services->insert(
			array(
				'name'                => 'Colour',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'processing_time_min' => 30,
				'price_minor'         => 5000,
				'currency'            => 'EUR',
				'payment_mode'        => 'onsite',
			)
		);

		$staffA = $resources->insert(
			array(
				'name'  => 'Alex',
				'email' => 'alex@example.com',
			)
		);
		$staffB = $resources->insert(
			array(
				'name'  => 'Bella',
				'email' => 'bella@example.com',
			)
		);

		foreach ( array( $cut, $colour ) as $serviceId ) {
			foreach ( array( $staffA, $staffB ) as $resourceId ) {
				$resources->linkService( $serviceId, $resourceId );
			}
		}
		foreach ( array( $staffA, $staffB ) as $resourceId ) {
			foreach ( range( 1, 7 ) as $weekday ) {
				$availability->insertRule( $resourceId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}

		$seminar    = $services->insert(
			array(
				'name'         => 'Seminar',
				'type'         => 'event',
				'capacity'     => 3,
				'price_minor'  => 1000,
				'currency'     => 'EUR',
				'payment_mode' => 'onsite',
			)
		);
		$seminarOcc = $occurrences->insert(
			array(
				'service_id' => $seminar,
				'start_utc'  => self::eventStart()->format( 'Y-m-d H:i:s' ),
				'end_utc'    => self::eventStart()->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ),
				'capacity'   => 3,
			)
		);

		$spec  = SeatMapSpec::parse( self::GRID_SPEC );
		$mapId = $seatMaps->insert( 'Grid hall', self::GRID_SPEC );
		$cells = $spec->seats();
		// Only claimable cells go on the wire: aisles are geometry, and a script that fed one to
		// POST /holds would get `bad_seat` for its trouble.
		$seatIds = array();
		foreach ( $seatMaps->insertSeats( $mapId, $cells ) as $index => $seatId ) {
			if ( 'seat' === $cells[ $index ]->kind ) {
				$seatIds[] = $seatId;
			}
		}
		$gridShow = $services->insert(
			array(
				'name'         => 'GridShow',
				'type'         => 'event',
				'capacity'     => $spec->capacity(),
				'seat_map_id'  => $mapId,
				'price_minor'  => 1000,
				'currency'     => 'EUR',
				'payment_mode' => 'onsite',
			)
		);
		$gridOcc  = $occurrences->insert(
			array(
				'service_id' => $gridShow,
				'start_utc'  => self::eventStart()->format( 'Y-m-d H:i:s' ),
				'end_utc'    => self::eventStart()->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ),
				'capacity'   => $spec->capacity(),
			)
		);

		return array(
			'cut'         => $cut,
			'colour'      => $colour,
			'staff_a'     => $staffA,
			'staff_b'     => $staffB,
			'seminar'     => $seminar,
			'seminar_occ' => $seminarOcc,
			'grid_show'   => $gridShow,
			'grid_occ'    => $gridOcc,
			'grid_seats'  => $seatIds,
		);
	}

	private static function eventStart(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
			->modify( '+' . self::EVENT_DAYS_AHEAD . ' days' )
			->setTime( 18, 0 );
	}
}
