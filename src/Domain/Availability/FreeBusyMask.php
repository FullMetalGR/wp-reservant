<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

/**
 * Fixed-width bitmask over time slots. Bit set = free. Immutable.
 * Words are 32 bits wide stored in PHP 64-bit ints, masked after left shifts.
 */
final class FreeBusyMask {

	private const WORD_BITS = 32;
	private const WORD_MASK = 0xFFFFFFFF;

	/** @param list<int> $words */
	private function __construct(
		private readonly array $words,
		public readonly int $slots,
	) {}

	public static function empty( int $slots ): self {
		return new self( array_fill( 0, self::wordCount( $slots ), 0 ), $slots );
	}

	public static function full( int $slots ): self {
		return ( new self( array_fill( 0, self::wordCount( $slots ), self::WORD_MASK ), $slots ) )->truncated();
	}

	/** @param list<array{int,int}> $intervals half-open [start,end) slot ranges */
	public static function fromIntervals( int $slots, array $intervals ): self {
		$words = array_fill( 0, self::wordCount( $slots ), 0 );
		foreach ( $intervals as $interval ) {
			$start = max( 0, $interval[0] );
			$end   = min( $slots, $interval[1] );
			for ( $s = $start; $s < $end; $s++ ) {
				$words[ intdiv( $s, self::WORD_BITS ) ] |= 1 << ( $s % self::WORD_BITS );
			}
		}
		return new self( $words, $slots );
	}

	public function and( self $other ): self {
		$this->assertCompatible( $other );
		$words = array();
		foreach ( $this->words as $i => $word ) {
			$words[ $i ] = $word & $other->words[ $i ];
		}
		return new self( $words, $this->slots );
	}

	public function or( self $other ): self {
		$this->assertCompatible( $other );
		$words = array();
		foreach ( $this->words as $i => $word ) {
			$words[ $i ] = $word | $other->words[ $i ];
		}
		return new self( $words, $this->slots );
	}

	public function andNot( self $other ): self {
		$this->assertCompatible( $other );
		$words = array();
		foreach ( $this->words as $i => $word ) {
			$words[ $i ] = $word & ( ~$other->words[ $i ] & self::WORD_MASK );
		}
		return new self( $words, $this->slots );
	}

	/** Result bit s = original bit (s + n). Zeroes pulled in from beyond the top. */
	public function shiftDown( int $n ): self {
		if ( 0 === $n ) {
			return $this;
		}
		$wordShift = intdiv( $n, self::WORD_BITS );
		$bitShift  = $n % self::WORD_BITS;
		$count     = count( $this->words );
		$words     = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$lo          = $this->words[ $i + $wordShift ] ?? 0;
			$hi          = $this->words[ $i + $wordShift + 1 ] ?? 0;
			$words[ $i ] = 0 === $bitShift
				? $lo
				: ( ( ( $lo >> $bitShift ) | ( $hi << ( self::WORD_BITS - $bitShift ) ) ) & self::WORD_MASK );
		}
		return new self( $words, $this->slots );
	}

	/** Result bit s = original bit (s - n). Bits pushed past the top are dropped. */
	public function shiftUp( int $n ): self {
		if ( 0 === $n ) {
			return $this;
		}
		$wordShift = intdiv( $n, self::WORD_BITS );
		$bitShift  = $n % self::WORD_BITS;
		$count     = count( $this->words );
		$words     = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$src         = $i - $wordShift;
			$lo          = $src >= 0 ? $this->words[ $src ] : 0;
			$carry       = ( $src - 1 ) >= 0 ? $this->words[ $src - 1 ] : 0;
			$words[ $i ] = 0 === $bitShift
				? $lo
				: ( ( ( $lo << $bitShift ) & self::WORD_MASK ) | ( $carry >> ( self::WORD_BITS - $bitShift ) ) );
		}
		return ( new self( $words, $this->slots ) )->truncated();
	}

	/** Bit s set iff slots s..s+length-1 all set. Shift-and doubling - O(log length) passes. */
	public function runs( int $length ): self {
		if ( $length < 1 ) {
			throw new \InvalidArgumentException( 'Run length must be >= 1.' );
		}
		$result  = $this;
		$covered = 1;
		while ( $covered < $length ) {
			$step     = min( $covered, $length - $covered );
			$result   = $result->and( $result->shiftDown( $step ) );
			$covered += $step;
		}
		return $result;
	}

	public function isSet( int $slot ): bool {
		if ( $slot < 0 || $slot >= $this->slots ) {
			return false;
		}
		return 1 === ( ( $this->words[ intdiv( $slot, self::WORD_BITS ) ] >> ( $slot % self::WORD_BITS ) ) & 1 );
	}

	/** @return list<int> */
	public function setSlots(): array {
		$out = array();
		foreach ( $this->words as $w => $word ) {
			if ( 0 === $word ) {
				continue;
			}
			for ( $b = 0; $b < self::WORD_BITS; $b++ ) {
				if ( 1 === ( ( $word >> $b ) & 1 ) ) {
					$out[] = $w * self::WORD_BITS + $b;
				}
			}
		}
		return $out;
	}

	public function equals( self $other ): bool {
		return $this->slots === $other->slots && $this->words === $other->words;
	}

	private function truncated(): self {
		$words   = $this->words;
		$topBits = $this->slots % self::WORD_BITS;
		if ( 0 !== $topBits ) {
			$words[ count( $words ) - 1 ] &= ( 1 << $topBits ) - 1;
		}
		return new self( $words, $this->slots );
	}

	private function assertCompatible( self $other ): void {
		if ( $other->slots !== $this->slots ) {
			throw new \InvalidArgumentException( 'Slot counts differ.' );
		}
	}

	private static function wordCount( int $slots ): int {
		if ( $slots < 1 ) {
			throw new \InvalidArgumentException( 'Slot count must be >= 1.' );
		}
		return intdiv( $slots + self::WORD_BITS - 1, self::WORD_BITS );
	}
}
