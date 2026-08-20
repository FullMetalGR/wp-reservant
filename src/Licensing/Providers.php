<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * Decides which `LicenseManager` this request gets - the licensing twin of
 * `Application\Payment\Providers`, and deliberately the same shape so that neither has a surprise
 * in it for anyone who has read the other.
 *
 * The default is `LocalKeyLicense`, the stub standing in for a validator platform that does not
 * exist yet. The filter is what makes that temporary: `reservant/license_manager` lets the real
 * remote implementation be dropped in without touching a single caller, which is the promise
 * AGENTS.md section 1 makes when it says licensing is "abstracted behind an interface, vendor
 * undecided".
 *
 * Resolution is memoized per request because a licence status is asked on ordinary admin requests
 * and re-resolving would re-run the filter chain each time. `reset()` exists for the tests that swap
 * implementations between cases, and for nothing else.
 *
 * A filter returning something that is not a `LicenseManager` is ignored rather than fatal - third
 * party code, guarded exactly as `Payment\Providers` and `Notifications\Mailer` guard theirs.
 */
final class Providers {

	private static ?LicenseManager $resolved = null;

	/** The license manager for this request. */
	public static function get(): LicenseManager {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$default  = new LocalKeyLicense();
		$filtered = apply_filters( 'reservant/license_manager', $default );

		self::$resolved = $filtered instanceof LicenseManager ? $filtered : $default;
		return self::$resolved;
	}

	/** Drop the memoized manager. For tests that install a fake between cases. */
	public static function reset(): void {
		self::$resolved = null;
	}
}
