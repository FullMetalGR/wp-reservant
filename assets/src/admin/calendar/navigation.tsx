import { __ } from '@wordpress/i18n';
import { Button, ButtonGroup } from '@wordpress/components';
import { addDays, format, startOfWeek } from 'date-fns';
import { utcToSite } from './adapter';

export type CalView = 'week' | 'day';

/**
 * "Now", expressed as the site-local `Date` the calendar/adapter work in (see `utcToSite`).
 *
 * This, and never `new Date()`, is what any screen must start from when it needs "today" in
 * BUSINESS terms. `date-fns`'s `format()` reads a `Date` through the HOST machine's local getters,
 * so `format(new Date(), 'yyyy-MM-dd')` answers the day it is on the admin's own laptop - which is
 * not the day it is at the business whenever the two are in different zones. An owner in US/Pacific
 * at 16:00 local is already on the NEXT day in a Europe/Athens business; a screen defaulting to the
 * host's day would silently show, and book against, yesterday.
 *
 * `siteNow()` packs the site zone's wall-clock numbers into a `Date` (that is what `utcToSite`
 * does), so those same host-local getters - and therefore `format()`, `startOfWeek()`, `addDays()` -
 * read the SITE's day back on any machine.
 */
export function siteNow( tz: string ): Date {
	return utcToSite( new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ), tz );
}

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
