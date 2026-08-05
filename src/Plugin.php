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

		if ( is_admin() ) {
			( new Admin\DemoDataPage() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'reservant', Cli\FixtureCommand::class );
		}
	}
}
