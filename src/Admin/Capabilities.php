<?php
declare( strict_types=1 );

namespace Reservant\Admin;

/**
 * Custom capabilities and the `reservant_staff` role (AGENTS.md section 7).
 *
 * Every Reservant capability check goes through one of these four caps - never `manage_options`.
 * `sync()` is idempotent and safe to call on every version bump: it (re)grants the full set to
 * `administrator` and rebuilds `reservant_staff` from scratch so its capability list never drifts.
 */
final class Capabilities {

	public const ALL = array(
		'reservant_manage_bookings',
		'reservant_approve_bookings',
		'reservant_manage_settings',
		'reservant_view_own_calendar',
	);

	public static function sync(): void {
		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			foreach ( self::ALL as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		remove_role( 'reservant_staff' );
		add_role(
			'reservant_staff',
			__( 'Reservant Staff', 'reservant' ),
			array(
				'read'                        => true,
				'reservant_view_own_calendar' => true,
				'reservant_approve_bookings'  => true,
			)
		);
	}
}
