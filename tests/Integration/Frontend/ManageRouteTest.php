<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Frontend;

use Reservant\Frontend\ManageRoute;
use Reservant\Plugin;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The magic-link manage page (plan Task 10): `/booking/{uuid}?token=...` under pretty permalinks
 * and `?reservant_booking={uuid}&token=...` under plain ones, both resolving to a page that
 * renders the manage-mode mount point inside the theme shell.
 *
 * The load-bearing test is the third one: the page must not be a booking-existence oracle. The
 * uuid and token travel to the client as `data-` attributes and are verified server-side ONLY by
 * the REST routes (`Rest\Routes::guard()`); the page itself never looks the booking up, so its
 * status and its bytes cannot vary with whether the booking exists.
 *
 * Rewrite state is process-global and the harness only resets it for core's own suite
 * (`WP_RUN_CORE_TESTS`), so tear_down() below puts the permalink structure back itself - the
 * in-memory `$wp_rewrite` mutation would otherwise follow this class into whichever runs next
 * (the DB rows roll back on their own; the object does not).
 */
final class ManageRouteTest extends ReservantTestCase {

	private const UUID = '3f2b9c04-1d5e-4a7b-8c9d-0e1f2a3b4c5d';

	/** Deliberately never the secret whose hash a fixture stores: every page render uses a WRONG token. */
	private const WRONG_TOKEN = 'not-the-real-secret';

	private const RULE_PATTERN = '^booking/([0-9a-f-]{36})/?$';

	private string $originalStylesheet = '';

