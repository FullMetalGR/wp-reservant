<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

use Reservant\Rest\Input;

/**
 * The magic-link manage page: `/booking/{uuid}?token=...`, with
 * `?reservant_booking={uuid}&token=...` as the plain-permalink form. The emailed link resolves to
 * a page rendering the manage-mode mount point inside the theme shell - no page for the site
 * owner to create, no shortcode to place.
 *
 * REGISTRATION RUNS ON EVERY `init`, ADMIN REQUESTS INCLUDED. Plugin::register() wires this class
 * OUTSIDE its `is_admin()` split for the same reason Block is, but with a sharper failure mode:
 * options-permalink.php is an ADMIN request, and when the owner resaves permalinks there,
 * `flush_rewrite_rules()` rebuilds the stored `rewrite_rules` option from whatever that request's
 * `init` registered. A rule registered only when `! is_admin()` silently drops out of the stored
 * table on every resave, and every emailed magic link 404s until the next activation.
 * `ManageRouteTest::test_the_rewrite_survives_a_permalink_resave_from_wp_admin` pins this.
 *
 * Registering is cheap - in-memory array entries on `$wp_rewrite` - and this class NEVER calls
 * `flush_rewrite_rules()` itself: a flush writes the options table, so on `init` it would write
 * on every request. Flushing happens in exactly two places, both in Plugin: `activate()` (which
 * must call `addRewrite()` first - the activation request included the plugin after
 * `plugins_loaded`, so `register()` never ran there) and `maybeFlushRewrites()`, which runs at
 * `wp_loaded` (after `init` has re-registered the rule) whenever `Plugin::REWRITE_VERSION` no
 * longer matches its stored marker - the path a plugin UPDATE takes, since updates never fire
 * the activation hook. `Plugin::REWRITE_VERSION` is DERIVED from the `RULE_PATTERN` and
 * `RULE_REDIRECT` constants below, so changing the registration changes the marker itself and
 * the flush re-arms on existing installs with nothing for anyone to remember.
 *
 * The `query_vars` FILTER below duplicates what `add_rewrite_tag()` already does through
 * `$wp->add_query_var()` - on a live request the filter is redundant. It is kept deliberately:
 * `add_query_var()` mutates the `$wp` INSTANCE, and anything that rebuilds that instance after
 * `init` (the PHPUnit harness does, per test) silently loses the var, while the filter is hook
 * state and survives. Do not "simplify" either half away.
 *
 * THE PAGE IS NOT A BOOKING-EXISTENCE ORACLE. It never looks the booking up - no repository, no
 * query, nothing keyed on the uuid - so an unknown uuid, a real uuid with a wrong token, and a
 * real uuid with the right token produce one identical response: status 200 unconditionally,
 * byte-identical markup (for identical inputs), identical headers and timing, no redirect, never
 * the theme's 404 template. The uuid and token merely pass through to the client as `data-`
 * attributes (escaped by MountPoint); the REST routes (`Rest\Routes::guard()`) are the only
 * verifier, exactly as they are for every other transport. AGENTS.md treats the existence oracle
 * as a product-wide property; this page adds no new channel to it.
 *
 * `dispatch()` runs at `template_redirect` priority 0: rendering and exiting there answers before
 * `redirect_canonical()` (priority 10) can rewrite the URL and before the template loader could
 * pick a 404 template - both of which would otherwise be uuid-independent but theme-dependent
 * noise on a page whose whole contract is "one fixed answer". Known side effect of exiting that
 * early: `rest_output_link_header()` and `wp_shortlink_header()` (both `template_redirect`
 * priority 11) never run, so this 200 carries no `Link` header while an ordinary page or 404
 * does. That difference is uniform across existing and missing bookings - keyed on the route,
 * never the uuid - so it is not an oracle. `_wp_admin_bar_init` (also priority 0, registered
 * before this class hooks) does still run.
 */
final class ManageRoute {

	public const QUERY_VAR = 'reservant_booking';

	/** Matches `Rest\Routes::UUID` - 36 chars of hex and hyphens, same looseness on purpose. */
	private const UUID_PATTERN = '[0-9a-f-]{36}';

	/**
	 * What addRewrite() registers, as constants rather than inline literals because
	 * `Plugin::REWRITE_VERSION` - the marker that re-arms the stored-rules flush on existing
	 * installs - is derived from them. See addRewrite().
	 */
	public const RULE_PATTERN  = '^booking/(' . self::UUID_PATTERN . ')/?$';
	public const RULE_REDIRECT = 'index.php?' . self::QUERY_VAR . '=$matches[1]';

