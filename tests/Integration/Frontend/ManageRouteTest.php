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

	private bool $hadTitleTagSupport = false;

	public function set_up(): void {
		parent::set_up();
		// Same reasoning as ShortcodeTest: the harness restores hooks around every test but
		// never resets the script/style registries, so an enqueue made while rendering one page
		// would survive into the next test.
		$GLOBALS['wp_scripts']     = null;
		$GLOBALS['wp_styles']      = null;
		$this->originalStylesheet  = (string) get_stylesheet();
		$this->hadTitleTagSupport  = (bool) current_theme_supports( 'title-tag' );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		if ( get_stylesheet() !== $this->originalStylesheet ) {
			switch_theme( $this->originalStylesheet );
		}
		// $_wp_theme_features is process-global and the harness never resets it; put title-tag
		// back the way this test found it (the block-theme test removes it - see there).
		if ( $this->hadTitleTagSupport && ! current_theme_supports( 'title-tag' ) ) {
			add_theme_support( 'title-tag' );
		} elseif ( ! $this->hadTitleTagSupport && current_theme_supports( 'title-tag' ) ) {
			remove_theme_support( 'title-tag' );
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
		// behind the same require_once state. BE HONEST ABOUT WHAT THAT MEANS: with the stub
		// theme's shell already loaded, both compared bodies are exactly the mount div - the
		// shell contributes ZERO bytes to either side, so the byte-compare below pins the div,
		// not the whole page. The page-wide no-oracle property rests on construction instead:
		// render() performs no lookup keyed on the uuid (no repository, no query, no wpdb - see
		// the class docblock), so no other byte CAN vary with the row. A whole-page comparison
		// was tried and is NOT viable in one process: core's wp-emoji-styles-inline-css and
		// wp-img-auto-sizes-contain-inline-css print in one render and not the other (one-shot
		// hook state of their own). Do not retry it.
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

	public function test_a_rewrite_change_reaches_the_stored_rules_of_an_existing_install(): void {
		// The exact production shape of the defect that shipped with 0.3.0: an EXISTING
		// pretty-permalink site whose plugin files just updated to a build whose rewrites
		// changed. No activation hook will ever fire; the stored rules predate the booking
		// route; the stored rewrite marker predates the mechanism (absent, as on every site
		// that ran 0.3.0). The plugin version deliberately MATCHES: the flush is keyed on
		// Plugin::REWRITE_VERSION alone, so a rewrite change must reach installs even when
		// nobody remembered that the plugin version is what used to arm it.
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		update_option( 'rewrite_rules', array( 'stale/?$' => 'index.php' ) );
		update_option( 'reservant_version', RESERVANT_VERSION );
		delete_option( 'reservant_rewrite_version' );

		// wp_loaded already fired in this process, so boot() takes its immediate branch - the
		// deferred production branch is pinned by the marker-ordering test below.
		Plugin::boot();

		$this->assertArrayHasKey( self::RULE_PATTERN, (array) get_option( 'rewrite_rules' ), 'a rewrite change must reach the stored rules of a site that only ever UPDATES - activation never fires there' );
		$this->assertSame( Plugin::REWRITE_VERSION, get_option( 'reservant_rewrite_version' ), 'the marker must catch up, or every subsequent request re-flushes' );
	}

	public function test_the_marker_advances_only_after_the_flush_actually_ran(): void {
		// The failure mode the previous shape had: boot() advanced its version marker at
		// plugins_loaded while core deferred the flush to wp_loaded, so a request dying in
		// between (an init-time redirect + exit, a fatal) advanced the marker WITHOUT ever
		// flushing - and no later request retried, permanently. Simulate the death by rewinding
		// the wp_loaded counter (the harness backs up and restores $wp_actions per test),
		// booting, and NOT firing wp_loaded: nothing may have advanced yet.
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		update_option( 'rewrite_rules', array( 'stale/?$' => 'index.php' ) );
		delete_option( 'reservant_rewrite_version' );
		unset( $GLOBALS['wp_actions']['wp_loaded'] );

		Plugin::boot();

		$this->assertFalse( get_option( 'reservant_rewrite_version' ), 'a request that dies before wp_loaded must not have advanced the marker - stale marker is what makes the flush retryable' );
		$this->assertArrayNotHasKey( self::RULE_PATTERN, (array) get_option( 'rewrite_rules' ), 'nothing may flush before wp_loaded - init has not finished registering rules yet' );
		$this->assertNotFalse( has_action( 'wp_loaded', array( Plugin::class, 'maybeFlushRewrites' ) ), 'the flush must be armed on wp_loaded' );

		// The next request survives to wp_loaded: flush first, marker after.
		$GLOBALS['wp_actions']['wp_loaded'] = 1;
		Plugin::maybeFlushRewrites();

		$this->assertArrayHasKey( self::RULE_PATTERN, (array) get_option( 'rewrite_rules' ) );
		$this->assertSame( Plugin::REWRITE_VERSION, get_option( 'reservant_rewrite_version' ) );
	}

	public function test_a_current_marker_flushes_nothing(): void {
		// The other half of "flushes once and then not again" (Task 17): the two tests above
		// prove a STALE marker reaches the stored rules exactly once, but neither would go red
		// if maybeFlushRewrites() lost its marker-equality early return and flushed on every
		// wp_loaded - the exact "every request writes the options table" failure the design
		// spec forbids registering-time flushes over. A sentinel rules table pins it: a flush
		// REBUILDS the stored option, so the sentinel surviving the call is proof no flush ran.
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		update_option( 'reservant_rewrite_version', Plugin::REWRITE_VERSION );
		$sentinel = array( 'sentinel/?$' => 'index.php' );
		update_option( 'rewrite_rules', $sentinel );

		Plugin::maybeFlushRewrites();

		$this->assertSame( $sentinel, get_option( 'rewrite_rules' ), 'a current marker must be a no-op - flushing here again would write the options table on every request' );
	}

	public function test_the_rewrite_marker_is_derived_from_the_rule_it_arms(): void {
		// The 0.3.0 defect had a HUMAN half the tests above cannot reach: a hand-bumped marker
		// constant must be REMEMBERED whenever the rule changes, and this project has already
		// shipped the forgetting once. The marker is therefore DERIVED - it IS the registered
		// pattern and redirect - so any change to what addRewrite() registers changes the
		// marker by construction, maybeFlushRewrites() re-arms with nobody remembering
		// anything, and a rollback re-arms the same way (the older build's rule text differs).
		// Pinned against the ACTUAL registration on a fresh $wp_rewrite, not recomputed from
		// the constants the marker is built from - that would be circular - so rewiring
		// addRewrite() past those constants goes red here.
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();

		( new ManageRoute() )->addRewrite();
		$registered = (array) $GLOBALS['wp_rewrite']->extra_rules_top;

		$this->assertCount( 1, $registered, 'addRewrite() registers exactly one rule - a second would have to join the marker derivation' );
		$pattern = (string) array_key_first( $registered );
		$this->assertSame(
			$pattern . '|' . $registered[ $pattern ],
			Plugin::REWRITE_VERSION,
			'the marker must BE the registered rule - derived, never hand-typed, so changing the rule re-arms the flush on its own'
		);
	}

	public function test_a_block_theme_still_gets_a_full_page_with_head_and_assets(): void {
		// On a theme without header.php - every bundled block theme, including the defaults a
		// fresh site activates - get_header() does not print the theme's design: it falls back
		// to the deprecated wp-includes/theme-compat/header.php (kubrick-era markup plus a
		// _deprecated_file() notice). The manage page must print its own shell there instead.
		// The precondition asserted is header.php ABSENCE, because that - not block-theme-ness
		// - is what the shell branch keys on.
		switch_theme( 'twentytwentyfive' );
		$this->assertFalse( file_exists( get_stylesheet_directory() . '/header.php' ), 'twentytwentyfive must lack header.php for this test to mean anything' );
		// Make the harness faithful to a real block-theme request: the STUB theme's
		// functions.php declared title-tag support at bootstrap, $_wp_theme_features is
		// process-global, and switch_theme() does not clear it - so core's _wp_render_title_tag
		// would print a title here that no real block-theme site ever gets (measured live: the
		// manage page rendered with NO title at all). Stale support left in place would let the
		// shell's own <title> line be deleted with this test still green. tear_down restores it.
		remove_theme_support( 'title-tag' );
		$this->assertFalse( current_theme_supports( 'title-tag' ), 'a block theme declares no title-tag support - the stub theme leak must be gone for the title pin to bite' );

		list( $status, $body ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( 'data-mode="manage"', $body );
		// wp_head carried the stylesheet and wp_footer the script - the reason the shell exists.
		$this->assertStringContainsString( 'reservant-widget-css', $body );
		$this->assertStringContainsString( 'reservant-widget-js', $body );

		// A non-empty <title>: neither core title mechanism reaches this page (no title-tag
		// theme support for _wp_render_title_tag; the block-theme replacement is only swapped
		// in by template-loader code that runs AFTER dispatch() has exited), so the shell must
		// print its own. A titleless head is invalid HTML, and the browser tab, bookmark and
		// history fall back to labelling the page with its URL - which contains the token.
		$this->assertMatchesRegularExpression( '#<title>[^<]+</title>#', $body, 'the shell must print a non-empty title itself - no core mechanism will' );
		$this->assertSame( 1, substr_count( $body, '<title>' ), 'exactly one title - a second one would mean the shell and a core mechanism both printed' );

		// ORDERING, not just presence: the stylesheet must land in <head>, BEFORE the mount
		// div. The mount is built before the shell starts printing precisely so that force()
		// defers onto the wp_enqueue_scripts that wp_head fires; built any later, the
		// stylesheet drops to print_late_styles() AFTER the mount - the flash of unstyled
		// widget ShortcodeTest pins against on the shortcode path. Presence alone cannot catch
		// that regression, because late styles still put the handle's string in the body.
		$styleAt = strpos( $body, 'reservant-widget-css' );
		$mountAt = strpos( $body, '<div class="reservant-widget"' );
		$this->assertNotFalse( $styleAt );
		$this->assertNotFalse( $mountAt );
		$this->assertLessThan( $mountAt, $styleAt, 'the stylesheet must be printed before the mount div - after it means print_late_styles() and a flash of unstyled widget' );
	}

	public function test_a_theme_with_a_header_template_keeps_the_theme_shell(): void {
		// The other side of the shell predicate: the harness's stub theme HAS a header.php, so
		// the manage page must take the get_header() branch - never the self-shell, which would
		// silently discard the theme's real header (the hybrid-theme hazard: block templates
		// plus a header.php is legal, and such a theme's header.php is authoritative). The pin
		// is on the SHELL's absence rather than the header's presence, because the stub theme's
		// header.php prints no doctype and, thanks to require_once, may print nothing at all on
		// a later render in this process - but the self-shell ALWAYS prints its own doctype.
		$this->assertFileExists( get_stylesheet_directory() . '/header.php', 'the stub theme must have a header.php for this test to mean anything' );

		list( $status, $body ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'data-mode="manage"', $body );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $body );
	}

	public function test_a_header_core_finds_only_in_theme_compat_counts_as_no_header(): void {
		// The shell predicate must ask THE SAME oracle the get_header() call it predicts will
		// ask. locate_template() reads the $wp_stylesheet_path/$wp_template_path globals, which
		// core snapshots in exactly two places (wp-settings.php, before the theme's functions.php
		// loads, and inside switch_theme()); get_stylesheet_directory() recomputes on every call
		// THROUGH the stylesheet_directory filter. So a filter registered after the snapshot -
		// the theme's functions.php, after_setup_theme, init; white-label and multi-tenant
		// plugins do exactly this - moves what a file_exists() predicate sees and NOT what
		// get_header() reads. Reproduce that divergence: the active theme (twentytwentyfive)
		// ships no header.php, so core resolves header.php ONLY to wp-includes/theme-compat/,
		// while the filter points the recomputed directory at the stub theme, which HAS one.
		// A file_exists() predicate answers "the theme provides a header" and get_header() then
		// loads the deprecated kubrick relic to a customer; the page must print its own shell.
		$stubDirectory = get_stylesheet_directory();
		$this->assertFileExists( $stubDirectory . '/header.php', 'the stub theme must have a header.php for the divergence to exist' );
		switch_theme( 'twentytwentyfive' );
		$filter = static function () use ( $stubDirectory ): string {
			return $stubDirectory;
		};
		add_filter( 'stylesheet_directory', $filter );

		// The divergence, pinned before rendering: the recomputed directory has a header.php...
		$this->assertFileExists( get_stylesheet_directory() . '/header.php' );
		// ...while core's own lookup - the one get_header() runs - finds only the compat relic.
		$this->assertStringStartsWith( ABSPATH . WPINC . '/theme-compat/', locate_template( array( 'header.php' ) ) );

		list( $status, $body ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );
		remove_filter( 'stylesheet_directory', $filter );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'data-mode="manage"', $body );
		$this->assertStringContainsString( '<!DOCTYPE html>', $body, 'the self shell must render - never silence, never the relic' );
		// Not the kubrick-era theme-compat header (its banner div, its XFN profile link):
		// shipping that relic plus its _deprecated_file() notice is the exact bug the
		// predicate exists to prevent.
		$this->assertStringNotContainsString( 'id="header"', $body );
		$this->assertStringNotContainsString( 'gmpg.org/xfn/11', $body );
	}

	public function test_the_page_whose_url_is_the_credential_forbids_caching(): void {
		// The magic-link token rides in this page's URL, so a cached copy of the response IS a
		// leaked credential - and core sends no cache headers here on its own
		// (WP::send_headers() emits them only for logged-in users, feeds, errors and
		// password-protected posts). Sharpest under plain permalinks: the page is served at `/`
		// distinguished only by query args, so a CDN rule that ignores unknown query args would
		// cache the manage page, token baked in, as the site's home page. The probe is an
		// injected spy standing in for core's nocache_headers() - the production default, see
		// the constructor - because under the CLI SAPI PHPUnit's banner has already "sent"
		// headers, so core's function early-returns leaving no observable trace and deleting
		// the call could never go red. WHAT the headers say is core's by construction (the
		// default calls nocache_headers() verbatim); this test pins THAT they are asked for,
		// exactly once, and before any body byte.
		$this->go_to( home_url( '/?reservant_booking=' . self::UUID . '&token=' . self::WRONG_TOKEN ) );

		$calls       = 0;
		$bodyAlready = -1;
		$route       = new ManageRoute(
			null,
			null,
			static function () use ( &$calls, &$bodyAlready ): void {
				++$calls;
				$bodyAlready = (int) ob_get_length();
			}
		);

		ob_start();
		$route->render();
		ob_end_clean();

		$this->assertSame( 1, $calls, 'render() must send the nocache headers exactly once - nothing else will on this page' );
		$this->assertSame( 0, $bodyAlready, 'headers must be sent before the first body byte, or PHP has already flushed the status line without them' );
	}

	public function test_the_manage_page_tells_robots_not_to_index_it(): void {
		// A crawler that reaches a magic link (a shared inbox, a forwarded email, a link
		// scanner) must not put the tokened URL into an index or an archive. Core's wp_robots
		// runs on wp_head and emits only max-image-preview by default - noindex has to be asked
		// for. Asserted on the shell path because the stub classic theme's header.php never
		// calls wp_head, so only the shell can carry the meta tag in this harness; on real
		// classic themes the same filter feeds the theme's own wp_head call.
		switch_theme( 'twentytwentyfive' );

		list( , $body ) = $this->renderManagePage( self::UUID, self::WRONG_TOKEN );

		$this->assertSame( 1, preg_match( "/<meta name='robots' content='([^']*)'/", $body, $directives ), 'no robots meta tag was printed at all' );
		$this->assertStringContainsString( 'noindex', $directives[1] );
		$this->assertStringContainsString( 'noarchive', $directives[1] );
	}

	public function test_template_redirect_is_what_turns_the_url_into_the_page(): void {
		// go_to() runs WP::main(), which ends at the 'wp' action - template_redirect, the hook
		// dispatch() answers on, never fires in this harness. So without this test the suite
		// proves the query var resolves and render() is neutral without ever proving that
		// VISITING THE URL PRODUCES THE PAGE. Fire the hook for real: clear template_redirect
		// first (the bootstrap-registered dispatch would `exit` the test process, and core's
		// redirect_canonical at priority 10 can exit too), then wire a fresh instance through
		// the SAME register() path with the terminator intercepted.
		remove_all_actions( 'template_redirect' );
		$terminated = false;
		$route      = new ManageRoute(
			null,
			static function () use ( &$terminated ): void {
				$terminated = true;
				throw new \RuntimeException( 'reservant-terminated' );
			}
		);
		$route->register();
		$this->assertSame( 0, has_action( 'template_redirect', array( $route, 'dispatch' ) ), 'dispatch must hook template_redirect at priority 0 - after redirect_canonical (10) the URL may already have been moved' );

		$this->go_to( home_url( '/?reservant_booking=' . self::UUID . '&token=' . self::WRONG_TOKEN ) );

		ob_start();
		try {
			do_action( 'template_redirect' );
		} catch ( \RuntimeException $signal ) {
			$this->assertSame( 'reservant-terminated', $signal->getMessage() );
		}
		$body = (string) ob_get_clean();

		$this->assertTrue( $terminated, 'visiting the URL must render and end the request - dispatch never ran off the hook' );
		$this->assertStringContainsString( 'data-mode="manage"', $body );
		$this->assertStringContainsString( 'data-uuid="' . self::UUID . '"', $body );
	}

	public function test_a_request_without_the_booking_var_passes_through_untouched(): void {
		// The guard's half of the wiring: dispatch() runs at priority 0 on EVERY front-end
		// request, so the empty-uuid early return is all that keeps the manage shell (and its
		// request-ending terminator) off every other page of the site.
		$terminated = false;
		$route      = new ManageRoute(
			null,
			static function () use ( &$terminated ): void {
				$terminated = true;
				throw new \RuntimeException( 'reservant-terminated' );
			}
		);
		$this->go_to( home_url( '/' ) );

		ob_start();
		try {
			$route->dispatch();
		} catch ( \RuntimeException $signal ) {
			// $terminated already records it; fall through to the assertions.
			unset( $signal );
		}
		$body = (string) ob_get_clean();

		$this->assertFalse( $terminated, 'an ordinary request must never be terminated by the manage route' );
		$this->assertSame( '', $body, 'an ordinary request must get no manage-page bytes' );
	}

	public function test_the_uuid_and_the_token_unslash_identically(): void {
		// Production requests pass through wp_magic_quotes(): $_GET arrives slashed, and on the
		// plain-permalink path WP::parse_request() copies $_GET['reservant_booking'] into the
		// query vars verbatim - slashes included - while $_GET['token'] is read directly. go_to()
		// populates $_GET via parse_str(), which never slashes, so the production shape is
		// restored by hand here for BOTH values. The pin is symmetry: the first pass shipped
		// wp_unslash() on the token but not the uuid, so `"><` rendered as data-token="&quot;&gt;"
		// but data-uuid="\&quot;&gt;" - no breakout (esc_attr held), just an unexplained
		// asymmetry that would puzzle every reader of the two attributes.
		$hostile = '">';
		$this->go_to( home_url( '/?reservant_booking=x&token=x' ) );
		set_query_var( ManageRoute::QUERY_VAR, addslashes( $hostile ) );
		$_GET['token'] = addslashes( $hostile );

		ob_start();
		( new ManageRoute() )->render();
		$body = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/data-uuid="([^"]*)"/', $body, $uuid ) );
		$this->assertSame( 1, preg_match( '/data-token="([^"]*)"/', $body, $token ) );
		$this->assertSame( '&quot;&gt;', $uuid[1], 'the slash wp_magic_quotes() added must not survive into the attribute' );
		$this->assertSame( $uuid[1], $token[1], 'uuid and token must come out of the same laundering identically' );
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
