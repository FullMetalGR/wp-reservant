<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityException;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The hold endpoint is the only authority (AGENTS.md section 2.2 step 3, section 5): a request that never asked
 * the advisory availability endpoint must still be refused when it lands off-grid, outside working
 * hours, inside the lead window, past the horizon, on inactive rows, or on a seat that is not one.
 */
final class HoldBookingValidationTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services       = new ServiceRepository( $wpdb );
		$resources      = new ResourceRepository( $wpdb );
		$this->cutId    = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->staffA   = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB   = $resources->insert( array( 'name' => 'Bella' ) );
		$this->staff( $this->cutId );
	}

	/** Link both staff to a service and give them 09:00-17:00 every weekday. */
	private function staff( int $serviceId ): void {
		global $wpdb;
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			$resources->linkService( $serviceId, $staff );
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/** @return array<string, mixed> */
	private function hold( int $serviceId, \DateTimeImmutable $start, ?int $resourceId = null ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest( $start, array( new SegmentChoice( $serviceId, $resourceId ) ) ) ),
			$this->utc( 0 )
		);
	}

	/** @return array<string, mixed> */
	private function holdEvent( int $occurrenceId, int $seats = 1, array $seatIds = array() ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), null, new EventRequest( $occurrenceId, $seats, $seatIds ) ),
			$this->utc( 0 )
		);
	}

	/** Exception rows are keyed by the BUSINESS-local date, which is not always the UTC one. */
	private function businessDate( int $dayOffset, string $time ): string {
		return $this->utc( $dayOffset, $time )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	private function assertConflict( string $reason, callable $call, ?int $segmentIndex = null ): void {
		try {
			$call();
		} catch ( SlotConflict $e ) {
			self::assertSame( $reason, $e->reason );
			if ( null !== $segmentIndex ) {
				self::assertSame( $segmentIndex, $e->segmentIndex );
			}
			return;
		}
		self::fail( 'Expected SlotConflict ' . $reason . '.' );
	}

	public function test_start_must_sit_on_the_granularity_grid(): void {
		$this->assertConflict( 'bad_time', fn () => $this->hold( $this->cutId, $this->utc( 1, '09:07' ) ) );
		self::assertSame( 'pending', $this->hold( $this->cutId, $this->utc( 1, '09:05' ) )['status'] );
	}

	public function test_start_inside_the_lead_window_is_refused(): void {
		global $wpdb;
		// 48h notice: day 1 is 33h out, day 2 is 57h out.
		$service = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Notice', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'lead_time_min' => 2880 )
		);
		$this->staff( $service );

		$this->assertConflict( 'lead_time', fn () => $this->hold( $service, $this->utc( 1, '09:00' ) ) );
		self::assertSame( 'pending', $this->hold( $service, $this->utc( 2, '09:00' ) )['status'] );
	}

	public function test_start_past_the_horizon_is_refused(): void {
		global $wpdb;
		// Bookable three days out: day 3 09:00 is nine hours past the horizon.
		$service = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Near', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'horizon_days' => 3 )
		);
		$this->staff( $service );

		$this->assertConflict( 'horizon', fn () => $this->hold( $service, $this->utc( 3, '09:00' ) ) );
		self::assertSame( 'pending', $this->hold( $service, $this->utc( 2, '09:00' ) )['status'] );
	}

	public function test_inactive_service_is_not_bookable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'reservant_services';
		$wpdb->update( $table, array( 'status' => 'archived' ), array( 'id' => $this->cutId ) );
		$this->assertConflict( 'not_found', fn () => $this->hold( $this->cutId, $this->utc( 1, '09:00' ) ), 0 );

		$wpdb->update( $table, array( 'status' => 'active' ), array( 'id' => $this->cutId ) );
		self::assertSame( 'pending', $this->hold( $this->cutId, $this->utc( 1, '09:00' ) )['status'] );
	}

	public function test_inactive_staff_cannot_be_pinned_or_picked(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'reservant_resources', array( 'status' => 'inactive' ), array( 'id' => $this->staffA ) );

		$this->assertConflict( 'no_staff', fn () => $this->hold( $this->cutId, $this->utc( 1, '09:00' ), $this->staffA ), 0 );
		// "Any staff" skips the inactive row rather than assigning it.
		self::assertSame( $this->staffB, $this->hold( $this->cutId, $this->utc( 1, '09:00' ) )['items'][0]['resource_id'] );
	}

	public function test_span_outside_working_hours_is_refused(): void {
		$this->assertConflict( 'outside_hours', fn () => $this->hold( $this->cutId, $this->utc( 1, '03:00' ) ), 0 );
		self::assertSame( 'pending', $this->hold( $this->cutId, $this->utc( 1, '09:00' ) )['status'] );
	}

	public function test_span_running_past_closing_time_is_refused(): void {
		// 16:45 + 30 min runs to 17:15; the rules close at 17:00.
		$this->assertConflict( 'outside_hours', fn () => $this->hold( $this->cutId, $this->utc( 1, '16:45' ) ), 0 );
		self::assertSame( 'pending', $this->hold( $this->cutId, $this->utc( 1, '16:30' ) )['status'] );
	}

	public function test_business_wide_closed_exception_closes_the_day(): void {
		global $wpdb;
		( new AvailabilityRepository( $wpdb ) )->insertException(
			null,
			new AvailabilityException( $this->businessDate( 1, '09:00' ), true )
		);

		$this->assertConflict( 'outside_hours', fn () => $this->hold( $this->cutId, $this->utc( 1, '09:00' ) ), 0 );
		self::assertSame( 'pending', $this->hold( $this->cutId, $this->utc( 2, '09:00' ) )['status'] );
	}

	public function test_any_staff_falls_through_to_the_member_whose_hours_cover_the_span(): void {
		global $wpdb;
		// Alex is closed that day; Bella is not. Availability would have offered Bella only.
		( new AvailabilityRepository( $wpdb ) )->insertException(
			$this->staffA,
			new AvailabilityException( $this->businessDate( 1, '09:00' ), true )
		);

		$booking = $this->hold( $this->cutId, $this->utc( 1, '09:00' ) );
		self::assertSame( $this->staffB, $booking['items'][0]['resource_id'] );
	}

	public function test_hours_are_tested_across_the_utc_midnight_a_local_day_spans(): void {
		// Filtered, not stored: a hold COMMITs the connection, so an option written here would
		// outlive the harness's per-test rollback and follow the timezone into the next class.
		$sydney = static fn (): string => 'Australia/Sydney';
		add_filter( 'pre_option_timezone_string', $sydney );
		try {
			// Sydney runs UTC+10/+11, so a morning appointment there sits on the previous UTC day:
			// 23:45 UTC + 30 min straddles UTC midnight whatever the offset and whatever DST does.
			$start = $this->utc( 1, '23:45' );
			$local = $start->setTimezone( wp_timezone() );
			$open  = $local->modify( '-2 hours' )->format( 'H:i' );

			$covered = $this->localHoursService( $local, $open, $local->modify( '+2 hours' )->format( 'H:i' ) );
			$booking = $this->hold( $covered, $start );
			self::assertSame( 'pending', $booking['status'] );
			self::assertNotSame(
				substr( (string) $booking['items'][0]['start_utc'], 0, 10 ),
				substr( (string) $booking['items'][0]['end_utc'], 0, 10 ),
				'The fixture must straddle UTC midnight or it proves nothing.'
			);

			// Closing 15 minutes into the span - exactly at UTC midnight - leaves the far side of
			// the boundary uncovered. Concatenating both days must not paper over that gap.
			$gap = $this->localHoursService( $local, $open, $local->modify( '+15 minutes' )->format( 'H:i' ) );
			$this->assertConflict( 'outside_hours', fn () => $this->hold( $gap, $start ), 0 );
		} finally {
			remove_filter( 'pre_option_timezone_string', $sydney );
		}
	}

	public function test_event_registration_window_is_enforced(): void {
		// Day -8 is yesterday (day 0 is a week out): a seminar nobody can attend any more.
		$this->assertConflict( 'lead_time', fn () => $this->holdEvent( $this->capacityEvent( -8 ) ) );
		// 48h notice, and day 1 is 24h after the injected now.
		$this->assertConflict( 'lead_time', fn () => $this->holdEvent( $this->capacityEvent( 1, array( 'lead_time_min' => 2880 ) ) ) );
		$this->assertConflict( 'horizon', fn () => $this->holdEvent( $this->capacityEvent( 4, array( 'horizon_days' => 3 ) ) ) );

		self::assertSame( 'pending', $this->holdEvent( $this->capacityEvent( 3 ) )['status'] );
	}

	public function test_a_claimed_grid_seat_cannot_be_claimed_again(): void {
		$mapId = $this->seatMap();
		$seats = $this->seatIds( $mapId );
		$event = $this->gridEvent( $mapId );

		self::assertSame( 'pending', $this->holdEvent( $event, 1, array( $seats['seats'][0] ) )['status'] );
		$this->assertConflict( 'seat_taken', fn () => $this->holdEvent( $event, 1, array( $seats['seats'][0] ) ) );
		// Its neighbour is untouched.
		self::assertSame( 'pending', $this->holdEvent( $event, 1, array( $seats['seats'][1] ) )['status'] );
	}

	public function test_grid_seats_must_be_real_seats_of_the_service_map(): void {
		$mapId    = $this->seatMap();
		$otherMap = $this->seatMap();
		$seats    = $this->seatIds( $mapId );
		$event    = $this->gridEvent( $mapId );

		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $event, 1, array( 999999 ) ) );
		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $event, 1, array( $seats['aisle'] ) ) );
		// A real seat, but on somebody else's map.
		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $event, 1, array( $this->seatIds( $otherMap )['seats'][0] ) ) );
		// A grid booking names every seat it takes - a seat map and plain capacity never mix.
		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $event, 1 ) );
		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $event, 2 ) );

		$booking = $this->holdEvent( $event, 1, array( $seats['seats'][0] ) );
		self::assertSame( 'pending', $booking['status'] );
		self::assertSame( $seats['seats'][0], $booking['items'][0]['seat_claim'] );
	}

	public function test_seat_ids_on_a_capacity_only_service_are_refused(): void {
		$occId = $this->capacityEvent( 3 );

		$this->assertConflict( 'bad_seat', fn () => $this->holdEvent( $occId, 1, array( 1 ) ) );
		self::assertSame( 'pending', $this->holdEvent( $occId, 2 )['status'] );
	}

	/**
	 * A service whose one staff member works a single window, given in business-local time on the
	 * local date of $local - no other rules, so the window is the whole story.
	 *
	 * @return int service_id
	 */
	private function localHoursService( \DateTimeImmutable $local, string $from, string $to ): int {
		global $wpdb;
		$resources = new ResourceRepository( $wpdb );
		$service   = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Sydney cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' )
		);
		$staff     = $resources->insert( array( 'name' => 'Nikos ' . $from . '-' . $to ) );
		$resources->linkService( $service, $staff );
		( new AvailabilityRepository( $wpdb ) )->insertRule( $staff, new AvailabilityRule( (int) $local->format( 'N' ), $from, $to ) );
		return $service;
	}

	/**
	 * A capacity-only occurrence on fixture day $dayOffset, 18:00-20:00.
	 *
	 * @param array<string, mixed> $serviceExtra
	 * @return int occurrence_id
	 */
	private function capacityEvent( int $dayOffset, array $serviceExtra = array() ): int {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert(
			$serviceExtra + array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' )
		);
		return ( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( $dayOffset, '18:00' ), 'end_utc' => $this->sql( $dayOffset, '20:00' ), 'capacity' => 10 )
		);
	}

	/** Rows A-B, two seats each, an aisle after A1. @return int seat_map_id */
	private function seatMap(): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'reservant_seat_maps', array( 'name' => 'Hall', 'spec' => 'rows A-B, 2 per row, aisle after 1' ) );
		$mapId = (int) $wpdb->insert_id;
		$cells = array(
			array( 'A', 'A1', 0, 0, 'seat' ),
			array( 'A', '', 0, 1, 'aisle' ),
			array( 'A', 'A2', 0, 2, 'seat' ),
			array( 'B', 'B1', 1, 0, 'seat' ),
			array( 'B', 'B2', 1, 1, 'seat' ),
		);
		foreach ( $cells as $cell ) {
			$wpdb->insert(
				$wpdb->prefix . 'reservant_seats',
				array( 'seat_map_id' => $mapId, 'row_label' => $cell[0], 'seat_label' => $cell[1], 'sort_row' => $cell[2], 'sort_col' => $cell[3], 'kind' => $cell[4] )
			);
		}
		return $mapId;
	}

	/** @return array{seats: list<int>, aisle: int} */
	private function seatIds( int $mapId ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, kind FROM {$wpdb->prefix}reservant_seats WHERE seat_map_id = %d ORDER BY id ASC", $mapId ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$out = array( 'seats' => array(), 'aisle' => 0 );
		foreach ( $rows as $row ) {
			if ( 'seat' === $row['kind'] ) {
				$out['seats'][] = (int) $row['id'];
			} else {
				$out['aisle'] = (int) $row['id'];
			}
		}
		return $out;
	}

	/** An occurrence of a grid event service. @return int occurrence_id */
	private function gridEvent( int $mapId ): int {
		global $wpdb;
		$services    = new ServiceRepository( $wpdb );
		$occurrences = new OccurrenceRepository( $wpdb );
		$eventId     = $services->insert(
			array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite', 'seat_map_id' => $mapId, 'capacity' => 4 )
		);
		return $occurrences->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 3, '18:00' ), 'end_utc' => $this->sql( 3, '20:00' ), 'capacity' => 4 )
		);
	}
}
