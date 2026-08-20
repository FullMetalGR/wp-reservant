<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Licensing\LicenseState;
use Reservant\Licensing\Providers;

/**
 * Capability gates for every `reservant/v1/admin/*` route (AGENTS.md section 7): every check goes
 * through one of the four Reservant capabilities - never `manage_options`.
 *
 * An unauthenticated caller gets 401 - there is no session to be short of a capability - while a
 * logged-in caller who simply lacks the capability gets 403. That distinction is what lets a
 * client tell "log in" and "you can't do that" apart, the same way a browser's own auth flows do.
 *
 * **One gate here asks a second question.** `configureSite()` is `manageSettings()` plus an active
 * license, and it is the ONLY place licensing is enforced in this plugin (AGENTS.md section 5,
 * "License enforcement"). Everything else - every read, every public route, the whole booking
 * lifecycle - is deliberately outside it. See `configureSite()` for the policy and why it stops
 * exactly where it does.
 */
final class AdminGuard {

	/**
	 * This request's instant, resolved once.
	 *
	 * The guard is built once per request in `AdminRoutes::register()` and kept alive by the
	 * `permission_callback` callables, so memoizing here is the same pattern the controllers use
	 * (`BookingsAdminController::now()`) and for the same reason: two checks inside one request must
	 * not straddle a grace deadline and disagree with each other.
	 */
	private ?\DateTimeImmutable $now = null;

	/** @return true|\WP_Error */
	public function manageBookings(): bool|\WP_Error {
		return self::gate( 'reservant_manage_bookings' );
	}

	/**
	 * The capability every configuration route is gated on, READS INCLUDED.
	 *
	 * Deliberately NOT license-aware, and that is not an oversight. This same callback answers
	 * `GET /admin/settings` - the screen an owner goes to in order to type the key that would make
	 * their license active again. A license check here would lock a lapsed owner out of the only
	 * door back in, with no route in the plugin that could ever let them re-enter. Writes get
	 * `configureSite()`; reads stay here.
	 *
	 * @return true|\WP_Error
	 */
	public function manageSettings(): bool|\WP_Error {
		return self::gate( 'reservant_manage_settings' );
	}

	/**
	 * `manageSettings()` AND an active license: the gate on every configuration WRITE.
	 *
	 * **What freezes when a license lapses:** creating, editing and deleting services, staff,
	 * availability rules, occurrences, seat maps and settings. That is the whole list.
	 *
	 * **What never freezes, under any circumstance:**
	 * - Every public and guest route. A customer must always be able to search availability, hold,
	 *   confirm, pay, cancel and reschedule. A billing lapse at the salon must never turn away the
	 *   salon's customers.
	 * - The entire admin booking lifecycle - approve, reject, cancel, reschedule, manual booking,
	 *   no-show, complete. `awaiting_approval` bookings sit on a TTL and `ExpireHolds` reclaims
	 *   them, so a frozen approval queue would not merely be inconvenient: held bookings would
	 *   silently expire and paying customers would be turned away by somebody's unpaid invoice.
	 *   That is a strictly worse outcome than an unlicensed site being unable to edit its own
	 *   service list.
	 * - Every read. The owner must still see their calendar, their bookings and their settings.
	 * - The license routes themselves (`/admin/license`), which are the way back.
	 *
	 * The capability is checked FIRST, so a caller who has no business here at all is told that
	 * rather than being handed the site's licensing state as a consolation prize.
	 *
	 * @return true|\WP_Error
	 */
	public function configureSite(): bool|\WP_Error {
		$capability = self::gate( 'reservant_manage_settings' );
		if ( true !== $capability ) {
			return $capability;
		}
		return $this->licensed();
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

	/**
	 * The license half of `configureSite()`.
	 *
	 * `LicenseStatus::isActive()` is the one question asked, so this guard never assembles its own
	 * list of acceptable states - `Grace` counts as active, and a sixth state added later inherits
	 * whatever the enum decides rather than whatever this file remembered to enumerate.
	 *
	 * **A manager that THROWS is treated as permission granted, not refused.** `status()` is
	 * forbidden from touching the network (see `LicenseManager::status()`), so a throw here is a
	 * genuine fault in a `reservant/license_manager` implementation, not an outage - and the site
	 * that filtered that implementation in is the site whose owner would be locked out of their own
	 * configuration, permanently, with no route left in the plugin that could repair it. That is the
	 * same asymmetry the grace window is built on: somebody else's failure must not turn a paying
	 * customer's plugin off. The fault is announced on `reservant/error` so it is visible rather
	 * than merely survivable.
	 *
	 * @return true|\WP_Error
	 */
	private function licensed(): bool|\WP_Error {
		try {
			$status = Providers::get()->status( $this->now() );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
			return true;
		}

		return $status->isActive() ? true : self::licenseRequired( $status->state );
	}

	/**
	 * 403, with a sentence per state, because the fix is different in each one.
	 *
	 * `LicenseState`'s own docblock makes the point: `Invalid` means get a good key, `DomainMismatch`
	 * means activate on THIS site, `Inactive` means enter one. Collapsing the three into "unlicensed"
	 * would leave an owner re-pasting a key that was never going to work. Every arm names where to go,
	 * and each is one whole translated sentence - never a concatenation of fragments (AGENTS.md
	 * section 7, i18n).
	 *
	 * The machine-readable `message` is `license_required` on every arm - a client switches on that,
	 * and `data.state` tells it which of the three situations it is looking at, exactly as
	 * `data.detail` tells a person.
	 */
	private static function licenseRequired( LicenseState $state ): \WP_Error {
		$detail = match ( $state ) {
			LicenseState::DomainMismatch => __( 'Your Reservant license is registered to a different domain, so changes to your setup are paused. Activate it for this site under Reservant -> Settings. Bookings are unaffected and keep running.', 'reservant' ),
			LicenseState::Invalid        => __( 'Your Reservant license is no longer valid, so changes to your setup are paused. Enter a valid license key under Reservant -> Settings. Bookings are unaffected and keep running.', 'reservant' ),
			default                      => __( 'Reservant is not licensed on this site, so changes to your setup are paused. Enter your license key under Reservant -> Settings. Bookings are unaffected and keep running.', 'reservant' ),
		};

		return new \WP_Error(
			'reservant_license_required',
			'license_required',
			array(
				'status' => 403,
				'state'  => $state->value,
				'detail' => $detail,
			)
		);
	}

	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
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