	/**
	 * Unlike Shortcode and Block, the no-argument fallback here wires Assets in: this page
	 * matches no post, so content detection can never see it and the renderer's `force()` is the
	 * ONLY channel through which the bundle loads - a bare `new MountPoint()` would render a
	 * permanently dead div. Assets keeps no instance state (its docblock), so this fresh
	 * instance behaves identically to the one Plugin wires in.
	 *
	 * `$terminator` is how `dispatch()` ends the request once the page is out - production uses
	 * the default (`exit`), tests inject a thrower so the `template_redirect` wiring can be
	 * driven end-to-end without killing the PHPUnit process. `exit` cannot be caught or stubbed
	 * any other way.
	 *
	 * `$nocache` is how `render()` sends its cache-forbidding headers - production uses the
	 * default, core's `nocache_headers()` verbatim, so the emission can never drift from
	 * core's. Injectable for the same reason as `$terminator`: under the CLI SAPI a PHPUnit
	 * process has always already "sent" headers (the progress output), so core's function
	 * early-returns without firing even its `nocache_headers` filter and leaves no trace a
	 * test could pin - only a spy standing in for the whole call can go red when the call is
	 * deleted.
	 */
	public function __construct(
		private readonly ?MountPoint $renderer = null,
		private readonly ?\Closure $terminator = null,
		private readonly ?\Closure $nocache = null
	) {}

	public function register(): void {
		add_filter( 'query_vars', array( $this, 'queryVars' ) );
		if ( did_action( 'init' ) > 0 ) {
			// A caller arriving after init already fired (the test harness re-booting the
			// plugin mid-request) - same immediate branch as Block::register(), and just as
			// idempotent: add_rewrite_rule() overwrites its own key, add_rewrite_tag() replaces
			// its own tag.
			$this->addRewrite();
		} else {
			add_action( 'init', array( $this, 'addRewrite' ) );
		}
		add_action( 'template_redirect', array( $this, 'dispatch' ), 0 );
	}

