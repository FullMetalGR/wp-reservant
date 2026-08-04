<?php
/**
 * Minimal WP-CLI surface for static analysis only.
 *
 * WP-CLI is not a Composer dependency of this plugin - it is the runtime that loads WordPress, not
 * something the plugin requires - so `php-stubs/wordpress-stubs` does not describe it. This file is
 * listed under `scanFiles` in phpstan.neon.dist and is never autoloaded or executed: it exists so
 * `src/Cli/FixtureCommand.php` and `Plugin::register()` can be analysed like everything else
 * instead of being excluded.
 *
 * @package Reservant
 */

declare( strict_types=1 );

define( 'WP_CLI', true );

class WP_CLI { // phpcs:ignore

	/**
	 * @param string               $name     Command name.
	 * @param callable|string      $callable Command implementation.
	 * @param array<string, mixed> $args     Command options.
	 */
	public static function add_command( string $name, $callable, array $args = array() ): bool { // phpcs:ignore
		return true;
	}

	public static function line( string $message = '' ): void {} // phpcs:ignore

	public static function success( string $message ): void {} // phpcs:ignore

	/** @return never */
	public static function error( string $message ) { // phpcs:ignore
		exit( 1 );
	}
}
