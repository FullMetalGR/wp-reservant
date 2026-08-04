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
} );

require $testsDir . '/includes/bootstrap.php';
