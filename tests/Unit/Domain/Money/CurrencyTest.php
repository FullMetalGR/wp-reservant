<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Money\Currency;

/**
 * Where the decimal point goes.
 *
 * This is the PHP half of a rule the browser already gets right: `assets/src/shared/money.ts`
 * derives its divisor from `Intl.NumberFormat().resolvedOptions().maximumFractionDigits` rather than
 * assuming 100, because a `/ 100` formatter renders a 5000-yen booking as 50. The admin booking
 * drawer shipped exactly that bug, on the one screen an owner reconciles takings from, and no test
 * caught it because no admin fixture used anything but EUR. These cases are the fixtures that were
 * missing.
 */
final class CurrencyTest extends TestCase {

	/** @return array<string, array{0: string, 1: int}> */
	public static function exponents(): array {
		return array(
			'EUR is the ordinary case'                => array( 'EUR', 2 ),
			'USD'                                     => array( 'USD', 2 ),
			'GBP'                                     => array( 'GBP', 2 ),
			'JPY has no minor unit at all'            => array( 'JPY', 0 ),
			'KRW'                                     => array( 'KRW', 0 ),
			'ISK'                                     => array( 'ISK', 0 ),
			'XOF, a shared West African currency'     => array( 'XOF', 0 ),
			'KWD is divided into thousandths'         => array( 'KWD', 3 ),
			'BHD'                                     => array( 'BHD', 3 ),
			'TND'                                     => array( 'TND', 3 ),
			'CLF, a four-decimal unit of account'     => array( 'CLF', 4 ),
			'an unknown code falls back to the usual' => array( 'ZZZ', 2 ),
		);
	}

	/** @dataProvider exponents */
	public function test_the_iso_4217_exponent( string $code, int $expected ): void {
		self::assertSame( $expected, Currency::exponent( $code ) );
	}

	public function test_a_code_is_matched_however_the_caller_cased_or_padded_it(): void {
		self::assertSame( 0, Currency::exponent( 'jpy' ) );
		self::assertSame( 0, Currency::exponent( ' JPY ' ) );
	}

	/** The bug itself, stated as an assertion: 5000 minor units is 5000 yen, not 50. */
	public function test_zero_decimal_currencies_are_not_divided_by_a_hundred(): void {
		self::assertSame( 5000.0, Currency::toMajor( 5000, 'JPY' ) );
		self::assertSame( 50.0, Currency::toMajor( 5000, 'EUR' ) );
		self::assertSame( 5.0, Currency::toMajor( 5000, 'KWD' ) );
	}

	public function test_zero_is_zero_in_every_currency(): void {
		foreach ( array( 'EUR', 'JPY', 'KWD', 'CLF' ) as $code ) {
			self::assertSame( 0.0, Currency::toMajor( 0, $code ) );
		}
	}
}
