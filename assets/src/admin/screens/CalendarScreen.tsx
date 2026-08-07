import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, SelectControl } from '@wordpress/components';
import { addDays, format } from 'date-fns';
import { bootConfig } from '../boot';
import { useCalendar, useResources } from '../api/queries';
import type { Resource } from '../api/types';
import { toEvents, type CalEvent } from '../calendar/adapter';
import { CalendarNav, rangeFor, siteNow, type CalView } from '../calendar/navigation';
import { ReservantCalendar, type CalendarSlot } from '../calendar/ReservantCalendar';
import { BookingDrawer } from './BookingDrawer';
import { ManualBookingDrawer } from './ManualBookingDrawer';

/**
 * "All staff" plus every ACTIVE resource, in the shape `<SelectControl>` wants.
 *
 * `useResources()` deliberately returns inactive rows too (the catalog screen needs them to be
 * reactivatable at all), so this filter is load-bearing rather than decorative: a departed staff
 * member must not be offered as the target of a NEW booking made from an empty calendar slot.
 * `BookingsScreen`'s own staff filter makes the opposite choice, and correctly - filtering the
 * booking HISTORY by a since-deactivated staff member is exactly what you want to be able to do.
 */
function staffOptions( resources: Resource[] ): { label: string; value: string }[] {
	return [
		{ label: __( 'All staff', 'reservant' ), value: 'all' },
		...resources
			.filter( ( resource ) => 'active' === resource.status )
			.map( ( resource ) => ( { label: resource.name, value: String( resource.id ) } ) ),
	];
}

type Selection = { kind: 'slot'; slot: CalendarSlot } | { kind: 'booking'; uuid: string };

/**
 * `CalEvent.id` is uuid-based (`adapter.ts`): `${uuid}:${index}` for a `booking` event,
 * `${uuid}:${index}:gap` for the processing gap that follows one - both belong to a real booking,
 * so the uuid is always the id's first `:`-separated segment. An `occurrence` event
 * (`occurrence:${id}`) names no booking at all - Task 15's drawers cover bookings only, so those
 * clicks are a deliberate no-op rather than opening a drawer for something that does not exist.
 */
function bookingUuidFor( event: CalEvent ): string | null {
	if ( 'occurrence' === event.kind ) {
		return null;
	}
	return event.id.split( ':' )[ 0 ] ?? null;
}

/** Whichever drawer the current selection calls for - `null` when nothing is selected. */
function SelectionDrawer( {
	selection,
	staffFilter,
	onClose,
}: {
	selection: Selection | null;
	staffFilter: number | 'all';
	onClose: () => void;
} ) {
	if ( null === selection ) {
		return null;
	}
	if ( 'booking' === selection.kind ) {
		return <BookingDrawer uuid={ selection.uuid } onClose={ onClose } />;
	}
	return (
		<ManualBookingDrawer
			onClose={ onClose }
			initialDate={ format( selection.slot.start, 'yyyy-MM-dd' ) }
			initialResourceId={ 'all' === staffFilter ? undefined : staffFilter }
		/>
	);
}

/**
 * The owner's whole schedule (Task 14 brief): a staff filter, week/day toggle, date nav, and the
 * week/day grid itself. Selecting an event opens `BookingDrawer` for the booking it belongs to
 * (skipped for an `occurrence` - see `bookingUuidFor`); selecting an empty slot opens
 * `ManualBookingDrawer` prefilled with that slot's date and the screen's own `staffFilter` -
 * `CalendarSlot.resourceId` itself is unreliable (undefined outside a resource-per-row view, per
 * `ReservantCalendar`), so `staffFilter` is the prefill source of truth, not the slot payload.
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

	function handleSelectEvent( event: CalEvent ): void {
		const uuid = bookingUuidFor( event );
		if ( null !== uuid ) {
			setSelection( { kind: 'booking', uuid } );
		}
	}

	return (
		<div className="reservant-calendar-screen">
			<div className="reservant-calendar-toolbar">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
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
				onSelectEvent={ handleSelectEvent }
			/>

			<SelectionDrawer selection={ selection } staffFilter={ staffFilter } onClose={ () => setSelection( null ) } />
		</div>
	);
}
