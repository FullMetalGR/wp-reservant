import { format, getDay, parse, startOfWeek } from 'date-fns';
import { enUS } from 'date-fns/locale';
import { __ } from '@wordpress/i18n';
import { Calendar, dateFnsLocalizer } from 'react-big-calendar';
import type { EventProps, EventPropGetter, SlotInfo } from 'react-big-calendar';
import 'react-big-calendar/lib/css/react-big-calendar.css';
import { bootConfig } from '../boot';
import { colorFor } from './colors';
import type { CalEvent } from './adapter';

const locales = { 'en-US': enUS };

// Built once, module-level: react-big-calendar's date-fns localizer only ever reads Date objects
// through LOCAL getters, which is exactly what `utcToSite` (adapter.ts) packs site-local wall-clock
// numbers into - see that function's docblock for why.
const localizer = dateFnsLocalizer( { format, parse, startOfWeek, getDay, locales } );

const NEUTRAL_COLOR = '#6b7280';

export interface CalendarSlot {
	start: Date;
	end: Date;
	resourceId?: number;
}

export interface ReservantCalendarProps {
	events: CalEvent[];
	view: 'week' | 'day';
	date: Date;
	staffFilter: number | 'all';
	readOnly?: boolean;
	onSelectSlot?: ( slot: CalendarSlot ) => void;
	onSelectEvent?: ( event: CalEvent ) => void;
}

/** The event body: a status badge for an awaiting-approval booking, otherwise just the title. */
function EventContent( { event }: EventProps< CalEvent > ) {
	return (
		<div className="reservant-cal-event__content">
			{ 'awaiting_approval' === event.status && (
				<span className="reservant-cal-event__badge">{ __( 'Pending', 'reservant' ) }</span>
			) }
			<span className="reservant-cal-event__title">{ event.title }</span>
		</div>
	);
}

/**
 * Visual treatment per event kind (Task 14 brief): a hatched fill for `gap` (the staff member is
 * free, but the slot is not open for new bookings either), a banner look for `occurrence`
 * (event-style, capacity-based rather than staff-based), and a flat staff color for an ordinary
 * `booking`. Color itself always comes from `colorFor()` so the same resource reads the same color
 * in every event kind.
 */
const eventPropGetter: EventPropGetter< CalEvent > = ( event ) => {
	const color = null === event.resourceId ? NEUTRAL_COLOR : colorFor( event.resourceId );

	if ( 'gap' === event.kind ) {
		return {
			className: 'reservant-cal-event reservant-cal-event--gap',
			style: {
				backgroundColor: 'transparent',
				backgroundImage: `repeating-linear-gradient(45deg, ${ color }55, ${ color }55 6px, transparent 6px, transparent 12px)`,
				border: `1px dashed ${ color }`,
				color: '#1e1e1e',
			},
		};
	}

	if ( 'occurrence' === event.kind ) {
		return {
			className: 'reservant-cal-event reservant-cal-event--occurrence',
			style: {
				backgroundColor: color,
				backgroundImage: 'linear-gradient(180deg, rgba(255,255,255,0.4), rgba(255,255,255,0))',
				borderLeft: `4px solid ${ color }`,
			},
		};
	}

	return {
		className: 'reservant-cal-event reservant-cal-event--booking',
		style: { backgroundColor: color },
	};
};

/**
 * Wraps react-big-calendar's week/day grid (Task 14 brief). Filters `events` down to `staffFilter`
 * itself - callers may already narrow the fetch server-side (`useCalendar(range, resourceId)`) as a
 * payload optimization, but this filter is the correctness guarantee regardless of what the caller
 * did. `readOnly` suppresses slot selection (no new-booking affordance); event selection (viewing a
 * booking's detail) is controlled purely by whether the caller passed `onSelectEvent`.
 */
export function ReservantCalendar( {
	events,
	view,
	date,
	staffFilter,
	readOnly = false,
	onSelectSlot,
	onSelectEvent,
}: ReservantCalendarProps ) {
	const { granularityMin } = bootConfig();
	const step = granularityMin > 0 ? granularityMin : 30;
	const timeslots = Math.max( 1, Math.round( 60 / step ) );

	const visible = 'all' === staffFilter ? events : events.filter( ( event ) => event.resourceId === staffFilter );

	const canSelectSlots = ! readOnly && undefined !== onSelectSlot;

	const handleSelectSlot = canSelectSlots
		? ( slotInfo: SlotInfo ): void => {
				onSelectSlot( {
					start: slotInfo.start,
					end: slotInfo.end,
					resourceId: 'number' === typeof slotInfo.resourceId ? slotInfo.resourceId : undefined,
				} );
		  }
		: undefined;

	const handleSelectEvent = onSelectEvent ? ( event: CalEvent ): void => onSelectEvent( event ) : undefined;

	return (
		<Calendar< CalEvent >
			localizer={ localizer }
			events={ visible }
			view={ view }
			date={ date }
			views={ [ 'week', 'day' ] }
			toolbar={ false }
			step={ step }
			timeslots={ timeslots }
			selectable={ canSelectSlots }
			onSelectSlot={ handleSelectSlot }
			onSelectEvent={ handleSelectEvent }
			eventPropGetter={ eventPropGetter }
			components={ { event: EventContent } }
			startAccessor="start"
			endAccessor="end"
			titleAccessor="title"
		/>
	);
}
