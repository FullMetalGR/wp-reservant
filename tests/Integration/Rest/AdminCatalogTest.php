<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `reservant/v1/admin/services` and `admin/resources` (Task 11, AGENTS.md section 4): catalog CRUD, the
 * `referenced` delete guard, atomic rule replacement on resource save, and business-wide exceptions.
 *
 * Fix round 1 (review): `destroy()`'s transactional in-lock reference recheck and its zero-affected-
 * rows failure surface are covered at the bottom of the file, under "delete race guards".
 */
final class AdminCatalogTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::sync();
		// Every configuration WRITE on this namespace now needs an active license
		// (`Rest\Admin\AdminGuard::configureSite()`), and `ReservantTestCase::set_up()` starts every
		// test from "no license". This class is about the CAPABILITY matrix, not the licensing gate
		// - `LicenseEnforcementTest` owns that - so it says once, here, that its site is licensed,
		// exactly as a real one would have to be before its owner could edit a service.
		$this->licenseThisSite();
	}

	// ---------------------------------------------------------------- helpers

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/** Holds reservant_manage_bookings but NOT reservant_manage_settings - a booking manager, not a settings admin. */
	private function asBookingManager(): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		$user->add_cap( 'reservant_manage_bookings' );
		wp_set_current_user( $id );
		return $id;
	}

	private function asSubscriber(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function asAnonymous(): void {
		wp_set_current_user( 0 );
	}

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

	/** @return array<string, mixed> */
	private function createService( string $name = 'Cut', string $type = 'appointment', int $durationMin = 30 ): array {
		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/services',
			array( 'name' => $name, 'type' => $type, 'duration_min' => $durationMin, 'payment_mode' => 'onsite' )
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return array<string, mixed> */
	private function createResource( string $name = 'Alex' ): array {
		$response = $this->jsonRequest( 'POST', '/reservant/v1/admin/resources', array( 'name' => $name ) );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @return list<string> UTC starts for a single-segment chain */
	private function availabilityStarts( int $serviceId, int $resourceId ): array {
		$response = $this->request(
			'GET',
			'/reservant/v1/admin/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $serviceId, 'resource_id' => $resourceId ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return array_column( $response->get_data()['starts'], 'utc' );
	}

	// ---------------------------------------------------------------- permission matrix

	public function test_permission_matrix_for_catalog_routes(): void {
		$service  = $this->createServiceAsAdmin();
		$resource = $this->createResourceAsAdmin();

		// Third element is what a manage_bookings holder without manage_settings gets. The two
		// collection reads are 200 because the Calendar and Bookings screens - gated on
		// manage_bookings - cannot render a staff filter, a service filter or the manual-booking
		// drawer without them (AdminGuard::readCatalog()). Everything else stays 403.
		$routes = array(
			array( 'GET', '/reservant/v1/admin/services', 200 ),
			array( 'GET', "/reservant/v1/admin/services/{$service['id']}", 403 ),
			array( 'GET', '/reservant/v1/admin/resources', 200 ),
			array( 'GET', "/reservant/v1/admin/resources/{$resource['id']}", 403 ),
			array( 'GET', '/reservant/v1/admin/exceptions', 403 ),
		);
		foreach ( $routes as list( $method, $route, $bookingManagerStatus ) ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->request( $method, $route )->get_status(), $route );
			$this->asSubscriber();
			self::assertSame( 403, $this->request( $method, $route )->get_status(), $route );
			$this->asBookingManager();
			self::assertSame( $bookingManagerStatus, $this->request( $method, $route )->get_status(), "manage_bookings alone on {$route}" );
			$this->asAdmin();
			self::assertSame( 200, $this->request( $method, $route )->get_status(), $route );
		}

		$this->asAnonymous();
		self::assertSame( 401, $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'X', 'type' => 'appointment', 'duration_min' => 30 ) )->get_status() );
		$this->asBookingManager();
		self::assertSame( 403, $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'X', 'type' => 'appointment', 'duration_min' => 30 ) )->get_status() );
	}

	/** @return array<string, mixed> */
	private function createServiceAsAdmin(): array {
		$this->asAdmin();
		return $this->createService();
	}

	/** @return array<string, mixed> */
	private function createResourceAsAdmin(): array {
		$this->asAdmin();
		return $this->createResource();
	}

	// ---------------------------------------------------------------- services CRUD

	public function test_create_service_round_trip(): void {
		$this->asAdmin();
		$created = $this->createService( 'Deluxe Cut', 'appointment', 45 );

		self::assertSame( 'Deluxe Cut', $created['name'] );
		self::assertSame( 'appointment', $created['type'] );
		self::assertSame( 45, $created['duration_min'] );
		self::assertSame( 'active', $created['status'] );

		$fetched = $this->request( 'GET', "/reservant/v1/admin/services/{$created['id']}" );
		self::assertSame( 200, $fetched->get_status() );
		self::assertSame( $created['id'], $fetched->get_data()['id'] );
		self::assertSame( 'Deluxe Cut', $fetched->get_data()['name'] );
	}

	/**
	 * Fix round 1: `description` is optional on create (defaults to `''`, not a missing column - the
	 * schema now has one, `Migrations.php`), sanitized with `sanitize_textarea_field()` so line
	 * breaks survive a save, and editable on an existing service through the same partial-PUT
	 * semantics every other field already gets.
	 */
	public function test_create_and_update_round_trip_description(): void {
		$this->asAdmin();

		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/services',
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'payment_mode' => 'onsite',
				'description'  => "A precision trim.\nWalk-ins welcome.",
			)
		);
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		self::assertSame( "A precision trim.\nWalk-ins welcome.", $created->get_data()['description'] );

		$updated = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/services/{$created->get_data()['id']}",
			array( 'description' => 'Now with a hot towel finish.' )
		);
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertSame( 'Now with a hot towel finish.', $updated->get_data()['description'] );
		// Untouched fields survive the partial update.
		self::assertSame( 'Cut', $updated->get_data()['name'] );
	}

	/** A caller that never sends `description` gets `''`, not a missing key. */
	public function test_create_defaults_description_to_empty_string_when_omitted(): void {
		$created = $this->createServiceAsAdmin();
		self::assertSame( '', $created['description'] );
	}

	public function test_create_service_rejects_bad_duration_and_bad_type(): void {
		$this->asAdmin();

		$badDuration = $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'X', 'type' => 'appointment', 'duration_min' => 7 ) );
		self::assertSame( 400, $badDuration->get_status() );

		$badType = $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'X', 'type' => 'nonsense' ) );
		self::assertSame( 400, $badType->get_status() );

		$eventNoCapacityNoSeatMap = $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'Gala', 'type' => 'event', 'capacity' => 0 ) );
		self::assertSame( 400, $eventNoCapacityNoSeatMap->get_status() );

		$validEvent = $this->jsonRequest( 'POST', '/reservant/v1/admin/services', array( 'name' => 'Gala', 'type' => 'event', 'capacity' => 40 ) );
		self::assertSame( 201, $validEvent->get_status() );
	}

	public function test_put_updates_fields(): void {
		$this->asAdmin();
		$created = $this->createService( 'Cut', 'appointment', 30 );

		$updated = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/services/{$created['id']}",
			array( 'name' => 'Cut & Colour', 'duration_min' => 60, 'price_minor' => 5500 )
		);
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertSame( 'Cut & Colour', $updated->get_data()['name'] );
		self::assertSame( 60, $updated->get_data()['duration_min'] );
		self::assertSame( 5500, $updated->get_data()['price_minor'] );
		// Untouched fields survive the partial update.
		self::assertSame( 'onsite', $updated->get_data()['payment_mode'] );
	}

	public function test_delete_referenced_service_is_refused_and_row_survives(): void {
		global $wpdb;
		$this->asAdmin();
		$created = $this->createService( 'Cut', 'appointment', 30 );

		$resource = ( new ResourceRepository( $wpdb ) )->insert( array( 'name' => 'Alex' ) );
		( new ResourceRepository( $wpdb ) )->linkService( (int) $created['id'], $resource );
		foreach ( range( 1, 7 ) as $weekday ) {
			( new AvailabilityRepository( $wpdb ) )->insertRule( $resource, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
		$this->manualBookingFixture( $wpdb, (int) $created['id'], $resource );

		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/services/{$created['id']}" );
		self::assertSame( 409, $deleted->get_status() );
		self::assertSame( 'referenced', $deleted->get_data()['message'] );

		$survives = ( new ServiceRepository( $wpdb ) )->find( (int) $created['id'] );
		self::assertNotNull( $survives );
		self::assertSame( 'Cut', $survives['name'] );
	}

	public function test_delete_unreferenced_service_succeeds(): void {
		$this->asAdmin();
		$created = $this->createService();

		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/services/{$created['id']}" );
		self::assertSame( 204, $deleted->get_status() );

		self::assertSame( 404, $this->request( 'GET', "/reservant/v1/admin/services/{$created['id']}" )->get_status() );
	}

	public function test_deactivating_a_referenced_service_is_always_allowed_and_hides_it_from_the_default_listing(): void {
		global $wpdb;
		$this->asAdmin();
		$created = $this->createService( 'Cut', 'appointment', 30 );

		$resource = ( new ResourceRepository( $wpdb ) )->insert( array( 'name' => 'Alex' ) );
		( new ResourceRepository( $wpdb ) )->linkService( (int) $created['id'], $resource );
		foreach ( range( 1, 7 ) as $weekday ) {
			( new AvailabilityRepository( $wpdb ) )->insertRule( $resource, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
		$this->manualBookingFixture( $wpdb, (int) $created['id'], $resource );

		// The delete guard still blocks it...
		self::assertSame( 409, $this->request( 'DELETE', "/reservant/v1/admin/services/{$created['id']}" )->get_status() );

		// ...but deactivating (the advised alternative) always succeeds, referenced or not.
		$deactivated = $this->jsonRequest( 'PUT', "/reservant/v1/admin/services/{$created['id']}", array( 'status' => 'inactive' ) );
		self::assertSame( 200, $deactivated->get_status(), (string) wp_json_encode( $deactivated->get_data() ) );
		self::assertSame( 'inactive', $deactivated->get_data()['status'] );

		// Default listing (all(false)) hides it...
		$defaultList = $this->request( 'GET', '/reservant/v1/admin/services' );
		self::assertSame( 200, $defaultList->get_status() );
		$ids = array_column( $defaultList->get_data()['services'], 'id' );
		self::assertNotContains( (int) $created['id'], $ids );

		// ...but it is still reachable with include_inactive=1 (all(true)).
		$fullList = $this->request( 'GET', '/reservant/v1/admin/services', array( 'include_inactive' => '1' ) );
		$fullIds  = array_column( $fullList->get_data()['services'], 'id' );
		self::assertContains( (int) $created['id'], $fullIds );
	}

	/** Books a confirmed slot through the ordinary application layer so the service/resource become "referenced". */
	private function manualBookingFixture( \wpdb $wpdb, int $serviceId, int $resourceId ): void {
		\Reservant\Application\HoldBooking::make( $wpdb )->execute(
			new \Reservant\Application\Dto\HoldRequest(
				new \Reservant\Application\Dto\Customer( 'Walk-in', 'walkin@example.com' ),
				new \Reservant\Application\Dto\AppointmentRequest(
					$this->utc( 1, '09:00' ),
					array( new \Reservant\Application\Dto\SegmentChoice( $serviceId, $resourceId ) )
				),
				null,
				true
			),
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);
	}

	// ---------------------------------------------------------------- resources CRUD + rules + service links

	public function test_create_resource_round_trip_with_service_links_and_rules(): void {
		$this->asAdmin();
		$service = $this->createService();

		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'email'       => 'alex@example.com',
				'service_ids' => array( $service['id'] ),
				'rules'       => array(
					array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ),
					array( 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00' ),
				),
			)
		);
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		self::assertSame( 'Alex', $data['name'] );
		self::assertSame( array( (int) $service['id'] ), $data['service_ids'] );
		self::assertCount( 2, $data['rules'] );
	}

	/**
	 * Final-review finding: deactivating a staff member was a one-way door in the SPA, because
	 * `useResources()` never sent `include_inactive` and the parameter defaults to `false` - the row
	 * left the only table it could ever have been selected and reactivated from. The SPA now always
	 * asks for the full catalog, so this pins the server contract that makes that possible: an
	 * inactive resource is absent by default and present with `include_inactive=1`, and it still
	 * carries the same associations every other row does (the staff screen has to be able to edit
	 * it, not merely see the name).
	 */
	public function test_inactive_resources_are_listed_only_when_include_inactive_is_asked_for(): void {
		$this->asAdmin();
		$service = $this->createService();

		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Departed',
				'service_ids' => array( $service['id'] ),
				'rules'       => array( array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ) ),
			)
		);
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$id = (int) $created->get_data()['id'];

		$deactivated = $this->jsonRequest( 'PUT', "/reservant/v1/admin/resources/{$id}", array( 'status' => 'inactive' ) );
		self::assertSame( 200, $deactivated->get_status(), (string) wp_json_encode( $deactivated->get_data() ) );
		self::assertSame( 'inactive', $deactivated->get_data()['status'] );

		$defaultList = $this->request( 'GET', '/reservant/v1/admin/resources' );
		self::assertSame( 200, $defaultList->get_status() );
		self::assertNotContains( $id, array_column( $defaultList->get_data()['resources'], 'id' ) );

		$fullList = $this->request( 'GET', '/reservant/v1/admin/resources', array( 'include_inactive' => '1' ) );
		self::assertSame( 200, $fullList->get_status() );
		/** @var list<array<string, mixed>> $rows */
		$rows    = $fullList->get_data()['resources'];
		$departed = null;
		foreach ( $rows as $row ) {
			if ( $id === (int) $row['id'] ) {
				$departed = $row;
			}
		}
		self::assertNotNull( $departed, 'include_inactive=1 must list the deactivated resource' );
		self::assertSame( array( (int) $service['id'] ), $departed['service_ids'] );
		self::assertCount( 1, $departed['rules'] );

		// And it can be put back - the whole point of being able to see it.
		$reactivated = $this->jsonRequest( 'PUT', "/reservant/v1/admin/resources/{$id}", array( 'status' => 'active' ) );
		self::assertSame( 200, $reactivated->get_status() );
		self::assertSame( 'active', $reactivated->get_data()['status'] );
		self::assertContains( $id, array_column( $this->request( 'GET', '/reservant/v1/admin/resources' )->get_data()['resources'], 'id' ) );
	}

	/**
	 * Final-review finding (critical), and the identical `index()`-vs-`present()` drift the test
	 * below this one covers for resources: `GET /admin/seat-maps` returned `SeatMapRepository::all()`
	 * verbatim - `id`/`name`/`spec`, no `seats` - while `GET /admin/seat-maps/{id}` went through
	 * `present()` and did attach them. The `SeatMap` TypeScript type declares `seats` non-optional
	 * and `SeatMapsScreen` renders `map.seats.length` for every row of this list, so the whole admin
	 * SPA threw and unmounted the moment the Seat Maps screen was opened - the feature was
	 * unreachable, and the dashboard showed nothing but wp-admin chrome.
	 *
	 * This is the server half of the pair; the client half is
	 * `assets/src/admin/screens/__tests__/seatMapsScreen.test.tsx`, which mounts the screen against
	 * exactly this payload. Asserting the wire shape HERE, against a real request, is what stops that
	 * fixture from being a hand-written approximation confirming the type against itself.
	 */
	public function test_seat_map_list_carries_seats_like_the_single_seat_map_shape(): void {
		$this->asAdmin();

		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/seat-maps',
			array( 'name' => 'Main Hall', 'spec' => 'rows A-B, 2 per row, aisle after 1' )
		);
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		/** @var array<string, mixed> $createdMap */
		$createdMap = $created->get_data();

		$listed = $this->request( 'GET', '/reservant/v1/admin/seat-maps' );
		self::assertSame( 200, $listed->get_status() );
		/** @var list<array<string, mixed>> $maps */
		$maps = $listed->get_data()['seat_maps'];
		self::assertNotSame( array(), $maps );

		$hall = null;
		foreach ( $maps as $row ) {
			if ( 'Main Hall' === $row['name'] ) {
				$hall = $row;
			}
		}
		self::assertNotNull( $hall, 'the created seat map must appear in the list' );

		// The whole point: `seats` is present on a LIST row, not only on the single-map response.
		self::assertArrayHasKey( 'seats', $hall );
		self::assertIsArray( $hall['seats'] );
		// 2 rows x (2 seats + 1 aisle) - aisles are stored too, they are geometry the grid draws.
		self::assertCount( 6, $hall['seats'] );

		// And it is the SAME shape the single-map route answers with, field for field.
		$single = $this->request( 'GET', "/reservant/v1/admin/seat-maps/{$createdMap['id']}" );
		self::assertSame( 200, $single->get_status() );
		self::assertSame( $single->get_data(), $hall );

		// Every seat carries the columns `SeatMapRepository::seatsForMap()` selects and casts - the
		// exact fields the `Seat` TypeScript type declares and `SeatMapPreview` reads.
		/** @var array<string, mixed> $seat */
		$seat = $hall['seats'][0];
		foreach ( array( 'id', 'seat_map_id', 'row_label', 'seat_label', 'sort_row', 'sort_col', 'kind' ) as $field ) {
			self::assertArrayHasKey( $field, $seat );
		}
		self::assertIsInt( $seat['id'] );
		self::assertIsInt( $seat['sort_row'] );
		self::assertIsInt( $seat['sort_col'] );
	}

	/**
	 * Lock-guard repair wave 3, item 2: `create()`'s only `try` used to wrap the spec parse alone, so
	 * `SeatMapRepository::insertSeats()`'s guarded `lock_unavailable` throw (added to stop a failed
	 * insert from feeding `insertSeats()` a stale or zero map id - see that repository's docblock)
	 * escaped this REST callback as an uncaught exception - a fatal or an opaque 500 - for a failure
	 * `update()` already answers with a clean 409. `create()` now wraps `insert()`/`insertSeats()` in
	 * the same catch `update()` uses.
	 *
	 * Wave 4 extends this past status-and-message to the COMMITTED STATE, which is what the 409
	 * actually promises. `create()` had no transaction, so the map row `insert()` wrote had already
	 * committed by the time `insertSeats()` refused: the caller got a 409 whose whole meaning is
	 * "nothing happened, repeat the request" together with an orphaned, seatless map - and every retry
	 * minted another one. `insert()`/`insertSeats()` now run inside one `TransactionRunner`, the shape
	 * `update()` and `destroy()` on this controller already use, so the refusal really does mean
	 * nothing happened.
	 */
	public function test_seat_map_create_answers_409_not_a_fatal_when_the_seat_insert_fails(): void {
		global $wpdb;
		$this->asAdmin();

		$sabotage = static function ( $query ) {
			return 1 === preg_match( '/^\s*INSERT\s+INTO\s+\S*reservant_seats\b/is', (string) $query )
				? 'INSERT INTO reservant_no_such_table (id) VALUES (1)'
				: $query;
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			$response = $this->jsonRequest(
				'POST',
				'/reservant/v1/admin/seat-maps',
				array( 'name' => 'Main Hall', 'spec' => 'rows A-B, 2 per row' )
			);
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}

		self::assertSame( 409, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 'lock_unavailable', $response->get_data()['message'] );

		self::assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_seat_maps" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'a 409 that says "repeat the request" must not leave an orphaned, seatless map behind for every attempt'
		);
		self::assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_seats" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'and no seat rows either'
		);

		// The retry the 409 invites must now succeed and leave exactly one map.
		$retry = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/seat-maps',
			array( 'name' => 'Main Hall', 'spec' => 'rows A-B, 2 per row' )
		);
		self::assertSame( 201, $retry->get_status(), (string) wp_json_encode( $retry->get_data() ) );
		self::assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_seat_maps" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertCount( 4, $retry->get_data()['seats'] );
	}

	/**
	 * Task 17 finding: `GET /admin/resources` (the list every catalog screen loads through -
	 * `useResources()`) used to return bare `reservant_resources` columns, never the
	 * `service_ids`/`rules` associations `GET /admin/resources/{id}` attaches via `present()`. The
	 * `Resource` TypeScript type declares both non-optional and several
	 * screens dereference them unconditionally the moment a resource loads - `ManualBookingDrawer`'s
	 * `staffOptionsForService()` (`resource.service_ids.includes(...)`) crashed the whole admin SPA
	 * the instant the "New booking" drawer opened with any real resource in the list. The admin
	 * smoke e2e spec (`tests/e2e/admin-smoke.spec.ts`) is what surfaced this - every existing
	 * integration test that reaches this route only asserted its HTTP status
	 * (`test_permission_matrix_for_catalog_routes`), never the shape of a resource row inside it.
	 *
	 * The `exceptions` assertion is now the opposite of what it was: that association is gone from
	 * the resource shape entirely (final-review finding - no client has read it since `StaffScreen`
	 * moved to `GET /admin/exceptions`, yet since `index()` began attaching associations to every
	 * row it cost a third query per resource and shipped every blackout date of every staff member
	 * on every catalog load).
	 */
	public function test_resource_list_carries_service_ids_and_rules_like_the_single_resource_shape(): void {
		$this->asAdmin();
		$service = $this->createService();

		$this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array( array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ) ),
			)
		);

		$listed = $this->request( 'GET', '/reservant/v1/admin/resources' );
		self::assertSame( 200, $listed->get_status() );
		/** @var list<array<string, mixed>> $resources */
		$resources = $listed->get_data()['resources'];
		self::assertNotSame( array(), $resources );

		$alex = null;
		foreach ( $resources as $row ) {
			if ( 'Alex' === $row['name'] ) {
				$alex = $row;
			}
		}
		self::assertNotNull( $alex, 'the created resource must appear in the list' );
		self::assertSame( array( (int) $service['id'] ), $alex['service_ids'] );
		self::assertCount( 1, $alex['rules'] );
		self::assertArrayNotHasKey( 'exceptions', $alex, 'no client reads it; attaching it costs a query per row' );

		// The single-resource route answers the same shape - including not carrying `exceptions`.
		$single = $this->request( 'GET', "/reservant/v1/admin/resources/{$alex['id']}" );
		self::assertSame( 200, $single->get_status() );
		self::assertArrayNotHasKey( 'exceptions', $single->get_data() );
		self::assertSame( $single->get_data(), $alex );
	}

	public function test_resource_save_replaces_rules_atomically(): void {
		$this->asAdmin();
		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'  => 'Alex',
				'rules' => array(
					array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ),
					array( 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00' ),
				),
			)
		);
		self::assertSame( 201, $created->get_status() );
		$oldIds = array_column( $created->get_data()['rules'], 'id' );
		self::assertCount( 2, $oldIds );

		$updated = $this->jsonRequest(
			'PUT',
			"/reservant/v1/admin/resources/{$created->get_data()['id']}",
			array(
				'rules' => array(
					array( 'weekday' => 3, 'start_time' => '10:00', 'end_time' => '14:00' ),
				),
			)
		);
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		$newRules = $updated->get_data()['rules'];
		self::assertCount( 1, $newRules );
		self::assertSame( 3, $newRules[0]['weekday'] );

		$newIds = array_column( $newRules, 'id' );
		foreach ( $oldIds as $oldId ) {
			self::assertNotContains( $oldId, $newIds, 'Old rule row ids must not survive a replace-all save.' );
		}
	}

	public function test_put_status_inactive_alone_leaves_rules_and_links_untouched(): void {
		$this->asAdmin();
		$service = $this->createService();
		$created = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array( array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ) ),
			)
		);
		self::assertSame( 201, $created->get_status() );

		$deactivated = $this->jsonRequest( 'PUT', "/reservant/v1/admin/resources/{$created->get_data()['id']}", array( 'status' => 'inactive' ) );
		self::assertSame( 200, $deactivated->get_status(), (string) wp_json_encode( $deactivated->get_data() ) );
		self::assertSame( 'inactive', $deactivated->get_data()['status'] );
		self::assertSame( array( (int) $service['id'] ), $deactivated->get_data()['service_ids'] );
		self::assertCount( 1, $deactivated->get_data()['rules'] );
	}

	public function test_delete_referenced_resource_is_refused_and_row_survives(): void {
		global $wpdb;
		$this->asAdmin();
		$service  = $this->createService();
		$resource = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array( array( 'weekday' => 1, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 2, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 3, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 4, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 5, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 6, 'start_time' => '00:00', 'end_time' => '23:55' ), array( 'weekday' => 7, 'start_time' => '00:00', 'end_time' => '23:55' ) ),
			)
		)->get_data();

		$this->manualBookingFixture( $wpdb, (int) $service['id'], (int) $resource['id'] );

		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/resources/{$resource['id']}" );
		self::assertSame( 409, $deleted->get_status() );
		self::assertSame( 'referenced', $deleted->get_data()['message'] );

		$survives = ( new ResourceRepository( $wpdb ) )->find( (int) $resource['id'] );
		self::assertNotNull( $survives );
		self::assertSame( 'Alex', $survives['name'] );
	}

	public function test_delete_unreferenced_resource_cleans_up_links_rules_and_exceptions(): void {
		global $wpdb;
		$this->asAdmin();
		$service  = $this->createService();
		$resource = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array( array( 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00' ) ),
			)
		)->get_data();
		$this->jsonRequest( 'POST', "/reservant/v1/admin/resources/{$resource['id']}/exceptions", array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) );

		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/resources/{$resource['id']}" );
		self::assertSame( 204, $deleted->get_status() );

		self::assertSame( array(), ( new AvailabilityRepository( $wpdb ) )->rulesForResource( (int) $resource['id'] ) );
		self::assertSame( array(), ( new AvailabilityRepository( $wpdb ) )->exceptionsForResource( (int) $resource['id'] ) );
		self::assertSame( array(), ( new ResourceRepository( $wpdb ) )->serviceIdsForResource( (int) $resource['id'] ) );
	}

	// ---------------------------------------------------------------- resource-scoped exceptions

	public function test_resource_exception_blocks_and_delete_restores_availability(): void {
		global $wpdb;
		$this->asAdmin();
		$service = $this->createService();
		$resource = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array_map(
					static fn ( int $weekday ): array => array( 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00' ),
					range( 1, 7 )
				),
			)
		)->get_data();

		$before = $this->availabilityStarts( (int) $service['id'], (int) $resource['id'] );
		self::assertContains( $this->sql( 1, '09:00' ), $before );

		$date  = $this->utc( 1 )->format( 'Y-m-d' );
		$added = $this->jsonRequest( 'POST', "/reservant/v1/admin/resources/{$resource['id']}/exceptions", array( 'date' => $date ) );
		self::assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );

		$after = $this->availabilityStarts( (int) $service['id'], (int) $resource['id'] );
		self::assertNotContains( $this->sql( 1, '09:00' ), $after );

		$removed = $this->jsonRequest( 'DELETE', "/reservant/v1/admin/resources/{$resource['id']}/exceptions", array( 'date' => $date ) );
		self::assertSame( 200, $removed->get_status(), (string) wp_json_encode( $removed->get_data() ) );

		$restored = $this->availabilityStarts( (int) $service['id'], (int) $resource['id'] );
		self::assertContains( $this->sql( 1, '09:00' ), $restored );
	}

	public function test_delete_exception_with_no_match_is_404(): void {
		$this->asAdmin();
		$resource = $this->createResource();
		$response = $this->jsonRequest( 'DELETE', "/reservant/v1/admin/resources/{$resource['id']}/exceptions", array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) );
		self::assertSame( 404, $response->get_status() );
	}

	// ---------------------------------------------------------------- business-wide exceptions

	public function test_business_wide_exception_blocks_availability_for_every_resource(): void {
		$this->asAdmin();
		$service   = $this->createService();
		$resourceA = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Alex',
				'service_ids' => array( $service['id'] ),
				'rules'       => array_map(
					static fn ( int $weekday ): array => array( 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00' ),
					range( 1, 7 )
				),
			)
		)->get_data();
		$resourceB = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/resources',
			array(
				'name'        => 'Bella',
				'service_ids' => array( $service['id'] ),
				'rules'       => array_map(
					static fn ( int $weekday ): array => array( 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00' ),
					range( 1, 7 )
				),
			)
		)->get_data();

		self::assertContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceA['id'] ) );
		self::assertContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceB['id'] ) );

		$date  = $this->utc( 1 )->format( 'Y-m-d' );
		$added = $this->jsonRequest( 'POST', '/reservant/v1/admin/exceptions', array( 'date' => $date ) );
		self::assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );
		self::assertNull( $added->get_data()['resource_id'] );

		// Both resources lose the slot on that date - a business-wide closure, not a per-resource one.
		self::assertNotContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceA['id'] ) );
		self::assertNotContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceB['id'] ) );

		$removed = $this->jsonRequest( 'DELETE', '/reservant/v1/admin/exceptions', array( 'date' => $date ) );
		self::assertSame( 200, $removed->get_status() );

		self::assertContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceA['id'] ) );
		self::assertContains( $this->sql( 1, '09:00' ), $this->availabilityStarts( (int) $service['id'], (int) $resourceB['id'] ) );
	}

	public function test_business_wide_exception_routes_are_forbidden_without_manage_settings(): void {
		$this->asBookingManager();
		self::assertSame( 403, $this->jsonRequest( 'POST', '/reservant/v1/admin/exceptions', array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) )->get_status() );
		self::assertSame( 403, $this->jsonRequest( 'DELETE', '/reservant/v1/admin/exceptions', array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) )->get_status() );
	}

	// ---------------------------------------------------------------- listing exceptions (Task 16b gap-filler)

	/**
	 * Task 11 shipped POST|DELETE /admin/exceptions but no GET, so the admin blackouts panel could
	 * not reload what it had already added (Task 16 report, "known limitation"). This adds the
	 * missing GET: business-wide rows (`resource_id` absent) by default, one resource's own rows
	 * when `resource_id` is given - never a merge of the two, unlike `exceptionsForResources()`
	 * (the availability-math read, which deliberately duplicates business-wide rows under every
	 * resource for feasibility checks).
	 */
	public function test_list_exceptions_business_wide_returns_only_null_resource_rows(): void {
		$this->asAdmin();
		$resource = $this->createResource();
		$this->jsonRequest( 'POST', '/reservant/v1/admin/exceptions', array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) );
		$this->jsonRequest( 'POST', "/reservant/v1/admin/resources/{$resource['id']}/exceptions", array( 'date' => $this->utc( 2 )->format( 'Y-m-d' ) ) );

		$response = $this->request( 'GET', '/reservant/v1/admin/exceptions' );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$rows = $response->get_data()['exceptions'];
		self::assertCount( 1, $rows );
		self::assertNull( $rows[0]['resource_id'] );
		self::assertSame( $this->utc( 1 )->format( 'Y-m-d' ), $rows[0]['date'] );
		self::assertNull( $rows[0]['start_time'] );
		self::assertNull( $rows[0]['end_time'] );
		self::assertSame( '', $rows[0]['reason'] );
	}

	public function test_list_exceptions_with_resource_id_returns_only_that_resources_rows(): void {
		$this->asAdmin();
		$resourceA = $this->createResource( 'Alex' );
		$resourceB = $this->createResource( 'Bella' );
		$this->jsonRequest( 'POST', '/reservant/v1/admin/exceptions', array( 'date' => $this->utc( 1 )->format( 'Y-m-d' ) ) );
		$this->jsonRequest( 'POST', "/reservant/v1/admin/resources/{$resourceA['id']}/exceptions", array( 'date' => $this->utc( 2 )->format( 'Y-m-d' ) ) );
		$this->jsonRequest( 'POST', "/reservant/v1/admin/resources/{$resourceB['id']}/exceptions", array( 'date' => $this->utc( 3 )->format( 'Y-m-d' ) ) );

		$response = $this->request( 'GET', '/reservant/v1/admin/exceptions', array( 'resource_id' => $resourceA['id'] ) );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$rows = $response->get_data()['exceptions'];
		self::assertCount( 1, $rows );
		self::assertSame( (int) $resourceA['id'], $rows[0]['resource_id'] );
		self::assertSame( $this->utc( 2 )->format( 'Y-m-d' ), $rows[0]['date'] );
	}

	public function test_list_exceptions_is_forbidden_for_manage_bookings_only_caller(): void {
		$this->asBookingManager();
		self::assertSame( 403, $this->request( 'GET', '/reservant/v1/admin/exceptions' )->get_status() );
	}

	public function test_list_exceptions_empty_is_empty_array_not_error(): void {
		$this->asAdmin();
		$response = $this->request( 'GET', '/reservant/v1/admin/exceptions' );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( array(), $response->get_data()['exceptions'] );
	}

	// ---------------------------------------------------------------- delete race guards (fix round 1)

	/**
	 * A second, independent DB connection - not a second OS process/thread, but a genuinely separate
	 * MySQL session with its own transaction state, autocommit by default. Used below to commit
	 * writes "concurrently" with the primary `$wpdb` connection's still-open transaction, the same
	 * way `LockingTest` exercises `TransactionRunner`/`LockManager` directly rather than spinning up
	 * real parallel processes.
	 */
	private function secondConnection(): \wpdb {
		return new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	}

	public function test_service_delete_returns_false_and_leaves_other_rows_untouched_when_nothing_matches(): void {
		global $wpdb;
		$repo = new ServiceRepository( $wpdb );
		$kept = $repo->insert( array( 'name' => 'Kept', 'type' => 'appointment', 'duration_min' => 30 ) );

		// No row with this id exists at all - delete() must report failure, not silently "succeed".
		self::assertFalse( $repo->delete( $kept + 1000000 ) );

		// The failed attempt has no side effect on an unrelated, still-existing row.
		self::assertNotNull( $repo->find( $kept ) );
	}

	public function test_resource_delete_returns_false_and_leaves_other_rows_untouched_when_nothing_matches(): void {
		global $wpdb;
		$repo = new ResourceRepository( $wpdb );
		$kept = $repo->insert( array( 'name' => 'Kept' ) );

		self::assertFalse( $repo->delete( $kept + 1000000 ) );
		self::assertNotNull( $repo->find( $kept ) );
	}

	/**
	 * Reproduces `ServicesAdminController::destroy()`'s fixed sequence exactly: the outer,
	 * non-transactional checks pass, then - in the gap before the transaction opens - a second
	 * connection removes the row and commits (nothing a reference-count check would ever catch,
	 * since it is not about a booking referencing the row, but the row itself vanishing by some
	 * other path). The recheck still passes (never referenced), so the cascade proceeds to the
	 * physical delete, which now matches nothing - `delete()` must report `false` rather than a
	 * unconditional success the fixed controller would otherwise turn into a false 204.
	 */
	public function test_service_delete_reports_failure_when_the_row_vanishes_before_the_physical_delete(): void {
		global $wpdb;
		$repo = new ServiceRepository( $wpdb );
		$id   = $repo->insert( array( 'name' => 'Ghost', 'type' => 'appointment', 'duration_min' => 30 ) );

		// Mirrors destroy()'s outer checks: the row exists and is unreferenced.
		self::assertNotNull( $repo->find( $id ) );
		self::assertFalse( $repo->isReferenced( $id ) );

		// Release the ambient transaction WP's own test framework wraps every test in (same note as
		// TransactionRunner's own docblock): otherwise the just-inserted row is still lock-held by
		// $wpdb's open transaction, and the second connection's DELETE below blocks until it times out.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL

		$second = $this->secondConnection();
		$second->query( $second->prepare( "DELETE FROM {$wpdb->prefix}reservant_services WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$second->close();

		// Reproduces the fixed destroy()'s in-transaction cascade: recheck first, then delete.
		$result = ( new TransactionRunner( $wpdb ) )->run(
			static function () use ( $repo, $id ): bool {
				self::assertFalse( $repo->isReferenced( $id ), 'Nothing ever referenced this row - only its existence changed.' );
				return $repo->delete( $id );
			}
		);
		self::assertFalse( $result, 'delete() must report failure when the physical DELETE affects zero rows, even though every check passed.' );
	}

	public function test_resource_delete_reports_failure_when_the_row_vanishes_before_the_physical_delete(): void {
		global $wpdb;
		$repo = new ResourceRepository( $wpdb );
		$id   = $repo->insert( array( 'name' => 'Ghost' ) );

		self::assertNotNull( $repo->find( $id ) );
		self::assertFalse( $repo->isReferenced( $id ) );

		// See the sibling service test above for why this is necessary before a second connection
		// touches the same row.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL

		$second = $this->secondConnection();
		$second->query( $second->prepare( "DELETE FROM {$wpdb->prefix}reservant_resources WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$second->close();

		$result = ( new TransactionRunner( $wpdb ) )->run(
			static function () use ( $repo, $id ): bool {
				self::assertFalse( $repo->isReferenced( $id ) );
				return $repo->delete( $id );
			}
		);
		self::assertFalse( $result );
	}

	/** "Deleting twice" (the review's own suggested simulation) at the REST layer: the second call must not lie and say 204. */
	public function test_delete_service_twice_is_not_204_the_second_time(): void {
		$this->asAdmin();
		$created = $this->createService();
		self::assertSame( 204, $this->request( 'DELETE', "/reservant/v1/admin/services/{$created['id']}" )->get_status() );
		self::assertNotSame( 204, $this->request( 'DELETE', "/reservant/v1/admin/services/{$created['id']}" )->get_status() );
	}

	public function test_delete_resource_twice_is_not_204_the_second_time(): void {
		$this->asAdmin();
		$created = $this->createResource();
		self::assertSame( 204, $this->request( 'DELETE', "/reservant/v1/admin/resources/{$created['id']}" )->get_status() );
		self::assertNotSame( 204, $this->request( 'DELETE', "/reservant/v1/admin/resources/{$created['id']}" )->get_status() );
	}

	/**
	 * The core claim behind the fix: the in-transaction recheck - run as the transaction's FIRST
	 * statement, exactly as `destroy()` performs it - observes a reference committed by a second
	 * connection AFTER the outer, pre-transaction check already passed. Under InnoDB REPEATABLE READ
	 * a transaction's consistency snapshot is established at its first read, not reused from an
	 * earlier autocommit-mode read, so this is not a tautology: it proves the specific ordering the
	 * fix depends on actually holds.
	 *
	 * True OS-level concurrency (a second process racing the exact same HTTP request) is not
	 * orchestrated here - PHPUnit's single-threaded process can't pause mid-request to let another
	 * process act, and this codebase's own concurrency tests (`LockingTest`) do not spin one up
	 * either. This is the "unit-level seam" proof: the same two statements, in the same order, on the
	 * same two connections `destroy()` would see under a real race.
	 */
	public function test_service_isReferenced_recheck_observes_a_reference_committed_after_the_outer_check(): void {
		global $wpdb;
		$this->asAdmin();
		$service  = $this->createService();
		$resource = ( new ResourceRepository( $wpdb ) )->insert( array( 'name' => 'Alex' ) );
		$services = new ServiceRepository( $wpdb );

		// The outer check, exactly as destroy() runs it - not referenced yet.
		self::assertFalse( $services->isReferenced( (int) $service['id'] ) );

		// A second, independent connection commits a referencing booking item in the gap between the
		// outer check and the transaction the fix wraps the cascade in.
		$second = $this->secondConnection();
		$second->insert(
			"{$wpdb->prefix}reservant_booking_items",
			array(
				'booking_id'      => 999999,
				'service_id'      => (int) $service['id'],
				'resource_id'     => $resource,
				'start_utc'       => $this->sql( 1, '09:00' ),
				'end_utc'         => $this->sql( 1, '09:30' ),
				'block_start_utc' => $this->sql( 1, '09:00' ),
				'block_end_utc'   => $this->sql( 1, '09:30' ),
			)
		);
		$second->close();

		// The recheck, as the new transaction's first statement, sees the now-committed reference.
		$observedInTransaction = ( new TransactionRunner( $wpdb ) )->run(
			static fn (): bool => $services->isReferenced( (int) $service['id'] )
		);
		self::assertTrue( $observedInTransaction, 'The in-transaction recheck must see a reference committed after the outer check.' );

		// End to end: destroy() itself refuses with 409 and the row survives.
		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/services/{$service['id']}" );
		self::assertSame( 409, $deleted->get_status() );
		self::assertSame( 'referenced', $deleted->get_data()['message'] );
		self::assertNotNull( $services->find( (int) $service['id'] ) );
	}

	public function test_resource_isReferenced_recheck_observes_a_reference_committed_after_the_outer_check(): void {
		global $wpdb;
		$this->asAdmin();
		$service   = $this->createService();
		$resource  = $this->createResource();
		$resources = new ResourceRepository( $wpdb );

		self::assertFalse( $resources->isReferenced( (int) $resource['id'] ) );

		$second = $this->secondConnection();
		$second->insert(
			"{$wpdb->prefix}reservant_booking_items",
			array(
				'booking_id'      => 999998,
				'service_id'      => (int) $service['id'],
				'resource_id'     => (int) $resource['id'],
				'start_utc'       => $this->sql( 1, '09:00' ),
				'end_utc'         => $this->sql( 1, '09:30' ),
				'block_start_utc' => $this->sql( 1, '09:00' ),
				'block_end_utc'   => $this->sql( 1, '09:30' ),
			)
		);
		$second->close();

		$observedInTransaction = ( new TransactionRunner( $wpdb ) )->run(
			static fn (): bool => $resources->isReferenced( (int) $resource['id'] )
		);
		self::assertTrue( $observedInTransaction, 'The in-transaction recheck must see a reference committed after the outer check.' );

		$deleted = $this->request( 'DELETE', "/reservant/v1/admin/resources/{$resource['id']}" );
		self::assertSame( 409, $deleted->get_status() );
		self::assertSame( 'referenced', $deleted->get_data()['message'] );
		self::assertNotNull( $resources->find( (int) $resource['id'] ) );
	}
}