	/**
	 * Registers the rewrite rule and its query var on the in-memory `$wp_rewrite`/`$wp`. Never
	 * flushes - see the class docblock for the two places that do. Everything registered here
	 * must flow through `RULE_PATTERN` / `RULE_REDIRECT` (the tag's two inputs, `QUERY_VAR` and
	 * `UUID_PATTERN`, are those constants' own inputs): `Plugin::REWRITE_VERSION` is derived
	 * from them, and that derivation is what carries a changed registration into the stored
	 * rules of existing installs. A literal registered past the constants would ship without
	 * ever being stored -
	 * `ManageRouteTest::test_the_rewrite_marker_is_derived_from_the_rule_it_arms` pins this.
	 */
	public function addRewrite(): void {
		add_rewrite_rule( self::RULE_PATTERN, self::RULE_REDIRECT, 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '(' . self::UUID_PATTERN . ')' );
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function queryVars( array $vars ): array {
		if ( ! in_array( self::QUERY_VAR, $vars, true ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * The `template_redirect` callback: on a matching request, render the page and end the
	 * request. Request termination lives here and nowhere else so `render()` stays callable from
	 * tests; the terminator itself is injectable (constructor) so this method is too.
	 */
	public function dispatch(): void {
		if ( '' === $this->uuid() ) {
			return;
		}
		$this->render();
		( $this->terminator ?? static function (): void {
			exit;
		} )();
	}

	/**
	 * Emits the whole page. Public for the tests; production reaches it only through
	 * `dispatch()`.
	 *
	 * The mount markup is built BEFORE the shell starts printing, and the order is load-bearing:
	 * `MountPoint::render()` calls `Assets::force()`, and at `template_redirect` time
	 * `wp_enqueue_scripts` has not fired yet, so `force()` defers onto that hook - which
	 * `wp_head` fires from inside the shell - and the stylesheet lands in `<head>`. Built after
	 * `get_header()` it would enqueue late (footer script plus late styles). No separate
	 * `Assets::force()` call is needed; the renderer's own is the one that counts.
	 */
	public function render(): void {
		global $wp_query;

		// This page's URL IS the credential - the magic-link token rides in the query string -
		// so nothing may store or index the response. Neither happens by default: core's
		// WP::send_headers() emits nocache headers only for logged-in users, feeds, errors and
		// password-protected posts, none of which this is, and under PLAIN permalinks the page
		// is served at `/` distinguished only by query args, which a routine CDN
		// "ignore unknown query args" rule would cache token-and-all as the site's home page.
		// wp-admin's credential-in-URL pages get nocache_headers() from admin-post.php for free;
		// this front-end page has to say it itself. Both calls must precede all output:
		// the cache headers because headers, the wp_robots filter because wp_head() (which the
		// shell or the theme header fires) is what prints the meta tag.
		$this->sendNocacheHeaders();
		add_filter( 'wp_robots', 'wp_robots_sensitive_page' );

		// On BOTH permalink forms the main query here is the BLOG HOME, not a miss:
		// 'reservant_booking' names no post, so WP_Query treats the request as the posts page
		// (is_home, body class "home blog") and is_404 is already false on every real path.
		// The assignment is defensive only - kept for whatever theme or plugin flips the flag
		// before template_redirect - and the status line below is what actually pins the 200.
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );

		$mount = ( $this->renderer ?? new MountPoint( new Assets() ) )->render(
			'manage',
			array(
				'uuid'  => $this->uuid(),
				'token' => $this->token(),
			)
		);

		if ( ! $this->themeProvidesHeader() ) {
			// On a theme without header.php, get_header()/get_footer() do NOT print the theme's
			// design - locate_template()'s fallback chain ends in wp-includes/theme-compat/,
			// deprecated kubrick-era markup that opens with a _deprecated_file() notice. Not a
			// page to ship a customer. Emit a minimal shell instead, whose wp_head/wp_footer
			// are also what the deferred enqueue needs to land on. The predicate asks about the
			// FILE, not wp_is_block_theme(): header.php absence is the actual mechanism, and
			// the two are not the same set - all four bundled block themes lack the file, but a
			// block theme may legally ship one (it should then be used, not discarded) and a
			// broken classic theme may lack one (it then needs this shell, not the compat
			// relic).
			$this->shell( $mount );
			return;
		}

		get_header();
		echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by MountPoint, which esc_attr()s every attribute VALUE; the data- attribute NAMES it interpolates raw are this caller's own literals (uuid, token), and no css is passed.
		get_footer();
	}

	/**
	 * Whether the active theme itself ships a header.php - the condition under which
	 * get_header() produces the theme's real page. Answered by running core's own lookup and
	 * rejecting only its terminal fallback: locate_template()'s chain ends in
	 * wp-includes/theme-compat/header.php, which exists on every install, so its bare return
	 * value "finds" a header for every theme ever and could never say no - the compat hit had
	 * to be excluded, not the lookup abandoned. Asking anything OTHER than locate_template()
	 * can disagree with the get_header() call in render() that this predicate predicts:
	 * get_header() reads the $wp_stylesheet_path/$wp_template_path globals, which core
	 * snapshots only at load time and inside switch_theme(), while
	 * get_stylesheet_directory()/get_template_directory() recompute on every call through the
	 * stylesheet_directory-family filters - so a filter registered after the snapshot (a
	 * theme's functions.php, after_setup_theme, init) or a switch_to_blog() still open at
	 * template_redirect makes a file_exists() predicate describe a different theme than
	 * get_header() will read, and in the predicate-true direction that ships the deprecated
	 * kubrick relic to a customer. Same globals, same is_child_theme() gate, same branch order
	 * as the call it predicts, by construction - and immune to any future change in core's
	 * search order. ($load stays at its default false: core loads nothing and fires nothing on
	 * that path.)
	 */
	private function themeProvidesHeader(): bool {
		$located = locate_template( array( 'header.php' ) );
		// The defined() guard is the Plugin::version() idiom: WPINC is a core runtime constant
		// (unconditionally 'wp-includes', wp-settings.php defines it before any plugin loads),
		// unknown only to static analysis of src/ in isolation.
		$compat = ABSPATH . ( defined( 'WPINC' ) ? WPINC : 'wp-includes' ) . '/theme-compat/';
		return '' !== $located && ! str_starts_with( $located, $compat );
	}

	/**
	 * Sends core's nocache headers - Expires, Cache-Control (no-cache/no-store/private) and
	 * the Last-Modified removal, all of them core's own semantics through core's own function,
	 * so the emission cannot drift from core's. The call is injectable (constructor, see
	 * `$nocache`) for testability only, exactly like `$terminator` one screen up.
	 */
	private function sendNocacheHeaders(): void {
		( $this->nocache ?? 'nocache_headers' )();
	}

	/**
	 * The self-rendered shell for themes without header.php: enough document to carry wp_head
	 * and wp_footer.
	 *
	 * The `<title>` must be printed here, because NEITHER core mechanism reaches this page:
	 * `_wp_render_title_tag` (on wp_head) early-returns without `title-tag` theme support, which
	 * core never grants block themes (_add_default_theme_supports() omits it and a theme without
	 * header.php has no reason to declare it), and the unconditional `_block_template_render_title_tag` that
	 * replaces it is only swapped in by `locate_block_template()` - template-loader.php code that
	 * runs AFTER template_redirect, which `dispatch()` has already exited. A titleless head is an
	 * HTML validity error, and worse here: the browser tab, bookmark and history entry fall back
	 * to labelling the page with its URL - WHICH CONTAINS THE TOKEN. Guarded on `title-tag`
	 * support so the one theme shape that would print its own (title-tag support declared, yet no
	 * header.php) does not get two.
	 */
	private function shell( string $mount ): void {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
		<title><?php echo esc_html( wp_get_document_title() ); ?></title>
		<?php endif; ?>
		<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
		<?php echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by MountPoint, which esc_attr()s every attribute VALUE; the raw-interpolated data- attribute NAMES are this caller's own literals. ?>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	private function uuid(): string {
		// wp_unslash() for symmetry with token(): on the PLAIN permalink form this value comes
		// from $_GET, which wp_magic_quotes() slashed and WP::parse_request() copied verbatim
		// into the query vars. The pretty path's parse_str() output is unslashed - and a uuid
		// the rewrite pattern matched cannot contain a backslash - so unslashing is a no-op
		// there, never a double-strip.
		return sanitize_text_field( Input::text( wp_unslash( get_query_var( self::QUERY_VAR ) ) ) );
	}

	private function token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render parameter: the token authenticates nothing on this page and is only echoed (escaped) for the client to present to the REST routes, which verify it.
		return sanitize_text_field( Input::text( wp_unslash( $_GET['token'] ?? '' ) ) );
	}
}
