<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Cli\FixtureCommand;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Rest\Errors;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The public API surface (AGENTS.md section 5). Requests go through `rest_do_request()` so the registered
 * routes, their `args` schemas and their permission callbacks are all exercised - not just the
 * controller methods.
 */
final class RestApiTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up(); // Clears the shared rate-limiter bucket too (ReservantTestCase::set_up()).
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

	/** @return array<string, mixed> */
	private function holdRequestBody( string $startUtc, ?int $resourceId = null ): array {
		$segment = array( 'service_id' => $this->cutId );
		if ( null !== $resourceId ) {
			$segment['resource_id'] = $resourceId;
		}
		return array(
			'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
			'appointment' => array( 'start_utc' => $startUtc, 'segments' => array( $segment ) ),
		);
	}

	/** @param array<string, mixed> $body */
	private function postHold( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	/** @return array<string, mixed> the 201 payload */
	private function hold( string $startUtc, ?int $resourceId = null ): array {
		$response = $this->postHold( $this->holdRequestBody( $startUtc, $resourceId ) );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	// ---------------------------------------------------------------- availability

	public function test_availability_returns_starts(): void {
		$response = $this->request(
			'GET',
			'/reservant/v1/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $this->cutId ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertNotEmpty( $data['starts'] );
		self::assertSame( 5, $data['granularity_min'] );
		self::assertSame( $this->sql( 1, '09:00' ), $data['starts'][0]['utc'] );
		self::assertArrayHasKey( 'local', $data['starts'][0] );
	}

	public function test_availability_rejects_a_window_it_cannot_serve(): void {
		$bad = $this->request(
			'GET',
			'/reservant/v1/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $this->cutId ) ) ),
				'from'  => $this->utc( 2 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 1 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 400, $bad->get_status() );

		$missing = $this->request(
			'GET',
			'/reservant/v1/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => 999999 ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 404, $missing->get_status() );
	}

	public function test_availability_for_an_event_service_returns_occurrences(): void {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert( array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' ) );
		$occId   = ( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 10, '18:00' ), 'end_utc' => $this->sql( 10, '20:00' ), 'capacity' => 3 )
		);

		$body = array(
			'customer' => array( 'name' => 'M', 'email' => 'm@example.com' ),
			'event'    => array( 'occurrence_id' => $occId, 'seats' => 2 ),
		);
		self::assertSame( 201, $this->postHold( $body )->get_status() );

		$response = $this->request(
			'GET',
			'/reservant/v1/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $eventId ) ) ),
				'from'  => $this->utc( 10 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 11 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertCount( 1, $data['occurrences'] );
		self::assertSame( $occId, $data['occurrences'][0]['id'] );
		self::assertSame( 1, $data['occurrences'][0]['remaining'] ); // 3 - 2 held.
	}

	public function test_service_endpoint_is_public_and_hides_internal_columns(): void {
		$response = $this->request( 'GET', "/reservant/v1/services/{$this->cutId}" );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'Cut', $data['name'] );
		self::assertSame( 2000, $data['price_minor'] );
		self::assertArrayNotHasKey( 'wc_product_id', $data );

		self::assertSame( 404, $this->request( 'GET', '/reservant/v1/services/999999' )->get_status() );
	}

	// ---------------------------------------------------------------- holds + lifecycle

	public function test_hold_then_confirm_via_rest(): void {
		$hold = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$hold->set_body_params(
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 1, '09:00' ), 'segments' => array( array( 'service_id' => $this->cutId ) ) ),
			)
		);
		$created = rest_do_request( $hold );
		self::assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		self::assertArrayNotHasKey( 'manage_token_hash', $data );
		self::assertNotEmpty( $data['manage_token'] );
		// The one response that ever carries the guest's credential must not be cached anywhere.
		self::assertSame( 'no-store', $created->get_headers()['Cache-Control'] );

		$confirm = new \WP_REST_Request( 'POST', "/reservant/v1/bookings/{$data['uuid']}/confirm" );
		$confirm->set_param( 'token', $data['manage_token'] );
		$confirmed = rest_do_request( $confirm );
		self::assertSame( 200, $confirmed->get_status() );
		self::assertSame( 'confirmed', $confirmed->get_data()['status'] );
	}

	public function test_conflict_returns_409_with_segment(): void {
		$body = $this->holdRequestBody( $this->sql( 1, '10:00' ), $this->staffA );
		self::assertSame( 201, $this->postHold( $body )->get_status() );
		$second = $this->postHold( $body );
		self::assertSame( 409, $second->get_status() );
		$data = $second->get_data();
		self::assertSame( 'reservant_conflict', $data['code'] );
		self::assertSame( 'overlap', $data['message'] );
		self::assertSame( 0, $data['data']['segment'] );
	}

	public function test_a_malformed_body_is_a_400(): void {
		self::assertSame( 400, $this->postHold( array( 'customer' => array( 'name' => 'M', 'email' => 'm@example.com' ) ) )->get_status() );
		self::assertSame(
			400,
			$this->postHold(
				array(
					'customer'    => array( 'name' => '', 'email' => 'not-an-email' ),
					'appointment' => array( 'start_utc' => $this->sql( 1, '09:00' ), 'segments' => array( array( 'service_id' => $this->cutId ) ) ),
				)
			)->get_status()
		);
		self::assertSame(
			400,
			$this->postHold(
				array(
					'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
					'appointment' => array( 'start_utc' => 'tomorrow-ish', 'segments' => array( array( 'service_id' => $this->cutId ) ) ),
				)
			)->get_status()
		);
		// A nested array where a string belongs must not cast to the literal "Array".
		self::assertSame(
			400,
			$this->postHold(
				array(
					'customer'    => array( 'name' => array( 'first' => 'M' ), 'email' => 'm@example.com' ),
					'appointment' => array( 'start_utc' => $this->sql( 1, '09:00' ), 'segments' => array( array( 'service_id' => $this->cutId ) ) ),
				)
			)->get_status()
		);
	}

	/**
	 * `(int) array( 'a' => 1 )` is 1, so a bare `absint()` would turn an object into service id 1
	 * and cheerfully book it. Ids must be plainly integral or the request is refused.
	 */
	public function test_non_scalar_ids_are_refused_rather_than_coerced(): void {
		$body = static fn ( mixed $serviceId ): array => array(
			'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
			'appointment' => array( 'start_utc' => '2099-06-01 09:00:00', 'segments' => array( array( 'service_id' => $serviceId ) ) ),
		);
		foreach ( array( array( 'a' => 1 ), array( 1 ), true, '1abc', 0, -1 ) as $hostile ) {
			self::assertSame( 400, $this->postHold( $body( $hostile ) )->get_status(), 'service_id: ' . wp_json_encode( $hostile ) );
		}

		// A pinned resource that cannot be read must fail, not silently become "any staff".
		self::assertSame(
			400,
			$this->postHold(
				array(
					'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
					'appointment' => array(
						'start_utc' => $this->sql( 1, '09:00' ),
						'segments'  => array( array( 'service_id' => $this->cutId, 'resource_id' => array( $this->staffA ) ) ),
					),
				)
			)->get_status()
		);

		// Same rule on the read side.
		self::assertSame(
			400,
			$this->request(
				'GET',
				'/reservant/v1/availability',
				array(
					'items' => wp_json_encode( array( array( 'service_id' => array( 'a' => $this->cutId ) ) ) ),
					'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
					'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
				)
			)->get_status()
		);
	}

	/**
	 * `RuntimeException` is also how the repositories report a failed write, carrying
	 * `$wpdb->last_error`. An anonymous caller must never be handed the database's own error text.
	 */
	public function test_unknown_runtime_failures_are_opaque_500s(): void {
		$leaky = "booking_insert_failed: Table 'wp.wp_reservant_bookings' doesn't exist";
		$error = Errors::failure( new \RuntimeException( $leaky ) );

		self::assertSame( 500, $error->get_error_data()['status'] );
		self::assertSame( 'reservant_error', $error->get_error_code() );
		$serialized = (string) wp_json_encode( array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ) );
		self::assertStringNotContainsString( 'Table', $serialized );
		self::assertStringNotContainsString( 'booking_insert_failed', $serialized );
		self::assertStringNotContainsString( 'reservant_bookings', $serialized );

		// The known reasons still speak plainly, or the mapping would be useless.
		$known = Errors::failure( new \RuntimeException( 'window_closed' ) );
		self::assertSame( 403, $known->get_error_data()['status'] );
		self::assertSame( 'window_closed', $known->get_error_message() );
	}

	/**
	 * Lock-guard repair wave 3, item 4: `lock_unavailable` means the database refused a write or a
	 * locking read the code expected to work - an operational event a site operator needs to see, not a
	 * customer mistake. Before this, only the unknown-reason (500) branch below fired `reservant/error`,
	 * so the seat-map write path traded a logged 500 (`update_conflict`) for a silent 409 the moment it
	 * was given this reason instead.
	 */
	public function test_lock_unavailable_fires_the_error_action_and_still_answers_409(): void {
		$fired          = array();
		$lockListener   = static function ( \Throwable $e ) use ( &$fired ): void {
			$fired[] = $e;
		};
		add_action( 'reservant/error', $lockListener );
		try {
			$error = Errors::failure( new \RuntimeException( 'lock_unavailable' ) );
		} finally {
			remove_action( 'reservant/error', $lockListener );
		}

		self::assertCount( 1, $fired, 'a lock_unavailable refusal is an infrastructure event and must be logged' );
		self::assertSame( 'lock_unavailable', $fired[0]->getMessage() );
		self::assertSame( 409, $error->get_error_data()['status'], 'the retry signal must survive being logged' );
		self::assertSame( 'lock_unavailable', $error->get_error_message() );

		// No other known reason gets this treatment - logging every ordinary refusal would just be noise.
		$benign           = array();
		$benignListener   = static function ( \Throwable $e ) use ( &$benign ): void {
			$benign[] = $e;
		};
		add_action( 'reservant/error', $benignListener );
		try {
			Errors::failure( new \RuntimeException( 'window_closed' ) );
		} finally {
			remove_action( 'reservant/error', $benignListener );
		}
		self::assertCount( 0, $benign, 'an ordinary known refusal must not be logged as an infrastructure event' );
	}

	public function test_bad_token_is_403_and_missing_uuid_404(): void {
		$created = $this->hold( $this->sql( 1, '11:00' ) );

		$bad = new \WP_REST_Request( 'GET', "/reservant/v1/bookings/{$created['uuid']}" );
		$bad->set_param( 'token', 'wrong-token' );
		self::assertSame( 403, rest_do_request( $bad )->get_status() );

		$missing = new \WP_REST_Request( 'GET', '/reservant/v1/bookings/00000000-0000-4000-8000-000000000000' );
		$missing->set_param( 'token', $created['manage_token'] );
		self::assertSame( 404, rest_do_request( $missing )->get_status() );
	}

	/** A token is a credential for ONE booking: valid elsewhere is still 403 here. */
	public function test_a_valid_token_does_not_open_another_booking(): void {
		$first  = $this->hold( $this->sql( 1, '11:00' ), $this->staffA );
		$second = $this->hold( $this->sql( 1, '11:00' ), $this->staffB );
		self::assertNotSame( $first['uuid'], $second['uuid'] );

		$replay = $this->request( 'GET', "/reservant/v1/bookings/{$second['uuid']}", array( 'token' => $first['manage_token'] ) );
		self::assertSame( 403, $replay->get_status() );

		// Its own token still works, so the 403 is about ownership and not a broken guard.
		self::assertSame( 200, $this->request( 'GET', "/reservant/v1/bookings/{$second['uuid']}", array( 'token' => $second['manage_token'] ) )->get_status() );
		// And the same replay is refused on the state-changing routes too.
		self::assertSame( 403, $this->request( 'POST', "/reservant/v1/bookings/{$second['uuid']}/confirm", array( 'token' => $first['manage_token'] ) )->get_status() );
		self::assertSame( 403, $this->request( 'DELETE', "/reservant/v1/holds/{$second['uuid']}", array( 'token' => $first['manage_token'] ) )->get_status() );
	}

	public function test_booking_payload_never_leaks_internal_columns(): void {
		$created  = $this->hold( $this->sql( 1, '11:30' ) );
		$response = $this->request( 'GET', "/reservant/v1/bookings/{$created['uuid']}", array( 'token' => $created['manage_token'] ) );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		self::assertArrayNotHasKey( 'manage_token_hash', $data );
		self::assertArrayNotHasKey( 'manage_token', $data ); // Returned by POST /holds only.
		self::assertArrayNotHasKey( 'id', $data );
		self::assertArrayNotHasKey( 'approved_by', $data );
		self::assertArrayNotHasKey( 'rejection_reason', $data );
		self::assertSame( $created['uuid'], $data['uuid'] );
		self::assertArrayNotHasKey( 'booking_id', $data['items'][0] );
		self::assertSame( $this->sql( 1, '11:30' ), $data['items'][0]['start_utc'] );
	}

	public function test_rate_limit_returns_429(): void {
		add_filter( 'reservant/holds/rate_limit', static fn (): int => 2 );
		try {
			self::assertSame( 201, $this->postHold( $this->holdRequestBody( $this->sql( 1, '12:00' ) ) )->get_status() );
			self::assertSame( 201, $this->postHold( $this->holdRequestBody( $this->sql( 1, '13:00' ) ) )->get_status() );
			$limited = $this->postHold( $this->holdRequestBody( $this->sql( 1, '14:00' ) ) );
			self::assertSame( 429, $limited->get_status() );
			// A throttled client is told when to come back rather than left to guess.
			self::assertSame( '60', $limited->get_headers()['Retry-After'] );
		} finally {
			remove_all_filters( 'reservant/holds/rate_limit' );
		}
	}

	public function test_delete_hold_releases_the_slot_but_refuses_a_confirmed_booking(): void {
		$held = $this->hold( $this->sql( 1, '15:00' ), $this->staffA );

		$released = $this->request( 'DELETE', "/reservant/v1/holds/{$held['uuid']}", array( 'token' => $held['manage_token'] ) );
		self::assertSame( 200, $released->get_status() );
		self::assertSame( 'cancelled', $released->get_data()['status'] );

		// The slot is takeable again, on the very same staff member.
		$again = $this->hold( $this->sql( 1, '15:00' ), $this->staffA );
		self::assertSame( 200, $this->request( 'POST', "/reservant/v1/bookings/{$again['uuid']}/confirm", array( 'token' => $again['manage_token'] ) )->get_status() );

		// Releasing a hold is not policy-bound; cancelling a confirmed booking is, so DELETE stops.
		$refused = $this->request( 'DELETE', "/reservant/v1/holds/{$again['uuid']}", array( 'token' => $again['manage_token'] ) );
		self::assertSame( 409, $refused->get_status() );
	}

	public function test_cancel_is_policy_bound_for_guests_and_forced_for_managers(): void {
		global $wpdb;
		// A year-long cancellation window makes every future booking "too late" - deterministic
		// whatever the wall clock says.
		$lateId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Bridal', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'cancel_window_hours' => 8760 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $lateId, $this->staffA );

		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 2, '09:00' ), 'segments' => array( array( 'service_id' => $lateId ) ) ),
			)
		);
		$created = rest_do_request( $request )->get_data();
		self::assertSame( 200, $this->request( 'POST', "/reservant/v1/bookings/{$created['uuid']}/confirm", array( 'token' => $created['manage_token'] ) )->get_status() );

		$guest = $this->request( 'POST', "/reservant/v1/bookings/{$created['uuid']}/cancel", array( 'token' => $created['manage_token'] ) );
		self::assertSame( 403, $guest->get_status() );
		self::assertSame( 'window_closed', $guest->get_data()['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$manager = $this->request( 'POST', "/reservant/v1/bookings/{$created['uuid']}/cancel" ); // No token: the capability is the credential.
		self::assertSame( 200, $manager->get_status() );
		self::assertSame( 'cancelled', $manager->get_data()['status'] );
	}

	public function test_manage_capability_reads_a_booking_without_a_token(): void {
		$created = $this->hold( $this->sql( 1, '16:00' ) );

		self::assertSame( 403, $this->request( 'GET', "/reservant/v1/bookings/{$created['uuid']}" )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertTrue( current_user_can( 'reservant_manage_bookings' ) );
		self::assertSame( 200, $this->request( 'GET', "/reservant/v1/bookings/{$created['uuid']}" )->get_status() );
	}

	/**
	 * Lock-guard repair wave 3, item 6: `BookingRepository::findByUuid()` is now guarded for uniformity
	 * with the rest of the class - a DB-level failure refuses `lock_unavailable` instead of returning a
	 * misleading null. `show()`'s only statement was that null check, so without its own catch a busy
	 * read here would have escaped this REST callback as an uncaught exception instead of the clean 409
	 * every other guarded read on this codebase answers with.
	 *
	 * A manager (capability, no token) is used deliberately so the permission callback (`Routes::guard()`)
	 * takes its short-circuit branch and never itself calls `findByUuid()` - isolating the assertion to
	 * `show()`'s own guard, not `guard()`'s twin fix.
	 */
	public function test_show_answers_409_not_a_fatal_when_the_lookup_fails_at_the_db_level(): void {
		global $wpdb;
		$created = $this->hold( $this->sql( 1, '11:00' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The plain `findByUuid()` read, never the locking `FOR UPDATE` one - this request takes no lock.
		$sabotage = static function ( $query ) {
			$q = (string) $query;
			return ( str_contains( $q, 'reservant_bookings' ) && str_contains( $q, 'uuid' ) && ! str_contains( $q, 'FOR UPDATE' ) )
				? 'SELECT * FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			$response = $this->request( 'GET', "/reservant/v1/bookings/{$created['uuid']}" );
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}

		self::assertSame( 409, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 'lock_unavailable', $response->get_data()['message'] );
	}

	// ---------------------------------------------------------------- seats

	public function test_occurrence_seats_lists_the_grid_and_its_claims(): void {
		global $wpdb;
		$seatMaps = new SeatMapRepository( $wpdb );
		$spec     = 'rows A-B, 4 per row';
		$mapId    = $seatMaps->insert( 'Hall', $spec );
		$seatIds  = $seatMaps->insertSeats( $mapId, SeatMapSpec::parse( $spec )->seats() );

		$eventId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'GridShow', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite', 'seat_map_id' => $mapId, 'capacity' => 8 )
		);
		$occId   = ( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 10, '18:00' ), 'end_utc' => $this->sql( 10, '20:00' ), 'capacity' => 8 )
		);

		$body = array(
			'customer' => array( 'name' => 'M', 'email' => 'm@example.com' ),
			'event'    => array( 'occurrence_id' => $occId, 'seats' => 1, 'seat_ids' => array( $seatIds[0] ) ),
		);
		self::assertSame( 201, $this->postHold( $body )->get_status() );

		$response = $this->request( 'GET', "/reservant/v1/occurrences/{$occId}/seats" );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertCount( 8, $data['seats'] );
		self::assertSame( 'A1', $data['seats'][0]['seat_label'] );
		self::assertSame( array( $seatIds[0] ), $data['claimed'] );

		self::assertSame( 404, $this->request( 'GET', '/reservant/v1/occurrences/999999/seats' )->get_status() );
	}

	// ---------------------------------------------------------------- fixture CLI

	public function test_fixture_command_is_idempotent(): void {
		global $wpdb;
		$first  = FixtureCommand::ensure( $wpdb );
		$second = FixtureCommand::ensure( $wpdb );

		self::assertSame( $first, $second );
		foreach ( array( 'cut', 'colour', 'staff_a', 'staff_b', 'seminar_occ', 'grid_occ' ) as $key ) {
			self::assertArrayHasKey( $key, $first );
			self::assertGreaterThan( 0, $first[ $key ] );
		}
		self::assertCount( 8, $first['grid_seats'] );
		// Re-running created no second copy of anything: this class's own set_up seeded two staff,
		// the fixture adds exactly two more, and the second call adds none.
		self::assertSame( '4', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_resources" ) ); // phpcs:ignore
		self::assertSame( '8', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_seats" ) ); // phpcs:ignore
	}
}
