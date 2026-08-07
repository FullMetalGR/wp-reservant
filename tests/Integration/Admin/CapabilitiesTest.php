<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Admin;

use Reservant\Admin\Capabilities;
use Reservant\Plugin;
use Reservant\Tests\Integration\ReservantTestCase;

final class CapabilitiesTest extends ReservantTestCase {

	public function testSyncGrantsAdminAndCreatesStaffRole(): void {
		Capabilities::sync();

		$admin = get_role( 'administrator' );
		self::assertNotNull( $admin );
		foreach ( Capabilities::ALL as $cap ) {
			self::assertTrue( $admin->has_cap( $cap ), $cap );
		}

		$staff = get_role( 'reservant_staff' );
		self::assertNotNull( $staff );
		self::assertTrue( $staff->has_cap( 'reservant_view_own_calendar' ) );
		self::assertTrue( $staff->has_cap( 'reservant_approve_bookings' ) );
		self::assertFalse( $staff->has_cap( 'reservant_manage_settings' ) );
	}

	public function testVersionMismatchTriggersSync(): void {
		update_option( 'reservant_version', '0.0.1' );
		$admin = get_role( 'administrator' );
		self::assertNotNull( $admin );
		$admin->remove_cap( 'reservant_manage_bookings' );

		Plugin::boot();

		self::assertTrue( get_role( 'administrator' )->has_cap( 'reservant_manage_bookings' ) );
		self::assertSame( RESERVANT_VERSION, get_option( 'reservant_version' ) );
	}
}
