<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\HoldBooking;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `reservant/v1/admin/occurrences` and `admin/seat-maps` (Task 12, AGENTS.md section 4): occurrence
 * CRUD with live booked counts, the `referenced`/`capacity` PUT/DELETE guard rails on occurrences, and
 * the seat map builder (`SeatMapSpec::parse`) with its own claim-based guard rail.
 *
 * Occurrence "delete" is a soft cancel (`status = 'cancelled'`), never a physical row delete - a
 * cancelled occurrence still names a real row for any booking history that already points at it.
 * Seat map delete IS physical, cascading to its seats, because an unclaimed map has no history to
 * protect (AGENTS.md Task 12 brief).
 */
final class AdminEventsTest extends ReservantTestCase {

	private const GRID_SPEC = 'rows A-B, 4 per row';

	public function set_up(): void {
		parent::set_up();
		Capabilities::sync();
	}

	// ---------------------------------------------------------------- auth helpers

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function asBookingManager(): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		$user->add_cap( 'reservant_manage_bookings' );
		wp_set_current_user( $id );
		return $id;
	}

	private function asAnonymous(): void {
		wp_set_current_user( 0 );
	}

	// ---------------------------------------------------------------- request helpers

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** @param array<string, mixed> $body */
	private function jsonRequest( string $method, string $route, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	// ---------------------------------------------------------------- fixture helpers

	/** @return array<string, mixed> */
	private function createEventService( string $name, int $capacity ): array {
		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/services',
			array(
				'name'         => $name,
				'type'         => 'event',
				'capacity'     => $capacity,
				'payment_mode' => 'onsite',
			)
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return array<string, mixed> */
	private function createSeatMappedService( string $name, int $seatMapId ): array {
		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/services',
			array(
				'name'         => $name,
				'type'         => 'event',
				'seat_map_id'  => $seatMapId,
				'payment_mode' => 'onsite',
			)
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return array<string, mixed> */
	private function createAppointmentService( string $name = 'Cut' ): array {
		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/services',
			array( 'name' => $name, 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' )
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return array<string, mixed> */
	private function createOccurrence( int $serviceId, ?int $capacity = null, int $dayOffset = 1 ): array {
		$body = array(
			'service_id' => $serviceId,
			'start_utc'  => $this->sql( $dayOffset, '18:00' ),
			'end_utc'    => $this->sql( $dayOffset, '20:00' ),
		);
		if ( null !== $capacity ) {
			$body['capacity'] = $capacity;
		}
		$response = $this->jsonRequest( 'POST', '/reservant/v1/admin/occurrences', $body );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return array<string, mixed> */
	private function createSeatMap( string $spec = self::GRID_SPEC, string $name = 'Grid hall' ): array {
		$response = $this->jsonRequest( 'POST', '/reservant/v1/admin/seat-maps', array( 'name' => $name, 'spec' => $spec ) );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/**
	 * Books straight through the application layer in admin mode (lands `confirmed`, no hold) - the
	 * same technique `AdminCatalogTest::manualBookingFixture()` uses for appointments, here for events.
	 *
	 * @param list<int> $seatIds
	 */
	private function bookEventAdmin( int $occurrenceId, int $seats, array $seatIds = array() ): void {
		global $wpdb;
		HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Walk-in', 'walkin@example.com' ),
				null,
				new EventRequest( $occurrenceId, $seats, $seatIds ),
				true
			),
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);
	}

	// ---------------------------------------------------------------- permission matrix

	public function test_occurrences_and_seat_maps_are_gated_on_manage_settings(): void {
		$this->asAdmin();
		$service = $this->createEventService( 'Seminar', 5 );
		$map     = $this->createSeatMap();

		$routes = array(
			array( 'GET', '/reservant/v1/admin/occurrences', array( 'service_id' => $service['id'] ) ),
			array( 'GET', '/reservant/v1/admin/seat-maps', array() ),
			array( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}", array() ),
		);
		foreach ( $routes as list( $method, $route, $params ) ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->request( $method, $route, $params )->get_status(), $route );
			$this->asBookingManager();
			self::assertSame( 403, $this->request( $method, $route, $params )->get_status(), "manage_bookings alone must not reach {$route}" );
			$this->asAdmin();
			self::assertSame( 200, $this->request( $method, $route, $params )->get_status(), $route );
		}

		$this->asBookingManager();
		self::assertSame( 403, $this->jsonRequest( 'POST', '/reservant/v1/admin/occurrences', array() )->get_status() );
		self::assertSame( 403, $this->jsonRequest( 'POST', '/reservant/v1/admin/seat-maps', array() )->get_status() );
	}

	// ---------------------------------------------------------------- occurrence CRUD + booked counts

	public function test_occurrence_create_list_update_and_cancel_round_trip(): void {
		$this->asAdmin();
		$service = $this->createEventService( 'Seminar', 5 );

		$created = $this->createOccurrence( (int) $service['id'], 5 );
		self::assertSame( (int) $service['id'], $created['service_id'] );
		self::assertSame( 5, $created['capacity'] );
		self::assertSame( 0, $created['booked'] );
		self::assertSame( 'active', $created['status'] );

		$list = $this->request( 'GET', '/reservant/v1/admin/occurrences', array( 'service_id' => $service['id'] ) );
		self::assertSame( 200, $list->get_status() );
		$ids = array_column( $list->get_data()['occurrences'], 'id' );
		self::assertContains( $created['id'], $ids );

		$updated = $this->jsonRequest( 'PUT', "/reservant/v1/admin/occurrences/{$created['id']}", array( 'capacity' => 10 ) );
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertSame( 10, $updated->get_data()['capacity'] );

		$cancelled = $this->request( 'DELETE', "/reservant/v1/admin/occurrences/{$created['id']}" );
		self::assertSame( 204, $cancelled->get_status() );

		$after = $this->request( 'GET', '/reservant/v1/admin/occurrences', array( 'service_id' => $service['id'] ) );
		$row   = self::findById( $after->get_data()['occurrences'], (int) $created['id'] );
		self::assertSame( 'cancelled', $row['status'] );
	}

	public function test_occurrence_list_reports_live_booked_counts_from_confirmed_items(): void {
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );

		$this->bookEventAdmin( (int) $occurrence['id'], 2 );

		$list = $this->request( 'GET', '/reservant/v1/admin/occurrences', array( 'service_id' => $service['id'] ) );
		$row  = self::findById( $list->get_data()['occurrences'], (int) $occurrence['id'] );
		self::assertSame( 2, $row['booked'] );
	}

	public function test_create_occurrence_requires_an_event_service(): void {
		$this->asAdmin();
		$appointment = $this->createAppointmentService();

		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/occurrences',
			array(
				'service_id' => $appointment['id'],
				'start_utc'  => $this->sql( 1, '18:00' ),
				'end_utc'    => $this->sql( 1, '20:00' ),
				'capacity'   => 10,
			)
		);
		self::assertSame( 400, $response->get_status() );
	}

	public function test_create_occurrence_for_seat_mapped_service_derives_capacity_from_the_map(): void {
		$this->asAdmin();
		$map     = $this->createSeatMap();
		$service = $this->createSeatMappedService( 'GridShow', (int) $map['id'] );

		// 2 rows x 4 seats, no aisles - the client's own (wrong) capacity guess is ignored.
		$created = $this->createOccurrence( (int) $service['id'], 999 );
		self::assertSame( 8, $created['capacity'] );
	}

	// ---------------------------------------------------------------- occurrence guard rails

	public function test_cancel_occurrence_with_an_active_booking_is_refused_and_leaves_it_unchanged(): void {
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );
		$this->bookEventAdmin( (int) $occurrence['id'], 1 );

		$response = $this->request( 'DELETE', "/reservant/v1/admin/occurrences/{$occurrence['id']}" );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'referenced', $response->get_data()['message'] );

		global $wpdb;
		$survives = ( new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb ) )->find( (int) $occurrence['id'] );
		self::assertNotNull( $survives );
		self::assertSame( 'active', $survives['status'] );
		self::assertSame( 5, $survives['capacity'] );
	}

	public function test_put_reschedule_with_an_active_booking_is_refused_referenced(): void {
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );
		$this->bookEventAdmin( (int) $occurrence['id'], 1 );

		$response = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/occurrences/{$occurrence['id']}",
			array( 'start_utc' => $this->sql( 2, '18:00' ), 'end_utc' => $this->sql( 2, '20:00' ) )
		);
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'referenced', $response->get_data()['message'] );

		global $wpdb;
		$survives = ( new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb ) )->find( (int) $occurrence['id'] );
		self::assertNotNull( $survives );
		self::assertSame( $occurrence['start_utc'], $survives['start_utc'] );
	}

	public function test_put_capacity_increase_is_allowed_despite_an_active_booking(): void {
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );
		$this->bookEventAdmin( (int) $occurrence['id'], 2 );

		// A capacity-only change never touches the schedule, so the active booking above does not
		// block it - only a reschedule (start_utc/end_utc) does. See OccurrencesAdminController's
		// class docblock for why the two guards are independent.
		$response = $this->jsonRequest( 'PUT', "/reservant/v1/admin/occurrences/{$occurrence['id']}", array( 'capacity' => 20 ) );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 20, $response->get_data()['capacity'] );
	}

	public function test_put_capacity_below_booked_is_refused_with_capacity_reason(): void {
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );
		$this->bookEventAdmin( (int) $occurrence['id'], 3 );

		$response = $this->jsonRequest( 'PUT', "/reservant/v1/admin/occurrences/{$occurrence['id']}", array( 'capacity' => 2 ) );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'capacity', $response->get_data()['message'] );

		global $wpdb;
		$survives = ( new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb ) )->find( (int) $occurrence['id'] );
		self::assertSame( 5, $survives['capacity'] );
	}

	public function test_put_capacity_on_a_seat_mapped_occurrence_is_rejected(): void {
		$this->asAdmin();
		$map        = $this->createSeatMap();
		$service    = $this->createSeatMappedService( 'GridShow', (int) $map['id'] );
		$occurrence = $this->createOccurrence( (int) $service['id'] );

		$response = $this->jsonRequest( 'PUT', "/reservant/v1/admin/occurrences/{$occurrence['id']}", array( 'capacity' => 12 ) );
		self::assertSame( 400, $response->get_status() );
	}

	public function test_occurrence_routes_404_for_an_unknown_id(): void {
		$this->asAdmin();
		self::assertSame( 404, $this->request( 'DELETE', '/reservant/v1/admin/occurrences/999999' )->get_status() );
		self::assertSame( 404, $this->jsonRequest( 'PUT', '/reservant/v1/admin/occurrences/999999', array( 'capacity' => 5 ) )->get_status() );
	}

	// ---------------------------------------------------------------- seat maps

	public function test_seat_map_create_parses_spec_and_returns_seats(): void {
		$this->asAdmin();
		$created = $this->createSeatMap( self::GRID_SPEC, 'Grid hall' );

		self::assertSame( 'Grid hall', $created['name'] );
		self::assertSame( self::GRID_SPEC, $created['spec'] );
		$seatKinds = array_column( $created['seats'], 'kind' );
		self::assertCount( 8, array_filter( $seatKinds, static fn ( string $kind ): bool => 'seat' === $kind ) );
	}

	public function test_seat_map_create_with_a_bad_spec_surfaces_the_parser_message(): void {
		$this->asAdmin();
		$response = $this->jsonRequest( 'POST', '/reservant/v1/admin/seat-maps', array( 'name' => 'Broken', 'spec' => 'nonsense' ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'Expected "rows X-Y".', $response->get_data()['data']['detail'] );
	}

	public function test_seat_map_put_replaces_seats_when_unclaimed(): void {
		$this->asAdmin();
		$map = $this->createSeatMap();

		$updated = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/seat-maps/{$map['id']}",
			array( 'name' => 'Grid hall v2', 'spec' => 'rows A-C, 2 per row' )
		);
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertSame( 'Grid hall v2', $updated->get_data()['name'] );
		$seatKinds = array_column( $updated->get_data()['seats'], 'kind' );
		self::assertCount( 6, array_filter( $seatKinds, static fn ( string $kind ): bool => 'seat' === $kind ) );
	}

	public function test_seat_map_put_after_a_seat_claim_is_refused_referenced_and_leaves_it_unchanged(): void {
		$this->asAdmin();
		$map        = $this->createSeatMap();
		$service    = $this->createSeatMappedService( 'GridShow', (int) $map['id'] );
		$occurrence = $this->createOccurrence( (int) $service['id'] );
		$seatId     = (int) $map['seats'][0]['id'];
		$this->bookEventAdmin( (int) $occurrence['id'], 1, array( $seatId ) );

		$response = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/seat-maps/{$map['id']}",
			array( 'name' => 'Grid hall v2', 'spec' => 'rows A-C, 2 per row' )
		);
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'referenced', $response->get_data()['message'] );

		$fetched = $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertSame( self::GRID_SPEC, $fetched->get_data()['spec'] );
	}

	public function test_seat_map_delete_unreferenced_removes_it_and_its_seats(): void {
		$this->asAdmin();
		$map = $this->createSeatMap();

		$response = $this->request( 'DELETE', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertSame( 204, $response->get_status() );
		self::assertSame( 404, $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" )->get_status() );

		global $wpdb;
		self::assertSame( array(), ( new \Reservant\Infrastructure\Db\SeatMapRepository( $wpdb ) )->seatsForMap( (int) $map['id'] ) );
	}

	/**
	 * Review round 1 fix: `hasClaims()` alone does not catch a map that no seat has ever been
	 * claimed on but that a live service still points at via `seat_map_id` - deleting it would
	 * leave that column dangling. No booking exists here at all, only the service link.
	 */
	public function test_seat_map_delete_linked_to_a_service_without_any_claims_is_refused_referenced(): void {
		$this->asAdmin();
		$map     = $this->createSeatMap();
		$this->createSeatMappedService( 'GridShow', (int) $map['id'] );

		$response = $this->request( 'DELETE', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'referenced', $response->get_data()['message'] );

		self::assertSame( 200, $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" )->get_status() );
		global $wpdb;
		self::assertNotSame(
			array(),
			( new \Reservant\Infrastructure\Db\SeatMapRepository( $wpdb ) )->seatsForMap( (int) $map['id'] ),
			'Seats must survive a refused delete.'
		);
	}

	/**
	 * PUT's guard is deliberately narrower than DELETE's (see the class docblock): re-parsing a map
	 * that a service still links but nobody has claimed a seat on is safe - the service goes on
	 * pointing at a real, still-existing map, just with new geometry - so `usesSeatMap()` must not
	 * block it the way it blocks DELETE.
	 */
	public function test_seat_map_put_is_allowed_while_linked_to_a_service_without_claims(): void {
		$this->asAdmin();
		$map = $this->createSeatMap();
		$this->createSeatMappedService( 'GridShow', (int) $map['id'] );

		$response = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/seat-maps/{$map['id']}",
			array( 'spec' => 'rows A-C, 2 per row' )
		);
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_seat_map_delete_after_a_seat_claim_is_refused_referenced(): void {
		$this->asAdmin();
		$map        = $this->createSeatMap();
		$service    = $this->createSeatMappedService( 'GridShow', (int) $map['id'] );
		$occurrence = $this->createOccurrence( (int) $service['id'] );
		$seatId     = (int) $map['seats'][0]['id'];
		$this->bookEventAdmin( (int) $occurrence['id'], 1, array( $seatId ) );

		$response = $this->request( 'DELETE', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'referenced', $response->get_data()['message'] );

		self::assertSame( 200, $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" )->get_status() );
	}

	public function test_seat_map_routes_404_for_an_unknown_id(): void {
		$this->asAdmin();
		self::assertSame( 404, $this->request( 'GET', '/reservant/v1/admin/seat-maps/999999' )->get_status() );
		self::assertSame( 404, $this->request( 'DELETE', '/reservant/v1/admin/seat-maps/999999' )->get_status() );
	}

	// ---------------------------------------------------------------- failed writes under the mutex

	/**
	 * Run `$body` with one statement rewritten to a table that does not exist.
	 *
	 * The same `false`/`null` shape a MariaDB 1205 lock-wait timeout produces, and the same sabotage
	 * idiom the rest of the suite uses. No assertions inside - the caller owns those.
	 *
	 * @param callable(): \WP_REST_Response $body
	 */
	private function underSabotage( string $pattern, string $replacement, callable $body ): \WP_REST_Response {
		global $wpdb;
		$sabotage = static function ( $query ) use ( $pattern, $replacement ) {
			return 1 === preg_match( $pattern, (string) $query ) ? $replacement : $query;
		};

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			return $body();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * An occurrence edit that failed to write must not answer 200 as though it applied.
	 *
	 * `OccurrenceRepository::update()` discarded `$wpdb->update()`'s return inside the occurrence-mutex
	 * transaction. The controller then re-reads the row and presents it, so a silently failed UPDATE
	 * produced the UNCHANGED row and a 200 - the owner is told the capacity change took effect while
	 * the database still holds the old value.
	 */
	public function test_an_occurrence_edit_that_could_not_be_written_is_refused_not_reported_as_applied(): void {
		global $wpdb;
		$this->asAdmin();
		$service    = $this->createEventService( 'Seminar', 5 );
		$occurrence = $this->createOccurrence( (int) $service['id'], 5 );

		$response = $this->underSabotage(
			'/^\s*UPDATE\s+\S*reservant_occurrences\S*\s+SET/is',
			'UPDATE reservant_no_such_table SET capacity = 1 WHERE 1 = 1',
			fn (): \WP_REST_Response => $this->jsonRequest( 'PUT', "/reservant/v1/admin/occurrences/{$occurrence['id']}", array( 'capacity' => 10 ) )
		);

		self::assertSame( 409, $response->get_status(), 'a write that failed must never be presented as a successful edit' );
		self::assertSame( 'lock_unavailable', $response->get_data()['message'] );

		$survives = ( new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb ) )->find( (int) $occurrence['id'] );
		self::assertSame( 5, $survives['capacity'], 'the row must be exactly as it was' );
	}

	/**
	 * The seat map's own locking existence recheck must answer 409, not an opaque 500.
	 *
	 * `SeatMapRepository::lockForUpdate()` reads through `get_var()`, so a DB failure and "the row is
	 * gone" both arrive as `null`. The controller reads that as gone and throws `update_conflict`,
	 * which is NOT in `Errors::KNOWN_REASONS` and therefore lands on the opaque 500 arm - a retryable
	 * contention failure reported as an internal error.
	 */
	public function test_a_seat_map_lock_failure_answers_409_rather_than_an_opaque_500(): void {
		$this->asAdmin();
		$map = $this->createSeatMap();

		$response = $this->underSabotage(
			'/^\s*SELECT\s+id\s+FROM\s+\S*reservant_seat_maps\b.*FOR UPDATE/is',
			'SELECT id FROM reservant_no_such_table WHERE 1 = 1',
			fn (): \WP_REST_Response => $this->jsonRequest( 'PUT', "/reservant/v1/admin/seat-maps/{$map['id']}", array( 'name' => 'Renamed', 'spec' => self::GRID_SPEC ) )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'lock_unavailable', $response->get_data()['message'] );

		$after = $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertSame( 'Grid hall', $after->get_data()['name'], 'the map must be untouched' );
	}

	/**
	 * A seat rewrite is a delete followed by an insert, in one transaction. If the delete fails
	 * silently the insert lands ON TOP of the rows that should have gone, and the map commits holding
	 * every seat twice.
	 */
	public function test_a_seat_rewrite_whose_delete_failed_does_not_commit_a_doubled_grid(): void {
		$this->asAdmin();
		$map      = $this->createSeatMap();
		$expected = count( $map['seats'] );

		$response = $this->underSabotage(
			'/^\s*DELETE\s+FROM\s+\S*reservant_seats\b/is',
			'DELETE FROM reservant_no_such_table WHERE 1 = 1',
			fn (): \WP_REST_Response => $this->jsonRequest( 'PUT', "/reservant/v1/admin/seat-maps/{$map['id']}", array( 'name' => 'Grid hall', 'spec' => self::GRID_SPEC ) )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'lock_unavailable', $response->get_data()['message'] );

		$after = $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$map['id']}" );
		self::assertCount( $expected, $after->get_data()['seats'], 'a half-applied seat rewrite must never commit' );
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	private static function findById( array $rows, int $id ): array {
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $id ) {
				return $row;
			}
		}
		self::fail( "No row with id {$id}." );
	}
}
