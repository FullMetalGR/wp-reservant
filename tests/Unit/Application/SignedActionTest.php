<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Reservant\Application\SignedAction;

/**
 * Pure HMAC round-trip (AGENTS.md "Approval holds": one-click signed approve/reject links). No
 * WordPress bootstrap involved - the secret is an argument, never `wp_salt()` itself.
 */
final class SignedActionTest extends TestCase {

	private const SECRET     = 'unit-test-secret';
	private const UUID       = 'b6f2b1b0-0000-4000-8000-000000000001';
	private const ACTION     = 'approve';
	private const EXPIRES    = 2000000000; // far future
	private const UPDATED_AT = '2026-08-05 12:00:00';
	private const NOW        = 1000000000; // far past relative to EXPIRES

	/**
	 * Frozen expected digest of `sign( SECRET, UUID, ACTION, EXPIRES, UPDATED_AT )`.
	 *
	 * Round-trip tests (sign then verify) cannot see a field that is dropped from the signed
	 * message - both sides drop it together and still agree. This literal is the one assertion that
	 * pins the exact byte string being HMAC'd, so removing or reordering ANY of the four fields
	 * fails here even when every round-trip test still passes.
	 */
	private const GOLDEN = 'dd6179635ca838cd275e49ab824b7548eacc747f7ca9a9c3b2441f661ae693ec';

	public function test_sign_matches_the_golden_vector(): void {
		self::assertSame(
			self::GOLDEN,
			SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT )
		);
	}

	/**
	 * The expiry must be INSIDE the HMAC, not merely compared against the clock. If it were only
	 * compared, anyone holding an old approval email (a forward, a shared or departed staff mailbox,
	 * an exported archive, a mail-log dump) could rewrite `?exp=` to a future timestamp and revive a
	 * link whose approval window closed - the row is still awaiting_approval, so `updated_at` is
	 * unchanged and every other signed field still matches.
	 *
	 * Both timestamps here are in the future relative to NOW, so the `$nowTs > $expiresTs` guard
	 * passes in both cases and only the signature comparison can reject.
	 */
	public function test_verify_rejects_a_signature_made_for_a_different_expiry(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES + 1, self::UPDATED_AT, self::NOW )
		);
	}

	/** The exploit end to end: an expired link is not revived by extending `exp` past the clock. */
	public function test_verify_rejects_an_expired_link_whose_expiry_was_pushed_into_the_future(): void {
		$issuedExpiry = self::NOW - 1; // link had already lapsed
		$sig          = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, $issuedExpiry, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_accepts_a_signature_it_produced(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertTrue(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_rejects_a_tampered_signature(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig . 'x', self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_rejects_after_expiry(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::EXPIRES + 1 )
		);
	}

	public function test_verify_rejects_a_changed_updated_at(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES, '2026-08-05 12:05:00', self::NOW )
		);
	}

	public function test_verify_rejects_a_different_secret(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( 'wrong-secret', $sig, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_rejects_a_mismatched_action(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, self::UUID, 'reject', self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_rejects_a_mismatched_uuid(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertFalse(
			SignedAction::verify( self::SECRET, $sig, 'a-different-uuid', self::ACTION, self::EXPIRES, self::UPDATED_AT, self::NOW )
		);
	}

	public function test_verify_at_the_exact_expiry_instant_still_succeeds(): void {
		$sig = SignedAction::sign( self::SECRET, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT );

		self::assertTrue(
			SignedAction::verify( self::SECRET, $sig, self::UUID, self::ACTION, self::EXPIRES, self::UPDATED_AT, self::EXPIRES )
		);
	}
}
