<?php
declare( strict_types=1 );

namespace Reservant\Application;

/**
 * The guest's self-service credential (AGENTS.md section 5).
 *
 * A random secret travels in the email link and is shown to the client exactly once; only its
 * SHA-256 hash is ever stored (`bookings.manage_token_hash`), and the comparison is constant-time.
 * Issued by `HoldBooking`, verified by the REST permission callbacks - hence `Application`, not
 * `Rest`: the token is part of the booking's identity, not of one transport.
 */
final class ManageToken {

	/**
	 * A fresh secret and the hash to store beside the booking.
	 *
	 * @return array{0: string, 1: string} [ secret, sha256 hash ]
	 */
	public static function issue(): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a CSPRNG secret, not obfuscation.
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		return array( $secret, self::hash( $secret ) );
	}

	/**
	 * @param string      $secret     the plaintext secret from the link
	 * @param string|null $storedHash `manage_token_hash`, which is NULLable
	 */
	public static function verify( string $secret, ?string $storedHash ): bool {
		if ( '' === $secret || null === $storedHash || '' === $storedHash ) {
			return false;
		}
		return hash_equals( $storedHash, self::hash( $secret ) );
	}

	private static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}
}
