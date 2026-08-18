/**
 * The public catalog as a pick-one list (P5 plan, Task 12). Plain semantic HTML - a `<ul>` of
 * real `<button>` elements, never a keyboard-simulating `<div>` - and no `@wordpress/components`:
 * that package is a webpack external, so importing it would add the ~800 KB `wp-components` core
 * handle to every visitor while the byte budget saw nothing (`bin/widget-contract.mjs` owns the
 * enforcement).
 */
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, formatMoney } from '../../shared';
import { useServices } from '../api/queries';

interface ServicePickerProps {
	/** The chosen service id, or null while nothing is chosen yet. */
	value: number | null;
	onChange: ( id: number ) => void;
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
								{ formatMoney( service.price_minor, service.currency ) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</>
	);
}
