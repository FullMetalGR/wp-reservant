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
 * `plugins_loaded`, so `register()` never ran there) and `boot()`'s once-per-version-change
 * upgrade branch (plugin updates never fire the activation hook; core defers a pre-`wp_loaded`
 * flush to `wp_loaded`, by which time `init` has re-registered the rule).
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
 * noise on a page whose whole contract is "one fixed answer".
 */
final class ManageRoute {

	public const QUERY_VAR = 'reservant_booking';

	/** Matches `Rest\Routes::UUID` - 36 chars of hex and hyphens, same looseness on purpose. */
	private const UUID_PATTERN = '[0-9a-f-]{36}';

	/**
	 * Unlike Shortcode and Block, the no-argument fallback here wires Assets in: this page
	 * matches no post, so content detection can never see it and the renderer's `force()` is the
	 * ONLY channel through which the bundle loads - a bare `new MountPoint()` would render a
	 * permanently dead div. Assets keeps no instance state (its docblock), so this fresh
	 * instance behaves identically to the one Plugin wires in.
	 */
	public function __construct( private readonly ?MountPoint $renderer = null ) {}

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
	 * flushes - see the class docblock for the two places that do.
	 */
	public function addRewrite(): void {
		add_rewrite_rule(
			'^booking/(' . self::UUID_PATTERN . ')/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
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
	 * request. The `exit` lives here and nowhere else so `render()` stays callable from tests.
	 */
	public function dispatch(): void {
		if ( '' === $this->uuid() ) {
			return;
		}
		$this->render();
		exit;
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

		// The main query matched no post, and whatever it concluded about that is irrelevant
		// here: this request is answered 200 with the manage shell for EVERY uuid, so neither
		// the status line nor the 404 body classes can vary with anything.
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

		if ( wp_is_block_theme() ) {
			// get_header()/get_footer() print NOTHING on a theme without header.php - every
			// block theme, including the bundled defaults. No theme shell means no wp_head and
			// no wp_footer, so the deferred enqueue would never land and the page would be a
			// bare unstyled div with a dead bundle. Emit a minimal shell that fires both.
			$this->shell( $mount );
			return;
		}

		get_header();
		echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by MountPoint, which esc_attr()s every attribute; there is no other content.
		get_footer();
	}

	/** The self-rendered shell for block themes: enough document to carry wp_head and wp_footer. */
	private function shell( string $mount ): void {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
		<?php echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by MountPoint, which esc_attr()s every attribute. ?>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	private function uuid(): string {
		return sanitize_text_field( Input::text( get_query_var( self::QUERY_VAR ) ) );
	}

	private function token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render parameter: the token authenticates nothing on this page and is only echoed (escaped) for the client to present to the REST routes, which verify it.
		return sanitize_text_field( Input::text( wp_unslash( $_GET['token'] ?? '' ) ) );
	}
}
