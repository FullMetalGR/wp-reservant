<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Availability;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Availability\MaskBuilder;

final class MaskBuilderTest extends TestCase {

	private function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	public function test_hours_and_bookings_are_kept_in_separate_masks(): void {
		$builder = new MaskBuilder( 5 );
		$day     = $this->utc( '2026-01-15 00:00:00' );
		// Open 09:00-10:00 = slots [108,120). Busy 09:12-09:23 rounds OUT to 09:10-09:25 = slots [110,113).
		$masks = $builder->masks(
			$day,
			1,
			array( array( $this->utc( '2026-01-15 09:00:00' ), $this->utc( '2026-01-15 10:00:00' ) ) ),
			array( array( $this->utc( '2026-01-15 09:12:00' ), $this->utc( '2026-01-15 09:23:00' ) ) )
		);

		// The roster mask knows only the working window - a booking inside it changes nothing.
		self::assertSame( 288, $masks->openMask->slots );
		self::assertTrue( $masks->openMask->isSet( 108 ) );  // 09:00 open
		self::assertTrue( $masks->openMask->isSet( 110 ) );  // 09:10 open, though booked
		self::assertFalse( $masks->openMask->isSet( 120 ) ); // 10:00 closed
		self::assertFalse( $masks->openMask->isSet( 107 ) ); // 08:55 closed

		// The contention mask knows only the bookings - free everywhere else, including outside the
		// working window, because that is where a buffer is allowed to sit.
		self::assertTrue( $masks->busyFreeMask->isSet( 109 ) );  // 09:05 unbooked
		self::assertFalse( $masks->busyFreeMask->isSet( 110 ) ); // 09:10 booked (floor of 09:12)
		self::assertFalse( $masks->busyFreeMask->isSet( 112 ) ); // 09:20 booked (ceil of 09:23 = 09:25)
		self::assertTrue( $masks->busyFreeMask->isSet( 113 ) );  // 09:25 unbooked again
		self::assertTrue( $masks->busyFreeMask->isSet( 107 ) );  // 08:55 shut, but unbooked
		self::assertTrue( $masks->busyFreeMask->isSet( 120 ) );  // 10:00 shut, but unbooked
	}

	public function test_two_day_window_slot_count(): void {
		self::assertSame( 576, ( new MaskBuilder( 5 ) )->slotsForDays( 2 ) );
	}

	public function test_unaligned_open_interval_rounds_inward(): void {
		$builder = new MaskBuilder( 5 );
		$day     = $this->utc( '2026-01-15 00:00:00' );
		// Open 09:02-09:18 rounds inward to 09:05-09:15 = slots [109,111).
		$masks = $builder->masks(
			$day,
			1,
			array( array( $this->utc( '2026-01-15 09:02:00' ), $this->utc( '2026-01-15 09:18:00' ) ) ),
			array()
		);
		self::assertSame( array( 109, 110 ), $masks->openMask->setSlots() );
		// Nothing is booked, so the whole window is free of contention.
		self::assertSame( 288, count( $masks->busyFreeMask->setSlots() ) );
	}

	public function test_rejects_granularity_not_dividing_1440(): void {
		$this->expectException( \InvalidArgumentException::class );
		new MaskBuilder( 7 );
	}
}
