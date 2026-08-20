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
		// Same leak, same reason, one row over: `reservant_license` is written by
		// `Licensing\LicenseRecord::persist()` and survives the harness's rollback through the object
		// cache exactly as `reservant_settings` does. Every licensing test starts from "no license".
		delete_option( 'reservant_license' );
		self::clearRateLimiter();
		self::silenceMailTransport();
		// The provider is memoized per request; a test that installs a fake must not leak it into
		// the next one, and - just as important - WooCommerce IS active in this container, so a
		// class that never touches payments still needs the memo cleared or it inherits whichever
		// provider ran last.
		\Reservant\Application\Payment\Providers::reset();
		// The licensing twin of the line above, memoized per request in the same shape. A test that
		// filters in a fake `LicenseManager` must not leave it resolved for the next one.
		\Reservant\Licensing\Providers::reset();
		$this->resetRewriteAndTheme();
	}

	/**
	 * Install a `PaymentProvider` for this test and return it.
	 *
	 * The filter is the documented extension point (`reservant/payment_provider`), so a test that
	 * uses this is exercising the same seam a site would - not a back door.
	 */
	protected function usePaymentProvider( \Reservant\Application\Payment\PaymentProvider $provider ): \Reservant\Application\Payment\PaymentProvider {
		\Reservant\Application\Payment\Providers::reset();
		add_filter( 'reservant/payment_provider', static fn () => $provider, 10, 1 );
		return $provider;
	}

	/**
	 * Whether `wp_mail()` can reach a transport is a property of the CONTAINER, and no test's
	 * assertions may depend on it.
	 *
	 * There is no mail transport in `tests-cli`, so every `wp_mail()` returns false and
	 * `Notifications\Mailer` dutifully reports `mail_send_failed` on `reservant/error` - the channel
	 * three unrelated tests count firings on to prove that a lost audit row is visible to an
	 * operator. Once `BookingEmails` started sending on `confirmed`, those tests began counting the
	 * mailer's honest complaint alongside the audit failure they were actually asserting about, and
	 * they would have gone green again on any machine that happened to have sendmail configured.
	 * That is an environment dependency in the assertion, not a real disagreement.
	 *
	 * Priority 999 and the pass-through are both load-bearing. A capturing filter
	 * (`ApprovalEmailsTest::captureMail()`) registers at 10 and must see the message first; and
	 * because `apply_filters()` runs EVERY callback rather than stopping at the first non-null one,
	 * a suppressor that returned `true` unconditionally would overwrite the decision of a test that
	 * deliberately makes delivery fail. Returning the incoming value untouched when it is already
	 * decided leaves both kinds of test in charge of their own mail.
	 *
	 * Hoisted here for the reason `reservant_settings` and the rate limiter were: this was already
	 * an opt-in in `ApprovalActionEndpointTest`, three times, and a class that forgot it inherited a
	 * failure that had nothing to do with what it was testing.
	 */
	private static function silenceMailTransport(): void {
		add_filter(
			'pre_wp_mail',
			static fn ( $pre ) => null === $pre ? true : $pre,
			999
		);
	}

	/** The pristine stylesheet of this process, recorded by the first test to run. */
	private static ?string $baselineTheme = null;

	/**
	 * Rewrite and theme state are process-global (the in-memory `$wp_rewrite`, the active theme)
	 * and the harness resets neither outside core's own suite (`WP_RUN_CORE_TESTS`). The class
	 * that mutates them (`ManageRouteTest`) puts both back in its tear_down, but tear_down is
	 * exactly what a mid-test fatal or an aborted run skips - and the classes that would consume
	 * the leak (`ShortcodeTest`, `BlockTest`, both calling `get_permalink()`) run directly after
	 * it in directory order. Hoisted here for the same reason `delete_option('reservant_settings')`
	 * was, above: neutralize the leak at the point of consumption, so it cannot recur in a class
	 * that forgets its own cleanup. Both guards are one comparison on the no-leak path, so every
	 * other test pays nothing.
	 */
	private function resetRewriteAndTheme(): void {
		global $wp_rewrite;
		if ( '' !== (string) $wp_rewrite->permalink_structure ) {
			$this->set_permalink_structure( '' );
		}
		self::$baselineTheme ??= (string) get_stylesheet();
		if ( get_stylesheet() !== self::$baselineTheme ) {
			switch_theme( self::$baselineTheme );
		}
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
	 * Scoped to the plugin's own group, and the two RECURRING jobs are deliberately spared: the hold
	 * sweeper and the daily license re-check are created once per process by `Plugin::register()`'s
	 * `init` hook, exactly as they are on a live site, and deleting them would misrepresent the
	 * environment for every later test (and quietly gut the two tests that assert each is already
	 * scheduled). Nothing else in the group belongs to a test that has finished.
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
				 WHERE g.slug = %s AND a.hook NOT IN ( %s, %s )", // phpcs:ignore WordPress.DB.PreparedSQL
				'reservant',
				Jobs::SWEEP,
				Jobs::LICENSE
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
