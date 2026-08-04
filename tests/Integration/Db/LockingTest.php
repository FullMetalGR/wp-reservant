<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Tests\Integration\ReservantTestCase;

final class LockingTest extends ReservantTestCase {

	public function test_transaction_commits_and_rolls_back(): void {
		global $wpdb;
		$runner = new TransactionRunner( $wpdb );
		$days   = new ResourceDayRepository( $wpdb );

		$runner->run( static function () use ( $days ): void {
			$days->ensure( array( LockKey::resourceDay( 1, '2026-01-15' ) ) );
		} );
		self::assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_resource_days" ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		try {
			$runner->run( static function () use ( $days ): void {
				$days->ensure( array( LockKey::resourceDay( 2, '2026-01-15' ) ) );
				throw new \RuntimeException( 'boom' );
			} );
			self::fail( 'Expected exception.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'boom', $e->getMessage() );
		}
		self::assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_resource_days" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function test_lock_keys_sort_globally(): void {
		$keys = array(
			LockKey::resourceDay( 2, '2026-01-15' ),
			LockKey::occurrence( 9 ),
			LockKey::resourceDay( 1, '2026-01-16' ),
			LockKey::resourceDay( 1, '2026-01-15' ),
			LockKey::occurrence( 3 ),
		);
		$sorted = LockKey::sorted( $keys );
		$flat   = array_map( static fn ( LockKey $k ): string => $k->type . ':' . $k->id . ':' . $k->day, $sorted );
		self::assertSame(
			array( 'occurrence:3:', 'occurrence:9:', 'resource_day:1:2026-01-15', 'resource_day:1:2026-01-16', 'resource_day:2:2026-01-15' ),
			$flat
		);
	}

	public function test_acquire_and_bump_rev(): void {
		global $wpdb;
		$runner = new TransactionRunner( $wpdb );
		$locks  = new LockManager( $wpdb );
		$days   = new ResourceDayRepository( $wpdb );
		$key    = LockKey::resourceDay( 5, '2026-02-01' );

		$days->ensure( array( $key ) );
		$runner->run( static function () use ( $locks, $days, $key ): void {
			$locks->acquire( array( $key ) );
			$days->bumpRev( array( $key ) );
		} );
		self::assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( "SELECT rev FROM {$wpdb->prefix}reservant_resource_days WHERE resource_id = %d AND day_utc = %s", 5, '2026-02-01' ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}
}
