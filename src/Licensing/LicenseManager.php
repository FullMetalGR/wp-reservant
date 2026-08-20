<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * The one seam between this plugin and whoever decides that a key is genuine (AGENTS.md section 1:
 * "Premium only. Licensing abstracted behind an interface").
 *
 * The vendor is still undecided and the platform that mints and validates keys does not exist yet,
 * so the shipped implementation is `LocalKeyLicense` - a stub that fakes exactly one thing, the
 * "is this key genuine" question, and does everything else for real. The point of this interface is
 * that swapping the stub for a remote validator later is a `Providers` filter and nothing more: no
 * caller learns whether the answer came from an HTTP round trip or from a constant.
 *
 * **Every method returns the resulting `LicenseStatus`.** A `void` activate would force every caller
 * into write-then-read, and the two halves of that pair are exactly where an implementation gets to
 * disagree with itself. What a method returns is what a subsequent `status()` will say.
 *
 * **Absence is a supported configuration, not an error** - the same rule `Payment\PaymentProvider`
 * lives by. A site with no key entered is `Inactive`, not broken; a site whose validator is
 * unreachable is `Grace`, not broken. So no method here throws to mean "unlicensed": every one has a
 * meaningful "there is no license system answering right now" reply, and it is a `LicenseStatus`.
 * An implementation may still throw on a genuine fault (a dead database, a malformed HTTP response),
 * which is why `Infrastructure\Scheduler\Jobs::licenseRecheck()` catches.
 */
interface LicenseManager {

	/**
	 * Bind a key to this site.
	 *
	 * A deliberate REPLACEMENT, not a merge: whatever was stored before is superseded, including by
	 * a refusal. The cost is real and worth naming - pasting a bad key over a working one loses the
	 * working one, and the owner has to paste the good one back. The alternative is worse: silently
	 * keeping the previous license would make `activate()`'s return value a statement about a
	 * license the caller did not just install, so "I pasted the wrong key" and "it worked" would
	 * look identical on screen.
	 *
	 * With no license system at all this returns `Invalid` for every key rather than throwing: the
	 * owner is told the key was refused, and the settings screen still loads to let them try again.
	 *
	 * An EMPTY key is the exception to the replacement rule and must change nothing: it carries no
	 * key to accept or refuse, and a blank field posted by accident must not cost a site the license
	 * it paid for. Unbinding is `deactivate()`'s job and only `deactivate()`'s.
	 *
	 * @param string $key The key as the owner typed it. Implementations trim it; nothing else.
	 */
	public function activate( string $key, \DateTimeImmutable $nowUtc ): LicenseStatus;

	/**
	 * Unbind this site, so the seat can be used somewhere else.
	 *
	 * Always succeeds and always lands on `Inactive`, even when the license was already invalid or
	 * bound elsewhere - "stop claiming to be licensed" is a request that has no failure mode worth
	 * reporting, and an owner who cannot deactivate is an owner who cannot move their own site.
	 *
	 * A remote implementation additionally tells the platform to release the seat, and must STILL
	 * return `Inactive` when that call fails: the local half of a deactivation cannot be held
	 * hostage by the remote half, or a validator outage would pin a license to a site being
	 * decommissioned.
	 */
	public function deactivate( \DateTimeImmutable $nowUtc ): LicenseStatus;

	/**
	 * Re-ask the validator about the stored key - the periodic check, driven daily by
	 * `Infrastructure\Scheduler\Jobs::licenseRecheck()`.
	 *
	 * This is the ONLY method that may touch the network, and the only one whose answer can change
	 * without anybody having done anything. A failure here must never unlicense a site outright: it
	 * starts the grace window (`LicenseRecord::GRACE_DAYS`), a later success clears it, and only the
	 * window running out yields `Invalid`. That asymmetry is the whole design - a re-check that
	 * failed is indistinguishable from an outage at this end, at that end, or anywhere in between.
	 *
	 * With no license system at all it is a no-op that returns what is already stored: there is
	 * nothing to ask, and nothing to ask it of.
	 */
	public function revalidate( \DateTimeImmutable $nowUtc ): LicenseStatus;

	/**
	 * What is stored right now.
	 *
	 * **MUST NOT hit the network, and must not write.** This is the question a guard asks on a
	 * request the user is waiting on, and an admin screen asks on every load; an implementation that
	 * phoned home here would put a third party's latency in front of wp-admin and turn their outage
	 * into this plugin's. Everything time-dependent is derived from stored facts against `$nowUtc`,
	 * which is why a grace window can expire on a site whose scheduler has not run in a month.
	 *
	 * With no license system at all this reports `Inactive`, which is also what a corrupt or absent
	 * stored row reads as - "no license here", the one answer from which re-activating still works.
	 */
	public function status( \DateTimeImmutable $nowUtc ): LicenseStatus;
}
