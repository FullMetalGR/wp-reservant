<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Availability;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Availability\AvailabilityException;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Availability\RuleExpander;

final class RuleExpanderTest extends TestCase {

	private function utcDay( string $date ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
	}

	/** @return list<AvailabilityRule> */
	private function everyDay( string $start, string $end ): array {
		$rules = array();
		foreach ( range( 1, 7 ) as $weekday ) {
			$rules[] = new AvailabilityRule( $weekday, $start, $end );
		}
		return $rules;
	}

	public function test_athens_rule_before_and_after_dst_spring_forward(): void {
		// Athens DST starts 2026-03-29: EET (UTC+2) becomes EEST (UTC+3).
		$expander = new RuleExpander( new \DateTimeZone( 'Europe/Athens' ) );
		$rules    = $this->everyDay( '09:00', '17:00' );

		$before = $expander->openIntervalsForUtcDay( $this->utcDay( '2026-03-28' ), $rules, array() );
		self::assertCount( 1, $before );
		self::assertSame( '2026-03-28 07:00', $before[0][0]->format( 'Y-m-d H:i' ) ); // 09:00 EET
		self::assertSame( '2026-03-28 15:00', $before[0][1]->format( 'Y-m-d H:i' ) );

		$after = $expander->openIntervalsForUtcDay( $this->utcDay( '2026-03-30' ), $rules, array() );
		self::assertSame( '2026-03-30 06:00', $after[0][0]->format( 'Y-m-d H:i' ) ); // 09:00 EEST
		self::assertSame( '2026-03-30 14:00', $after[0][1]->format( 'Y-m-d H:i' ) );
	}

	public function test_los_angeles_evening_spills_across_utc_midnight(): void {
		// LA 09:00-17:00 PST = 17:00-01:00 UTC: the tail lands on the NEXT UTC day.
		$expander = new RuleExpander( new \DateTimeZone( 'America/Los_Angeles' ) );
		$day      = $expander->openIntervalsForUtcDay( $this->utcDay( '2026-01-15' ), $this->everyDay( '09:00', '17:00' ), array() );
		// Two clipped pieces: [00:00,01:00) tail of Jan 14 local + [17:00,24:00) head of Jan 15 local.
		self::assertCount( 2, $day );
		self::assertSame( '2026-01-15 00:00', $day[0][0]->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-01-15 01:00', $day[0][1]->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-01-15 17:00', $day[1][0]->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-01-16 00:00', $day[1][1]->format( 'Y-m-d H:i' ) );
	}

	public function test_closed_exception_blanks_the_day(): void {
		$expander = new RuleExpander( new \DateTimeZone( 'Europe/Athens' ) );
		$rules    = array( new AvailabilityRule( 3, '09:00', '17:00' ) ); // Wednesday
		$closed   = array( new AvailabilityException( '2026-01-14', true ) ); // a Wednesday
		self::assertSame( array(), $expander->openIntervalsForUtcDay( $this->utcDay( '2026-01-14' ), $rules, $closed ) );
	}

	public function test_open_exception_replaces_rule_windows(): void {
		$expander = new RuleExpander( new \DateTimeZone( 'UTC' ) );
		$rules    = array( new AvailabilityRule( 3, '09:00', '17:00' ) );
		$override = array( new AvailabilityException( '2026-01-14', false, '12:00', '14:00' ) );
		$day      = $expander->openIntervalsForUtcDay( $this->utcDay( '2026-01-14' ), $rules, $override );
		self::assertCount( 1, $day );
		self::assertSame( '2026-01-14 12:00', $day[0][0]->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-01-14 14:00', $day[0][1]->format( 'Y-m-d H:i' ) );
	}

	public function test_valid_from_bound_applies_on_local_dates(): void {
		$expander = new RuleExpander( new \DateTimeZone( 'UTC' ) );
		$rules    = array( new AvailabilityRule( 3, '09:00', '17:00', '2026-02-01', null ) );
		self::assertSame( array(), $expander->openIntervalsForUtcDay( $this->utcDay( '2026-01-14' ), $rules, array() ) );
	}
}
