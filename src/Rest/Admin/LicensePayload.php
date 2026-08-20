<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Licensing\LicenseStatus;

/**
 * What a caller may see of a license - the one place that decides it, for the same reason
 * `Rest\BookingPayload` is the one place that decides it for a booking.
 *
 * Two surfaces render a license: `GET|POST|DELETE /admin/license` and the admin SPA's bootstrap
 * (`Admin\AdminPage::config()`), which carries the current status so the Settings screen can draw
 * itself without a second round trip on every page load. Two hand-written shapes for one object is
 * how the two drift, and a drift here is not cosmetic - the SPA would have to parse the bootstrap
 * copy one way and the copy that comes back from `POST /admin/license` another, for the same
 * license. So both call this.
 *
 * **The plaintext key cannot travel through here, by construction.** This takes a `LicenseStatus`,
 * and `LicenseStatus` only ever carries the masked form (`LicenseRecord::mask()`): eight asterisks
 * plus at most the last four characters, and nothing at all for a key short enough that "the last
 * four" would be the whole thing. There is no field on the input that holds the real key, so no
 * amount of editing this file can leak one - which is the point of routing the wire shape through
 * the DTO rather than through the record.
 *
 * Timestamps are `Y-m-d H:i:s` in UTC, the same wire form every other date takes on this namespace
 * (AGENTS.md section 7: UTC in the DB, converted at the edges), and NULL rather than an empty
 * string when there is nothing to show. `grace_ends_at` is non-null only in `Grace` - a deadline
 * left on screen outside the grace window reads as a threat that is not real.
 */
final class LicensePayload {

	/**
	 * `active` is computed here rather than left to the client, because "which states count as
	 * licensed" is a fact about the state machine (`LicenseState::isActive()`), not a rule a
	 * TypeScript file should be keeping its own copy of. `Grace` is active; a client that
	 * reimplemented the check as `state === 'active'` would put a scary banner in front of an owner
	 * whose only problem is that somebody else's DNS was down.
	 *
	 * @return array{state:string,active:bool,masked_key:string,domain:string,last_checked_at:?string,grace_ends_at:?string}
	 */
	public static function of( LicenseStatus $status ): array {
		return array(
			'state'           => $status->state->value,
			'active'          => $status->isActive(),
			'masked_key'      => $status->maskedKey,
			'domain'          => $status->domain,
			'last_checked_at' => self::instant( $status->lastCheckedAt ),
			'grace_ends_at'   => self::instant( $status->graceEndsAt ),
		);
	}

	private static function instant( ?\DateTimeImmutable $moment ): ?string {
		return $moment?->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
