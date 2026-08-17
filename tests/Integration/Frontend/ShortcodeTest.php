<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Frontend;

use Reservant\Frontend\Assets;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The widget's PHP surface (plan Task 8): the `[reservant_booking]` / `[reservant_manage]` mount
 * points, and `Assets` enqueuing `build/widget.js` + `build/style-widget.css` ONLY on pages that
 * actually carry the widget. The conditional-load tests are the load-bearing ones - a booking
 * plugin that ships 100 KB to every page of the site is the complaint that follows.
 *
 * These tests run against the real build output (CI builds the bundles before this suite, and
 * `Assets` reads `build/widget.asset.php` for its dependency list), so they also pin the one
 * failure no JS gate can see: PHP enqueuing a URL the build never emits. `wp_enqueue_style()`
 * does not check that its URL resolves - the stylesheet is `style-widget.css`, NOT `widget.css`,
 * and only test_the_enqueued_urls_point_at_files_the_build_emits stands between that rename
 * hazard and an unstyled widget shipping with every other gate green.
 */
final class ShortcodeTest extends ReservantTestCase {

	private const HANDLE = 'reservant-widget';

	public function set_up(): void {
		parent::set_up();
		// The WP test harness restores `$wp_filter`/`$wp_actions` around every test but never
		// resets the script/style registries, so an enqueue made by one test would survive into
		// the next and break the "not enqueued" assertions. Nulling the globals makes the next
		// wp_scripts()/wp_styles() call rebuild them from core defaults, same as a fresh request.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	public function tear_down(): void {
		// Same courtesy in the other direction: no test class after this one inherits a queue
		// with reservant-widget already in it.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	/** Creates a published post and navigates the test request to it, as a visitor would. */
	private function visitPostWith( string $content ): void {
		$postId = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( (string) get_permalink( $postId ) );
	}

	/**
	 * Reads the bootstrap object back out of the inline before-script attached to the widget
	 * handle - the exact bytes a visitor's browser would evaluate.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrapPayload(): array {
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );
		$this->assertIsArray( $before, 'No inline before-script is attached to the widget handle.' );
		$code = implode( '', array_filter( $before, 'is_string' ) );
		$this->assertStringStartsWith( 'window.reservantWidget = ', $code );
		$this->assertStringEndsWith( ';', $code );
		$payload = json_decode( substr( $code, strlen( 'window.reservantWidget = ' ), -1 ), true );
		$this->assertIsArray( $payload );
		return $payload;
	}

	public function test_shortcode_renders_a_mount_point_with_its_attributes(): void {
		$html = do_shortcode( '[reservant_booking service="3"]' );
		$this->assertStringContainsString( 'class="reservant-widget"', $html );
		$this->assertStringContainsString( 'data-service="3"', $html );
		$this->assertStringContainsString( 'data-mode="book"', $html );
		// The bundle's contract (assets/src/public/index.tsx): the shortcode's default is an
		// EMPTY attribute, which the reader collapses to "no preselection".
		$this->assertStringContainsString( 'data-staff=""', $html );
	}

	public function test_assets_are_not_enqueued_on_a_page_without_the_shortcode(): void {
		$this->visitPostWith( 'nothing here' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_assets_are_enqueued_on_a_page_with_the_shortcode(): void {
		$this->visitPostWith( 'Book here: [reservant_booking service="3"]' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_detection_and_a_rendered_shortcode_together_attach_the_bootstrap_once(): void {
		// The commonest production request drives enqueue() TWICE: detection fires it on
		// wp_enqueue_scripts, then the_content renders the shortcode and mount() calls force(),
		// which - after the hook - enqueues on the spot. The guard in enqueue() is what keeps the
		// second pass from attaching a second inline bootstrap; without it the page would carry
		// two `window.reservantWidget = ...` statements.
		$this->visitPostWith( 'Book here: [reservant_booking service="3"]' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );

		do_shortcode( '[reservant_booking service="3"]' );

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::HANDLE, 'enqueued' ) );
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );
		$this->assertIsArray( $before );
		$this->assertCount( 1, array_filter( $before, 'is_string' ), 'The inline bootstrap must be attached exactly once per request.' );
		$this->assertCount( 1, array_keys( wp_scripts()->queue, self::HANDLE, true ) );
	}

	public function test_granularity_reaches_the_bootstrap_through_its_filter(): void {
		// Asserting only the default would prove nothing - 5 is what the fallback produces too,
		// so a renamed filter passes every default-value assertion. The name and default must
		// stay identical to AdminPage::granularityMin() and AvailabilityQuery::DEFAULT_GRANULARITY:
		// one time grid, three emitting sites.
		add_filter( 'reservant/granularity_min', static fn (): int => 10 );
		$this->visitPostWith( '[reservant_booking]' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertSame( 10, $this->bootstrapPayload()['granularityMin'] );
	}

	public function test_the_widget_script_declares_its_translations(): void {
		// wp_set_script_translations() is what makes wp.i18n load the reservant catalog for this
		// handle (plan Task 8 Step 3, AGENTS.md section 7). Nothing else fails when it is missing -
		// every widget string just silently renders untranslated on non-English sites - so the
		// registered textdomain is pinned here.
		$this->visitPostWith( '[reservant_booking]' );
		do_action( 'wp_enqueue_scripts' );
		$script = wp_scripts()->registered[ self::HANDLE ] ?? null;
		$this->assertNotNull( $script );
		$this->assertSame( 'reservant', $script->textdomain );
	}

	public function test_the_manage_shortcode_renders_manage_mode_and_escapes_its_attributes(): void {
		$html = do_shortcode( '[reservant_manage uuid="abc-123" token="a&b"]' );
		$this->assertStringContainsString( 'class="reservant-widget"', $html );
		$this->assertStringContainsString( 'data-mode="manage"', $html );
		$this->assertStringContainsString( 'data-uuid="abc-123"', $html );
		// esc_attr() proof: the raw ampersand must not survive into the attribute.
		$this->assertStringContainsString( 'data-token="a&amp;b"', $html );
		$this->assertStringNotContainsString( 'data-token="a&b"', $html );
	}

	public function test_a_page_with_only_the_manage_shortcode_still_gets_the_assets(): void {
		// The plan's Step 3 lists only reservant_booking in the detection, but a page carrying
		// only [reservant_manage] renders a mount point too - without the assets it would be a
		// dead div. Detection must cover both registered shortcodes.
		$this->visitPostWith( '[reservant_manage]' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_rendering_a_shortcode_after_the_enqueue_hook_still_loads_the_assets(): void {
		// A shortcode rendered from somewhere content detection cannot see (a template part, a
		// text widget, a page builder module) runs during the body, after wp_enqueue_scripts
		// fired. The mount point must not come out dead: rendering it late enqueues the assets
		// on the spot, and WordPress prints footer scripts and late styles for them.
		$this->visitPostWith( 'nothing here' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );

		do_shortcode( '[reservant_booking]' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_forcing_the_assets_enqueues_without_any_widget_on_the_page(): void {
		// The Task 10 seam: the manage route matches no post at all, so content detection can
		// never find it. ManageRoute calls force() on template_redirect - BEFORE its get_header()
		// call fires wp_enqueue_scripts - and the assets must arrive through that alone.
		$this->visitPostWith( 'nothing here' );
		( new Assets() )->force();
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ), 'force() before the hook must defer, not enqueue early.' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_the_bootstrap_object_carries_the_contract_shape_and_no_nonce_for_visitors(): void {
		$this->visitPostWith( '[reservant_booking]' );
		do_action( 'wp_enqueue_scripts' );

		$payload = $this->bootstrapPayload();
		$this->assertSame(
			array( 'restRoot', 'nonce', 'currency', 'timezone', 'granularityMin', 'checkoutTtlMin' ),
			array_keys( $payload ),
			'Task 11 is written against exactly this shape.'
		);
		$this->assertSame( rest_url(), $payload['restRoot'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( wp_timezone_string(), $payload['timezone'] );
		$this->assertSame( 5, $payload['granularityMin'] );
		$this->assertSame( 15, $payload['checkoutTtlMin'] );
		// NOBODY gets a nonce, deliberately: page caches serve this HTML for days, a wp_rest
		// nonce dies in 12-24 hours, and WordPress hard-fails any request carrying an invalid
		// nonce (rest_cookie_invalid_nonce, 403) even on fully public routes - where the same
		// request with no nonce at all would have succeeded. Empty string means "do not send the
		// X-WP-Nonce header" to the Task 11 client. The logged-in case is pinned separately below.
		$this->assertSame( '', $payload['nonce'] );
	}

	public function test_a_logged_in_visitor_gets_no_nonce_either(): void {
		// Deliberately the same empty string a visitor gets. No route the widget calls requires
		// cookie authentication (they are public or token-authorized), while core validates any
		// nonce the client DOES send before every permission callback - so the only thing a nonce
		// can add here is a failure mode: a page left open past nonce expiry (or cached for
		// logged-in users) starts sending a stale nonce and 403s even fully public routes. See
		// Assets::config() for the full reasoning; this test is what keeps the nonce from being
		// "fixed" back.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->visitPostWith( '[reservant_booking]' );
		do_action( 'wp_enqueue_scripts' );

		$payload = $this->bootstrapPayload();
		$this->assertSame( '', $payload['nonce'] );
	}

	public function test_detection_reads_the_main_query_not_the_post_global(): void {
		// `is_singular()` reads the main query; `get_post()` reads `$GLOBALS['post']` - and the
		// two can disagree at `wp_enqueue_scripts` time: an SEO or schema plugin that runs a
		// secondary WP_Query + setup_postdata() from an early `wp_head`-adjacent hook without
		// `wp_reset_postdata()` leaves a FOREIGN post in the global. The queried page still
		// carries the widget, so detection must inspect the object the main query resolved
		// (get_queried_object()), not whatever happens to sit in the global.
		$this->visitPostWith( '[reservant_booking]' );
		$GLOBALS['post'] = get_post( self::factory()->post->create( array( 'post_content' => 'no widget here' ) ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_the_enqueued_urls_point_at_files_the_build_emits(): void {
		$this->visitPostWith( '[reservant_booking]' );
		do_action( 'wp_enqueue_scripts' );

		$script = wp_scripts()->registered[ self::HANDLE ] ?? null;
		$style  = wp_styles()->registered[ self::HANDLE ] ?? null;
		$this->assertNotNull( $script );
		$this->assertNotNull( $style );

		$scriptPath = str_replace( RESERVANT_URL, RESERVANT_PATH, (string) $script->src );
		$stylePath  = str_replace( RESERVANT_URL, RESERVANT_PATH, (string) $style->src );
		$this->assertFileExists( $scriptPath, 'PHP enqueues a script URL the build does not emit.' );
		$this->assertFileExists( $stylePath, 'PHP enqueues a style URL the build does not emit - wp_enqueue_style never checks, so only this does.' );

		// The build emits an RTL twin (style-widget-rtl.css); declaring it via style_add_data is
		// what makes WordPress swap the whole sheet for RTL locales. 'replace' derives the URL by
		// s/.css/-rtl.css/, so the twin's on-disk name must match that derivation exactly.
		$this->assertSame( 'replace', wp_styles()->get_data( self::HANDLE, 'rtl' ) );
		$this->assertFileExists( str_replace( '.css', '-rtl.css', $stylePath ) );

		// The dependency list must come from build/widget.asset.php, not a hardcoded copy: the
		// real bundle depends on exactly these three core handles (react and react-dom arrive
		// transitively through wp-element; WP >= 6.6 registers react-jsx-runtime).
		$asset = include RESERVANT_PATH . 'build/widget.asset.php';
		$this->assertSame( $asset['dependencies'], $script->deps );
		$this->assertSame( $asset['version'], $script->ver );
	}
}
