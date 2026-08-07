<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `POST /bookings/{uuid}/reschedule` (AGENTS.md section 5, Task 5): the REST wrapper around
 * `RescheduleBooking::execute()`. Token handling and error mapping mirror `cancel()`'s, with one
 * deliberate divergence: the route is guarded by `Routes::requireTokenOrCapNoOracle()`, which answers
 * a wrong token and an unknown uuid identically - `test_rejects_a_wrong_token_without_revealing_whether_the_uuid_exists()`
 * is the test that proves it (the brief's own "test that matters most"), so an anonymous caller
 * cannot use this endpoint to learn which booking ids are real.
 */
final class RescheduleRouteTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->cutId  = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->staffA = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB = $resources->insert( array( 'name' => 'Bella' ) );
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			$resources->linkService( $this->cutId, $staff );
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	// ---------------------------------------------------------------- helpers

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** @return array<string, mixed> the 201 hold payload */
	private function hold( string $startUtc, int $resourceId ): array {
		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $startUtc, 'segments' => array( array( 'service_id' => $this->cutId, 'resource_id' => $resourceId ) ) ),
			)
		);
		$response = rest_do_request( $request );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @param array<string, mixed> $params */
	private function reschedule( string $uuid, array $params = array() ): \WP_REST_Response {
		return $this->request( 'POST', "/reservant/v1/bookings/{$uuid}/reschedule", $params );
	}

	// ---------------------------------------------------------------- tests

	public function test_guest_with_a_valid_token_can_move_their_booking(): void {
		$booking = $this->hold( $this->sql( 1, '11:00' ), $this->staffA );

		$response = $this->reschedule( (string) $booking['uuid'], array( 'token' => $booking['manage_token'], 'start_utc' => $this->sql( 1, '14:00' ) ) );

		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertSame( $booking['uuid'], $data['uuid'] );
		self::assertSame( $this->sql( 1, '14:00' ), $data['items'][0]['start_utc'] );
	}

	/**
	 * The brief's second test, and the one that matters most: a wrong token on a REAL booking and any
	 * token on a booking that does NOT exist must be indistinguishable - same status, same error code,
	 * same message - or an anonymous caller could enumerate real booking ids by probing tokens
	 * against them one at a time.
	 */
	public function test_rejects_a_wrong_token_without_revealing_whether_the_uuid_exists(): void {
		$booking = $this->hold( $this->sql( 1, '11:15' ), $this->staffA );

		$realUuidBadToken = $this->reschedule( (string) $booking['uuid'], array( 'token' => 'wrong-token', 'start_utc' => $this->sql( 1, '15:00' ) ) );
		$fakeUuidAnyToken = $this->reschedule( '00000000-0000-4000-8000-000000000000', array( 'token' => 'wrong-token', 'start_utc' => $this->sql( 1, '15:00' ) ) );

		self::assertSame( $realUuidBadToken->get_status(), $fakeUuidAnyToken->get_status() );
		self::assertSame( $realUuidBadToken->get_data()['code'], $fakeUuidAnyToken->get_data()['code'] );
		self::assertSame( $realUuidBadToken->get_data()['message'], $fakeUuidAnyToken->get_data()['message'] );
		// Pinned to the actual answer, not just "the two match" - a pair of 200s would match too.
		self::assertSame( 403, $realUuidBadToken->get_status() );
		self::assertSame( 'reservant_forbidden', $realUuidBadToken->get_data()['code'] );
	}

	public function test_rejects_a_missing_token_for_a_guest(): void {
		$booking = $this->hold( $this->sql( 1, '11:30' ), $this->staffA );

		$response = $this->reschedule( (string) $booking['uuid'], array( 'start_utc' => $this->sql( 1, '15:00' ) ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'reservant_forbidden', $response->get_data()['code'] );
	}

	public function test_manager_capability_can_move_without_a_token(): void {
		$booking = $this->hold( $this->sql( 1, '11:45' ), $this->staffA );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->reschedule( (string) $booking['uuid'], array( 'start_utc' => $this->sql( 1, '15:30' ) ) );

		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( $this->sql( 1, '15:30' ), $response->get_data()['items'][0]['start_utc'] );
	}

	public function test_conflict_answers_409_with_the_engine_reason(): void {
		$this->hold( $this->sql( 1, '16:00' ), $this->staffA );
		$mine = $this->hold( $this->sql( 1, '12:00' ), $this->staffA );

		$response = $this->reschedule( (string) $mine['uuid'], array( 'token' => $mine['manage_token'], 'start_utc' => $this->sql( 1, '16:00' ) ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'overlap', $response->get_data()['message'] );
	}

	public function test_requires_exactly_one_of_start_utc_or_occurrence_id(): void {
		$booking = $this->hold( $this->sql( 1, '12:15' ), $this->staffA );

		$neither = $this->reschedule( (string) $booking['uuid'], array( 'token' => $booking['manage_token'] ) );
		self::assertSame( 400, $neither->get_status() );

		$both = $this->reschedule(
			(string) $booking['uuid'],
			array( 'token' => $booking['manage_token'], 'start_utc' => $this->sql( 1, '15:00' ), 'occurrence_id' => 1 )
		);
		self::assertSame( 400, $both->get_status() );
	}

	/**
	 * The route-level proof of the Task 4 ruling: a guest's move is held to the service's reschedule
	 * window and answers 403 (`window_closed`, through `Errors::failure()`), not 409 - the same status
	 * `CancelBooking`'s equivalent refusal already answers, on purpose (Task 5 brief). A manager's
	 * capability is the only way past it; no `force` flag is ever read from the request.
	 */
	public function test_guest_reschedule_is_policy_bound_and_forced_for_managers(): void {
		global $wpdb;
		$lateId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Bridal', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'reschedule_window_hours' => 8760 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $lateId, $this->staffA );

		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 2, '09:00' ), 'segments' => array( array( 'service_id' => $lateId, 'resource_id' => $this->staffA ) ) ),
			)
		);
		/** @var array<string, mixed> $booking */
		$booking = rest_do_request( $request )->get_data();

		$guest = $this->reschedule( (string) $booking['uuid'], array( 'token' => $booking['manage_token'], 'start_utc' => $this->sql( 2, '11:00' ) ) );
		self::assertSame( 403, $guest->get_status() );
		self::assertSame( 'window_closed', $guest->get_data()['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// No token: the capability is the credential, and it is also the override.
		$manager = $this->reschedule( (string) $booking['uuid'], array( 'start_utc' => $this->sql( 2, '11:00' ) ) );
		self::assertSame( 200, $manager->get_status(), (string) wp_json_encode( $manager->get_data() ) );
		self::assertSame( $this->sql( 2, '11:00' ), $manager->get_data()['items'][0]['start_utc'] );
	}

	/** The `occurrence_id` branch, not merely the appointment one - a distinct plan()/lock path. */
	public function test_guest_can_move_an_event_booking_to_another_occurrence(): void {
		global $wpdb;
		$eventId     = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' )
		);
		$occurrences = new OccurrenceRepository( $wpdb );
		$occA        = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 10, '18:00' ), 'end_utc' => $this->sql( 10, '20:00' ), 'capacity' => 3 ) );
		$occB        = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 11, '18:00' ), 'end_utc' => $this->sql( 11, '20:00' ), 'capacity' => 3 ) );

		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer' => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'event'    => array( 'occurrence_id' => $occA, 'seats' => 2 ),
			)
		);
		/** @var array<string, mixed> $booking */
		$booking = rest_do_request( $request )->get_data();

		$response = $this->reschedule( (string) $booking['uuid'], array( 'token' => $booking['manage_token'], 'occurrence_id' => $occB ) );

		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( $this->sql( 11, '18:00' ), $response->get_data()['items'][0]['start_utc'] );
		self::assertSame( $occB, $response->get_data()['items'][0]['occurrence_id'] );
	}
}
