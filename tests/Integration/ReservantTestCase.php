<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration;

use Reservant\Infrastructure\Db\Migrations;
use Reservant\Infrastructure\Scheduler\Jobs;

abstract class ReservantTestCase extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Migrations::run(); // idempotent
		foreach ( Migrations::tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		self::clearScheduledActions();
		// wp_options is not truncated above (only the plugin's own tables are), and core's
		// per-test transaction rollback does not clear the object cache - `update_option()` writes
		// through to both, so a value another test wrote can otherwise survive into this one even
		// though its DB row is gone. `reservant_settings` is the only option user-facing code
		// writes in a way tests observe (`Settings::update()`); `reservant_version`/
		// `reservant_db_version` are upgrade-trigger markers every relevant call sets fresh anyway,
		// not state a test could see stale. This used to be an opt-in per test class (`SettingsTest`,
		// `AdminSettingsTest`); hoisted here so the leak cannot recur in a class that forgets it.
		delete_option( 'reservant_settings' );
		self::clearRateLimiter();
	}

	/**
	 * `RateLimiter::allow()` counts in a transient, and a hold's own locked write commits the
	 * enclosing connection (AGENTS.md section 2.2) - so a counter left by an earlier test class
	 * outlives the harness's per-test ROLLBACK. Every caller of `POST /holds` buckets on the same
	 * client IP under this harness (`wordpress-phpunit/includes/functions.php` hard-codes
	 * `$_SERVER['REMOTE_ADDR']` to `127.0.0.1` for the whole run, and nothing resets it per test), so
	 * without this every integration test class shares one rate-limiter bucket by construction. This
	 * used to be an opt-in per test class (`RestApiTest`, `RescheduleRouteTest`); hoisted here for the
	 * same reason `reservant_settings` was, above.
	 */
	private static function clearRateLimiter(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%reservant\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		wp_cache_flush();
	}

	/**
	 * Action Scheduler's tables are outside every isolation mechanism this suite has.
	 *
	 * They are not among the plugin tables truncated above, and WordPress's per-test transaction does
	 * not cover them either: `TransactionRunner`'s own `START TRANSACTION` (AGENTS.md section 2.2)
	 * implicitly commits the enclosing one, so every approval hold a test mints leaves its three nag
	 * rows and its timeout row behind - permanently, across whole suite runs, on a wp-env volume that
	 * is never recreated. Observed at 1422 pending rows, at which point a booking's own three nags
	 * straddled `as_get_scheduled_actions()`'s page window and `JobsTest` failed with two of them.
	 * Raising `per_page` would only move the cliff; this removes it.
	 *
	 * Scoped to the plugin's own group, and the recurring sweeper is deliberately spared: it is
	 * created once per process by `Plugin::register()`'s `init` hook, exactly as it is on a live
	 * site, and deleting it would misrepresent the environment for every later test (and quietly
	 * gut `JobsTest::testSweepIsAlreadyScheduledByPluginRegistration`). Nothing else in the group
	 * belongs to a test that has finished.
	 */
	private static function clearScheduledActions(): void {
		global $wpdb;
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a, l FROM {$wpdb->prefix}actionscheduler_actions a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id
				 LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON l.action_id = a.action_id
				 WHERE g.slug = %s AND a.hook <> %s", // phpcs:ignore WordPress.DB.PreparedSQL
				'reservant',
				Jobs::SWEEP
			)
		);
	}

	/**
	 * A fixture instant, always a week or more ahead of the wall clock.
	 *
	 * HoldBooking measures lead time and horizon from `max(injected now, wall clock)` so that a
	 * lagging clock cannot book into the past (AGENTS.md section 2.2 step 3). Hard-coded calendar dates
	 * would therefore start failing the day they slipped behind today. Day 0 is the "now" these
	 * tests inject; bookings live on day 1 and later.
	 */
	protected function utc( int $dayOffset, string $time = '00:00' ): \DateTimeImmutable {
		$parts = array_map( 'intval', explode( ':', $time ) );
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
			->setTime( 0, 0 )
			->modify( '+' . ( 7 + $dayOffset ) . ' days' )
			->setTime( $parts[0], $parts[1] );
	}

	/** The same instant as the DB stores it. */
	protected function sql( int $dayOffset, string $time = '00:00' ): string {
		return $this->utc( $dayOffset, $time )->format( 'Y-m-d H:i:s' );
	}
}
