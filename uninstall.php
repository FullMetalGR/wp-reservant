<?php
/**
 * Uninstall handler (AGENTS.md section 3 and section 7: "keep data by default; purge only if the
 * user opted in").
 *
 * WordPress runs this file - and nothing else of the plugin - when the user DELETES Reservant from
 * the Plugins screen. Deactivation never reaches it. Because the plugin itself is not loaded here,
 * nothing in `src/` is autoloaded and no Reservant class may be referenced: the opt-in flag is read
 * straight out of its option row, and the table list below is a literal copy of
 * `Reservant\Infrastructure\Db\Migrations::tables()` rather than a call to it.
 *
 * This is the only destructive code in the repository, so every step is deliberately narrow:
 *
 * - It refuses to run outside WordPress's uninstall path (`WP_UNINSTALL_PLUGIN` is defined by
 *   `uninstall_plugin()` immediately before this file is included, and by nothing else).
 * - It does nothing at all unless `purge_on_uninstall` is stored as boolean `true`. The check is
 *   `true ===`, not truthy: a missing option, a corrupt option, a `1`, a `"yes"`, or an option
 *   written by some future version with a different shape all mean "keep the data". The default in
 *   `Settings::DEFAULTS` is `false`, so silence is always "keep".
 * - It drops only the thirteen `{$wpdb->prefix}reservant_*` tables and deletes only six
 *   `reservant_*` options, each named in full. No `LIKE`, no wildcard, no loop over a discovered
 *   set - nothing else in the database can be reached from here. `reservant_license` is among them
 *   because it holds the owner's license key: a purge the owner asked for that left a credential
 *   behind in `wp_options` would not be a purge.
 * - Posts, users, comments, other plugins' tables and other plugins' options are never touched.
 *
 * Two things it deliberately does NOT clean up, because neither is Reservant's own storage:
 * Action Scheduler's tables (its rows are keyed by a `reservant` group, but the tables belong to
 * Action Scheduler, which WooCommerce may also own on the same site - orphaned actions fail
 * harmlessly and are pruned by its own retention), and the 60-second `reservant_rl_*` rate-limit
 * transients (they expire on their own within a minute and are keyed by digest, so removing them
 * would need exactly the wildcard delete this file refuses to perform).
 *
 * @package Reservant
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$reservant_settings = get_option( 'reservant_settings' );
if ( ! is_array( $reservant_settings ) || true !== ( $reservant_settings['purge_on_uninstall'] ?? null ) ) {
	return; // The default, and every ambiguous case: keep the data.
}

global $wpdb;

// Literal copy of Migrations::tables(), reverse dependency order. Kept in step by hand on purpose -
// see the file header for why this cannot call into the plugin.
$reservant_tables = array(
	'reservant_audit_log',
	'reservant_booking_meta',
	'reservant_booking_items',
	'reservant_bookings',
	'reservant_resource_days',
	'reservant_occurrences',
	'reservant_seats',
	'reservant_seat_maps',
	'reservant_availability_exceptions',
	'reservant_availability_rules',
	'reservant_service_resource',
	'reservant_resources',
	'reservant_services',
);

foreach ( $reservant_tables as $reservant_table ) {
	// The name is a constant from the list above concatenated to $wpdb->prefix - no request data
	// reaches it, and $wpdb::prepare() cannot parameterise an identifier in any case.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $reservant_table . '`' );
}

foreach ( array( 'reservant_settings', 'reservant_license', 'reservant_version', 'reservant_db_version', 'reservant_rewrite_version', 'reservant_fixture_ids' ) as $reservant_option ) {
	delete_option( $reservant_option );
}

// The capabilities and role Admin\Capabilities::sync() granted. Removing them leaves the
// administrator role exactly as it was before Reservant was ever activated.
remove_role( 'reservant_staff' );
$reservant_admin_role = get_role( 'administrator' );
if ( null !== $reservant_admin_role ) {
	foreach ( array( 'reservant_manage_bookings', 'reservant_approve_bookings', 'reservant_manage_settings', 'reservant_view_own_calendar' ) as $reservant_cap ) {
		$reservant_admin_role->remove_cap( $reservant_cap );
	}
}
