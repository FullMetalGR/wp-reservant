<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Licensing\LicenseManager;
use Reservant\Licensing\LocalKeyLicense;
use Reservant\Licensing\Providers;
use Reservant\Tests\Integration\Licensing\ExplodingLicenseManager;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * What a lapsed license actually costs a site, and - far more important - what it does not.
 *
 * The policy (AGENTS.md section 5, "License enforcement") is deliberately narrow: an unlicensed
 * site loses configuration WRITES and nothing else. It keeps every read, the whole public booking
 * surface, and the entire admin booking lifecycle. The last of those is the one worth a test of its
 * own rather than a line in a matrix: `awaiting_approval` bookings sit on a TTL that `ExpireHolds`
 * reclaims, so a frozen approval queue would not merely inconvenience the owner - real customers
 * would be turned away by somebody's unpaid invoice, silently, while the owner watched. This class
 * asserts that for real, on real bookings, rather than trusting the route table.
 *
 * The other half is the way back. An unlicensed site reaches the refusal on every configuration
 * write, so the routes that clear it - `GET|POST|DELETE /admin/license` - and the settings READ
 * that renders them must both keep working while unlicensed, or the enforcement is not strict, it
 * is terminal.
 */
final class LicenseEnforcementTest extends ReservantTestCase {

	/** Not the built-in key, and it does not need to be: the dev-mode stub accepts any non-empty one. */
	private const KEY = 'RSVT-0000-1111-2222';

	private const WRITE_VERBS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	private int $cutId;
	private int $approvalServiceId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		Capabilities::sync();

		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->cutId             = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->approvalServiceId = $services->insert( array( 'name' => 'Consult', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'free', 'requires_approval' => 1, 'approval_hold_hours' => 48 ) );
		$this->staffA            = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB            = $resources->insert( array( 'name' => 'Bella' ) );
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			$resources->linkService( $this->cutId, $staff );
			$resources->linkService( $this->approvalServiceId, $staff );
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	// ---------------------------------------------------------------- session helpers

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/** manage_bookings + approve_bookings, deliberately without manage_settings. */
	private function asFrontDesk(): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		$user->add_cap( 'reservant_manage_bookings' );
		$user->add_cap( 'reservant_approve_bookings' );
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
	private function send( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	private function json( \WP_REST_Response $response ): string {
		return (string) wp_json_encode( $response->get_data() );
	}

	// ---------------------------------------------------------------- license helpers

	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/** An active license bound to this site, written through the real state machine. */
	private function licensed(): void {
		( new LocalKeyLicense( true ) )->activate( self::KEY, $this->now() );
	}

	/**
	 * A previously-good license whose re-checks have started failing: `Grace`, and `Grace` is
	 * active. Activating with the accepting stub and re-checking with the refusing one is exactly
	 * what an outage looks like from inside this plugin.
	 */
	private function inGrace(): void {
		$this->licensed();
		( new LocalKeyLicense( false ) )->revalidate( $this->now() );
		self::assertSame( 'grace', ( new LocalKeyLicense( false ) )->status( $this->now() )->state->value );
	}

	/** Installs a third-party manager through the documented seam, not a back door. */
	private function useManager( LicenseManager $manager ): void {
		Providers::reset();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => $manager, 10, 1 );
	}

	/** @return array<string, mixed> the /admin/services POST body a licensed site would send */
	private function newServiceBody(): array {
		return array( 'name' => 'Colour', 'type' => 'appointment', 'duration_min' => 45, 'payment_mode' => 'onsite' );
	}

