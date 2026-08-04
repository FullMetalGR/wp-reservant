<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Money\Money;

final class MoneyTest extends TestCase {
	public function test_rejects_negative_amount(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Money( -1, 'EUR' );
	}

	public function test_rejects_malformed_currency(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Money( 100, 'eu' );
	}

	public function test_adds_same_currency(): void {
		$sum = ( new Money( 1000, 'EUR' ) )->add( new Money( 250, 'EUR' ) );
		self::assertSame( 1250, $sum->minor );
		self::assertSame( 'EUR', $sum->currency );
	}

	public function test_add_rejects_currency_mismatch(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Money( 100, 'EUR' ) )->add( new Money( 100, 'USD' ) );
	}

	public function test_sum_of_nothing_is_zero(): void {
		self::assertTrue( Money::sum( 'EUR' )->equals( Money::zero( 'EUR' ) ) );
	}
}
