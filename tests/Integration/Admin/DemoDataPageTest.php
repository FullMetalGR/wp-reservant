<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Admin;

use Reservant\Admin\DemoDataPage;
use Reservant\Cli\FixtureCommand;
use Reservant\Tests\Integration\ReservantTestCase;

final class DemoDataPageTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function testStoredIsNullUntilSeededAndStableAfter(): void {
		global $wpdb;
		self::assertNull( FixtureCommand::stored( $wpdb ), 'truncated tables must read as unseeded even if the option row survives' );

		$ids = FixtureCommand::ensure( $wpdb );
		self::assertSame( $ids, FixtureCommand::stored( $wpdb ) );
		self::assertSame( $ids, FixtureCommand::ensure( $wpdb ), 'ensure() must be idempotent' );
	}

	public function testMenuIsCapabilityGated(): void {
		$page = new DemoDataPage();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertIsString( $page->menu(), 'administrators get the Tools page' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertFalse( $page->menu(), 'subscribers must not see the page' );
	}

	public function testRenderShowsSeedButtonAndThenIds(): void {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new DemoDataPage();

		ob_start();
		$page->render();
		$before = (string) ob_get_clean();
		self::assertStringContainsString( 'Seed demo data', $before );
		self::assertStringNotContainsString( 'Seeded ids', $before );

		$ids = FixtureCommand::ensure( $wpdb );

		ob_start();
		$page->render();
		$after = (string) ob_get_clean();
		self::assertStringContainsString( 'Seeded ids', $after );
		self::assertStringContainsString( (string) $ids['cut'], $after );
		self::assertStringContainsString( 'availability', $after, 'the page links a live availability call' );
	}
}
