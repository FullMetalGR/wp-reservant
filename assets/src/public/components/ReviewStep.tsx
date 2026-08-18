/**
 * The review step (P5 plan, Task 14): what is about to be confirmed, read off the HELD BOOKING
 * the server returned - never off the widget's own selections. The server already re-validated
 * and priced everything under lock, so its items and total are the truth; echoing client state
 * here could show a price or a time the engine never agreed to.
 *
 * Service names come from the `useServices()` catalog the flow passes in: the public booking read
 * is a bare projection with no joined names (`types.ts` on `BookingItem`), and a service that
 * left the catalog since page load falls back to the same placeholder ChainBuilder uses. Times
 * render on the SITE clock through `utcToSite` + the bootstrap timezone - the one spine every
 * surface shares - formatted by `Intl` in the machine's own locale (the ServicePicker precedent).
 * The total reuses ServicePicker's `formatPrice`, so minor-unit scaling stays currency-derived in
 * exactly one place.
 *
 * Dumb and props-driven: the flow owns the countdown, the notices and the confirm transition;
 * this renders the summary and one real `<button>` whose disabled state the flow dictates.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { utcToSite } from '../../shared';
import type { Booking, PublicService } from '../api/types';
import { formatPrice } from './ServicePicker';

interface ReviewStepProps {
	/** The held booking as `POST /holds` returned it. */
	booking: Booking;
	/** The public catalog, for item names only. */
	services: PublicService[];
	/** The site timezone from the bootstrap - times render on the salon's clock. */
	timezone: string;
	onConfirm: () => void;
	/** True once the hold expired or while the confirm is in flight. */
	confirmDisabled: boolean;
}

export function ReviewStep( {
	booking,
	services,
	timezone,
	onConfirm,
	confirmDisabled,
}: ReviewStepProps ): JSX.Element {
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );

	return (
		<div className="reservant-review">
			<ul className="reservant-review__items">
				{ booking.items.map( ( item ) => (
					<li key={ item.id } className="reservant-review__item">
						<span className="reservant-review__service">
							{ services.find( ( service ) => service.id === item.service_id )?.name ??
								__( 'Unavailable service', 'reservant' ) }
						</span>
						<span className="reservant-review__when">
							{ formatter.format( utcToSite( item.start_utc, timezone ) ) }
						</span>
						{ item.seats > 1 && (
							<span className="reservant-review__seats">
								{ sprintf(
									/* translators: %d: number of places reserved. */
									_n( '%d place', '%d places', item.seats, 'reservant' ),
									item.seats
								) }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<p className="reservant-review__total">
				{ sprintf(
					/* translators: %s: the formatted total price. */
					__( 'Total: %s', 'reservant' ),
					formatPrice( booking.total_minor, booking.currency )
				) }
			</p>
			<p className="reservant-review__customer">
				{ booking.customer_name } ({ booking.customer_email })
			</p>
			<button
				type="button"
				className="reservant-review__confirm"
				onClick={ onConfirm }
				disabled={ confirmDisabled }
			>
				{ __( 'Confirm booking', 'reservant' ) }
			</button>
		</div>
	);
}
