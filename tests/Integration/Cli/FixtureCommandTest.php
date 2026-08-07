<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Cli;

use Reservant\Cli\FixtureCommand;
use Reservant\Plugin;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The demo-data seeder behind `wp reservant fixture`.
 *
 * `bin/run-concurrency.sh` and two other integration tests call `ensure()` directly, so the seeder
 * itself stays - only its registration is gated, and only the HTTP-reachable Tools page that used
 * to wrap it was removed. The idempotency assertions here came from the deleted DemoDataPageTest;
 * they were never about the page.
 */
final class FixtureCommandTest extends ReservantTestCase {

	public function testStoredIsNullUntilSeededAndStableAfter(): void {
		global $wpdb;
		self::assertNull( FixtureCommand::stored( $wpdb ), 'truncated tables must read as unseeded even if the option row survives' );

		$ids = FixtureCommand::ensure( $wpdb );
		self::assertSame( $ids, FixtureCommand::stored( $wpdb ) );
		self::assertSame( $ids, FixtureCommand::ensure( $wpdb ), 'ensure() must be idempotent' );
	}

	/**
	 * The gate has to be true here, or `bin/run-concurrency.sh` - which drives this same wp-env
	 * install through `wp reservant fixture` - would stop finding the command.
	 */
	public function testTheSeederIsRegisterableInThisEnvironment(): void {
		self::assertSame( 'local', wp_get_environment_type() );
		self::assertTrue( Plugin::devToolsAllowed( wp_get_environment_type(), null ) );
	}
}
