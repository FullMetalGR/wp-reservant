<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * Where a site's license stands right now (AGENTS.md section 1: "Premium only. Licensing abstracted
 * behind an interface").
 *
 * Five cases, and the split between them is deliberately about WHY a license is not working rather
 * than only whether it is: the owner-facing fix is different in every one of them. `Invalid` means
 * get a good key; `DomainMismatch` means activate on THIS site; `Grace` means nothing at all needs
 * doing yet; `Inactive` means enter a key. A single boolean would collapse four different messages
 * into one useless "unlicensed".
 */
enum LicenseState: string {
	/** Never activated, or deactivated by the owner. The default state of a fresh install. */
	case Inactive = 'inactive';

	/** Activated here, and the last check agreed. */
	case Active = 'active';

	/**
	 * The license was good and a re-check is now failing, inside the window that forgives it.
	 * See `LicenseRecord::GRACE_DAYS`.
	 */
	case Grace = 'grace';

	/** Refused by the validator at activation, or a grace window that ran out. */
	case Invalid = 'invalid';

	/** Activated for a different domain, and this build never rebinds one silently. */
	case DomainMismatch = 'domain_mismatch';

	/**
	 * Whether this state means "the site may use what it paid for".
	 *
	 * `Grace` is true, and that is the whole reason the case exists: a site whose validator host had
	 * a DNS blip, or whose own outbound HTTP was down for an afternoon, is not an unlicensed site.
	 * Turning a paying customer's plugin off because of somebody else's outage is a worse failure
	 * than letting a genuinely lapsed license run for another fortnight.
	 *
	 * Stated on the enum rather than at each call site, for the reason
	 * `Domain\Enum\BookingStatus::releasesSeatClaims()` is: "which states count as licensed" is a
	 * fact about the state machine, not about whichever guard happens to be asking, and a guard that
	 * assembles its own list is a guard that a sixth case can silently break.
	 */
	public function isActive(): bool {
		return self::Active === $this || self::Grace === $this;
	}
}
