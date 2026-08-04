<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Infrastructure\Db\Migrations;
use Reservant\Tests\Integration\ReservantTestCase;

final class MigrationsTest extends ReservantTestCase {

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
}
