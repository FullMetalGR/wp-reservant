<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

/**
 * Capability gates for every `reservant/v1/admin/*` route (AGENTS.md section 7): every check goes
 * through one of the four Reservant capabilities - never `manage_options`.
 *
 * An unauthenticated caller gets 401 - there is no session to be short of a capability - while a
 * logged-in caller who simply lacks the capability gets 403. That distinction is what lets a
 * client tell "log in" and "you can't do that" apart, the same way a browser's own auth flows do.
 */
final class AdminGuard {

	/** @return true|\WP_Error */
	public function manageBookings(): bool|\WP_Error {
		return self::gate( 'reservant_manage_bookings' );
	}

	/** @return true|\WP_Error */
	public function manageSettings(): bool|\WP_Error {
		return self::gate( 'reservant_manage_settings' );
	}

	/** @return true|\WP_Error */
	public function approveBookings(): bool|\WP_Error {
		return self::gate( 'reservant_approve_bookings' );
	}

	/**
	 * The calendar is readable by anyone who can manage bookings outright, or a staff member
	 * limited to their own schedule (`reservant_view_own_calendar`).
	 *
	 * @return true|\WP_Error
	 */
	public function calendarAccess(): bool|\WP_Error {
		if ( current_user_can( 'reservant_manage_bookings' ) || current_user_can( 'reservant_view_own_calendar' ) ) {
			return true;
		}
		return is_user_logged_in() ? self::forbidden() : self::unauthorized();
	}

	/**
	 * Catalog LISTS, readable by a settings admin or by anyone who can manage bookings.
	 *
	 * The Calendar and Bookings screens are gated on `reservant_manage_bookings`, but neither can
	 * render without the staff and service lists - the staff filter, the service filter and the
	 * manual-booking drawer are all built from them. With those lists behind
	 * `reservant_manage_settings`, a composed "front desk" role (manage_bookings +
	 * approve_bookings) - exactly the delegation these custom capabilities exist to enable - got
	 * both pages with three permanently empty pickers while POST /admin/bookings was allowed for
	 * it. The capability model only actually worked for someone holding all four caps.
	 *
	 * Widened for READS only, and only for the two collection routes those screens fetch. Every
	 * write, every single-item read and every other catalog route stays on `reservant_manage_settings`,
	 * so a manage_bookings holder can see the catalog and still not touch it.
	 *
	 * @return true|\WP_Error
	 */
	public function readCatalog(): bool|\WP_Error {
		if ( current_user_can( 'reservant_manage_settings' ) || current_user_can( 'reservant_manage_bookings' ) ) {
			return true;
		}
		return is_user_logged_in() ? self::forbidden() : self::unauthorized();
	}

	/** @return true|\WP_Error */
	private static function gate( string $capability ): bool|\WP_Error {
		if ( current_user_can( $capability ) ) {
			return true;
		}
		return is_user_logged_in() ? self::forbidden() : self::unauthorized();
	}

	/**
	 * Shared with controllers that refuse past the capability gate itself - e.g. a staff member's
	 * own-resource-only scope on approve/reject (AGENTS.md Task 10). Same 403 shape as the gate's own.
	 */
	public static function forbiddenError(): \WP_Error {
		return self::forbidden();
	}

	private static function unauthorized(): \WP_Error {
		return new \WP_Error(
			'reservant_unauthorized',
			'unauthorized',
			array(
				'status' => 401,
				'detail' => __( 'You must be logged in to do that.', 'reservant' ),
			)
		);
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error(
			'reservant_forbidden',
			'forbidden',
			array(
				'status' => 403,
				'detail' => __( 'You do not have permission to do that.', 'reservant' ),
			)
		);
	}
}
