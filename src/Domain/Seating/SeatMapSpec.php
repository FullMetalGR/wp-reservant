<?php
declare( strict_types=1 );

namespace Reservant\Domain\Seating;

/** Parses "rows A-J, 12 per row, aisle after 6" into a seat grid. The v1.2 admin "builder". */
final class SeatMapSpec {

	/**
	 * @param list<string> $rowLabels
	 * @param list<int>    $aislesAfter
	 */
	private function __construct(
		public readonly array $rowLabels,
		public readonly int $seatsPerRow,
		public readonly array $aislesAfter,
	) {}

	public static function parse( string $spec ): self {
		$text = strtolower( trim( $spec ) );

		if ( 1 !== preg_match( '/rows\s+([a-z0-9]+)\s*-\s*([a-z0-9]+)/', $text, $rows ) ) {
			throw new SpecParseError( 'Expected "rows X-Y".' );
		}
		if ( 1 !== preg_match( '/(\d+)\s+per\s+row/', $text, $perRow ) ) {
			throw new SpecParseError( 'Expected "N per row".' );
		}
		$seatsPerRow = (int) $perRow[1];
		if ( $seatsPerRow < 1 ) {
			throw new SpecParseError( 'Seats per row must be >= 1.' );
		}

		$aisles = array();
		if ( 1 === preg_match( '/aisle\s+after\s+([\d,\s]+)/', $text, $aisleMatch ) ) {
			$aisles = array_values( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $aisleMatch[1] ) ), static fn( $v ) => strlen( $v ) > 0 ) ) );
			foreach ( $aisles as $position ) {
				if ( $position < 1 || $position >= $seatsPerRow ) {
					throw new SpecParseError( 'Aisle position must be between 1 and seats-per-row minus 1.' );
				}
			}
		}

		return new self( self::labelRange( $rows[1], $rows[2] ), $seatsPerRow, $aisles );
	}

	/** @return list<Seat> Row-major; aisles occupy their own sortCol. */
	public function seats(): array {
		$seats = array();
		foreach ( $this->rowLabels as $rowIndex => $rowLabel ) {
			$col = 0;
			for ( $n = 1; $n <= $this->seatsPerRow; $n++ ) {
				$seats[] = new Seat( $rowLabel, $rowLabel . $n, $rowIndex, $col, 'seat' );
				++$col;
				if ( in_array( $n, $this->aislesAfter, true ) ) {
					$seats[] = new Seat( $rowLabel, '', $rowIndex, $col, 'aisle' );
					++$col;
				}
			}
		}
		return $seats;
	}

	public function capacity(): int {
		return count( $this->rowLabels ) * $this->seatsPerRow;
	}

	/** @return list<string> */
	private static function labelRange( string $from, string $to ): array {
		if ( ctype_digit( $from ) && ctype_digit( $to ) ) {
			if ( (int) $from > (int) $to ) {
				throw new SpecParseError( 'Row range is inverted.' );
			}
			return array_map( 'strval', range( (int) $from, (int) $to ) );
		}
		if ( 1 === strlen( $from ) && 1 === strlen( $to ) && ctype_alpha( $from ) && ctype_alpha( $to ) ) {
			if ( $from > $to ) {
				throw new SpecParseError( 'Row range is inverted.' );
			}
			return array_map( 'strtoupper', range( $from, $to ) );
		}
		throw new SpecParseError( 'Row range must be alphabetic (A-J) or numeric (1-10).' );
	}
}
