<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration;

use Reservant\Admin\Capabilities;
use Reservant\Infrastructure\Db\Migrations;
use Reservant\Settings;

/**
 * `uninstall.php` (AGENTS.md section 3 and section 7: "keep data by default; purge only if the user
 * opted in").
 *
 * What these tests prove: given the file WordPress includes on plugin deletion, the stored
 * `purge_on_uninstall` flag decides - `true` drops every Reservant table, deletes every Reservant
 * option and gives the administrator role back exactly the capabilities it had before, while a
 * missing, false, or merely truthy flag leaves all of it untouched.
 *
 * What they do NOT prove: that WordPress actually reaches this file. `uninstall_plugin()` finds it
 * by path and `define()`s `WP_UNINSTALL_PLUGIN` itself, which can only happen once per PHP process,
 * so calling it here would make every test after the first emit a redefinition warning. These tests
 * define the constant once and `require` the file directly; `test_uninstall_php_is_where_wordpress_looks_for_it`
 * pins the one thing that indirection gives up - that the file sits at the plugin root under the
 * name core looks for. Multisite deletion (per-site loops) is out of scope, as it is for the plugin
 * as a whole (AGENTS.md section 10).
 */
final class UninstallTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		// `WP_UnitTestCase_Base::start_transaction()` installs two `query` filters that rewrite
		// `CREATE TABLE` to `CREATE TEMPORARY TABLE` and `DROP TABLE` to `DROP TEMPORARY TABLE`, so
		// that a test which creates tables cannot pollute the database. Under them a purge is a
		// silent no-op: `DROP TEMPORARY TABLE IF EXISTS wptests_reservant_bookings` matches nothing
		// and reports nothing, and the test would pass while proving the opposite of what it claims.
		// Core's own dbDelta tests remove the pair for exactly this reason; `tear_down()` below is
		// what makes that safe here.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'reservant/reservant.php' );
		}
		Capabilities::sync();
	}

	public function tear_down(): void {
		// `DROP TABLE` implicitly commits, so WordPress's per-test transaction cannot roll the purge
		// back: whatever the opt-in test destroyed is rebuilt here, by hand, before the next test in
		// the process runs.
		Migrations::run();
		Capabilities::sync();
		parent::tear_down();
	}

	/** Exactly what WordPress does on delete: include the file with the uninstall constant defined. */
	private function uninstall(): void {
		require RESERVANT_PATH . 'uninstall.php';
	}

	private function seedRow(): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'reservant_services',
			array(
				'name'       => 'Cut',
				'created_at' => $this->sql( 0 ),
				'updated_at' => $this->sql( 0 ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function tableExists( string $table ): bool {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		return $full === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	}

	public function test_uninstall_php_is_where_wordpress_looks_for_it(): void {
		self::assertFileExists( RESERVANT_PATH . 'uninstall.php' );
	}

	public function test_default_install_keeps_every_table_and_option(): void {
		// No `reservant_settings` row at all - a site whose owner never opened the settings screen.
		delete_option( 'reservant_settings' );
		$serviceId = $this->seedRow();

		$this->uninstall();

		foreach ( Migrations::tables() as $table ) {
			self::assertTrue( $this->tableExists( $table ), "Uninstall dropped {$table} without an opt-in." );
		}
		global $wpdb;
		self::assertSame(
			'Cut',
			$wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}reservant_services WHERE id = %d", $serviceId ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		self::assertTrue( get_role( 'administrator' )->has_cap( 'reservant_manage_bookings' ) );
		self::assertNotNull( get_role( 'reservant_staff' ) );
	}

	public function test_an_explicit_opt_out_keeps_every_table(): void {
		Settings::make()->update( array( 'purge_on_uninstall' => false ) );
		$this->seedRow();

		$this->uninstall();

		foreach ( Migrations::tables() as $table ) {
			self::assertTrue( $this->tableExists( $table ), "Uninstall dropped {$table} against an explicit opt-out." );
		}
		self::assertIsArray( get_option( 'reservant_settings' ) );
	}

	/**
	 * The flag is read raw (no `Settings` class is autoloaded during uninstall), so the check is
	 * `true ===` rather than truthy: an int `1` written by some other version, a `"yes"`, or any
	 * other near miss must read as "keep", not as consent to destroy the site's bookings.
	 */
	public function test_a_truthy_but_non_boolean_flag_is_not_an_opt_in(): void {
		update_option( 'reservant_settings', array( 'purge_on_uninstall' => 1 ), false );
		$this->seedRow();

		$this->uninstall();

		self::assertTrue( $this->tableExists( 'reservant_bookings' ) );
		self::assertTrue( $this->tableExists( 'reservant_services' ) );
	}

	public function test_opting_in_drops_every_table_option_and_capability(): void {
		Settings::make()->update( array( 'purge_on_uninstall' => true ) );
		update_option( 'reservant_fixture_ids', array( 'cut' => 1 ), false );
		update_option( 'reservant_license', array( 'key' => 'SOME-KEY' ), false );
		update_option( 'reservant_version', '9.9.9', false );
		update_option( 'reservant_rewrite_version', '9', false );
		$this->seedRow();

		$this->uninstall();

		// Every table Migrations knows about - so a table added to the schema but forgotten in
		// uninstall.php's hand-copied list fails here rather than surviving a purge in the field.
		foreach ( Migrations::tables() as $table ) {
			self::assertFalse( $this->tableExists( $table ), "Uninstall left {$table} behind after an opt-in." );
		}

		// `reservant_license` is in this list because it holds a credential: an opted-in purge that
		// left the owner's key in wp_options would be a purge that kept the one row nobody wants kept.
		foreach ( array( 'reservant_settings', 'reservant_license', 'reservant_version', 'reservant_db_version', 'reservant_rewrite_version', 'reservant_fixture_ids' ) as $option ) {
			self::assertFalse( get_option( $option ), "Uninstall left the {$option} option behind." );
		}

		self::assertNull( get_role( 'reservant_staff' ) );
		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::ALL as $cap ) {
			self::assertFalse( $administrator->has_cap( $cap ), "Uninstall left the {$cap} capability on administrator." );
		}
	}

	/**
	 * The blast radius, stated as an assertion rather than as a comment: the purge must not reach a
	 * table, an option or a role that is not Reservant's. Nothing core is written here on purpose -
	 * the `DROP TABLE` inside `uninstall()` implicitly commits, so a test that had first edited, say,
	 * `blogname` would leave that edit behind for the rest of the process.
	 */
	public function test_opting_in_touches_nothing_outside_reservants_own_storage(): void {
		Settings::make()->update( array( 'purge_on_uninstall' => true ) );
		update_option( 'unrelated_plugin_settings', array( 'keep' => 'me' ), false );
		$siteUrl = get_option( 'siteurl' );

		$this->uninstall();

		global $wpdb;
		foreach ( array( 'posts', 'postmeta', 'users', 'options' ) as $core ) {
			$full = $wpdb->prefix . $core;
			self::assertSame( $full, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ), "Uninstall dropped the core {$core} table." );
		}
		self::assertSame( $siteUrl, get_option( 'siteurl' ) );
		self::assertSame( array( 'keep' => 'me' ), get_option( 'unrelated_plugin_settings' ) );
		self::assertTrue( get_role( 'editor' )->has_cap( 'edit_posts' ) );

		delete_option( 'unrelated_plugin_settings' ); // Committed alongside the drop; not this suite's to leave behind.
	}
}
