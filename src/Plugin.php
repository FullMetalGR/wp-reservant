<?php
declare( strict_types=1 );

namespace Reservant;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function boot(): void {
		self::$instance ??= new self();
		self::$instance->register();
	}

	public static function activate(): void {
		Infrastructure\Db\Migrations::run();
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
