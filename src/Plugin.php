<?php
declare( strict_types=1 );

namespace Reservant;

final class Plugin {

	/**
	 * The marker that arms maybeFlushRewrites(): the exact rewrite registration this build
	 * ships - `Frontend\ManageRoute::RULE_PATTERN` and `::RULE_REDIRECT`, joined verbatim -
	 * stored into `reservant_rewrite_version` once a flush has run. A stored copy that differs
	 * means the stored `rewrite_rules` table may predate the rule this build registers, so the
	 * next request re-flushes. DERIVED, NEVER HAND-TYPED: a hand-bumped counter has to be
	 * remembered, and the 0.3.0 manage-route release shipped exactly that forgetting (new
	 * rewrite, no bump of the version that then armed the flush, every magic link 404ing until
	 * a manual permalink resave). A marker that IS the rule cannot be forgotten when the rule
	 * changes, cannot fire spuriously on releases that change no rewrite, and makes a rollback
	 * self-correcting - the older build's rule text differs too, so its flush re-arms the same
	 * way. `ManageRouteTest::test_the_rewrite_marker_is_derived_from_the_rule_it_arms` pins the
	 * derivation against what addRewrite() actually registers.
	 *
	 * Why its own marker and not `RESERVANT_VERSION`: a plugin UPDATE never fires the
	 * activation hook, so carrying a changed rule set into the stored `rewrite_rules` option
	 * depends entirely on `maybeFlushRewrites()` noticing a change - and the plugin version
	 * moves for reasons that have nothing to do with rewrites.
	 */
	public const REWRITE_VERSION = Frontend\ManageRoute::RULE_PATTERN . '|' . Frontend\ManageRoute::RULE_REDIRECT;

	private static ?Plugin $instance = null;

	public static function boot(): void {
		if ( self::version() !== get_option( 'reservant_version' ) ) {
			Infrastructure\Db\Migrations::run();
			Admin\Capabilities::sync();
			update_option( 'reservant_version', self::version() );
		}
		self::$instance ??= new self();
		self::$instance->register();

		// After register(): on the immediate branch below the flush snapshots whatever is on
		// $wp_rewrite RIGHT NOW, so the booking route must already be registered. That holds
		// only because ManageRoute::register() carries the symmetric immediate branch - past
		// init it calls addRewrite() directly instead of hooking it; simplified back to an
		// unconditional add_action('init', ...), the flush below would store a table WITHOUT
		// the route and then advance the marker, a permanent wedge. The deferred branch (every
		// production request - boot() runs at plugins_loaded) does not care about this
		// ordering, but the harness re-booting mid-request does.
		if ( did_action( 'wp_loaded' ) > 0 ) {
			self::maybeFlushRewrites();
		} else {
			add_action( 'wp_loaded', array( self::class, 'maybeFlushRewrites' ) );
		}
	}

	/**
	 * Flushes the stored rewrite rules once per REWRITE_VERSION change - the update-time twin of
	 * activate()'s flush, for the sites a release reaches without ever firing the activation hook.
	 *
	 * Runs at `wp_loaded` so `init` has already re-registered every rule (and so
	 * `WP_Rewrite::flush_rules()` executes instead of deferring), and the marker option advances
	 * only AFTER the flush has actually run. That ordering is the point: a request that dies
	 * anywhere before the flush leaves the marker stale, so the next request simply retries - a
	 * repeated flush costs one options-table write, a lost one costs every magic link on the site.
	 * (The previous shape advanced its marker at plugins_loaded while core deferred the flush to
	 * wp_loaded; one request dying in between - an init-time redirect + exit, a fatal - lost the
	 * flush permanently.) On ordinary requests this is one autoloaded option read and an early
	 * return - never the per-request flush the plan forbids.
	 */
	public static function maybeFlushRewrites(): void {
		if ( self::REWRITE_VERSION === get_option( 'reservant_rewrite_version' ) ) {
			return;
		}
		flush_rewrite_rules();
		update_option( 'reservant_rewrite_version', self::REWRITE_VERSION );
	}

	public static function activate(): void {
		Infrastructure\Db\Migrations::run();
		Admin\Capabilities::sync();
		// The activation request included this plugin only after plugins_loaded had fired, so
		// register() never ran and the rewrite is not on $wp_rewrite yet: register it explicitly
		// or the flush would store a rules table WITHOUT the booking route. The flush stays
		// UNCONDITIONAL (not maybeFlushRewrites()): a reactivation with a current marker still
		// needs the rules rebuilt, because whatever happened while the plugin was inactive is
		// unknowable. The marker is set anyway so the next request's check has nothing to redo.
		( new Frontend\ManageRoute() )->addRewrite();
		flush_rewrite_rules();
		update_option( 'reservant_rewrite_version', self::REWRITE_VERSION );
		update_option( 'reservant_version', self::version() );
	}

	/**
	 * `RESERVANT_VERSION` is defined by `reservant.php` before this class is ever reachable; the
	 * `defined()` guard exists only so static analysis of `src/` in isolation (see
	 * `Infrastructure\Db\Migrations::run()` for the same idiom) does not flag an unknown constant.
	 */
	private static function version(): string {
		return defined( 'RESERVANT_VERSION' ) ? RESERVANT_VERSION : '';
	}

