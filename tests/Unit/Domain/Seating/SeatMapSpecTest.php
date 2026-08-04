<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Seating;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Domain\Seating\SpecParseError;

final class SeatMapSpecTest extends TestCase {

	public function test_parses_alpha_rows_with_aisle(): void {
		$spec = SeatMapSpec::parse( 'rows A-C, 4 per row, aisle after 2' );
		self::assertSame( array( 'A', 'B', 'C' ), $spec->rowLabels );
		self::assertSame( 4, $spec->seatsPerRow );
		self::assertSame( array( 2 ), $spec->aislesAfter );
		self::assertSame( 12, $spec->capacity() );

		$seats = $spec->seats();
		self::assertCount( 15, $seats ); // 12 seats + 3 aisle cells.
		self::assertSame( 'A1', $seats[0]->seatLabel );
		self::assertSame( 0, $seats[0]->sortCol );
		self::assertSame( 'aisle', $seats[2]->kind );       // after seat 2 in row A.
		self::assertSame( 2, $seats[2]->sortCol );
		self::assertSame( 'A3', $seats[3]->seatLabel );
		self::assertSame( 3, $seats[3]->sortCol );          // shifted by the aisle.
	}

	public function test_parses_numeric_rows(): void {
		$spec = SeatMapSpec::parse( 'ROWS 1-10, 12 PER ROW' );
		self::assertSame( 10, count( $spec->rowLabels ) );
		self::assertSame( '10', $spec->rowLabels[9] );
		self::assertSame( 120, $spec->capacity() );
	}

	public function test_rejects_garbage(): void {
		$this->expectException( SpecParseError::class );
		SeatMapSpec::parse( 'twelve seats please' );
	}

	public function test_rejects_aisle_outside_row(): void {
		$this->expectException( SpecParseError::class );
		SeatMapSpec::parse( 'rows A-B, 4 per row, aisle after 4' );
	}

	public function test_rejects_inverted_row_range(): void {
		$this->expectException( SpecParseError::class );
		SeatMapSpec::parse( 'rows J-A, 4 per row' );
	}
}
