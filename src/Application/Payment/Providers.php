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
	 * Whether the WooCommerce integration is active - the question `Plugin::register()` asks before
	 * wiring anything out of `Integrations\WooCommerce\` (currently `OrderObserver`).
	 *
	 * A method rather than letting callers repeat the check, because the check IS the thing this
	 * class exists to contain: the class docblock above explains why `class_exists( 'WooCommerce' )`
	 * may appear here and nowhere else, and a caller needing the same answer gets it by asking, not
	 * by copying. Deliberately NOT the same question as `get()->isAvailable()`: a site can filter in
	 * a non-WC provider while WooCommerce is active, and the order observer must keep listening to
	 * WooCommerce's own hooks regardless of who is taking NEW money - orders created before the
	 * switch still exist, still get refunded, and still carry booking uuids that must release.
	 */
	public static function wooCommerceActive(): bool {
		return class_exists( 'WooCommerce' );
	}

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

		$default = self::wooCommerceActive()
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
