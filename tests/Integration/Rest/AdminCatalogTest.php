<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `reservant/v1/admin/services` and `admin/resources` (Task 11, AGENTS.md section 4): catalog CRUD, the
 * `referenced` delete guard, atomic rule replacement on resource save, and business-wide exceptions.
 */
final class AdminCatalogTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::sync();
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

		$routes = array(
			array( 'GET', '/reservant/v1/admin/services' ),
			array( 'GET', "/reservant/v1/admin/services/{$service['id']}" ),
			array( 'GET', '/reservant/v1/admin/resources' ),
			array( 'GET', "/reservant/v1/admin/resources/{$resource['id']}" ),
		);
		foreach ( $routes as list( $method, $route ) ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->request( $method, $route )->get_status(), $route );
			$this->asSubscriber();
			self::assertSame( 403, $this->request( $method, $route )->get_status(), $route );
			$this->asBookingManager();
			self::assertSame( 403, $this->request( $method, $route )->get_status(), "manage_bookings alone must not reach {$route}" );
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
}
