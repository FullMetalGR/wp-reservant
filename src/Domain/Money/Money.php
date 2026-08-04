<?php
declare( strict_types=1 );

namespace Reservant\Domain\Money;

final class Money {

	public function __construct(
		public readonly int $minor,
		public readonly string $currency,
	) {
		if ( $this->minor < 0 ) {
			throw new \InvalidArgumentException( 'Money cannot be negative.' );
		}
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $this->currency ) ) {
			throw new \InvalidArgumentException( 'Currency must be a 3-letter ISO 4217 code.' );
		}
	}

	public static function zero( string $currency ): self {
		return new self( 0, $currency );
	}

	public static function sum( string $currency, self ...$amounts ): self {
		$total = self::zero( $currency );
		foreach ( $amounts as $amount ) {
			$total = $total->add( $amount );
		}
		return $total;
	}

	public function add( self $other ): self {
		if ( $other->currency !== $this->currency ) {
			throw new \InvalidArgumentException( 'Currency mismatch.' );
		}
		return new self( $this->minor + $other->minor, $this->currency );
	}

	public function equals( self $other ): bool {
		return $this->minor === $other->minor && $this->currency === $other->currency;
	}
}
