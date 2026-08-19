<?php
declare( strict_types=1 );

namespace Reservant\Application\Payment;

/**
 * Decides which `PaymentProvider` this request gets, and is the ONLY place that asks whether
 * WooCommerce is loaded.
 *
 * `class_exists( 'WooCommerce' )` appears here and nowhere else in the codebase. AGENTS.md section 6
 * bans WooCommerce symbols outside `src/Integrations/WooCommerce/`, and a bare class name in a
 * `class_exists()` call is exactly the kind of reference that spreads: one in the service
 * controller, one in the approve path, one in the admin notice, and the ban is over. The check is
 * cheap enough to make central.
 *
 * Resolution is memoized per request because it is asked on ordinary front-end requests (every
 * confirm consults `isAvailable()`), and re-resolving would re-run the filter chain each time.
 * `reset()` exists for the tests that swap providers between cases, and for nothing else.
 */
final class Providers {

	private static ?PaymentProvider $resolved = null;

	/**
	 * The provider for this request.
	 *
	 * Filterable at `reservant/payment_provider` so a site can supply its own gateway without
	 * touching this plugin - and so the test suite can install a fake, which matters more than usual
	 * here: WooCommerce IS present in the test containers, so the WC-absent path would otherwise be
	 * unreachable by any test on the machine that runs them.
	 *
	 * A filter returning something that is not a `PaymentProvider` is ignored rather than fatal -
	 * third-party code, guarded the same way `Notifications\Mailer` guards its own filter.
	 */
	public static function get(): PaymentProvider {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$default = class_exists( 'WooCommerce' )
			? new \Reservant\Integrations\WooCommerce\WooPaymentProvider()
			: new NullPaymentProvider();

		$filtered = apply_filters( 'reservant/payment_provider', $default );

		self::$resolved = $filtered instanceof PaymentProvider ? $filtered : $default;
		return self::$resolved;
	}

	/** Drop the memoized provider. For tests that install a fake between cases. */
	public static function reset(): void {
		self::$resolved = null;
	}
}
