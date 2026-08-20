<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Licensing;

use PHPUnit\Framework\TestCase;
use Reservant\Licensing\LocalKeyLicense;

/**
 * The one faked decision in the licensing stub, pinned on its own.
 *
 * `accepts()` is a pure function of the key and the dev flag - the `Plugin::devToolsAllowed()` shape
 * - so the dev-mode branch is reachable here without a process-wide `define( 'RESERVANT_LICENSE_DEV' )`
 * that would leak into every later test in the run.
 *
 * The built-in key's own accept branch is deliberately NOT pinned here: doing so would require the
 * plaintext, and a key committed next to its own hash is a key every customer has. What is pinned is
 * everything around it - that the check is a hash comparison against a stored digest, and that
 * nothing else gets in.
 */
final class LocalKeyLicenseTest extends TestCase {

	public function test_an_arbitrary_key_is_refused_when_dev_mode_is_off(): void {
		self::assertFalse( LocalKeyLicense::accepts( 'RSVT-NOPE-NOPE-NOPE', false ) );
		self::assertFalse( LocalKeyLicense::accepts( 'admin', false ) );
		self::assertFalse( LocalKeyLicense::accepts( '0', false ) );
	}

	/** The dev-site escape hatch: any non-empty key opens the door, and only there. */
	public function test_dev_mode_accepts_any_non_empty_key(): void {
		self::assertTrue( LocalKeyLicense::accepts( 'anything-at-all', true ) );
		self::assertTrue( LocalKeyLicense::accepts( '0', true ), 'a key of "0" is a key, however unlikely' );
	}

	/** An empty submit is not a key, and dev mode does not make it one. */
	public function test_an_empty_key_is_refused_in_both_modes(): void {
		self::assertFalse( LocalKeyLicense::accepts( '', false ) );
		self::assertFalse( LocalKeyLicense::accepts( '', true ) );
	}

	/**
	 * The check compares SHA-256 digests, so a key differing anywhere is refused - there is no
	 * prefix match, no truncation, and no length below which anything is waved through.
	 */
	public function test_nothing_resembling_a_key_gets_in_by_accident(): void {
		foreach ( array( 'RSVT', 'RSVT-', '*', '********', str_repeat( 'A', 512 ) ) as $attempt ) {
			self::assertFalse( LocalKeyLicense::accepts( $attempt, false ), "accepted '{$attempt}'" );
		}
	}
}
