<?php
declare( strict_types=1 );

namespace Reservant\Application;

/**
 * HMAC-signed one-click approve/reject links (AGENTS.md "Approval holds": "Owner emails carry
 * one-click signed approve/reject links so the decision never requires a wp-admin login").
 *
 * Pure PHP - no WordPress functions, so it is unit-tested with no WP bootstrap at all. The secret
 * is always injected by the caller; `Admin\ApprovalActionEndpoint` is the only caller in this
 * codebase and it supplies `wp_salt( 'auth' )`.
 *
 * The signed message binds four things: the booking, the decision, the link's own expiry, and the
 * booking row's `updated_at` at the moment the link was issued. That last part is what makes a
 * link effectively single-use without a "used" flag in the database: any state transition on the
 * booking (this same approval, a rival rejection, a cancellation) bumps `updated_at`, so a
 * signature checked against the value read from the *current* row
 * (`BookingRepository::findByUuid()`) silently stops matching once the row has moved on -
 * `Admin\ApprovalActionEndpoint::handle()` is what tells that apart from a forged signature.
 */
final class SignedAction {

	/** The exact byte string that gets HMAC'd - callers never need to know its shape. */
	public static function sign( string $secret, string $uuid, string $action, int $expiresTs, string $updatedAt ): string {
		return hash_hmac( 'sha256', "{$uuid}|{$action}|{$expiresTs}|{$updatedAt}", $secret );
	}

	/**
	 * Constant-time signature check plus expiry. `$updatedAt` must be the CURRENT value read from
	 * the booking row, not whatever the caller thinks it was at issue time - the whole point is
	 * that the two only agree while the row is unchanged since the link was created.
	 */
	public static function verify( string $secret, string $sig, string $uuid, string $action, int $expiresTs, string $updatedAt, int $nowTs ): bool {
		if ( $nowTs > $expiresTs ) {
			return false;
		}
		return hash_equals( self::sign( $secret, $uuid, $action, $expiresTs, $updatedAt ), $sig );
	}
}
