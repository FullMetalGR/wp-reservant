/**
 * The public catalog as a pick-one list (P5 plan, Task 12). Plain semantic HTML - a `<ul>` of
 * real `<button>` elements, never a keyboard-simulating `<div>` - and no `@wordpress/components`:
 * that package is a webpack external, so importing it would add the ~800 KB `wp-components` core
 * handle to every visitor while the byte budget saw nothing (`bin/widget-contract.mjs` owns the
 * enforcement).
 */
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../../shared';
import { useServices } from '../api/queries';

interface ServicePickerProps {
	/** The chosen service id, or null while nothing is chosen yet. */
	value: number | null;
	onChange: ( id: number ) => void;
}

/**
 * `price_minor` displayed in the SERVICE'S OWN currency (`PublicService.currency`), not the
 * bootstrap's: price and currency live in the same `reservant_services` row, so the amount is
 * denominated in that currency by construction, while the bootstrap carries the site-wide
 * default - change the site setting after services exist and formatting old rows with it would
 * state a price the row never held. The divisor comes from the currency too: minor units scale
 * per currency (EUR hundredths, JPY ones, BHD thousandths), so `Intl`'s fraction digits for the
 * currency replace any hardcoded 100.
 */
function formatPrice( minor: number, currency: string ): string {
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
		// /^[A-Z]{3}$/, but reachable by direct SQL - must not throw: no error boundary wraps the
		// widget yet (Task 16), so a RangeError here would blank the whole thing. Only in this
		// fallback is a fixed scale defensible: with no formatter there are no resolved fraction
		// digits to derive, so ISO 4217's most common 2-decimal scale is all that is left.
		return `${ ( minor / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}

export function ServicePicker( { value, onChange }: ServicePickerProps ): JSX.Element {
	const { data, error, isPending } = useServices();

	if ( isPending ) {
		return (
			<p className="reservant-service-picker__status" role="status">
				{ __( 'Loading services...', 'reservant' ) }
			</p>
		);
	}

	// Destructive only when there is nothing better to show. React Query keeps `data` intact
	// through a background refetch failure (its error reducer spreads the previous state), and
	// `useServices()` refetches on every window focus - so a transient blip mid-choice must
	// degrade to the notice below, never wipe the list the visitor is using.
	if ( error && undefined === data ) {
		return (
			<p className="reservant-service-picker__status" role="alert">
				{ errorMessage( error ) }
			</p>
		);
	}

	return (
		<>
			{ null !== error && (
				<p className="reservant-service-picker__notice" role="status">
					{ __( 'The service list could not be refreshed and may be out of date.', 'reservant' ) }
				</p>
			) }
			<ul className="reservant-service-picker">
				{ ( data ?? [] ).map( ( service ) => (
					<li key={ service.id } className="reservant-service-picker__item">
						<button
							type="button"
							className={
								value === service.id
									? 'reservant-service-picker__choice reservant-service-picker__choice--selected'
									: 'reservant-service-picker__choice'
							}
							aria-pressed={ value === service.id }
							onClick={ () => onChange( service.id ) }
						>
							<span className="reservant-service-picker__name">{ service.name }</span>
							<span className="reservant-service-picker__duration">
								{ sprintf(
									/* translators: %d: duration in minutes. */
									__( '%d min', 'reservant' ),
									service.duration_min
								) }
							</span>
							<span className="reservant-service-picker__price">
								{ formatPrice( service.price_minor, service.currency ) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</>
	);
}
