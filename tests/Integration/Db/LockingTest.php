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

		// Captured into a variable rather than asserted inside the `catch`: PHPUnit's own
		// `AssertionFailedError` is a `\RuntimeException`, so a `self::fail()` in the `try` of a broad
		// catch would be swallowed by it and the test would pass having proved nothing.
		$refusal = null;
		try {
			$runner->run( static function () use ( $days ): void {
				$days->ensure( array( LockKey::resourceDay( 2, '2026-01-15' ) ) );
				throw new \RuntimeException( 'boom' );
			} );
		} catch ( \RuntimeException $e ) {
			$refusal = $e->getMessage();
		}
		self::assertSame( 'boom', $refusal );
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

	/**
	 * A lock statement that failed is a lock this request does not hold - `acquire()` must say so.
	 *
	 * wpdb answers `false` on a DB-level failure and neither throws nor surfaces anything while
	 * `WP_DEBUG` is off, so an unchecked `SELECT ... FOR UPDATE` returns normally on a 1205 lock-wait
	 * timeout (`innodb_rollback_on_timeout` is OFF by default, so only the STATEMENT rolls back and the
	 * transaction stays open, unlocked) and on a 1213 deadlock (the server rolled the transaction back,
	 * so everything after this commits individually under restored autocommit). The `query` filter
	 * below rewrites the lock statement to a table that does not exist, which is the same
	 * `false`-with-`last_error` shape both produce.
	 *
	 * `RescheduleBookingTest::test_a_lock_that_cannot_be_taken_refuses_the_move_rather_than_writing_without_it`
	 * is the one that asserts the committed consequence; this asserts the guard itself, on both
	 * statements `acquire()` issues.
	 */
	public function test_a_lock_that_cannot_be_taken_is_refused(): void {
		global $wpdb;
		$days = new ResourceDayRepository( $wpdb );
		$key  = LockKey::resourceDay( 6, '2026-02-02' );
		$days->ensure( array( $key ) );

		$onResourceDay = $this->refusalUnderSabotage(
			'/^\s*SELECT\s+resource_id\s+FROM\s+\S*reservant_resource_days\b.*FOR UPDATE/is',
			'SELECT resource_id FROM reservant_no_such_table WHERE 1 = 1',
			static function ( LockManager $locks, ResourceDayRepository $repo ) use ( $key ): void {
				$locks->acquire( array( $key ) );
			}
		);
		self::assertSame( 'lock_unavailable', $onResourceDay, 'A failed resource-day lock must refuse the request.' );

		$onOccurrence = $this->refusalUnderSabotage(
			'/^\s*SELECT\s+id\s+FROM\s+\S*reservant_occurrences\b.*FOR UPDATE/is',
			'SELECT id FROM reservant_no_such_table WHERE 1 = 1',
			static function ( LockManager $locks, ResourceDayRepository $repo ): void {
				$locks->acquire( array( LockKey::occurrence( 7 ) ) );
			}
		);
		self::assertSame( 'lock_unavailable', $onOccurrence, 'A failed occurrence lock must refuse the request.' );
	}

	/**
	 * The same guard on the revision bump, for the same reason: it is a write inside the same
	 * transaction family, and a silent failure there should not be a judgement call each time somebody
	 * reads it. `rev` has no reader yet (the mask cache is unimplemented), so this is consistency
	 * rather than a live bug - and the rollback it forces is asserted on the committed row.
	 */
	public function test_a_failed_revision_bump_is_refused_and_rolls_back(): void {
		global $wpdb;
		$days = new ResourceDayRepository( $wpdb );
		$key  = LockKey::resourceDay( 8, '2026-02-03' );
		$days->ensure( array( $key ) );

		$refusal = $this->refusalUnderSabotage(
			'/^\s*UPDATE\s+\S*reservant_resource_days\s+SET\s+rev/is',
			'UPDATE reservant_no_such_table SET rev = rev + 1 WHERE 1 = 1',
			static function ( LockManager $locks, ResourceDayRepository $repo ) use ( $key ): void {
				$repo->bumpRev( array( $key ) );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal );
		self::assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT rev FROM {$wpdb->prefix}reservant_resource_days WHERE resource_id = %d AND day_utc = %s", 8, '2026-02-03' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'A refused bump must leave the revision where it was.'
		);
	}

	/**
	 * Run `$body` inside a transaction with one statement rewritten to a table that does not exist, and
	 * answer the refusal message.
	 *
	 * The message is returned rather than asserted in place: PHPUnit's `AssertionFailedError` extends
	 * `\RuntimeException`, so a `self::fail()` inside the `try` of a broad catch is swallowed and the
	 * test passes having proved nothing. Every assertion happens in the caller, after the catch.
	 *
	 * @param callable(LockManager, ResourceDayRepository): void $body
	 */
	private function refusalUnderSabotage( string $pattern, string $replacement, callable $body ): ?string {
		global $wpdb;
		$sabotage = static function ( $query ) use ( $pattern, $replacement ) {
			return 1 === preg_match( $pattern, (string) $query ) ? $replacement : $query;
		};

		$refusal    = null;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			( new TransactionRunner( $wpdb ) )->run(
				static function () use ( $body, $wpdb ): void {
					$body( new LockManager( $wpdb ), new ResourceDayRepository( $wpdb ) );
				}
			);
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}
		return $refusal;
	}
}
