<?php
/**
 * Plugin Name: Reservant
 * Description: Bookings for appointments, chains, and events - with or without WooCommerce.
 * Version: 0.3.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: ADS Solutions
 * Text Domain: reservant
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p>Reservant requires PHP 8.1 or newer.</p></div>';
	} );
	return;
}

define( 'RESERVANT_VERSION', '0.3.1' );
define( 'RESERVANT_FILE', __FILE__ );
define( 'RESERVANT_PATH', plugin_dir_path( __FILE__ ) );
define( 'RESERVANT_URL', plugin_dir_url( __FILE__ ) );

require __DIR__ . '/vendor/autoload.php';

// Action Scheduler self-initializes on `plugins_loaded` (priority 0-1, ahead of Plugin::boot()
// below) - it is never constructed directly. Guarded because a host site running WooCommerce (or
// another plugin bundling it) may already have loaded a copy; the guard is also what keeps a
// `composer install --no-dev` skip of this path analysable.
if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( \Reservant\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( \Reservant\Plugin::class, 'boot' ) );
