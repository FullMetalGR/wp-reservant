<?php
declare( strict_types=1 );

namespace Reservant\Admin;

use Reservant\Application\Payment\Providers;
use Reservant\Domain\Enum\PaymentMode;

/**
 * Tells the owner, in wp-admin, that services they marked "pay online" are currently taking no
 * money.
 *
 * AGENTS.md section 6 makes the degrade itself silent by design - `online` behaves as `onsite` and
 * the booking still completes - which is the right behaviour and the wrong user experience on its
 * own: from the outside, bookings simply keep arriving unpaid, and the owner has no reason to
 * connect that to a plugin they deactivated last week. `ConfirmBooking` carries the functional half
 * of the rule; this is the half that says so out loud.
 *
 * Deliberately conditional on BOTH halves. A site with no online services does not care that
 * WooCommerce is absent, and a site with WooCommerce running does not need telling that it works;
 * a notice that fires in either of those cases is one the owner learns to dismiss without reading.
 */
final class PaymentNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		// `reservant_manage_settings`, not `manage_options`: this plugin's own capability set is the
		// authority on who administers it (AGENTS.md section 5), and the owner of a booking site is
		// not necessarily a WordPress administrator.
		if ( ! current_user_can( 'reservant_manage_settings' ) || Providers::get()->isAvailable() ) {
			return;
		}
		$affected = $this->onlineServiceCount();
		if ( 0 === $affected ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of services set to take payment online. */
					_n(
						'Reservant: %d service is set to take payment online, but no payment plugin is active. It is being booked as pay-on-arrival until one is.',
						'Reservant: %d services are set to take payment online, but no payment plugin is active. They are being booked as pay-on-arrival until one is.',
						$affected,
						'reservant'
					),
					$affected
				)
			)
		);
	}

	private function onlineServiceCount(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'reservant_services';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE payment_mode = %s AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL
				PaymentMode::Online->value
			)
		);
	}
}
