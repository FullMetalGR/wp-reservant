import { formatMoney } from '../money';

// Expectations are BUILT with `Intl` rather than written as glyph literals, the same discipline
// `servicePicker.test.tsx` uses: the exact symbol, separator and space (Intl pads with NBSP) vary
// by ICU version, so a literal would pin the environment rather than the behaviour. What is asserted
// here is the scaling - which minor-unit divisor the currency implies - and that is environment-free.
function expected( major: number, currency: string ): string {
	return new Intl.NumberFormat( undefined, { style: 'currency', currency } ).format( major );
}

describe( 'formatMoney', () => {
	// The three ISO 4217 exponents that exist in practice. The middle row is the one a hardcoded
	// `/ 100` gets right, which is why a two-decimal-only fixture proves nothing.
	it.each( [
		[ 'JPY', 0, 5000, 5000 ],
		[ 'EUR', 2, 4500, 45 ],
		[ 'USD', 2, 500, 5 ],
		[ 'BHD', 3, 12345, 12.345 ],
	] )(
		'scales %s (%i decimals) by the currency, never by a hardcoded 100',
		( currency, _digits, minor, major ) => {
			expect( formatMoney( minor as number, currency as string ) ).toBe(
				expected( major as number, currency as string )
			);
		}
	);

	it( 'renders zero without inventing a fraction the currency does not have', () => {
		expect( formatMoney( 0, 'JPY' ) ).toBe( expected( 0, 'JPY' ) );
		expect( formatMoney( 0, 'EUR' ) ).toBe( expected( 0, 'EUR' ) );
	} );

	it( 'never renders a zero-decimal amount as a hundredth of itself', () => {
		// The regression this module exists for, stated as its own assertion rather than left
		// implicit in the table: the admin drawer's copy divided every currency by 100, so a
		// 5000-yen booking read as 50 yen on the only screen an owner reconciles takings from.
		expect( formatMoney( 5000, 'JPY' ) ).not.toBe( expected( 50, 'JPY' ) );
	} );

	it( 'falls back to a plain string rather than throwing on a currency Intl refuses', () => {
		// Unreachable through the admin path, which enforces /^[A-Z]{3}$/, but reachable by direct
		// SQL. Both bundles now render inside an error boundary, so a RangeError here would be
		// caught rather than fatal - but a caught boundary is still a blanked panel.
		expect( formatMoney( 1500, '' ) ).toBe( '15.00 ' );
		expect( formatMoney( 1500, 'not-a-code' ) ).toBe( '15.00 not-a-code' );
	} );
} );
