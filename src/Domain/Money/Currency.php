<?php
declare( strict_types=1 );

namespace Reservant\Domain\Money;

/**
 * How many minor units a currency divides into (AGENTS.md section 7: "Money: integers ... Format
 * only at the presentation layer").
 *
 * Money is stored and summed in minor units throughout, so every presenter has to know where the
 * decimal point goes - and "divide by 100" is wrong for about thirty of the world's currencies.
 * Getting it wrong is not a rounding nuisance: a 5000-yen booking rendered by a `/ 100` formatter
 * reads as 50, which is what the admin booking drawer did until it was fixed, on the one screen an
 * owner reconciles takings from.
 *
 * The browser gets this from `Intl.NumberFormat` (`assets/src/shared/money.ts` derives the divisor
 * from `resolvedOptions().maximumFractionDigits` rather than assuming). PHP's equivalent lives in
 * the `intl` extension, which WordPress does not require and a shared host frequently omits, so the
 * exponents are stated here instead. It is a short, stable list: ISO 4217 has changed it a handful
 * of times in decades, and an unlisted code falls back to 2, which is right for the overwhelming
 * majority and is the same answer the old hard-coded `/ 100` gave.
 */
final class Currency {

	/** Currencies with no minor unit at all - the ones a `/ 100` formatter divides by 100 too many. */
	private const ZERO_DECIMAL = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'ISK',
		'JPY',
		'KMF',
		'KRW',
		'PYG',
		'RWF',
		'UGX',
		'UYI',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/** Mostly Gulf and North African currencies, divided into thousandths. */
	private const THREE_DECIMAL = array(
		'BHD',
		'IQD',
		'JOD',
		'KWD',
		'LYD',
		'OMR',
		'TND',
	);

	/** Unit-of-account currencies. Rare, but they exist and they are four. */
	private const FOUR_DECIMAL = array(
		'CLF',
		'UYW',
	);

	/** The ISO 4217 exponent: the power of ten between the minor unit and the major one. */
	public static function exponent( string $code ): int {
		$code = strtoupper( trim( $code ) );
		if ( in_array( $code, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		if ( in_array( $code, self::THREE_DECIMAL, true ) ) {
			return 3;
		}
		if ( in_array( $code, self::FOUR_DECIMAL, true ) ) {
			return 4;
		}
		return 2;
	}

	/** Minor units as a major-unit amount - 5000 JPY is 5000, 5000 EUR is 50.0. */
	public static function toMajor( int $minor, string $code ): float {
		return $minor / ( 10 ** self::exponent( $code ) );
	}
}
