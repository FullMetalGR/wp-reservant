<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Chain;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Availability\FreeBusyMask;
use Reservant\Domain\Availability\ResourceMasks;
use Reservant\Domain\Chain\ChainResolver;
use Reservant\Domain\Chain\ChainSegment;
use Reservant\Domain\Chain\NoFeasibleAssignment;

final class ChainResolverTest extends TestCase {

	private const SLOTS = 96; // 8 hours at 5-min granularity, small enough to reason about.

	/**
	 * A resource that is at work AND unbooked exactly over $intervals. With no buffers in play the
	 * two masks constrain the same span, so this is the plain "free here, busy elsewhere" fixture
	 * the unbuffered cases below want. The buffered cases set the two masks apart on purpose.
	 */
	private function free( array $intervals ): ResourceMasks {
		$mask = FreeBusyMask::fromIntervals( self::SLOTS, $intervals );
		return new ResourceMasks( $mask, $mask );
	}

	public function test_buffers_contend_with_bookings_and_not_with_opening_hours(): void {
		$resolver = new ChainResolver( 5 );
		// 30-min service (6 slots), 10-min buffer before (2 slots), 5-min after (1 slot).
		$segment = new ChainSegment( 1, 30, 0, 10, 5, array( 7 ) );
		// At work [10,20) - 10 slots - and nothing booked anywhere.
		$masks = array( array( 7 => new ResourceMasks( FreeBusyMask::fromIntervals( self::SLOTS, array( array( 10, 20 ) ) ), FreeBusyMask::full( self::SLOTS ) ) ) );

		// The SERVICE span is what has to be inside the hours: [s, s+6) is a subset of [10,20) => s in 10..14.
		// The before-buffer of the 10:00 start sits at slots 8-9, i.e. before opening - allowed,
		// because a buffer is protection from the previous CUSTOMER, not a claim on the roster.
		// This is the alignment that matters: HoldBooking accepts s = 10, so availability offers it.
		self::assertSame( array( 10, 11, 12, 13, 14 ), $resolver->feasibleStarts( array( $segment ), $masks )->setSlots() );
	}

	public function test_a_neighbouring_booking_does_push_the_buffered_start_later(): void {
		$resolver = new ChainResolver( 5 );
		$segment  = new ChainSegment( 1, 30, 0, 10, 5, array( 7 ) );
		// Open all window; unbooked only over [10,20) - 10 slots for a 2+6+1 = 9-slot block.
		$masks = array( array( 7 => new ResourceMasks( FreeBusyMask::full( self::SLOTS ), FreeBusyMask::fromIntervals( self::SLOTS, array( array( 10, 20 ) ) ) ) ) );

		// The block may start at 10 or 11 only; the customer start is the block start plus the
		// 2 buffer slots => 12 or 13. Contention, unlike the roster, does move the offered time.
		self::assertSame( array( 12, 13 ), $resolver->feasibleStarts( array( $segment ), $masks )->setSlots() );
	}

	public function test_processing_gap_frees_the_staff_between_segments(): void {
		$resolver = new ChainResolver( 5 );
		// Segment A: 30 min work + 30 min processing (staff free). Segment B: 30 min, other staff.
		$a = new ChainSegment( 1, 30, 30, 0, 0, array( 1 ) );
		$b = new ChainSegment( 2, 30, 0, 0, 0, array( 2 ) );
		// Staff 1 free [0, 12) only; staff 2 free [12, 18) only.
		// Chain start 0: A occupies staff1 [0,6); B starts at offset (30+30)/5=12, occupies staff2 [12,18). Feasible.
		$masks = array(
			array( 1 => $this->free( array( array( 0, 12 ) ) ) ),
			array( 2 => $this->free( array( array( 12, 18 ) ) ) ),
		);
		$feasible = $resolver->feasibleStarts( array( $a, $b ), $masks );
		self::assertSame( array( 0 ), $feasible->setSlots() );
	}

	public function test_offsets_exclude_buffers_from_customer_timeline(): void {
		$resolver = new ChainResolver( 5 );
		$a = new ChainSegment( 1, 30, 15, 5, 10, array( 1 ) );
		$b = new ChainSegment( 2, 30, 0, 0, 0, array( 1 ) );
		// offset(B) = duration(30) + processing(15) = 45 min = 9 slots. Buffers (5/10) excluded.
		self::assertSame( 9, $resolver->offsetSlots( array( $a, $b ), 1 ) );
	}

	public function test_union_over_staff_and_same_staff_variant(): void {
		$resolver = new ChainResolver( 5 );
		$a = new ChainSegment( 1, 30, 0, 0, 0, array( 1, 2 ) );
		$b = new ChainSegment( 2, 30, 0, 0, 0, array( 1, 2 ) );
		// Staff 1 free [0,6) only; staff 2 free [6,12) only.
		$masks = array(
			array( 1 => $this->free( array( array( 0, 6 ) ) ), 2 => $this->free( array( array( 6, 12 ) ) ) ),
			array( 1 => $this->free( array( array( 0, 6 ) ) ), 2 => $this->free( array( array( 6, 12 ) ) ) ),
		);
		// Any-staff: A on staff1 at 0, B (offset 6) on staff2 at 6 -> start 0 feasible.
		self::assertSame( array( 0 ), $resolver->feasibleStarts( array( $a, $b ), $masks )->setSlots() );
		// Same-staff: no single staff member can do both back-to-back.
		self::assertSame( array(), $resolver->feasibleStarts( array( $a, $b ), $masks, true )->setSlots() );
	}

	public function test_requested_resource_pins_the_segment(): void {
		$resolver = new ChainResolver( 5 );
		$a = new ChainSegment( 1, 30, 0, 0, 0, array( 1, 2 ), 2 );
		// Staff 1 free everywhere, staff 2 free nowhere: pin to 2 kills feasibility.
		$masks = array( array( 1 => $this->free( array( array( 0, self::SLOTS ) ) ), 2 => $this->free( array() ) ) );
		self::assertSame( array(), $resolver->feasibleStarts( array( $a ), $masks )->setSlots() );
	}

	public function test_assign_is_deterministic_lowest_free_id(): void {
		$resolver = new ChainResolver( 5 );
		$a     = new ChainSegment( 1, 30, 0, 0, 0, array( 2, 1 ) );
		$masks = array( array( 1 => $this->free( array( array( 0, self::SLOTS ) ) ), 2 => $this->free( array( array( 0, self::SLOTS ) ) ) ) );
		self::assertSame( array( 0 => 1 ), $resolver->assign( array( $a ), $masks, 0 ) );
	}

	public function test_assign_throws_when_nobody_free(): void {
		$resolver = new ChainResolver( 5 );
		$a     = new ChainSegment( 1, 30, 0, 0, 0, array( 1 ) );
		$masks = array( array( 1 => $this->free( array() ) ) );
		$this->expectException( NoFeasibleAssignment::class );
		$resolver->assign( array( $a ), $masks, 0 );
	}

	public function test_rejects_durations_not_aligned_to_granularity(): void {
		$resolver = new ChainResolver( 5 );
		$a = new ChainSegment( 1, 32, 0, 0, 0, array( 1 ) );
		$this->expectException( \InvalidArgumentException::class );
		$resolver->feasibleStarts( array( $a ), array( array( 1 => $this->free( array( array( 0, self::SLOTS ) ) ) ) ) );
	}
}
