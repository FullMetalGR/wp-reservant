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
