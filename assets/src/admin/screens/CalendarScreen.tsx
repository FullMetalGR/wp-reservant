import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, ButtonGroup, Notice, SelectControl } from '@wordpress/components';
import { addDays, format, startOfWeek } from 'date-fns';
import { bootConfig } from '../boot';
import { useCalendar, useResources } from '../api/queries';
import type { Resource } from '../api/types';
import { toEvents, utcToSite, type CalEvent } from '../calendar/adapter';
import { ReservantCalendar, type CalendarSlot } from '../calendar/ReservantCalendar';

export type CalView = 'week' | 'day';

/** "Now", expressed as the site-local `Date` the calendar/adapter work in (see `utcToSite`). */
export function siteNow( tz: string ): Date {
	return utcToSite( new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ), tz );
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

/** "All staff" plus every active resource, in the shape `<SelectControl>` wants. */
function staffOptions( resources: Resource[] ): { label: string; value: string }[] {
	return [
		{ label: __( 'All staff', 'reservant' ), value: 'all' },
		...resources
			.filter( ( resource ) => 'active' === resource.status )
			.map( ( resource ) => ( { label: resource.name, value: String( resource.id ) } ) ),
	];
}

type Selection = { kind: 'slot'; slot: CalendarSlot } | { kind: 'event'; event: CalEvent };

/** Task 15 replaces this with the real drawers; for now the selection just names itself. */
function selectionMessage( selection: Selection ): string {
	if ( 'event' === selection.kind ) {
		return `${ __( 'Selected', 'reservant' ) }: ${ selection.event.title }`;
	}
	return __( 'Selected an empty slot - booking creation lands in a later task.', 'reservant' );
}

/**
 * The owner's whole schedule (Task 14 brief): a staff filter, week/day toggle, date nav, and the
 * week/day grid itself. Selecting a slot or an event just records the selection and shows it in a
 * plain `<Notice>` for now - Task 15 replaces that with `ManualBookingDrawer`/`BookingDrawer`
 * without this screen needing to change its own selection wiring.
 */
export function CalendarScreen() {
	const { timezone } = bootConfig();
	const [ view, setView ] = useState< CalView >( 'week' );
	const [ date, setDate ] = useState< Date >( () => siteNow( timezone ) );
	const [ staffFilter, setStaffFilter ] = useState< number | 'all' >( 'all' );
	const [ selection, setSelection ] = useState< Selection | null >( null );

	const range = useMemo( () => rangeFor( date, view ), [ date, view ] );
	const resourceId = 'all' === staffFilter ? undefined : staffFilter;
	const calendarQuery = useCalendar( range, resourceId );
	const resourcesQuery = useResources();

	const events = useMemo(
		() => toEvents( calendarQuery.data?.bookings ?? [], calendarQuery.data?.occurrences ?? [], timezone ),
		[ calendarQuery.data, timezone ]
	);

	const step = 'day' === view ? 1 : 7;

	return (
		<div className="reservant-calendar-screen">
			<div className="reservant-calendar-toolbar">
				<SelectControl
					label={ __( 'Staff', 'reservant' ) }
					value={ 'all' === staffFilter ? 'all' : String( staffFilter ) }
					options={ staffOptions( resourcesQuery.data ?? [] ) }
					onChange={ ( value ) => setStaffFilter( 'all' === value ? 'all' : Number( value ) ) }
				/>
				<CalendarNav
					view={ view }
					onChangeView={ setView }
					onNavigate={ ( direction ) => setDate( ( current ) => addDays( current, direction * step ) ) }
					onToday={ () => setDate( siteNow( timezone ) ) }
				/>
			</div>

			{ calendarQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load the calendar.', 'reservant' ) }
				</Notice>
			) }

			<ReservantCalendar
				events={ events }
				view={ view }
				date={ date }
				staffFilter={ staffFilter }
				onSelectSlot={ ( slot ) => setSelection( { kind: 'slot', slot } ) }
				onSelectEvent={ ( event ) => setSelection( { kind: 'event', event } ) }
			/>

			{ null !== selection && (
				<Notice status="info" onRemove={ () => setSelection( null ) }>
					{ selectionMessage( selection ) }
				</Notice>
			) }
		</div>
	);
}
