<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration;

use Reservant\Infrastructure\Db\Migrations;

abstract class ReservantTestCase extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Migrations::run(); // idempotent
		foreach ( Migrations::tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		// wp_options is not truncated above (only the plugin's own tables are), and core's
		// per-test transaction rollback does not clear the object cache - `update_option()` writes
		// through to both, so a value another test wrote can otherwise survive into this one even
		// though its DB row is gone. `reservant_settings` is the only option user-facing code
		// writes in a way tests observe (`Settings::update()`); `reservant_version`/
		// `reservant_db_version` are upgrade-trigger markers every relevant call sets fresh anyway,
		// not state a test could see stale. This used to be an opt-in per test class (`SettingsTest`,
		// `AdminSettingsTest`); hoisted here so the leak cannot recur in a class that forgets it.
		delete_option( 'reservant_settings' );
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