	/** A held customer booking on the approval-required service - the one an owner has to decide. */
	private function awaitingApproval( string $startUtc ): string {
		$this->asAnonymous();
		$response = $this->send(
			'POST',
			'/reservant/v1/holds',
			array(
				'customer'    => array( 'name' => 'Customer', 'email' => 'customer@example.com' ),
				'appointment' => array( 'start_utc' => $startUtc, 'segments' => array( array( 'service_id' => $this->approvalServiceId, 'resource_id' => $this->staffA ) ) ),
			)
		);
		self::assertSame( 201, $response->get_status(), $this->json( $response ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		self::assertSame( 'awaiting_approval', $data['status'] );
		return (string) $data['uuid'];
	}

	// ---------------------------------------------------------------- the gate, structurally

	/**
	 * Read off the registered routes rather than off a list in this file.
	 *
	 * A hand-written matrix of "these seventeen writes are gated" is a matrix that goes stale the
	 * first time somebody adds an eighteenth route, and goes stale silently - the new endpoint would
	 * simply not be enforced, and no test would say so. So this walks the live route table the way
	 * `BookingPayloadTest` walks the live schema: every write verb on the admin namespace must be on
	 * `configureSite`, and the exemptions have to be named here to be exempt.
	 */
	public function test_every_configuration_write_on_the_admin_namespace_is_gated_on_the_license(): void {
		$ungated = array();
		foreach ( self::adminWriteHandlers() as $route => $methods ) {
			if ( str_starts_with( $route, '/reservant/v1/admin/bookings' ) || '/reservant/v1/admin/license' === $route ) {
				continue; // The booking lifecycle and the way back - exempt on purpose, asserted below.
			}
			foreach ( $methods as $method => $guard ) {
				if ( 'configureSite' !== $guard ) {
					$ungated[] = $method . ' ' . $route . ' (' . $guard . ')';
				}
			}
		}

		self::assertSame( array(), $ungated, 'Unlicensed configuration writes: ' . implode( ', ', $ungated ) );
	}

	/**
	 * The other direction, and the one that matters more: a READ must never be license-gated.
	 *
	 * `GET /admin/settings` is the screen an owner opens to type the key that makes their license
	 * active again. Gate it on an active license and a lapsed site can never be repaired from
	 * inside wp-admin - not a stricter policy, an unrecoverable one. The same callback answers the
	 * settings read and the settings write, so the two are exactly one careless edit apart.
	 */
	public function test_no_read_anywhere_on_the_admin_namespace_is_gated_on_the_license(): void {
		$gatedReads = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/reservant/v1/admin' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['methods']['GET'] ) ) {
					continue;
				}
				if ( 'configureSite' === self::guardName( $handler ) ) {
					$gatedReads[] = $route;
				}
			}
		}

