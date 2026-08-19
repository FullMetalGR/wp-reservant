<?php
declare( strict_types=1 );

// wp-env exposes the WP test framework at /wordpress-phpunit inside the tests-cli container.
$testsDir = getenv( 'WP_TESTS_DIR' );
if ( false === $testsDir || '' === $testsDir ) {
	$testsDir = '/wordpress-phpunit';
}

require_once $testsDir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function (): void {
	require dirname( __DIR__, 2 ) . '/reservant.php';
	// Action Scheduler must never actually run its queue in this suite (AGENTS.md Task 8 brief:
	// "do NOT try to run the AS queue runner" - only schedule actions and invoke job callbacks
	// synchronously). Its own async runner dispatches a loopback HTTP request from a `shutdown`
	// hook whenever it sees a due action; this is the official extension point to suppress that,
	// so a test process can never trigger a real, possibly-hanging network round trip. Production
	// execution is unaffected - it goes through WP-Cron's `action_scheduler_run_queue` event, not
	// this optimization.
	add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

	// WooCommerce, for the bridge suite (AGENTS.md section 6, P7). The WordPress test framework
	// activates NOTHING - it loads exactly what this file requires - so a plugin being "active" in
	// the container's database is invisible here, and `class_exists( 'WooCommerce' )` would be false
	// throughout a suite whose whole subject is what happens when it is true.
	//
	// Guarded rather than required: the file is only there because `.wp-env.json` installs it, and a
	// contributor running the suite against a container built before that line was added should get
	// the non-WC half of the suite rather than a fatal. `Integrations\WooCommerce` tests skip
	// themselves when it is absent; every other test is indifferent.
	$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $woo ) ) {
		require_once $woo;
	}
} );

require $testsDir . '/includes/bootstrap.php';
