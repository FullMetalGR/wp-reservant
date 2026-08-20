<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `reservant/v1/admin/settings` (Task 13b, plan gap-filler - AGENTS.md interface table names
 * GET|PUT /admin/settings but no Task 1-17 brief ever claimed it; see the P4 ledger entry for Task
 * 13's implementer flagging the gap).
 *
 * GET returns `Reservant\Settings::make()->toArray()` verbatim. PUT forwards only the keys actually
 * present in the request body to `Settings::update()`, which validates the merged result and
 * persists it. Ledger obligation from Task 3: `Settings::update()` merges a partial with `??`, so a
 * key present but explicitly `null` would silently keep the old value rather than error - this
 * controller must reject that before it ever reaches `update()`, naming the offending key in the
 * 400 rather than letting it through.
 */
final class AdminSettingsTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::sync();
		// Every configuration WRITE on this namespace now needs an active license
		// (`Rest\Admin\AdminGuard::configureSite()`), and `ReservantTestCase::set_up()` starts every
		// test from "no license". This class is about the CAPABILITY matrix, not the licensing gate
		// - `LicenseEnforcementTest` owns that - so it says once, here, that its site is licensed,
		// exactly as a real one would have to be before its owner could edit a service.
		$this->licenseThisSite();
		// `reservant_settings` already starts clean every test - `ReservantTestCase::set_up()`.
	}

	// ---------------------------------------------------------------- auth helpers

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

	// ---------------------------------------------------------------- request helpers

	private function get(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/reservant/v1/admin/settings' ) );
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'PUT', '/reservant/v1/admin/settings' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	// ---------------------------------------------------------------- permission matrix

	public function test_permission_matrix(): void {
		$this->asAnonymous();
		self::assertSame( 401, $this->get()->get_status() );
		self::assertSame( 401, $this->put( array( 'currency' => 'USD' ) )->get_status() );

		$this->asSubscriber();
		self::assertSame( 403, $this->get()->get_status() );
		self::assertSame( 403, $this->put( array( 'currency' => 'USD' ) )->get_status() );

		$this->asBookingManager();
		self::assertSame( 403, $this->get()->get_status(), 'manage_bookings alone must not reach /admin/settings' );
		self::assertSame( 403, $this->put( array( 'currency' => 'USD' ) )->get_status() );

		$this->asAdmin();
		self::assertSame( 200, $this->get()->get_status() );
	}

	// ---------------------------------------------------------------- GET defaults

	public function test_get_returns_defaults_for_a_fresh_site(): void {
		$this->asAdmin();
		$response = $this->get();
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'currency'            => 'EUR',
				'checkout_ttl_min'    => 15,
				'approval_ttl_hours'  => 48,
				'payment_ttl_hours'   => 24,
				'purge_on_uninstall'  => false,
				'reminder_lead_hours' => 24,
				'emails_off'          => array(),
			),
			$response->get_data()
		);
	}

	// ---------------------------------------------------------------- PUT round trip

	public function test_put_round_trip_persists_and_second_get_agrees(): void {
		$this->asAdmin();
		$updated = $this->put( array( 'currency' => 'USD', 'checkout_ttl_min' => 30 ) );
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertSame( 'USD', $updated->get_data()['currency'] );
		self::assertSame( 30, $updated->get_data()['checkout_ttl_min'] );
		// Untouched fields survive the partial update.
		self::assertSame( 48, $updated->get_data()['approval_ttl_hours'] );

		$fetched = $this->get();
		self::assertSame( 200, $fetched->get_status() );
		self::assertSame( 'USD', $fetched->get_data()['currency'] );
		self::assertSame( 30, $fetched->get_data()['checkout_ttl_min'] );
	}

	public function test_put_purge_on_uninstall_true_round_trips(): void {
		$this->asAdmin();
		$updated = $this->put( array( 'purge_on_uninstall' => true ) );
		self::assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		self::assertTrue( $updated->get_data()['purge_on_uninstall'] );

		$fetched = $this->get();
		self::assertTrue( $fetched->get_data()['purge_on_uninstall'] );
	}

	// ---------------------------------------------------------------- validation

	public function test_put_invalid_currency_is_rejected(): void {
		$this->asAdmin();
		self::assertSame( 400, $this->put( array( 'currency' => 'eu' ) )->get_status() );
	}

	public function test_put_zero_checkout_ttl_min_is_rejected(): void {
		$this->asAdmin();
		self::assertSame( 400, $this->put( array( 'checkout_ttl_min' => 0 ) )->get_status() );
	}

	public function test_put_explicit_null_currency_is_rejected_naming_the_key(): void {
		$this->asAdmin();
		$response = $this->put( array( 'currency' => null ) );
		self::assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		self::assertStringContainsString( 'currency', (string) $data['data']['detail'] );

		// And the stored value was never touched by the rejected request.
		self::assertSame( 'EUR', $this->get()->get_data()['currency'] );
	}

	public function test_put_unknown_keys_are_ignored_silently(): void {
		$this->asAdmin();
		$response = $this->put( array( 'currency' => 'USD', 'not_a_real_setting' => 'whatever' ) );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame(
			array(
				'currency'            => 'USD',
				'checkout_ttl_min'    => 15,
				'approval_ttl_hours'  => 48,
				'payment_ttl_hours'   => 24,
				'purge_on_uninstall'  => false,
				'reminder_lead_hours' => 24,
				'emails_off'          => array(),
			),
			$response->get_data()
		);
	}
}
