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
			( new Admin\DemoDataPage() )->register();
			( new Admin\ApprovalActionEndpoint() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'reservant', Cli\FixtureCommand::class );
		}
	}
}
