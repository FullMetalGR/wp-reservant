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
} );

require $testsDir . '/includes/bootstrap.php';
