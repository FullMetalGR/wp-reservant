<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * **The placeholder for a remote validator that does not exist yet.**
 *
 * AGENTS.md section 1 settles the shape - premium only, key activation bound to a domain - and
 * leaves the vendor undecided. The platform that mints keys and answers "is this one genuine" has
 * not been built, so this class stands in for it, and the file is named for what it is so that
 * nobody has to read the code to find that out.
 *
 * **Exactly one thing here is fake: `accepts()`.** The state machine, the storage, the domain
 * binding and the grace window are all real, run against the real `reservant_license` row, and are
 * the same code a remote implementation will drive. When the platform exists, the replacement is a
 * class that answers `accepts()` over HTTP plus a `reservant/license_manager` filter - not a rewrite
 * of anything below.
 *
 * A key is accepted when EITHER its SHA-256 matches `TEST_KEY_HASH` (one built-in key, so the dev
 * site can activate; only the hash is in the repository, never the plaintext) OR dev mode is on, in
 * which case any non-empty key activates. Dev mode is `RESERVANT_LICENSE_DEV`, and it follows the
 * `Plugin::devToolsAllowed()` precedent of taking an explicit override so the branch is testable
 * without a process-wide `define()`.
 *
 * What this stub does NOT fake, and a remote one will have to answer: seat counts, expiry dates,
 * product tiers, and the difference between "your key is not genuine" and "I could not reach the
 * server". That last one is why every failing `revalidate()` here goes to grace - see
 * `revalidate()`.
 */
final class LocalKeyLicense implements LicenseManager {

	/**
	 * SHA-256 of the one built-in key. The plaintext is deliberately NOT in this repository: a key
	 * committed next to its own check is a key every customer has.
	 */
	private const TEST_KEY_HASH = '9e4ebf2a5352c240d1088aca49926e6d65bf895be3536a919ee703965644fc55';

	/**
	 * @param bool|null $devMode Explicit override, or null to read `RESERVANT_LICENSE_DEV`. The
	 *                           override exists for tests, which need to drive the real state
	 *                           machine from both sides of the accept/refuse decision within one
	 *                           process - a constant can only be defined once, and defining it would
	 *                           leak into every later test in the run.
	 */
	public function __construct( private readonly ?bool $devMode = null ) {}

	/**
	 * Stores this key against this domain, accepted or refused, and reports the result.
	 *
	 * Both outcomes are written, because both are true things that just happened - see
	 * `LicenseManager::activate()` on why a refusal must not leave a stale success in place.
	 *
	 * An empty submit is the one input that writes NOTHING and reports what is already stored. It is
	 * not an activation attempt: there is no key in it to accept or to refuse, and deactivating has
	 * its own method. Treated as a refusal it would store an empty row, and an empty row reads as
	 * `Inactive` - so a stray form post with a blank field would silently unlicense a paying site.
	 * That is the single worst outcome available on this path, and it is available for free unless
	 * this returns early.
	 */
	public function activate( string $key, \DateTimeImmutable $nowUtc ): LicenseStatus {
		$key    = trim( $key );
		$domain = SiteDomain::current();

		if ( '' === $key ) {
			return LicenseRecord::load()->statusAt( $nowUtc, $domain );
		}

		$record = self::accepts( $key, $this->devModeActive() )
			? LicenseRecord::activated( $key, $domain, $nowUtc )
			: LicenseRecord::rejected( $key, $domain );

		$record->persist();
		return $record->statusAt( $nowUtc, $domain );
	}

	/**
	 * Forgets the row entirely. A remote implementation also releases the seat at the platform, and
	 * still returns `Inactive` if that call fails (see the interface).
	 */
	public function deactivate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		LicenseRecord::clear();
		return LicenseRecord::none()->statusAt( $nowUtc, SiteDomain::current() );
	}

	/**
	 * Re-asks about the stored key.
	 *
	 * Two cases short-circuit without writing anything, because in both there is nothing to ask:
	 * no key at all, and a key bound to a different domain. The second is the load-bearing one - a
	 * re-check on a clone must not touch the record, or the clone would rewrite the check history
	 * (or the grace clock) of a row that describes the real site. The domain is re-read here rather
	 * than trusted from the row for the same reason it is never rebound: the stored domain is the
	 * claim, the live one is the fact.
	 *
	 * A refusal starts the grace window rather than invalidating, and it does so unconditionally,
	 * because this seam cannot tell a genuine "that key is revoked" from "the validator was down".
	 * A real implementation that CAN tell them apart is where an immediate `Invalid` could be
	 * introduced - it must be the validator's explicit verdict, never the client's inference from a
	 * failure.
	 */
	public function revalidate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		$record = LicenseRecord::load();
		$domain = SiteDomain::current();

		if ( '' === $record->key() || $record->domain() !== $domain ) {
			return $record->statusAt( $nowUtc, $domain );
		}

		$next = self::accepts( $record->key(), $this->devModeActive() )
			? $record->withSuccessfulCheck( $nowUtc )
			: $record->withFailedCheck( $nowUtc );

		$next->persist();
		return $next->statusAt( $nowUtc, $domain );
	}

	/** One option read and a comparison. No network, no write - see the interface. */
	public function status( \DateTimeImmutable $nowUtc ): LicenseStatus {
		return LicenseRecord::load()->statusAt( $nowUtc, SiteDomain::current() );
	}

	/**
	 * THE FAKE. Everything else in this class is real.
	 *
	 * A pure function of its two inputs so the whole decision is pinned by a unit test with no
	 * WordPress and no `define()` - the `Plugin::devToolsAllowed()` shape, for the same reason.
	 *
	 * `hash_equals()` rather than `===` because the comparison is against a secret's digest, and
	 * the habit is cheaper to keep than to acquire once a real validator's token is being compared
	 * here instead.
	 *
	 * The empty-key guard is belt and braces: both callers already refuse to reach here with one
	 * (`activate()` returns early, `revalidate()` has nothing stored to ask about), but a predicate
	 * that answered "yes, in dev mode" to a key that does not exist would be a bad predicate.
	 */
	public static function accepts( string $key, bool $devMode ): bool {
		if ( '' === $key ) {
			return false;
		}
		if ( $devMode ) {
			return true;
		}
		return hash_equals( self::TEST_KEY_HASH, hash( 'sha256', $key ) );
	}

	/** The constructor override wins in both directions; absent, the constant decides; absent, off. */
	private function devModeActive(): bool {
		return $this->devMode ?? ( defined( 'RESERVANT_LICENSE_DEV' ) && (bool) RESERVANT_LICENSE_DEV );
	}
}
