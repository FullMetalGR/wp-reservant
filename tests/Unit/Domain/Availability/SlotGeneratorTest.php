<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Availability;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Availability\MaskBuilder;
use Reservant\Domain\Availability\RuleExpander;
use Reservant\Domain\Availability\SlotGenerator;
use Reservant\Domain\Chain\ChainResolver;
use Reservant\Domain\Chain\ChainSegment;

final class SlotGeneratorTest extends TestCase {

	private function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	private function generator( string $tz = 'UTC' ): SlotGenerator {
		return new SlotGenerator( new RuleExpander( new \DateTimeZone( $tz ) ), new MaskBuilder( 5 ), new ChainResolver( 5 ) );
	}

	/** @return list<AvailabilityRule> */
	private function everyDay( string $start, string $end ): array {
		$rules = array();
		foreach ( range( 1, 7 ) as $weekday ) {
			$rules[] = new AvailabilityRule( $weekday, $start, $end );
		}
		return $rules;
	}

	public function test_generates_starts_within_open_hours_minus_busy(): void {
		$segments = array( new ChainSegment( 1, 60, 0, 0, 0, array( 1 ) ) );
		$starts   = $this->generator()->starts(
			$segments,
			$this->utc( '2026-01-15 00:00:00' ),
			$this->utc( '2026-01-16 00:00:00' ),
			$this->utc( '2026-01-10 00:00:00' ),
			0,
			365,
			array( 1 => $this->everyDay( '09:00', '11:00' ) ),
			array( 1 => array() ),
			array( 1 => array( array( $this->utc( '2026-01-15 09:00:00' ), $this->utc( '2026-01-15 10:00:00' ) ) ) )
		);
		// Open 09-11, first hour busy: the only 60-min start is 10:00.
		self::assertSame( array( '2026-01-15 10:00' ), array_map( static fn ( $d ) => $d->format( 'Y-m-d H:i' ), $starts ) );
	}

	public function test_second_customer_fits_inside_processing_gap(): void {
		// THE money case: colour (30 work + 30 processing) booked at 09:00 on staff 1.
		// Staff 1 is occupied [09:00,09:30) and free during processing [09:30,10:00).
		$segments = array( new ChainSegment( 2, 30, 0, 0, 0, array( 1 ) ) );
		$starts   = $this->generator()->starts(
			$segments,
			$this->utc( '2026-01-15 09:00:00' ),
			$this->utc( '2026-01-15 10:00:00' ),
			$this->utc( '2026-01-10 00:00:00' ),
			0,
			365,
			array( 1 => $this->everyDay( '09:00', '17:00' ) ),
			array( 1 => array() ),
			array( 1 => array( array( $this->utc( '2026-01-15 09:00:00' ), $this->utc( '2026-01-15 09:30:00' ) ) ) )
		);
		self::assertContains( '2026-01-15 09:30', array_map( static fn ( $d ) => $d->format( 'Y-m-d H:i' ), $starts ) );
	}

	/**
	 * The alignment case. Hours 09:00-17:00, a 30-min service with a 15-min before-buffer and
	 * nothing booked all day.
	 *
	 * openMask.runs(30 min = 6 slots) is set from 09:00 (slot 108) to 16:30 (slot 198) - the
	 * service span has to fit inside the working window. busyFreeMask is the full window, so its
	 * runs(15+30 = 45 min = 9 slots), shifted up by the 3 buffer slots, is set everywhere the two
	 * masks can meet. The intersection therefore starts at 09:00: the buffer occupies 08:45-09:00,
	 * before opening, and that is fine - HoldBooking checks hours against the SERVICE span only
	 * (`coversSpan`) and buffers against other BOOKINGS only (`overlapCount`), so a first
	 * appointment at opening time is holdable and must be offered.
	 */
	public function test_a_before_buffer_does_not_push_the_first_start_past_opening_time(): void {
		$segments = array( new ChainSegment( 1, 30, 0, 15, 0, array( 1 ) ) );
		$starts   = $this->generator()->starts(
			$segments,
			$this->utc( '2026-01-15 00:00:00' ),
			$this->utc( '2026-01-16 00:00:00' ),
			$this->utc( '2026-01-10 00:00:00' ),
			0,
			365,
			array( 1 => $this->everyDay( '09:00', '17:00' ) ),
			array( 1 => array() ),
			array( 1 => array() )
		);
		$formatted = array_map( static fn ( $d ) => $d->format( 'H:i' ), $starts );

		self::assertSame( '09:00', $formatted[0] );
		self::assertNotContains( '08:55', $formatted ); // The service itself may not start before opening.
		self::assertSame( '16:30', end( $formatted ) ); // Nor end after closing.
	}

	public function test_lead_time_and_horizon_clip_the_window(): void {
		$segments = array( new ChainSegment( 1, 60, 0, 0, 0, array( 1 ) ) );
		$starts   = $this->generator()->starts(
			$segments,
			$this->utc( '2026-01-15 00:00:00' ),
			$this->utc( '2026-01-16 00:00:00' ),
			$this->utc( '2026-01-15 09:30:00' ),   // "now" is mid-morning of the requested day
			60,                                     // 1h lead time: nothing before 10:30
			365,
			array( 1 => $this->everyDay( '09:00', '12:00' ) ),
			array( 1 => array() ),
			array( 1 => array() )
		);
		$formatted = array_map( static fn ( $d ) => $d->format( 'H:i' ), $starts );
		self::assertNotContains( '09:00', $formatted );
		self::assertNotContains( '10:00', $formatted );
		self::assertContains( '10:30', $formatted );
		self::assertContains( '11:00', $formatted );
	}

	public function test_la_evening_chain_crosses_utc_midnight(): void {
		// LA: 16:00 local = 00:00 UTC next day. A 2h chain starting 23:00 UTC must survive the boundary.
		$segments = array( new ChainSegment( 1, 120, 0, 0, 0, array( 1 ) ) );
		$starts   = $this->generator( 'America/Los_Angeles' )->starts(
			$segments,
			$this->utc( '2026-01-15 00:00:00' ),
			$this->utc( '2026-01-16 00:00:00' ),
			$this->utc( '2026-01-10 00:00:00' ),
			0,
			365,
			array( 1 => $this->everyDay( '09:00', '17:00' ) ),
			array( 1 => array() ),
			array( 1 => array() )
		);
		// 23:00 UTC on the 15th = 15:00 LA; service runs to 01:00 UTC = 17:00 LA closing. Valid.
		self::assertContains( '2026-01-15 23:00', array_map( static fn ( $d ) => $d->format( 'Y-m-d H:i' ), $starts ) );
	}

	public function test_rounds_unaligned_durations_up_to_granularity(): void {
		// 32-min service must consume 35 min (7 slots), not throw.
		$segments = array( new ChainSegment( 1, 32, 0, 0, 0, array( 1 ) ) );
		$starts   = $this->generator()->starts(
			$segments,
			$this->utc( '2026-01-15 00:00:00' ),
			$this->utc( '2026-01-16 00:00:00' ),
			$this->utc( '2026-01-10 00:00:00' ),
			0,
			365,
			array( 1 => $this->everyDay( '09:00', '09:35' ) ),
			array( 1 => array() ),
			array( 1 => array() )
		);
		self::assertSame( array( '09:00' ), array_map( static fn ( $d ) => $d->format( 'H:i' ), $starts ) );
	}
}
