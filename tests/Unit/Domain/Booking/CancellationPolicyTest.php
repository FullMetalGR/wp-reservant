<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Booking;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Booking\CancellationPolicy;

final class CancellationPolicyTest extends TestCase {

	private function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	public function test_cancel_allowed_before_and_at_the_window_boundary(): void {
		$policy = new CancellationPolicy( 24, 48 );
		$start  = $this->utc( '2026-01-15 10:00:00' );
		self::assertTrue( $policy->canCancel( $this->utc( '2026-01-13 10:00:00' ), $start ) );
		self::assertTrue( $policy->canCancel( $this->utc( '2026-01-14 10:00:00' ), $start ) );  // exactly 24h
		self::assertFalse( $policy->canCancel( $this->utc( '2026-01-14 10:00:01' ), $start ) );
	}

	public function test_reschedule_uses_its_own_window(): void {
		$policy = new CancellationPolicy( 24, 48 );
		$start  = $this->utc( '2026-01-15 10:00:00' );
		self::assertFalse( $policy->canReschedule( $this->utc( '2026-01-14 10:00:00' ), $start ) ); // inside 48h
		self::assertTrue( $policy->canCancel( $this->utc( '2026-01-14 10:00:00' ), $start ) );      // but cancel ok
	}

	public function test_zero_window_allows_until_start(): void {
		$policy = new CancellationPolicy( 0, 0 );
		$start  = $this->utc( '2026-01-15 10:00:00' );
		self::assertTrue( $policy->canCancel( $this->utc( '2026-01-15 10:00:00' ), $start ) );
		self::assertFalse( $policy->canCancel( $this->utc( '2026-01-15 10:00:01' ), $start ) );
	}
}
