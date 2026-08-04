<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Availability;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Availability\FreeBusyMask;

final class FreeBusyMaskTest extends TestCase {

	public function test_empty_and_full(): void {
		$empty = FreeBusyMask::empty( 288 );
		$full  = FreeBusyMask::full( 288 );
		self::assertSame( array(), $empty->setSlots() );
		self::assertCount( 288, $full->setSlots() );
		self::assertTrue( $full->isSet( 287 ) );
		self::assertFalse( $full->isSet( 288 ) ); // out of range is never set
	}

	public function test_from_intervals_sets_half_open_ranges_and_clamps(): void {
		$m = FreeBusyMask::fromIntervals( 288, array( array( 10, 13 ), array( -5, 2 ), array( 286, 400 ) ) );
		self::assertSame( array( 0, 1, 10, 11, 12, 286, 287 ), $m->setSlots() );
	}

	public function test_runs_finds_consecutive_free_starts_across_word_boundary(): void {
		// Free slots [30, 36) - crosses the 32-bit word boundary.
		$m = FreeBusyMask::fromIntervals( 288, array( array( 30, 36 ) ) );
		self::assertSame( array( 30, 31, 32 ), $m->runs( 4 )->setSlots() );
		self::assertSame( $m->setSlots(), $m->runs( 1 )->setSlots() );
	}

	public function test_runs_rejects_zero_length(): void {
		$this->expectException( \InvalidArgumentException::class );
		FreeBusyMask::full( 288 )->runs( 0 );
	}

	public function test_shift_down_and_up(): void {
		$m = FreeBusyMask::fromIntervals( 288, array( array( 40, 42 ) ) );
		self::assertSame( array( 7, 8 ), $m->shiftDown( 33 )->setSlots() );
		self::assertSame( array( 73, 74 ), $m->shiftUp( 33 )->setSlots() );
		self::assertSame( array(), $m->shiftDown( 50 )->setSlots() );  // dropped off the bottom
		self::assertSame( array(), $m->shiftUp( 250 )->setSlots() );   // dropped off the top
	}

	public function test_boolean_ops(): void {
		$a = FreeBusyMask::fromIntervals( 64, array( array( 0, 4 ) ) );
		$b = FreeBusyMask::fromIntervals( 64, array( array( 2, 6 ) ) );
		self::assertSame( array( 2, 3 ), $a->and( $b )->setSlots() );
		self::assertSame( array( 0, 1, 2, 3, 4, 5 ), $a->or( $b )->setSlots() );
		self::assertSame( array( 0, 1 ), $a->andNot( $b )->setSlots() );
	}

	public function test_ops_reject_mismatched_slot_counts(): void {
		$this->expectException( \InvalidArgumentException::class );
		FreeBusyMask::full( 288 )->and( FreeBusyMask::full( 64 ) );
	}
}
