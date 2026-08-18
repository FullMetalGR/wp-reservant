/**
 * A minor-unit integer rendered in its own currency.
 *
 * Every money field on the wire is an integer of minor units paired with the currency it is
 * denominated in - `price_minor`/`currency` on a service row, `total_minor`/`currency` on a
 * booking. Format with the row's OWN currency, never the bootstrap's site-wide default: change the
 * site setting after services exist and formatting old rows with it would state a price the row
 * never held.
 *
 * The divisor comes from the currency too. Minor units scale per currency - EUR hundredths, JPY
 * ones, BHD thousandths - so `Intl`'s resolved fraction digits for the currency replace any
 * hardcoded 100. A fixed `/ 100` renders JPY 5000 as 50 yen, off by two orders of magnitude in the
 * direction that undercharges.
 *
 * One module rather than one per bundle: the widget and the admin SPA format the same integers off
 * the same REST payloads, and when each owned a copy they disagreed about exactly this. `Intl` is a
 * platform global, so this adds no script handle to either bundle and `bin/widget-contract.mjs` is
 * untouched.
 */
export function formatMoney( minor: number, currency: string ): string {
	try {
		const formatter = new Intl.NumberFormat( undefined, { style: 'currency', currency } );
		// Present at runtime whenever no significant-digits options are set (ECMA-402
		// resolvedOptions); TypeScript types it optional to cover the significant-digits case, so
		// the `?? 2` is only for the compiler, falling back to ISO 4217's most common minor-unit
		// scale.
		const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;
		return formatter.format( minor / 10 ** digits );
	} catch {
		// A currency `Intl` refuses - unreachable through the admin path, which enforces
		// /^[A-Z]{3}$/, but reachable by direct SQL - must not throw. Only in this fallback is a
		// fixed scale defensible: with no formatter there are no resolved fraction digits to
		// derive, so ISO 4217's most common 2-decimal scale is all that is left.
		return `${ ( minor / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}
