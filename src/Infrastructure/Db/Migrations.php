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
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'appointment',
				duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
				processing_time_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				buffer_before_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				buffer_after_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				seat_map_id BIGINT UNSIGNED NULL,
				price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				currency CHAR(3) NOT NULL DEFAULT 'EUR',
				payment_mode VARCHAR(10) NOT NULL DEFAULT 'free',
				requires_approval TINYINT(1) NOT NULL DEFAULT 0,
				approval_hold_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48,
				on_approval_timeout VARCHAR(15) NOT NULL DEFAULT 'expire',
				cancel_window_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
				reschedule_window_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
				lead_time_min INT UNSIGNED NOT NULL DEFAULT 0,
				horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
				wc_product_id BIGINT UNSIGNED NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY type_status (type, status)
			) $charset",
			"CREATE TABLE {$p}reservant_resources (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT UNSIGNED NULL,
				name VARCHAR(190) NOT NULL,
				email VARCHAR(190) NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) $charset",
			"CREATE TABLE {$p}reservant_service_resource (
				service_id BIGINT UNSIGNED NOT NULL,
				resource_id BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (service_id, resource_id),
				KEY resource_id (resource_id)
			) $charset",
			"CREATE TABLE {$p}reservant_availability_rules (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				resource_id BIGINT UNSIGNED NOT NULL,
				weekday TINYINT UNSIGNED NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				valid_from DATE NULL,
				valid_to DATE NULL,
				PRIMARY KEY  (id),
				KEY resource_weekday (resource_id, weekday)
			) $charset",
			"CREATE TABLE {$p}reservant_availability_exceptions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				resource_id BIGINT UNSIGNED NULL,
				date_local DATE NOT NULL,
				closed TINYINT(1) NOT NULL DEFAULT 1,
				start_time TIME NULL,
				end_time TIME NULL,
				PRIMARY KEY  (id),
				KEY resource_date (resource_id, date_local)
			) $charset",
			"CREATE TABLE {$p}reservant_seat_maps (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				spec TEXT NOT NULL,
				PRIMARY KEY  (id)
			) $charset",
			"CREATE TABLE {$p}reservant_seats (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				seat_map_id BIGINT UNSIGNED NOT NULL,
				row_label VARCHAR(8) NOT NULL,
				seat_label VARCHAR(8) NOT NULL,
				sort_row SMALLINT UNSIGNED NOT NULL,
				sort_col SMALLINT UNSIGNED NOT NULL,
				kind VARCHAR(10) NOT NULL DEFAULT 'seat',
				PRIMARY KEY  (id),
				KEY seat_map (seat_map_id, sort_row, sort_col)
			) $charset",
			"CREATE TABLE {$p}reservant_occurrences (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				service_id BIGINT UNSIGNED NOT NULL,
				start_utc DATETIME NOT NULL,
				end_utc DATETIME NOT NULL,
				capacity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				booked_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (id),
				KEY service_start (service_id, start_utc)
			) $charset",
			"CREATE TABLE {$p}reservant_resource_days (
				resource_id BIGINT UNSIGNED NOT NULL,
				day_utc DATE NOT NULL,
				rev INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (resource_id, day_utc)
			) $charset",
			"CREATE TABLE {$p}reservant_bookings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid CHAR(36) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				hold_class VARCHAR(20) NULL,
				hold_expires_at DATETIME NULL,
				customer_name VARCHAR(190) NOT NULL DEFAULT '',
				customer_email VARCHAR(190) NOT NULL DEFAULT '',
				customer_phone VARCHAR(50) NOT NULL DEFAULT '',
				total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				currency CHAR(3) NOT NULL DEFAULT 'EUR',
				payment_mode VARCHAR(10) NOT NULL DEFAULT 'free',
				requires_approval TINYINT(1) NOT NULL DEFAULT 0,
				approved_at DATETIME NULL,
				approved_by BIGINT UNSIGNED NULL,
				rejection_reason TEXT NULL,
				wc_order_id BIGINT UNSIGNED NULL,
				manage_token_hash CHAR(64) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status_hold (status, hold_expires_at)
			) $charset",
			"CREATE TABLE {$p}reservant_booking_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				service_id BIGINT UNSIGNED NOT NULL,
				resource_id BIGINT UNSIGNED NULL,
				occurrence_id BIGINT UNSIGNED NULL,
				start_utc DATETIME NOT NULL,
				end_utc DATETIME NOT NULL,
				block_start_utc DATETIME NOT NULL,
				block_end_utc DATETIME NOT NULL,
				processing_ends_utc DATETIME NULL,
				seats SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				seat_claim BIGINT UNSIGNED NULL,
				price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY occ_seat (occurrence_id, seat_claim),
				KEY booking_id (booking_id),
				KEY resource_block (resource_id, block_start_utc, block_end_utc),
				KEY occurrence_id (occurrence_id)
			) $charset",
			"CREATE TABLE {$p}reservant_booking_meta (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				meta_key VARCHAR(190) NOT NULL,
				meta_value LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY booking_key (booking_id, meta_key)
			) $charset",
			"CREATE TABLE {$p}reservant_audit_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
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
		self::grantCapabilities();
		update_option( 'reservant_db_version', defined( 'RESERVANT_VERSION' ) ? RESERVANT_VERSION : '' );
	}

	/**
	 * Custom capabilities, never `manage_options` (AGENTS.md section 7).
	 *
	 * Only the administrator grant lands here; the `reservant_staff` role and the remaining caps
	 * arrive with the admin surface. `get_role()` is null on a network where roles have not been
	 * installed yet, and re-adding an existing cap would rewrite the roles option on every run -
	 * both are guarded.
	 */
	private static function grantCapabilities(): void {
		$role = get_role( 'administrator' );
		if ( null === $role || $role->has_cap( 'reservant_manage_bookings' ) ) {
			return;
		}
		$role->add_cap( 'reservant_manage_bookings' );
	}
}
