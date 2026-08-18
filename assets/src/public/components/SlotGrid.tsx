/**
 * The slot step (P5 plan, Task 13): every feasible start for the chosen day as a real `<button>`
 * in a `<ul>` the stylesheet (Task 16) lays out as a responsive grid - list semantics plus native
 * button keyboard behaviour, never an `onKeyDown` on something that is not a button (the P4
 * lesson).
 *
 * Times render on the SITE clock, and the plan calls this pin NOT OPTIONAL: the whole timezone
 * spine exists so a customer in another timezone sees the salon's 09:00, not their own. The
 * conversion is `utcToSite( start.utc, widgetBootstrap().timezone )` - the one spine every
 * surface shares - rather than a re-parse of the wire's `local` string: `local`'s digits follow
 * the REQUEST's `tz` parameter (`AvailabilityController` formats `->setTimezone( $display )`), so
 * leaning on them would silently track whatever a future caller sends as `tz`, while the
 * bootstrap timezone is the salon's by construction. The browser's own clock is read nowhere.
 *
 * The chosen start is reported WHOLE: its `utc` half is exactly the string `POST /holds` expects
 * back (`SlotStart`'s docblock), so it must round-trip untouched, and the `local` half rides
 * along for any caller that wants to echo the choice.
 *
 * Formatting is `Intl` in the machine's own locale over the site-packed `Date` (the `undefined`
 * locale is deliberate, the ServicePicker precedent): `utcToSite` packs the SITE's wall-clock
 * digits into the fields local getters read, which is exactly what a timeZone-less formatter
 * consumes - so the glyphs are the visitor's, the clock the salon's.
 */
import { __ } from '@wordpress/i18n';
import { utcToSite } from '../../shared';
import { widgetBootstrap } from '../api/client';
import type { SlotStart } from '../api/types';

interface SlotGridProps {
	starts: SlotStart[];
	onSelect: ( start: SlotStart ) => void;
}

export function SlotGrid( { starts, onSelect }: SlotGridProps ): JSX.Element {
	if ( 0 === starts.length ) {
		// role="status": a polite answer to the visitor's own action (they picked this date),
		// per the widget's live-region convention (assets/src/public/index.tsx) - nothing went
		// wrong, so role="alert" would interrupt for no reason. Never a blank panel: an empty
		// answer the visitor cannot see reads as a broken widget.
		return (
			<p className="reservant-slot-grid__empty" role="status">
				{ __( 'No times are available on this day. Please pick another date.', 'reservant' ) }
			</p>
		);
	}

	const { timezone } = widgetBootstrap();
	const formatter = new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } );

	return (
		<ul className="reservant-slot-grid">
			{ starts.map( ( start ) => (
				<li key={ start.utc } className="reservant-slot-grid__item">
					<button
						type="button"
						className="reservant-slot-grid__slot"
						onClick={ () => onSelect( start ) }
					>
						{ formatter.format( utcToSite( start.utc, timezone ) ) }
					</button>
				</li>
			) ) }
		</ul>
	);
}
