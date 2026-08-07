<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Admin\Capabilities;
use Reservant\Infrastructure\Db\Migrations;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Plugin;
use Reservant\Tests\Integration\ReservantTestCase;

final class MigrationsTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		// `WP_UnitTestCase_Base::start_transaction()` installs two `query` filters that rewrite
		// `CREATE TABLE` to `CREATE TEMPORARY TABLE` and `DROP TABLE` to `DROP TEMPORARY TABLE`.
		// Left in place, the legacy schema below would be built as session-temporary tables shadowing
		// the real ones - dbDelta would still be fed a v0.1.x shape (its `DESCRIBE` resolves to the
		// shadow), so the assertions would still hold, but they would hold for the wrong tables and
		// the shadows would outlive the test. Core's own dbDelta tests remove the same pair; the
		// schema is rebuilt in `tear_down()` because nothing rolls real DDL back.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		// The upgrade-path tests below (`test_upgrading_a_v0_1_x_schema_*`, and the v0.2.x ->
		// v0.3.0 pair that drops `reservant_services.description` before re-adding it) replace or
		// alter the real tables and let `Migrations::run()` bring them forward; every one of those
		// operations is DDL, which implicitly commits, so WordPress's per-test transaction cannot
		// undo any of it. Put the current schema and the capability grants back by hand before the
		// next test in the process runs.
		Migrations::run();
		Capabilities::sync();
		parent::tear_down();
	}

	public function test_all_tables_exist(): void {
		global $wpdb;
		foreach ( Migrations::tables() as $table ) {
			$full = $wpdb->prefix . $table;
			self::assertSame( $full, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ), "Missing table {$table}" );
		}
	}

	public function test_booking_items_has_seat_claim_unique_index(): void {
		global $wpdb;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}reservant_booking_items WHERE Key_name = 'occ_seat'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertNotEmpty( $indexes );
		self::assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_run_is_idempotent(): void {
		Migrations::run();
		Migrations::run();
		self::assertSame( RESERVANT_VERSION, get_option( 'reservant_db_version' ) );
	}

	// ------------------------------------------------------------------ the v0.1.x upgrade path

	/**
	 * The real upgrade: a site running v0.1.x takes this release and `Migrations::run()` brings its
	 * schema forward. Everything below runs against a table set actually shaped the way v0.1.x
	 * shipped it, not against the already-current one the test bootstrap creates.
	 *
	 * Four properties, and the third is the whole content of the display-width fix (commit af95332):
	 *
	 * 1. The two genuinely missing columns (`bookings.approved_by`, added by af95332's release, and
	 *    `services.description`, added by this one) are added - and nothing else. Both are new
	 *    since the frozen fixture below, so a real v0.1.x site takes both `ADD COLUMN`s in the same
	 *    upgrade; a fixture representing v0.2.x instead (already has `approved_by`) is covered
	 *    separately below by `test_upgrading_a_v0_2_x_schema_adds_only_the_description_column()`.
	 * 2. Nothing else is altered - in particular not one `CHANGE COLUMN` on a column whose only
	 *    difference is a display width. MariaDB always reports `bigint(20) unsigned` from `DESCRIBE`,
	 *    while a widthless `BIGINT UNSIGNED` source spec makes dbDelta believe every such column has
	 *    the wrong type and re-issue an `ALTER TABLE ... CHANGE COLUMN` for it on EVERY run, forever
	 *    (dbDelta's own strip-the-display-width shortcut is guarded on MySQL 8.0.17+ and never
	 *    applies here). Delete the explicit widths from `Migrations::run()` and this assertion is the
	 *    thing that fails; the rest of the suite still passes, only slower.
	 * 3. A second consecutive run issues no DDL whatsoever.
	 * 4. The site's data and every index - including the unique `occ_seat` backstop of AGENTS.md
	 *    section 2.2 - come through untouched.
	 *
	 * Queries are captured through wpdb's own `query` filter rather than `SAVEQUERIES` or dbDelta's
	 * return value. `SAVEQUERIES` is a constant: defining it here would stay defined for the rest of
	 * the process and make every later test accumulate its statements. dbDelta's return value is the
	 * other honest option (it is what af95332 was diagnosed with) but `Migrations::run()` discards
	 * it, and exposing it purely for a test would be writing the assertion into the code under test.
	 * The `query` filter sees exactly the SQL wpdb executes, is removed the moment the call returns,
	 * and asserts on what actually reached the database rather than on what dbDelta reported.
	 */
	public function test_upgrading_a_v0_1_x_schema_adds_only_the_missing_columns(): void {
		global $wpdb;
		$this->installLegacySchema();
		$seeded = $this->seedLegacyBookingAndItem();

		$first = self::altersIn( $this->captureQueries( static fn () => Migrations::run() ) );
		$typed = array_values( array_filter( $first, static fn ( string $sql ): bool => false !== stripos( $sql, 'CHANGE COLUMN' ) ) );

		self::assertSame(
			array(),
			$typed,
			"dbDelta re-typed columns that were already correct. This is the display-width regression: every\n"
				. "UNSIGNED column in Migrations::run() needs an explicit width matching what MariaDB reports.\n"
				. implode( "\n", $typed )
		);
		self::assertCount( 2, $first, "Expected exactly two ALTER TABLE statements.\n" . implode( "\n", $first ) );
		$joined = implode( "\n", $first );
		self::assertMatchesRegularExpression( '/ALTER TABLE \S*reservant_bookings ADD COLUMN\s+approved_by\b/i', $joined );
		self::assertMatchesRegularExpression( '/ALTER TABLE \S*reservant_services ADD COLUMN\s+description\b/i', $joined );

		// Both columns really are there, and the pre-existing rows got the defaults dbDelta gives an
		// added column (NULL for `approved_by`, which has none in the schema; the empty string for
		// `description`, which is `TEXT NOT NULL` with none either) rather than a value invented here.
		self::assertSame(
			'approved_by',
			$wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}reservant_bookings LIKE 'approved_by'" ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		self::assertSame(
			'description',
			$wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}reservant_services LIKE 'description'" ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reservant_bookings WHERE uuid = %s", $seeded['uuid'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertIsArray( $booking, 'The upgrade lost the booking row.' );
		self::assertNull( $booking['approved_by'] );
		self::assertSame( 'awaiting_approval', $booking['status'] );
		self::assertSame( 'Legacy Customer', $booking['customer_name'] );

		// The item, including its seat claim - the column the unique index backstops.
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reservant_booking_items WHERE booking_id = %d", (int) $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertIsArray( $item, 'The upgrade lost the booking item.' );
		self::assertSame( (int) $seeded['seat_claim'], (int) $item['seat_claim'] );
		self::assertSame( $seeded['block_start_utc'], $item['block_start_utc'] );

		// Every index the schema promises, still present and still unique where it must be.
		self::assertSame( '0', $this->indexNonUnique( 'reservant_booking_items', 'occ_seat' ), 'occ_seat must survive the upgrade as a UNIQUE index.' );
		self::assertSame( '0', $this->indexNonUnique( 'reservant_bookings', 'uuid' ) );
		self::assertSame( '1', $this->indexNonUnique( 'reservant_bookings', 'status_hold' ) );
		self::assertSame( '1', $this->indexNonUnique( 'reservant_booking_items', 'booking_id' ) );
		self::assertSame( '1', $this->indexNonUnique( 'reservant_booking_items', 'resource_block' ) );
		self::assertSame( '1', $this->indexNonUnique( 'reservant_booking_items', 'occurrence_id' ) );
		self::assertSame( '1', $this->indexNonUnique( 'reservant_occurrences', 'service_start' ) );
	}

	/**
	 * The second half of the same story, kept separate so a failure names which one broke: once the
	 * upgrade has run, running it again must be a no-op at the database level. This is the assertion
	 * that turns "the suite got slower" - the only symptom the display-width bug ever produced in CI -
	 * into "the suite failed".
	 */
	public function test_a_second_consecutive_run_issues_no_ddl_at_all(): void {
		$this->installLegacySchema();
		Migrations::run();

		$second = $this->captureQueries( static fn () => Migrations::run() );

		self::assertSame( array(), self::altersIn( $second ), "A settled schema must produce no ALTER TABLE.\n" . implode( "\n", self::altersIn( $second ) ) );
		self::assertSame( array(), self::createsIn( $second ), "A settled schema must produce no CREATE TABLE.\n" . implode( "\n", self::createsIn( $second ) ) );
	}

	// ------------------------------------------------------------------ the v0.2.x -> v0.3.0 upgrade path (description column)

	/**
	 * The narrower, more literal upgrade this task actually ships: a site already on v0.2.x (has
	 * `approved_by`, has every display-width fix, does not have `services.description`) takes v0.3.0
	 * and `Migrations::run()` adds exactly one column.
	 *
	 * Unlike `installLegacySchema()` above, this does not hand-freeze a second whole schema copy.
	 * v0.1.x differed from current on nearly every column (nine tables' worth of missing display
	 * widths), which is why that fixture is worth maintaining as a literal historical copy. v0.2.x
	 * differs from current by exactly one column, so the truer and far less duplicative way to build
	 * "a table shaped like v0.2.x" is to run the real, current `Migrations::run()` and then drop that
	 * one column back off - the result is byte-for-byte what a v0.2.x install's `reservant_services`
	 * table actually looks like, not a hand-copied approximation of it that could drift from the real
	 * schema over time.
	 */
	public function test_upgrading_a_v0_2_x_schema_adds_only_the_description_column(): void {
		global $wpdb;
		Migrations::run();
		$serviceId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Legacy Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' )
		);
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}reservant_services DROP COLUMN description" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$first = self::altersIn( $this->captureQueries( static fn () => Migrations::run() ) );

		self::assertCount( 1, $first, "Expected exactly one ALTER TABLE.\n" . implode( "\n", $first ) );
		self::assertMatchesRegularExpression(
			'/^ALTER TABLE \S*reservant_services ADD COLUMN\s+description\b/i',
			trim( $first[0] )
		);
		self::assertSame(
			'description',
			$wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}reservant_services LIKE 'description'" ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		// The pre-existing row survived the upgrade and reads back as the empty string, not NULL or
		// a dropped row - `TEXT NOT NULL` with no SQL-level DEFAULT still resolves to `''` for a row
		// that predates the column, the same way it does for a fresh INSERT that omits the field
		// (verified against this database directly; see the report for the reproduction).
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reservant_services WHERE id = %d", $serviceId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertIsArray( $row, 'The upgrade lost the pre-existing service row.' );
		self::assertSame( '', $row['description'] );
		self::assertSame( 'Legacy Cut', $row['name'] );
	}

	/**
	 * The dbDelta-stability proof the fix-round instructions asked for by name: not "it looks right"
	 * but a captured, asserted absence of any `ALTER TABLE` touching `description` (or anything else)
	 * once the column is in place. Mirrors `test_a_second_consecutive_run_issues_no_ddl_at_all()`
	 * above, scoped to the v0.2.x starting point instead of v0.1.x, since that is the step this task
	 * actually adds.
	 */
	public function test_a_second_run_after_the_description_upgrade_issues_no_ddl(): void {
		global $wpdb;
		Migrations::run();
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}reservant_services DROP COLUMN description" ); // phpcs:ignore WordPress.DB.PreparedSQL
		Migrations::run(); // First run after the drop: re-adds the column (proven above).

		$second = $this->captureQueries( static fn () => Migrations::run() );

		self::assertSame( array(), self::altersIn( $second ), "A settled schema must produce no ALTER TABLE.\n" . implode( "\n", self::altersIn( $second ) ) );
		self::assertSame( array(), self::createsIn( $second ), "A settled schema must produce no CREATE TABLE.\n" . implode( "\n", self::createsIn( $second ) ) );
	}

	/**
	 * The other half of "a schema change that does not bump the version never reaches an installed
	 * site": the two tests above prove `Migrations::run()` itself upgrades a v0.2.x-shaped table, but
	 * a real site never calls that directly - it goes through `Plugin::boot()`'s
	 * `reservant_version` option check. This pins that a site whose stored option genuinely reads
	 * `0.2.0` (not merely absent, which `test_the_upgrade_entry_point_restores_capabilities_it_no_longer_grants_itself`
	 * already covers below) reaches `Migrations::run()` because `RESERVANT_VERSION` moved to `0.3.0`,
	 * and ends the request with both the column and the stored option caught up.
	 */
	public function test_plugin_boot_upgrades_a_site_whose_stored_version_is_0_2_0(): void {
		global $wpdb;
		Migrations::run();
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}reservant_services DROP COLUMN description" ); // phpcs:ignore WordPress.DB.PreparedSQL
		update_option( 'reservant_version', '0.2.0' );

		Plugin::boot();

		self::assertSame(
			'description',
			$wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}reservant_services LIKE 'description'" ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		self::assertSame( RESERVANT_VERSION, get_option( 'reservant_version' ) );
	}

	// ------------------------------------------------------------------ run() + Capabilities::sync()

	/**
	 * `Migrations::grantCapabilities()` was removed on this branch, so `Migrations::run()` no longer
	 * grants anything. Every production caller pairs it with `Capabilities::sync()`; this pins the
	 * pairing at the entry point rather than testing the two calls in isolation, because "run()
	 * without sync()" is precisely the mistake that would strip an administrator's caps on upgrade
	 * and pass a test suite that only ever checked them separately.
	 *
	 * `Plugin::boot()` is the upgrade path (it fires when `reservant_version` no longer matches) and
	 * `Plugin::activate()` is the install path. Both are exercised from a genuinely stripped role, so
	 * a regression that drops the `Capabilities::sync()` line from either fails here.
	 */
	public function test_the_upgrade_entry_point_restores_capabilities_it_no_longer_grants_itself(): void {
		$this->stripReservantCapabilities();
		delete_option( 'reservant_version' ); // Force boot() down the upgrade branch.

		Plugin::boot();

		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::ALL as $cap ) {
			self::assertTrue( $administrator->has_cap( $cap ), "Plugin::boot() upgraded the schema but left {$cap} ungranted." );
		}
		self::assertNotNull( get_role( 'reservant_staff' ), 'Plugin::boot() upgraded the schema but left the staff role missing.' );
		self::assertSame( RESERVANT_VERSION, get_option( 'reservant_version' ) );
	}

	public function test_the_activation_entry_point_grants_capabilities_too(): void {
		$this->stripReservantCapabilities();

		Plugin::activate();

		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::ALL as $cap ) {
			self::assertTrue( $administrator->has_cap( $cap ), "Plugin::activate() installed the schema but left {$cap} ungranted." );
		}
		self::assertNotNull( get_role( 'reservant_staff' ) );
	}

	/** Running the migrations ALONE must not be mistaken for a grant - that is the whole risk. */
	public function test_migrations_run_alone_grants_nothing(): void {
		$this->stripReservantCapabilities();

		Migrations::run();

		self::assertFalse( get_role( 'administrator' )->has_cap( 'reservant_manage_bookings' ) );
		self::assertNull( get_role( 'reservant_staff' ) );
	}

	// ------------------------------------------------------------------ helpers

	private function stripReservantCapabilities(): void {
		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::ALL as $cap ) {
			$administrator->remove_cap( $cap );
		}
		remove_role( 'reservant_staff' );
	}

	/**
	 * Every SQL statement wpdb executed while `$run` was running.
	 *
	 * @param callable():void $run
	 * @return list<string>
	 */
	private function captureQueries( callable $run ): array {
		$seen   = array();
		$filter = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$run();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $seen;
	}

	/**
	 * @param list<string> $queries
	 * @return list<string>
	 */
	private static function altersIn( array $queries ): array {
		return array_values( array_filter( $queries, static fn ( string $sql ): bool => 1 === preg_match( '/^\s*ALTER\s+TABLE/i', $sql ) ) );
	}

	/**
	 * @param list<string> $queries
	 * @return list<string>
	 */
	private static function createsIn( array $queries ): array {
		return array_values( array_filter( $queries, static fn ( string $sql ): bool => 1 === preg_match( '/^\s*CREATE\s+TABLE/i', $sql ) ) );
	}

	private function indexNonUnique( string $table, string $keyName ): string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$wpdb->prefix}{$table} WHERE Key_name = %s", $keyName ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertNotEmpty( $rows, "Index {$keyName} is missing from {$table}." );
		return (string) $rows[0]->Non_unique;
	}

	/** Drop the current tables and recreate them exactly as v0.1.x shipped them. */
	private function installLegacySchema(): void {
		global $wpdb;
		foreach ( array_reverse( Migrations::tables() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		foreach ( self::legacySchemas() as $sql ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * A booking and an item on the legacy tables, written column by column so nothing in `src/`
	 * (which knows about `approved_by`) is involved in producing the pre-upgrade state.
	 *
	 * @return array{uuid: string, seat_claim: int, block_start_utc: string}
	 */
	private function seedLegacyBookingAndItem(): array {
		global $wpdb;
		$uuid  = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$start = $this->sql( 1, '09:00' );
		$end   = $this->sql( 1, '10:00' );

		$wpdb->insert(
			$wpdb->prefix . 'reservant_bookings',
			array(
				'uuid'            => $uuid,
				'status'          => 'awaiting_approval',
				'hold_class'      => 'approval',
				'hold_expires_at' => $this->sql( 3 ),
				'customer_name'   => 'Legacy Customer',
				'customer_email'  => 'legacy@example.com',
				'total_minor'     => 4200,
				'currency'        => 'EUR',
				'payment_mode'    => 'onsite',
				'created_at'      => $this->sql( 0 ),
				'updated_at'      => $this->sql( 0 ),
			)
		);
		$bookingId = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'reservant_booking_items',
			array(
				'booking_id'      => $bookingId,
				'sort'            => 0,
				'service_id'      => 1,
				'occurrence_id'   => 7,
				'start_utc'       => $start,
				'end_utc'         => $end,
				'block_start_utc' => $start,
				'block_end_utc'   => $end,
				'seats'           => 1,
				'seat_claim'      => 11,
				'price_minor'     => 4200,
			)
		);

		return array(
			'uuid'            => $uuid,
			'seat_claim'      => 11,
			'block_start_utc' => $start,
		);
	}

	/**
	 * The v0.1.x schema, frozen: the spec as of commit a3b8ac9 (before every UNSIGNED column got an
	 * explicit display width) with `bookings.approved_by` removed, since that column arrived later.
	 * A deliberate literal copy - it is a historical artifact, and deriving it from the current
	 * `Migrations::run()` would make the test agree with whatever that file says today.
	 *
	 * @return list<string>
	 */
	private static function legacySchemas(): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		return array(
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
	}
}
