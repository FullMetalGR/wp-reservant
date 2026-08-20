<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The composed "front desk" role the custom capabilities exist to make possible:
 * `reservant_manage_bookings` + `reservant_approve_bookings`, and NOT `reservant_manage_settings`.
 *
 * Every other integration test's "manager" is a full administrator holding all four caps, which is
 * why nothing caught this: the Calendar and Bookings screens are gated on manage_bookings, but the
 * staff and service lists they are built from were gated on manage_settings, so a front-desk user
 * saw both pages with a permanently empty staff filter, service filter and manual-booking drawer
 * while POST /admin/bookings was allowed for them.
 *
 * The two collection reads now accept either capability. These tests hold both halves in place: the
 * new 200s, and the fact that nothing else moved - every write, every single-item read and every
 * other admin route still needs manage_settings, and no capability below manage_bookings gained
 * anything at all.
 */
final class AdminCatalogCapabilityTest extends ReservantTestCase {

	private const SERVICES  = '/reservant/v1/admin/services';
	private const RESOURCES = '/reservant/v1/admin/resources';

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

	/** @param list<string> $caps */
	private function asUserWith( array $caps ): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $id );
		return $id;
	}

	/** manage_bookings + approve_bookings, deliberately without manage_settings. */
	private function asFrontDesk(): int {
		return $this->asUserWith( array( 'reservant_manage_bookings', 'reservant_approve_bookings' ) );
	}

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/** @param array<string, mixed> $params */
	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** @param array<string, mixed> $body */
	private function send( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	/** Seeds one service and one staff resource as an administrator, then drops the session. */
	private function seedCatalog(): void {
		$this->asAdmin();
		$service = $this->send(
			'POST',
			self::SERVICES,
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'payment_mode' => 'onsite',
			)
		);
		self::assertSame( 201, $service->get_status(), (string) wp_json_encode( $service->get_data() ) );

		$resource = $this->send( 'POST', self::RESOURCES, array( 'name' => 'Alex' ) );
		self::assertSame( 201, $resource->get_status(), (string) wp_json_encode( $resource->get_data() ) );

		wp_set_current_user( 0 );
	}

	// ---------------------------------------------------------------- the new 200s

	public function test_front_desk_can_read_the_staff_and_service_lists(): void {
		$this->seedCatalog();
		$this->asFrontDesk();

		$services = $this->get( self::SERVICES, array( 'include_inactive' => true ) );
		self::assertSame( 200, $services->get_status(), 'the Bookings service filter must have something to show' );
		self::assertCount( 1, $services->get_data()['services'] );
		self::assertSame( 'Cut', $services->get_data()['services'][0]['name'] );

		$resources = $this->get( self::RESOURCES, array( 'include_inactive' => true ) );
		self::assertSame( 200, $resources->get_status(), 'the Calendar staff filter must have something to show' );
		self::assertCount( 1, $resources->get_data()['resources'] );
		self::assertSame( 'Alex', $resources->get_data()['resources'][0]['name'] );
	}

	/** manage_bookings on its own is enough - approve_bookings is not what unlocked it. */
	public function test_manage_bookings_alone_is_sufficient(): void {
		$this->seedCatalog();
		$this->asUserWith( array( 'reservant_manage_bookings' ) );

		self::assertSame( 200, $this->get( self::SERVICES )->get_status() );
		self::assertSame( 200, $this->get( self::RESOURCES )->get_status() );
	}

	/** The settings admin who could already read them still can. */
	public function test_manage_settings_alone_is_still_sufficient(): void {
		$this->seedCatalog();
		$this->asUserWith( array( 'reservant_manage_settings' ) );

		self::assertSame( 200, $this->get( self::SERVICES )->get_status() );
		self::assertSame( 200, $this->get( self::RESOURCES )->get_status() );
	}

	// ---------------------------------------------------------------- the still-403s

	/**
	 * Reading the catalog must not imply changing it. Every one of these is refused for the same
	 * front-desk session that just read both lists.
	 */
	public function test_front_desk_cannot_write_to_the_catalog(): void {
		$this->asAdmin();
		$service  = $this->send(
			'POST',
			self::SERVICES,
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'payment_mode' => 'onsite',
			)
		)->get_data();
		$resource = $this->send( 'POST', self::RESOURCES, array( 'name' => 'Alex' ) )->get_data();

		$this->asFrontDesk();
		self::assertSame( 200, $this->get( self::SERVICES )->get_status(), 'precondition: the read is open' );

		$writes = array(
			array( 'POST', self::SERVICES ),
			array( 'PUT', self::SERVICES . '/' . $service['id'] ),
			array( 'DELETE', self::SERVICES . '/' . $service['id'] ),
			array( 'POST', self::RESOURCES ),
			array( 'PUT', self::RESOURCES . '/' . $resource['id'] ),
			array( 'DELETE', self::RESOURCES . '/' . $resource['id'] ),
			array( 'POST', self::RESOURCES . '/' . $resource['id'] . '/exceptions' ),
			array( 'DELETE', self::RESOURCES . '/' . $resource['id'] . '/exceptions' ),
			array( 'POST', '/reservant/v1/admin/exceptions' ),
			array( 'DELETE', '/reservant/v1/admin/exceptions' ),
			array( 'POST', '/reservant/v1/admin/occurrences' ),
			array( 'POST', '/reservant/v1/admin/seat-maps' ),
			array( 'PUT', '/reservant/v1/admin/settings' ),
		);
		foreach ( $writes as list( $method, $route ) ) {
			self::assertSame( 403, $this->send( $method, $route, array( 'name' => 'Nope' ) )->get_status(), "{$method} {$route}" );
		}
	}

	/** Only the two collection reads moved; the rest of the catalog surface did not. */
	public function test_front_desk_cannot_read_the_rest_of_the_catalog(): void {
		$this->asAdmin();
		$service  = $this->send(
			'POST',
			self::SERVICES,
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'payment_mode' => 'onsite',
			)
		)->get_data();
		$resource = $this->send( 'POST', self::RESOURCES, array( 'name' => 'Alex' ) )->get_data();

		$this->asFrontDesk();

		$reads = array(
			self::SERVICES . '/' . $service['id'],
			self::RESOURCES . '/' . $resource['id'],
			'/reservant/v1/admin/exceptions',
			'/reservant/v1/admin/occurrences',
			'/reservant/v1/admin/seat-maps',
			'/reservant/v1/admin/settings',
		);
		foreach ( $reads as $route ) {
			self::assertSame( 403, $this->get( $route, array( 'service_id' => $service['id'] ) )->get_status(), "GET {$route}" );
		}
	}

	/**
	 * The widening is to manage_bookings and nothing below it. `reservant_staff` carries
	 * view_own_calendar and approve_bookings, so it reaches the calendar but not the full catalog -
	 * a staff member limited to their own schedule has no business enumerating every colleague.
	 */
	public function test_the_staff_role_did_not_gain_the_catalog(): void {
		$this->seedCatalog();
		$id = self::factory()->user->create( array( 'role' => 'reservant_staff' ) );
		wp_set_current_user( $id );

		self::assertTrue( current_user_can( 'reservant_view_own_calendar' ), 'precondition: the role synced' );
		self::assertFalse( current_user_can( 'reservant_manage_bookings' ) );
		self::assertSame( 403, $this->get( self::SERVICES )->get_status() );
		self::assertSame( 403, $this->get( self::RESOURCES )->get_status() );
	}

	public function test_the_widened_reads_are_still_closed_to_everyone_else(): void {
		$this->seedCatalog();

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->get( self::SERVICES )->get_status(), 'no session is 401, not 403' );
		self::assertSame( 401, $this->get( self::RESOURCES )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertSame( 403, $this->get( self::SERVICES )->get_status() );
		self::assertSame( 403, $this->get( self::RESOURCES )->get_status() );
	}
}
