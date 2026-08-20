<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * What the license looks like from outside: the state, plus the four facts a guard or a settings
 * screen needs in order to say something useful about it.
 *
 * Every `LicenseManager` method returns one of these rather than `void` or `bool`, so no caller has
 * to write a state change and then read it back to learn what it did - a two-step that invites the
 * two answers to disagree.
 *
 * It carries the MASKED key and never the key itself. The plaintext is stored (a remote validator
 * has to re-send it) but it is a credential, and this object is what reaches an admin screen, a REST
 * payload and any listener a site cares to attach - so the value that travels is the one that cannot
 * be used to license a second install.
 */
final class LicenseStatus {

	/**
	 * @param string                  $maskedKey     Asterisks plus at most the last 4 characters;
	 *                                               empty when there is no key at all.
	 * @param string                  $domain        The domain the key is BOUND to, which on
	 *                                               `DomainMismatch` is deliberately not this site's.
	 * @param \DateTimeImmutable|null $lastCheckedAt Last SUCCESSFUL validation, null if never.
	 * @param \DateTimeImmutable|null $graceEndsAt   Non-null only in `Grace` - outside it there is no
	 *                                               deadline to show, and a stale one on screen would
	 *                                               read as a threat that is not real.
	 */
	public function __construct(
		public readonly LicenseState $state,
		public readonly string $maskedKey,
		public readonly string $domain,
		public readonly ?\DateTimeImmutable $lastCheckedAt,
		public readonly ?\DateTimeImmutable $graceEndsAt,
	) {}

	/**
	 * The one question callers actually ask, so they ask it once instead of assembling their own
	 * list of acceptable states.
	 *
	 * `Grace` counts as active: a site whose host had a DNS blip is not an unlicensed site. The
	 * reasoning lives on `LicenseState::isActive()`, which this delegates to so that the rule has
	 * exactly one home.
	 */
	public function isActive(): bool {
		return $this->state->isActive();
	}
}
