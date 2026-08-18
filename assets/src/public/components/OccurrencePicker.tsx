/**
 * The event counterpart of the slot step (P5 plan, Task 13): an event's occurrences are authored
 * by the owner, so "when could this run" is a fixed list, and what the visitor needs next to each
 * start is how many places are left (design section 5.1).
 *
 * `remaining` is ADVISORY, like every availability read - the authoritative check happens under
 * the occurrence row lock inside `POST /holds` - which is exactly why `remaining: 0` renders as a
 * REAL `<button disabled>`: never a styled div, never a button that fires and fails (the P4
 * lesson). The platform makes the click inert, and "Sold out" is stated in TEXT inside the
 * control, so the state is announced rather than conveyed by colour alone.
 *
 * Starts render on the SITE clock via `utcToSite` + the bootstrap timezone, the same spine as
 * `SlotGrid` - the occurrence wire shape carries only `*_utc` fields
 * (`AvailabilityController::occurrences()`), so there is no server-formatted local string here at
 * all. The chosen occurrence is reported WHOLE: `id` is what `POST /holds`' `event.occurrence_id`
 * wants, and the `*_utc` fields let the review step echo the times without a second lookup.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { utcToSite } from '../../shared';
import { widgetBootstrap } from '../api/client';
import type { OccurrenceOption } from '../api/types';

interface OccurrencePickerProps {
	occurrences: OccurrenceOption[];
	onSelect: ( occurrence: OccurrenceOption ) => void;
}

export function OccurrencePicker( { occurrences, onSelect }: OccurrencePickerProps ): JSX.Element {
	if ( 0 === occurrences.length ) {
		// role="status", the widget's polite live-region convention (see SlotGrid's empty state):
		// an empty list is an answer, not an emergency - and never a blank panel.
		return (
			<p className="reservant-occurrence-picker__empty" role="status">
				{ __( 'There are no upcoming dates for this event.', 'reservant' ) }
			</p>
		);
	}

	const { timezone } = widgetBootstrap();
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );

	return (
		<ul className="reservant-occurrence-picker">
			{ occurrences.map( ( occurrence ) => {
				const soldOut = 0 >= occurrence.remaining;
				return (
					<li key={ occurrence.id } className="reservant-occurrence-picker__item">
						<button
							type="button"
							className={
								soldOut
									? 'reservant-occurrence-picker__choice reservant-occurrence-picker__choice--sold-out'
									: 'reservant-occurrence-picker__choice'
							}
							disabled={ soldOut }
							onClick={ () => onSelect( occurrence ) }
						>
							<span className="reservant-occurrence-picker__when">
								{ formatter.format( utcToSite( occurrence.start_utc, timezone ) ) }
							</span>
							<span className="reservant-occurrence-picker__remaining">
								{ soldOut
									? __( 'Sold out', 'reservant' )
									: sprintf(
											/* translators: %d: number of places still bookable. */
											_n(
												'%d place left',
												'%d places left',
												occurrence.remaining,
												'reservant'
											),
											occurrence.remaining
									  ) }
							</span>
						</button>
					</li>
				);
			} ) }
		</ul>
	);
}
