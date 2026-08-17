<?php
declare( strict_types=1 );

namespace Reservant;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function boot(): void {
		if ( self::version() !== get_option( 'reservant_version' ) ) {
			Infrastructure\Db\Migrations::run();
			Admin\Capabilities::sync();
			update_option( 'reservant_version', self::version() );
		}
		self::$instance ??= new self();
		self::$instance->register();
	}

	public static function activate(): void {
		Infrastructure\Db\Migrations::run();
		Admin\Capabilities::sync();
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

		if ( ! is_admin() ) {
			// The shortcode holds the Assets instance so that rendering a mount point can
			// force-enqueue the bundle even where content detection cannot see it; Task 10's
			// ManageRoute takes this same instance for the same reason (its route matches no
			// post at all) and calls force() before rendering.
			$assets = new Frontend\Assets();
			$assets->register();
			( new Frontend\Shortcode( $assets ) )->register();
		}

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