		self::assertSame( array(), $gatedReads, 'These reads would lock a lapsed owner out: ' . implode( ', ', $gatedReads ) );
	}

	/**
	 * Every write verb registered under `/reservant/v1/admin`, as `route => [ method => guard ]`.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function adminWriteHandlers(): array {
		$found = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/reservant/v1/admin' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( self::WRITE_VERBS as $verb ) {
					if ( ! empty( $handler['methods'][ $verb ] ) ) {
						$found[ $route ][ $verb ] = self::guardName( $handler );
					}
				}
			}
		}
		self::assertNotEmpty( $found, 'the admin namespace must be registered before this can mean anything' );
		return $found;
	}

	/** @param array<string, mixed> $handler */
	private static function guardName( array $handler ): string {
		$callback = $handler['permission_callback'] ?? null;
		return is_array( $callback ) && isset( $callback[1] ) && is_string( $callback[1] ) ? $callback[1] : 'unknown';
	}

	// ---------------------------------------------------------------- the gate, behaviourally

	public function test_an_unlicensed_site_is_refused_a_configuration_write_and_told_where_to_fix_it(): void {
		$this->asAdmin();

		$refused = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );

		self::assertSame( 403, $refused->get_status(), $this->json( $refused ) );
		/** @var array<string, mixed> $body */
		$body = $refused->get_data();
		self::assertSame( 'reservant_license_required', $body['code'] );
		self::assertSame( 'license_required', $body['message'] ); // Clients switch on this.
		/** @var array<string, mixed> $data */
		$data = $body['data'];
		self::assertSame( 'inactive', $data['state'] );

		// The sentence has to name the remedy and the place, or a 403 is just a locked door.
		$detail = (string) $data['detail'];
		self::assertStringContainsString( 'license key', $detail );
		self::assertStringContainsString( 'Settings', $detail );
		// And it has to say the thing an owner most needs to hear before they panic.
		self::assertStringContainsString( 'Bookings are unaffected', $detail );
	}

	public function test_an_unlicensed_site_is_refused_every_configuration_write_it_can_attempt(): void {
		$this->asAdmin();

		$writes = array(
			array( 'POST', '/reservant/v1/admin/services' ),
			array( 'PUT', '/reservant/v1/admin/services/' . $this->cutId ),
			array( 'DELETE', '/reservant/v1/admin/services/' . $this->cutId ),
			array( 'POST', '/reservant/v1/admin/resources' ),
			array( 'PUT', '/reservant/v1/admin/resources/' . $this->staffA ),
			array( 'DELETE', '/reservant/v1/admin/resources/' . $this->staffA ),
			array( 'POST', '/reservant/v1/admin/resources/' . $this->staffA . '/exceptions' ),
			array( 'DELETE', '/reservant/v1/admin/resources/' . $this->staffA . '/exceptions' ),
			array( 'POST', '/reservant/v1/admin/exceptions' ),
			array( 'DELETE', '/reservant/v1/admin/exceptions' ),
			array( 'POST', '/reservant/v1/admin/occurrences' ),
			array( 'PUT', '/reservant/v1/admin/occurrences/1' ),
			array( 'DELETE', '/reservant/v1/admin/occurrences/1' ),
			array( 'POST', '/reservant/v1/admin/seat-maps' ),
			array( 'PUT', '/reservant/v1/admin/seat-maps/1' ),
			array( 'DELETE', '/reservant/v1/admin/seat-maps/1' ),
			array( 'PUT', '/reservant/v1/admin/settings' ),
		);

		foreach ( $writes as list( $method, $route ) ) {
			$response = $this->send( $method, $route, array( 'name' => 'Anything' ) );
			$label    = $method . ' ' . $route;
			self::assertSame( 403, $response->get_status(), $label . ': ' . $this->json( $response ) );
			/** @var array<string, mixed> $body */
			$body = $response->get_data();
			self::assertSame( 'reservant_license_required', $body['code'], $label );
		}
	}

	public function test_a_licensed_site_is_refused_none_of_them(): void {
		$this->licensed();
		$this->asAdmin();

		$created = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );
		self::assertSame( 201, $created->get_status(), $this->json( $created ) );

		$settings = $this->send( 'PUT', '/reservant/v1/admin/settings', array( 'currency' => 'USD' ) );
		self::assertSame( 200, $settings->get_status(), $this->json( $settings ) );
	}

	/**
	 * Grace is active, and that is the whole reason the state exists (`LicenseState::isActive()`).
	 * A site whose validator host had a DNS blip is not an unlicensed site, and a guard that
	 * assembled its own list of acceptable states is exactly how it would become one.
	 */
	public function test_a_license_inside_its_grace_window_is_refused_nothing(): void {
		$this->inGrace();
		$this->asAdmin();

		$created = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );
		self::assertSame( 201, $created->get_status(), $this->json( $created ) );

		$settings = $this->send( 'PUT', '/reservant/v1/admin/settings', array( 'currency' => 'USD' ) );
		self::assertSame( 200, $settings->get_status(), $this->json( $settings ) );

		$deleted = $this->send( 'DELETE', '/reservant/v1/admin/services/' . $this->cutId );
		self::assertNotSame( 403, $deleted->get_status(), $this->json( $deleted ) );
	}

	/**
	 * A staging clone carrying production's option row. The refusal says "activate it for THIS
	 * site", which is a different instruction from "enter a key" - collapsing the two would leave
	 * an owner re-pasting a key that was never going to work here.
	 */
	public function test_a_license_bound_to_another_domain_freezes_configuration_and_names_that_reason(): void {
		$this->licensed();
		add_filter( 'home_url', static fn (): string => 'https://someone-elses-site.example.org', 10, 1 );
		$this->asAdmin();

		$refused = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );

		self::assertSame( 403, $refused->get_status(), $this->json( $refused ) );
		/** @var array<string, mixed> $body */
		$body = $refused->get_data();
		/** @var array<string, mixed> $data */
		$data = $body['data'];
		self::assertSame( 'domain_mismatch', $data['state'] );
		self::assertStringContainsString( 'different domain', (string) $data['detail'] );
		self::assertStringContainsString( 'this site', (string) $data['detail'] );
	}

	/** The capability is asked first, so a stranger learns nothing about this site's billing. */
	public function test_a_caller_without_the_capability_is_told_that_and_not_the_licensing_state(): void {
		$this->asSubscriber();
		$refused = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );
		self::assertSame( 403, $refused->get_status() );
		/** @var array<string, mixed> $body */
		$body = $refused->get_data();
		self::assertSame( 'reservant_forbidden', $body['code'] );

		$this->asAnonymous();
		self::assertSame( 401, $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() )->get_status() );
	}

	// ---------------------------------------------------------------- what never freezes

	public function test_an_unlicensed_site_can_still_read_its_settings_services_and_calendar(): void {
		$this->asAdmin();

		$reads = array(
			'/reservant/v1/admin/settings'                    => array(),
			'/reservant/v1/admin/services'                    => array(),
			'/reservant/v1/admin/services/' . $this->cutId    => array(),
			'/reservant/v1/admin/resources'                   => array(),
			'/reservant/v1/admin/resources/' . $this->staffA  => array(),
			'/reservant/v1/admin/exceptions'                  => array(),
			'/reservant/v1/admin/seat-maps'                   => array(),
			'/reservant/v1/admin/occurrences'                 => array( 'service_id' => $this->approvalServiceId ),
			'/reservant/v1/admin/bookings'                    => array(),
			'/reservant/v1/admin/calendar'                    => array(
				'from' => $this->utc( 0 )->format( 'Y-m-d' ),
				'to'   => $this->utc( 7 )->format( 'Y-m-d' ),
			),
		);

		foreach ( $reads as $route => $params ) {
			$response = $this->request( 'GET', $route, $params );
			self::assertSame( 200, $response->get_status(), $route . ': ' . $this->json( $response ) );
		}
	}

	/**
	 * The policy's whole point, asserted on real bookings rather than on the route table.
	 *
	 * `awaiting_approval` holds sit on a TTL and `ExpireHolds` reclaims them. If a lapsed license
	 * froze approvals, those bookings would expire on their own and paying customers would be
	 * turned away by a billing problem - a strictly worse outcome than an unlicensed site being
	 * unable to edit its service list, which is why the lifecycle is exempt and stays exempt.
	 */
	public function test_an_unlicensed_site_can_still_approve_reject_cancel_and_reschedule_a_booking(): void {
		$toApprove   = $this->awaitingApproval( $this->sql( 1, '09:00' ) );
		$toReject    = $this->awaitingApproval( $this->sql( 1, '10:00' ) );
		$toCancel    = $this->awaitingApproval( $this->sql( 1, '11:00' ) );
		$toReschedle = $this->awaitingApproval( $this->sql( 1, '12:00' ) );

		$this->asAdmin();

		$approved = $this->send( 'POST', "/reservant/v1/admin/bookings/{$toApprove}/approve" );
		self::assertSame( 200, $approved->get_status(), $this->json( $approved ) );
		self::assertSame( 'confirmed', $approved->get_data()['status'] );

		$rejected = $this->send( 'POST', "/reservant/v1/admin/bookings/{$toReject}/reject", array( 'reason' => 'Fully booked' ) );
		self::assertSame( 200, $rejected->get_status(), $this->json( $rejected ) );
		self::assertSame( 'rejected', $rejected->get_data()['status'] );

		$cancelled = $this->send( 'POST', "/reservant/v1/admin/bookings/{$toCancel}/cancel" );
		self::assertSame( 200, $cancelled->get_status(), $this->json( $cancelled ) );
		self::assertSame( 'cancelled', $cancelled->get_data()['status'] );

		$moved = $this->request( 'POST', "/reservant/v1/bookings/{$toReschedle}/reschedule", array( 'start_utc' => $this->sql( 2, '14:00' ) ) );
		self::assertSame( 200, $moved->get_status(), $this->json( $moved ) );

		// The manager's own booking - a walk-in taken at the desk - and the outcomes that close it.
		$manual = $this->send(
			'POST',
			'/reservant/v1/admin/bookings',
			array(
				'customer'    => array( 'name' => 'Walk-in', 'email' => 'walkin@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 1, '15:00' ), 'segments' => array( array( 'service_id' => $this->cutId, 'resource_id' => $this->staffB ) ) ),
			)
		);
		self::assertSame( 201, $manual->get_status(), $this->json( $manual ) );
		/** @var array<string, mixed> $created */
		$created = $manual->get_data();

		$noShow = $this->send( 'POST', "/reservant/v1/admin/bookings/{$created['uuid']}/no_show" );
		self::assertSame( 200, $noShow->get_status(), $this->json( $noShow ) );
		self::assertSame( 'no_show', $noShow->get_data()['status'] );
	}

	/**
	 * A billing lapse at the salon must never turn away the salon's customers, so the public
	 * surface is not gated at all - end to end, from an anonymous visitor's hold to their
	 * confirmation.
	 */
	public function test_an_unlicensed_site_can_still_take_a_public_booking_from_hold_to_confirmation(): void {
		$this->asAnonymous();

		$available = $this->request(
			'GET',
			'/reservant/v1/availability',
			array(
				'items' => (string) wp_json_encode( array( array( 'service_id' => $this->cutId ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		self::assertSame( 200, $available->get_status(), $this->json( $available ) );

		$held = $this->send(
			'POST',
			'/reservant/v1/holds',
			array(
				'customer'    => array( 'name' => 'Visitor', 'email' => 'visitor@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 1, '13:00' ), 'segments' => array( array( 'service_id' => $this->cutId ) ) ),
			)
		);
		self::assertSame( 201, $held->get_status(), $this->json( $held ) );
		/** @var array<string, mixed> $booking */
		$booking = $held->get_data();

		$confirmed = $this->request( 'POST', "/reservant/v1/bookings/{$booking['uuid']}/confirm", array( 'token' => $booking['manage_token'] ) );
		self::assertSame( 200, $confirmed->get_status(), $this->json( $confirmed ) );
		self::assertSame( 'confirmed', $confirmed->get_data()['status'] );

		// And the guest keeps their own self-service, which is the same promise from the other side.
		$cancelled = $this->request( 'POST', "/reservant/v1/bookings/{$booking['uuid']}/cancel", array( 'token' => $booking['manage_token'] ) );
		self::assertSame( 200, $cancelled->get_status(), $this->json( $cancelled ) );
	}

	// ---------------------------------------------------------------- the way back

	public function test_the_license_routes_answer_while_the_site_is_unlicensed(): void {
		$this->asAdmin();

		$status = $this->request( 'GET', '/reservant/v1/admin/license' );
		self::assertSame( 200, $status->get_status(), $this->json( $status ) );
		/** @var array<string, mixed> $body */
		$body = $status->get_data();
		self::assertSame( 'inactive', $body['state'] );
		self::assertFalse( $body['active'] );
		self::assertSame( '', $body['masked_key'] );
		self::assertNull( $body['last_checked_at'] );
		self::assertNull( $body['grace_ends_at'] );
	}

	public function test_a_key_activates_and_deactivates_through_the_rest_routes(): void {
		$this->useManager( new LocalKeyLicense( true ) );
		$this->asAdmin();

		$activated = $this->send( 'POST', '/reservant/v1/admin/license', array( 'key' => self::KEY ) );
		self::assertSame( 200, $activated->get_status(), $this->json( $activated ) );
		/** @var array<string, mixed> $body */
		$body = $activated->get_data();
		self::assertSame( 'active', $body['state'] );
		self::assertTrue( $body['active'] );
		self::assertSame( '********2222', $body['masked_key'] );
		self::assertSame( \Reservant\Licensing\SiteDomain::current(), $body['domain'] );
		self::assertIsString( $body['last_checked_at'] );

		// What activation reported is what a later read says - no caller writes and reads back.
		$read = $this->request( 'GET', '/reservant/v1/admin/license' );
		self::assertSame( $body, $read->get_data() );

		// And the site it just licensed can configure itself again, which is the point of the route.
		$created = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );
		self::assertSame( 201, $created->get_status(), $this->json( $created ) );

		$deactivated = $this->send( 'DELETE', '/reservant/v1/admin/license' );
		self::assertSame( 200, $deactivated->get_status(), $this->json( $deactivated ) );
		self::assertSame( 'inactive', $deactivated->get_data()['state'] );
		self::assertSame( '', $deactivated->get_data()['masked_key'] );

		// The freeze comes straight back, on the same request cycle.
		self::assertSame( 403, $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() )->get_status() );
	}

	/**
	 * A blank field posted by accident must not cost a site the license it paid for
	 * (`LicenseManager::activate()`), and the route inherits that rather than restating it.
	 */
	public function test_an_empty_key_posted_by_accident_changes_nothing(): void {
		$this->licensed();
		$this->asAdmin();

		$response = $this->send( 'POST', '/reservant/v1/admin/license', array( 'key' => '' ) );

		self::assertSame( 200, $response->get_status(), $this->json( $response ) );
		self::assertSame( 'active', $response->get_data()['state'] );
	}

	public function test_a_key_the_validator_refuses_is_reported_rather_than_thrown(): void {
		$this->useManager( new LocalKeyLicense( false ) );
		$this->asAdmin();

		$response = $this->send( 'POST', '/reservant/v1/admin/license', array( 'key' => 'NOT-A-REAL-KEY-9999' ) );

		self::assertSame( 200, $response->get_status(), $this->json( $response ) );
		self::assertSame( 'invalid', $response->get_data()['state'] );
		// The key is kept and shown back masked: "that is not the key I meant to paste" is the
		// commonest cause and the cheapest to spot (`LicenseRecord::rejected()`).
		self::assertSame( '********9999', $response->get_data()['masked_key'] );
	}

	/**
	 * The stored plaintext exists - a remote validator has to re-send it - but it is a credential
	 * and it never crosses the wire. `LicensePayload` takes a `LicenseStatus`, which carries only
	 * the masked form, so there is no field on the input that could leak one.
	 */
	public function test_no_license_response_ever_carries_the_key_that_was_submitted(): void {
		$this->useManager( new LocalKeyLicense( true ) );
		$this->asAdmin();

		$activated = $this->send( 'POST', '/reservant/v1/admin/license', array( 'key' => self::KEY ) );
		$read      = $this->request( 'GET', '/reservant/v1/admin/license' );

		foreach ( array( $activated, $read ) as $response ) {
			$json = $this->json( $response );
			self::assertStringNotContainsString( self::KEY, $json );
			self::assertStringContainsString( '********2222', $json, 'the masked form is what travels' );
		}
	}

	public function test_only_a_settings_admin_may_read_or_change_the_license(): void {
		$attempts = array(
			array( 'GET', array() ),
			array( 'POST', array( 'key' => self::KEY ) ),
			array( 'DELETE', array() ),
		);

		foreach ( $attempts as list( $method, $body ) ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->send( $method, '/reservant/v1/admin/license', $body )->get_status(), $method );

			$this->asSubscriber();
			self::assertSame( 403, $this->send( $method, '/reservant/v1/admin/license', $body )->get_status(), $method );

			// A front-desk role can take bookings all day and still not touch the site's billing.
			$this->asFrontDesk();
			self::assertSame( 403, $this->send( $method, '/reservant/v1/admin/license', $body )->get_status(), $method );

			$this->asAdmin();
			self::assertSame( 200, $this->send( $method, '/reservant/v1/admin/license', $body )->get_status(), $method );
		}
	}

	// ---------------------------------------------------------------- a third-party manager

	/**
	 * `reservant/license_manager` is a documented seam and whatever arrives through it is somebody
	 * else's code. A validator that throws is a fault, not a lapsed license - and refusing on a
	 * fault would freeze a paying site's configuration with no route left that could repair it. The
	 * same asymmetry the grace window is built on.
	 */
	public function test_a_license_manager_that_throws_does_not_freeze_the_owners_configuration(): void {
		$this->useManager( new ExplodingLicenseManager() );
		$this->asAdmin();

		$created = $this->send( 'POST', '/reservant/v1/admin/services', $this->newServiceBody() );

		self::assertSame( 201, $created->get_status(), $this->json( $created ) );
	}

	public function test_a_license_manager_that_throws_is_announced_and_answered_rather_than_fatal(): void {
		$reported = 0;
		add_action( 'reservant/error', function () use ( &$reported ): void {
			++$reported;
		}, 10, 1 );

		$this->useManager( new ExplodingLicenseManager() );
		$this->asAdmin();

		$response = $this->request( 'GET', '/reservant/v1/admin/license' );

		self::assertSame( 503, $response->get_status(), $this->json( $response ) );
		/** @var array<string, mixed> $body */
		$body = $response->get_data();
		self::assertSame( 'reservant_license_unavailable', $body['code'] );
		self::assertGreaterThan( 0, $reported, 'the fault must reach the diagnostics channel' );
	}
}
