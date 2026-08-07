import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { addDays } from 'date-fns';
import { bootConfig } from '../boot';
import { useCalendar } from '../api/queries';
import { toEvents, type CalEvent } from '../calendar/adapter';
import { CalendarNav, rangeFor, siteNow, type CalView } from '../calendar/navigation';
import { ReservantCalendar } from '../calendar/ReservantCalendar';

/**
 * A staff member's own schedule (Task 14 brief): the same week/day grid as `CalendarScreen`, but
 * read-only (no slot selection - staff cannot create bookings from here), no staff filter (the
 * server already scopes `/admin/calendar` to the caller's own resource for a
 * `reservant_view_own_calendar`-only viewer, per `CalendarAdminController::index()`), and no
 * `resource_id` sent - the endpoint decides the scope, not this screen. Clicking an event still
 * records the selection in a plain `<Notice>`, same as `CalendarScreen` - Task 15 decides whether
 * that becomes a read-only detail view.
 */
export function MyCalendarScreen() {
	const { timezone } = bootConfig();
	const [ view, setView ] = useState< CalView >( 'week' );
	const [ date, setDate ] = useState< Date >( () => siteNow( timezone ) );
	const [ selected, setSelected ] = useState< CalEvent | null >( null );

	const range = useMemo( () => rangeFor( date, view ), [ date, view ] );
	const calendarQuery = useCalendar( range );

	const events = useMemo(
		() => toEvents( calendarQuery.data?.bookings ?? [], calendarQuery.data?.occurrences ?? [], timezone ),
		[ calendarQuery.data, timezone ]
	);

	const step = 'day' === view ? 1 : 7;

	return (
		<div className="reservant-calendar-screen">
			<div className="reservant-calendar-toolbar">
				<CalendarNav
					view={ view }
					onChangeView={ setView }
					onNavigate={ ( direction ) => setDate( ( current ) => addDays( current, direction * step ) ) }
					onToday={ () => setDate( siteNow( timezone ) ) }
				/>
			</div>

			{ calendarQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load your calendar.', 'reservant' ) }
				</Notice>
			) }

			<ReservantCalendar
				events={ events }
				view={ view }
				date={ date }
				staffFilter="all"
				readOnly
				onSelectEvent={ setSelected }
			/>

			{ null !== selected && (
				<Notice status="info" onRemove={ () => setSelected( null ) }>
					{ `${ __( 'Selected', 'reservant' ) }: ${ selected.title }` }
				</Notice>
			) }
		</div>
	);
}
