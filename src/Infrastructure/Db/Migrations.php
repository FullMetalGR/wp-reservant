<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** Versioned schema. All datetimes UTC. All money integer minor units. InnoDB required. */
final class Migrations {

	/** @return list<string> unprefixed table names, dependency order */
	public static function tables(): array {
		return array(
			'reservant_services',
			'reservant_resources',
			'reservant_service_resource',
			'reservant_availability_rules',
			'reservant_availability_exceptions',
			'reservant_seat_maps',
			'reservant_seats',
			'reservant_occurrences',
			'reservant_resource_days',
			'reservant_bookings',
			'reservant_booking_items',
			'reservant_booking_meta',
			'reservant_audit_log',
		);
	}

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		$schemas = array(
			"CREATE TABLE {$p}reservant_services (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'appointment',
				duration_min SMALLINT(5) UNSIGNED NOT NULL DEFAULT 30,
				processing_time_min SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				buffer_before_min SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				buffer_after_min SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				capacity SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
				seat_map_id BIGINT(20) UNSIGNED NULL,
				price_minor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				currency CHAR(3) NOT NULL DEFAULT 'EUR',
				payment_mode VARCHAR(10) NOT NULL DEFAULT 'free',
				requires_approval TINYINT(1) NOT NULL DEFAULT 0,
				approval_hold_hours SMALLINT(5) UNSIGNED NOT NULL DEFAULT 48,
				on_approval_timeout VARCHAR(15) NOT NULL DEFAULT 'expire',
				cancel_window_hours SMALLINT(5) UNSIGNED NOT NULL DEFAULT 24,
				reschedule_window_hours SMALLINT(5) UNSIGNED NOT NULL DEFAULT 24,
				lead_time_min INT(10) UNSIGNED NOT NULL DEFAULT 0,
				horizon_days SMALLINT(5) UNSIGNED NOT NULL DEFAULT 60,
				wc_product_id BIGINT(20) UNSIGNED NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY type_status (type, status)
			) $charset",
			"CREATE TABLE {$p}reservant_resources (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT(20) UNSIGNED NULL,
				name VARCHAR(190) NOT NULL,
				email VARCHAR(190) NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) $charset",
			"CREATE TABLE {$p}reservant_service_resource (
				service_id BIGINT(20) UNSIGNED NOT NULL,
				resource_id BIGINT(20) UNSIGNED NOT NULL,
				PRIMARY KEY  (service_id, resource_id),
				KEY resource_id (resource_id)
			) $charset",
			"CREATE TABLE {$p}reservant_availability_rules (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				resource_id BIGINT(20) UNSIGNED NOT NULL,
				weekday TINYINT(3) UNSIGNED NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				valid_from DATE NULL,
				valid_to DATE NULL,
				PRIMARY KEY  (id),
				KEY resource_weekday (resource_id, weekday)
			) $charset",
			"CREATE TABLE {$p}reservant_availability_exceptions (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				resource_id BIGINT(20) UNSIGNED NULL,
				date_local DATE NOT NULL,
				closed TINYINT(1) NOT NULL DEFAULT 1,
				start_time TIME NULL,
				end_time TIME NULL,
				PRIMARY KEY  (id),
				KEY resource_date (resource_id, date_local)
			) $charset",
			"CREATE TABLE {$p}reservant_seat_maps (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				spec TEXT NOT NULL,
				PRIMARY KEY  (id)
			) $charset",
			"CREATE TABLE {$p}reservant_seats (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				seat_map_id BIGINT(20) UNSIGNED NOT NULL,
				row_label VARCHAR(8) NOT NULL,
				seat_label VARCHAR(8) NOT NULL,
				sort_row SMALLINT(5) UNSIGNED NOT NULL,
				sort_col SMALLINT(5) UNSIGNED NOT NULL,
				kind VARCHAR(10) NOT NULL DEFAULT 'seat',
				PRIMARY KEY  (id),
				KEY seat_map (seat_map_id, sort_row, sort_col)
			) $charset",
			"CREATE TABLE {$p}reservant_occurrences (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				service_id BIGINT(20) UNSIGNED NOT NULL,
				start_utc DATETIME NOT NULL,
				end_utc DATETIME NOT NULL,
				capacity SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				booked_seats SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (id),
				KEY service_start (service_id, start_utc)
			) $charset",
			"CREATE TABLE {$p}reservant_resource_days (
				resource_id BIGINT(20) UNSIGNED NOT NULL,
				day_utc DATE NOT NULL,
				rev INT(10) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (resource_id, day_utc)
			) $charset",
			"CREATE TABLE {$p}reservant_bookings (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid CHAR(36) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				hold_class VARCHAR(20) NULL,
				hold_expires_at DATETIME NULL,
				customer_name VARCHAR(190) NOT NULL DEFAULT '',
				customer_email VARCHAR(190) NOT NULL DEFAULT '',
				customer_phone VARCHAR(50) NOT NULL DEFAULT '',
				total_minor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				currency CHAR(3) NOT NULL DEFAULT 'EUR',
				payment_mode VARCHAR(10) NOT NULL DEFAULT 'free',
				requires_approval TINYINT(1) NOT NULL DEFAULT 0,
				approved_at DATETIME NULL,
				approved_by BIGINT(20) UNSIGNED NULL,
				rejection_reason TEXT NULL,
				wc_order_id BIGINT(20) UNSIGNED NULL,
				manage_token_hash CHAR(64) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status_hold (status, hold_expires_at)
			) $charset",
			"CREATE TABLE {$p}reservant_booking_items (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT(20) UNSIGNED NOT NULL,
				sort SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				service_id BIGINT(20) UNSIGNED NOT NULL,
				resource_id BIGINT(20) UNSIGNED NULL,
				occurrence_id BIGINT(20) UNSIGNED NULL,
				start_utc DATETIME NOT NULL,
				end_utc DATETIME NOT NULL,
				block_start_utc DATETIME NOT NULL,
				block_end_utc DATETIME NOT NULL,
				processing_ends_utc DATETIME NULL,
				seats SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
				seat_claim BIGINT(20) UNSIGNED NULL,
				price_minor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY occ_seat (occurrence_id, seat_claim),
				KEY booking_id (booking_id),
				KEY resource_block (resource_id, block_start_utc, block_end_utc),
				KEY occurrence_id (occurrence_id)
			) $charset",
			"CREATE TABLE {$p}reservant_booking_meta (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(190) NOT NULL,
				meta_value LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY booking_key (booking_id, meta_key)
			) $charset",
			"CREATE TABLE {$p}reservant_audit_log (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT(20) UNSIGNED NOT NULL,
				actor VARCHAR(60) NOT NULL,
				action VARCHAR(60) NOT NULL,
				payload_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY booking_id (booking_id)
			) $charset",
		);

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
		// Capability grants are `Admin\Capabilities::sync()`'s job, never duplicated here: both
		// `Plugin::boot()`'s upgrade-trigger path and `Plugin::activate()` call `Migrations::run()`
		// immediately followed by `Capabilities::sync()` (which grants the full set - AGENTS.md
		// section 7's four caps, never `manage_options` - to `administrator`, a superset of what a
		// former, now-removed `grantCapabilities()` here duplicated for one cap only). A caller
		// that runs `Migrations::run()` on its own (`ReservantTestCase::set_up()`, every test) never
		// needs to re-grant anything either: the SAME upgrade-trigger path already ran once, process
		// -wide, the first time the plugin bootstrapped in that process.
		update_option( 'reservant_db_version', defined( 'RESERVANT_VERSION' ) ? RESERVANT_VERSION : '' );
	}
}