	private function register(): void {
		add_action( 'rest_api_init', array( new Rest\Routes(), 'register' ) );

		// `add_action` here needs nothing from Action Scheduler itself - only the hook names this
		// plugin owns - so it is safe at `plugins_loaded` time, unlike the sweeper guard below.
		Infrastructure\Scheduler\Jobs::register();
		Notifications\ApprovalEmails::register();

		// Action Scheduler's own data store initializes on `init` (priority 1, `ActionScheduler::init()`),
		// which has not run yet at `plugins_loaded` - calling `as_*` functions here would silently
		// no-op (and trigger `_doing_it_wrong`). Deferred to `init` at a lower-precedence priority
		// so the store is guaranteed ready first. `everyFiveMinutes()` is itself idempotent
		// (guards on `as_has_scheduled_action`), so running this on every request never
		// accumulates a second recurring sweep.
		add_action(
			'init',
			static function (): void {
				Infrastructure\Scheduler\Scheduler::everyFiveMinutes( Infrastructure\Scheduler\Jobs::SWEEP );
			},
			20
		);

		if ( is_admin() ) {
			( new Admin\AdminPage() )->register();
			( new Admin\ApprovalActionEndpoint() )->register();
		}

		// The mount-point renderer wraps an Assets instance so that rendering a mount point can
		// force-enqueue the bundle even where content detection cannot see it. Sharing THIS
		// instance is dependency-injection hygiene, not a mechanism requirement: Assets keeps
		// no instance state (force() records its decision in the hook table - see its
		// docblock), so a fresh instance behaves identically, and ShortcodeTest proves it by
		// constructing its own. Task 10's ManageRoute needs force() too (its route matches no
		// post at all); hand it this same renderer for the same hygiene.
		$assets   = new Frontend\Assets();
		$renderer = new Frontend\MountPoint( $assets );

		if ( ! is_admin() ) {
			$assets->register();
			( new Frontend\Shortcode( $renderer ) )->register();
		}

		// Deliberately OUTSIDE the is_admin() split, unlike everything above: the block editor
		// IS wp-admin, and THIS block reaches the inserter only via server registration -
		// editor.tsx registers the client half with edit/save alone, taking title, category and
		// attributes from the server-bootstrapped definition (a block shipping its own full
		// client-side metadata would list regardless; this one deliberately does not). A block
		// registered under `! is_admin()` would render fine on the front end while being
		// impossible to insert. Registration itself is cheap and side-effect free on requests
		// that never render the block.
		( new Frontend\Block( $renderer ) )->register();

		// Also OUTSIDE the is_admin() split, with a sharper failure mode than the block's:
		// options-permalink.php is an ADMIN request, and a permalink resave there rebuilds the
		// stored rewrite_rules from whatever that request's `init` registered. Guarded by
		// `! is_admin()`, the booking route would silently drop out of the stored table on every
		// resave and every emailed magic link would 404 until the next activation. Registration
		// is in-memory only; the flushes live in maybeFlushRewrites() and activate() above
		// (see ManageRoute).
		( new Frontend\ManageRoute( $renderer ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI && self::devToolsAllowed( wp_get_environment_type(), self::devOverride() ) ) {
			\WP_CLI::add_command( 'reservant', Cli\FixtureCommand::class );
		}
	}

	/**
	 * Whether the demo-data seeder may be reachable at all.
	 *
	 * `wp reservant fixture` writes services ("Cut", "Colour"), staff ("Alex", "Bella") and events
	 * straight into the live catalog, where the fully public, unauthenticated GET /availability and
	 * GET /services/{id} serve them immediately. It is a test fixture, not a feature, so it must not
	 * be reachable on a production site - the Tools > Reservant Demo Data page that also exposed it
	 * over HTTP has been removed outright, and this keeps the CLI half from shipping live.
	 *
	 * Kept as a pure function of its two inputs so it is unit-testable with no WordPress bootstrap;
	 * `register()` supplies the real ones. An explicit `RESERVANT_DEV` wins in both directions (a
	 * staging box that self-reports as production can opt in; a local site that must never carry
	 * demo rows can opt out), and with the constant absent the environment decides, defaulting to
	 * off - `wp_get_environment_type()` returns 'production' unless a site says otherwise.
	 *
	 * Note this is NOT gated on WP_DEBUG, despite `.wp-env.json` setting it: WP_DEBUG is false in
	 * the `tests-cli` container, which is exactly the one `bin/run-concurrency.sh` drives, so a
	 * WP_DEBUG gate would break the concurrency proof AGENTS.md section 2.2 requires in CI. Both
	 * wp-env containers report the environment type as 'local'.
	 *
	 * @param string    $environmentType The value of `wp_get_environment_type()`.
	 * @param bool|null $override        `RESERVANT_DEV` if defined, null when it is not.
	 */
	public static function devToolsAllowed( string $environmentType, ?bool $override ): bool {
		return $override ?? 'production' !== $environmentType;
	}

	private static function devOverride(): ?bool {
		return defined( 'RESERVANT_DEV' ) ? (bool) RESERVANT_DEV : null;
	}
}
