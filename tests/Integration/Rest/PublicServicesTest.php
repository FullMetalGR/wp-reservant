<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `GET /services` (AGENTS.md section 5): the unauthenticated catalog the booking widget lists
 * from. Narrower than the admin catalog on purpose - see `ServicesController`. Seeded directly
 * through the repositories, the same way `RestApiTest` seeds the other public routes, rather than
 * through the (capability-gated) admin endpoints this route has nothing to do with.
 */
final class PublicServicesTest extends ReservantTestCase {

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** @return list<array<string, mixed>> */
	private function list(): array {
		/** @var list<array<string, mixed>> $data */
		$data = $this->request( 'GET', '/reservant/v1/services' )->get_data();
		return $data;
	}

	/** @param list<array<string, mixed>> $rows @return array<string, mixed> */
	private function byName( array $rows, string $name ): array {
		$row = current( array_filter( $rows, static fn ( array $r ): bool => $name === $r['name'] ) );
		self::assertIsArray( $row, "no row named {$name}" );
		return $row;
	}

	public function test_lists_only_active_services(): void {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );
		$services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$retiredId = $services->insert( array( 'name' => 'Retired', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$services->setStatus( $retiredId, 'inactive' );

		$response = $this->request( 'GET', '/reservant/v1/services' );
		self::assertSame( 200, $response->get_status() );
		$names = wp_list_pluck( $response->get_data(), 'name' );
		self::assertContains( 'Cut', $names );
		self::assertNotContains( 'Retired', $names );
	}

	public function test_embeds_performing_staff_by_id_and_name(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );

		$cutId  = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$alexId = $resources->insert( array( 'name' => 'Alex', 'email' => 'alex@example.com' ) );
		$resources->linkService( $cutId, $alexId );

		$cut = $this->byName( $this->list(), 'Cut' );
		self::assertSame( array( array( 'id' => $alexId, 'name' => 'Alex' ) ), $cut['resources'] );
	}

	/**
	 * Ambiguity resolved in the brief: a service nobody can currently perform still lists, with an
	 * empty `resources` array, rather than being hidden - a silently dropped catalog row is harder
	 * to debug than a widget that shows "no staff available" for one.
	 */
	public function test_service_with_no_resources_still_lists_with_an_empty_array(): void {
		global $wpdb;
		( new ServiceRepository( $wpdb ) )->insert( array( 'name' => 'Solo', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );

		$solo = $this->byName( $this->list(), 'Solo' );
		self::assertSame( array(), $solo['resources'] );
	}

	/** A jumping list is a worse widget bug than a slow one: order must not depend on insert order. */
	public function test_services_are_ordered_by_name_then_id(): void {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );
		$services->insert( array( 'name' => 'Zeta', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$services->insert( array( 'name' => 'Alpha', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$services->insert( array( 'name' => 'Mid', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );

		$names = array_column( $this->list(), 'name' );
		self::assertSame( array( 'Alpha', 'Mid', 'Zeta' ), $names );
	}

	/**
	 * Not paranoia: P4 shipped a response carrying staff contact details that a review had to
	 * strip. This is an unauthenticated endpoint, so nothing here gets a second chance.
	 */
	public function test_never_exposes_staff_contact_details(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );

		$cutId  = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$alexId = $resources->insert( array( 'name' => 'Alex', 'email' => 'alex@example.com' ) );
		$resources->linkService( $cutId, $alexId );

		$json = (string) wp_json_encode( $this->list() );
		foreach ( array( 'email', 'wp_user_id', 'phone', '@' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_is_readable_without_authentication(): void {
		wp_set_current_user( 0 );
		self::assertSame( 200, $this->request( 'GET', '/reservant/v1/services' )->get_status() );
	}
}
