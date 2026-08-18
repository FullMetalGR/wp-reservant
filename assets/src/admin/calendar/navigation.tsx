import { __ } from '@wordpress/i18n';
import { Button, ButtonGroup } from '@wordpress/components';
import { addDays, format, startOfWeek } from 'date-fns';
import { siteNow } from '../../shared';

export type CalView = 'week' | 'day';

/** Today's business date as `yyyy-MM-dd` - the date-input default every date-taking screen wants. */
export function siteToday( tz: string ): string {
	return format( siteNow( tz ), 'yyyy-MM-dd' );
}

/**
 * The `{from, to}` business-date window (`useCalendar`'s `CalendarRange`, `to` exclusive) that
 * covers `date`'s week or day, in site-local terms. `date` is itself a site-local-packed `Date`
 * (see `utcToSite`), and `date-fns`'s `startOfWeek`/`addDays`/`format` all read/write through the
 * same LOCAL getters that packing relies on, so this stays correct without any tz argument of its
 * own.
 */
export function rangeFor( date: Date, view: CalView ): { from: string; to: string } {
	if ( 'day' === view ) {
		return { from: format( date, 'yyyy-MM-dd' ), to: format( addDays( date, 1 ), 'yyyy-MM-dd' ) };
	}
	const start = startOfWeek( date );
	return { from: format( start, 'yyyy-MM-dd' ), to: format( addDays( start, 7 ), 'yyyy-MM-dd' ) };
}

interface CalendarNavProps {
	view: CalView;
	onChangeView: ( view: CalView ) => void;
	onNavigate: ( direction: 1 | -1 ) => void;
	onToday: () => void;
}

/** The week/day toggle plus Previous/Today/Next date nav - shared by `CalendarScreen` and `MyCalendarScreen`. */
export function CalendarNav( { view, onChangeView, onNavigate, onToday }: CalendarNavProps ) {
	return (
		<>
			<ButtonGroup>
				<Button variant={ 'week' === view ? 'primary' : 'secondary' } onClick={ () => onChangeView( 'week' ) }>
					{ __( 'Week', 'reservant' ) }
				</Button>
				<Button variant={ 'day' === view ? 'primary' : 'secondary' } onClick={ () => onChangeView( 'day' ) }>
					{ __( 'Day', 'reservant' ) }
				</Button>
			</ButtonGroup>
			<ButtonGroup>
				<Button onClick={ () => onNavigate( -1 ) }>{ __( 'Previous', 'reservant' ) }</Button>
				<Button onClick={ onToday }>{ __( 'Today', 'reservant' ) }</Button>
				<Button onClick={ () => onNavigate( 1 ) }>{ __( 'Next', 'reservant' ) }</Button>
			</ButtonGroup>
		</>
	);
}