	public function set_up(): void {
		parent::set_up();
		// Same reasoning as ShortcodeTest: the harness restores hooks around every test but
		// never resets the script/style registries, so an enqueue made while rendering one page
		// would survive into the next test.
		$GLOBALS['wp_scripts']    = null;
		$GLOBALS['wp_styles']     = null;
		$this->originalStylesheet = (string) get_stylesheet();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		if ( get_stylesheet() !== $this->originalStylesheet ) {
			switch_theme( $this->originalStylesheet );
		}
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	public function test_pretty_permalinks_resolve_the_booking_route(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( home_url( '/booking/' . self::UUID . '?token=' . self::WRONG_TOKEN ) );
		$this->assertSame( self::UUID, get_query_var( 'reservant_booking' ) );
	}

	public function test_plain_permalinks_resolve_through_the_query_arg(): void {
		$this->set_permalink_structure( '' );
		$this->go_to( home_url( '/?reservant_booking=' . self::UUID . '&token=' . self::WRONG_TOKEN ) );
		$this->assertSame( self::UUID, get_query_var( 'reservant_booking' ) );
	}

	public function test_an_unknown_uuid_renders_the_same_neutral_page_as_a_bad_token(): void {
		global $wpdb;

		// The SAME uuid and the SAME wrong token twice; the only variable is whether a booking
		// row exists. Any difference between the two responses - status code or body bytes - is
		// a booking-existence oracle for whoever holds a guessed link.
		// Warm-up render, discarded: get_header()/get_footer() load the theme's template files
		// with require_once, so THE FIRST render in a process prints the theme shell and every
		// later one skips it. Real requests are one process each and always print it; only this
		// test renders twice in one process. One discarded render puts both compared renders
		// behind the same require_once state, so the comparison sees every byte the page still
		// varies - which, by design, must be none.
		$this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$wpdb->insert(
			$wpdb->prefix . 'reservant_bookings',
			array(
				'uuid'              => self::UUID,
				'status'            => 'confirmed',
				'manage_token_hash' => hash( 'sha256', 'the-real-secret' ),
				'created_at'        => $this->sql( 0 ),
				'updated_at'        => $this->sql( 0 ),
			)
		);
		list( $existingStatus, $existingBody ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$wpdb->delete( $wpdb->prefix . 'reservant_bookings', array( 'uuid' => self::UUID ) );
		list( $unknownStatus, $unknownBody ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$this->assertSame( 200, $existingStatus, 'The manage page answers 200 unconditionally - never a 404 that would tag unknown uuids.' );
		$this->assertSame( $existingStatus, $unknownStatus, 'The status code must not reveal whether the booking exists.' );
		$this->assertSame( $existingBody, $unknownBody, 'The body must be byte-identical - any difference is an existence oracle.' );

		// The neutral page still carries the mount the bundle boots from: uuid and token travel
		// as data- attributes, and ONLY the REST routes ever verify them.
		$this->assertStringContainsString( 'class="reservant-widget"', $existingBody );
		$this->assertStringContainsString( 'data-mode="manage"', $existingBody );
		$this->assertStringContainsString( 'data-uuid="' . self::UUID . '"', $existingBody );
		$this->assertStringContainsString( 'data-token="' . self::WRONG_TOKEN . '"', $existingBody );
	}

	public function test_the_rewrite_survives_a_permalink_resave_from_wp_admin(): void {
		// options-permalink.php is an ADMIN request, and flush_rewrite_rules() there rebuilds
		// the stored rules table from whatever THAT request's init registered. Simulate it
		// faithfully: a fresh rewrite object (nothing carried over from this process's
		// bootstrap), an admin screen, and the plugin re-wired through Plugin::boot() exactly
		// as the admin request would wire it. A registration guarded by `! is_admin()` fails
		// exactly here - the rule silently drops out of the stored table on every permalink
		// resave and every emailed magic link 404s until the next activation. (BlockTest pins
		// the same trap for the block's registration.)
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();

		set_current_screen( 'options-permalink' );
		$this->assertTrue( is_admin(), 'the simulated permalinks screen must count as wp-admin' );
		Plugin::boot();

		// The harness's helper is what the resave does: re-init, store the structure, flush.
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertArrayHasKey( self::RULE_PATTERN, (array) get_option( 'rewrite_rules' ) );
	}

	public function test_activation_writes_the_booking_rule_into_the_stored_rules(): void {
		// The activation request includes the plugin file only after plugins_loaded has fired,
		// so register() and its init hook never ran there: activate() must register the rewrite
		// itself before flushing, or it stores a rules table WITHOUT the booking route. The
		// fresh object is what lets this fail - the bootstrap's own registration lives in the
		// process-global $wp_rewrite and would otherwise mask the omission.
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		delete_option( 'rewrite_rules' );

		Plugin::activate();

		$this->assertArrayHasKey( self::RULE_PATTERN, (array) get_option( 'rewrite_rules' ) );
	}

	public function test_a_block_theme_still_gets_a_full_page_with_head_and_assets(): void {
		// get_header() prints NOTHING on a theme without header.php - which is every block
		// theme, including the bundled defaults a fresh site activates. Without its own shell
		// the manage page would be a bare unstyled div with a dead bundle: wp_head/wp_footer
		// never run, so the enqueue Assets::force() deferred has no hook to land on.
		switch_theme( 'twentytwentyfive' );
		$this->assertTrue( wp_is_block_theme(), 'twentytwentyfive must count as a block theme for this test to mean anything' );

		list( $status, $body ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( 'data-mode="manage"', $body );
		// wp_head carried the stylesheet and wp_footer the script - the reason the shell exists.
		$this->assertStringContainsString( 'reservant-widget-css', $body );
		$this->assertStringContainsString( 'reservant-widget-js', $body );
	}

	/**
	 * Drives the page the way a plain-permalink visitor request would: resolve the URL through
	 * the main query, then render. The status code is read off the `status_header` filter -
	 * the CLI SAPI never reliably reports real header calls back.
	 *
	 * Registries are reset per render because handles already printed once are marked done and
	 * would be skipped on a second pass, making two otherwise-identical pages differ.
	 *
	 * @return array{0: int, 1: string} status code, body
	 */
	private function renderManagePage( string $uuid, string $token ): array {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		$status  = 0;
		$capture = static function ( string $header, int $code ) use ( &$status ): string {
			$status = $code;
			return $header;
		};
		add_filter( 'status_header', $capture, 10, 2 );

		$this->go_to( home_url( '/?reservant_booking=' . $uuid . '&token=' . $token ) );

		ob_start();
		( new ManageRoute() )->render();
		$body = (string) ob_get_clean();

		remove_filter( 'status_header', $capture );

		return array( $status, $body );
	}
}
