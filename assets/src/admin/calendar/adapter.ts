import { __ } from '@wordpress/i18n';
import type { CalendarBooking, CalendarBookingItem, CalendarOccurrence } from '../api/types';

/**
 * One drawable slot on the calendar grid (Task 14 brief). `resourceId` is null only for an event
 * item (`CalendarBookingItem.resource_id` is null for those - no staff member is named) or for an
 * `occurrence` event, which never names one either.
 */
export interface CalEvent {
	id: string;
	kind: 'booking' | 'gap' | 'occurrence';
	title: string;
	start: Date;
	end: Date;
	resourceId: number | null;
	status: string;
}

/**
 * Parses a `Y-m-d H:i:s` (MySQL) or `Y-m-dTH:i:s` (ISO) UTC datetime string - exactly the shape
 * every `*_utc` field on the wire carries (`CalendarAdminController`) - into the real UTC instant
 * it names. A hand-rolled regex rather than the bare `Date` constructor: the space-separated MySQL
 * form is not part of the ECMAScript-guaranteed grammar, so parsing it via `new Date(str)` is
 * implementation-defined and unsafe to rely on across engines.
 */
function parseUtc( dateStr: string ): Date {
	const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec( dateStr );
	if ( null === match ) {
		throw new Error( `utcToSite: unparseable UTC datetime "${ dateStr }"` );
	}
	const [ , year, month, day, hour, minute, second ] = match;
	return new Date(
		Date.UTC( Number( year ), Number( month ) - 1, Number( day ), Number( hour ), Number( minute ), Number( second ) )
	);
}

function requirePart( parts: Partial< Record< Intl.DateTimeFormatPartTypes, string > >, type: Intl.DateTimeFormatPartTypes ): number {
	const value = parts[ type ];
	if ( undefined === value ) {
		throw new Error( `utcToSite: Intl.DateTimeFormat did not produce a "${ type }" part` );
	}
	return Number( value );
}

/**
 * Converts a UTC datetime string to the `Date` that react-big-calendar (and every date-fns call it
 * makes internally) reads back as `tz`'s own wall-clock time - regardless of the browser's or the
 * CI runner's own timezone. `date-fns`/react-big-calendar only ever read a `Date` through its LOCAL
 * getters (`getHours`, `getDay`, ...), which reflect the *host machine's* timezone; a plain
 * `date-fns` localizer has no "pass a timezone in" option. So rather than building the real instant
 * in `tz` (which those local getters would then read back wrong on any host not already in `tz`),
 * this packs `tz`'s wall-clock numbers - computed by `Intl`, which does know the IANA rules
 * including DST - into a `Date` via the LOCAL constructor: the exact inverse of the local getters.
 * `new Date(y, m, d, h, mi, s).getHours()` always returns `h` back, on any machine, in any
 * timezone, which is what makes the result portable between a dev box and a CI runner in a
 * different zone without either of them needing to *be* `tz`.
 */
export function utcToSite( dateStr: string, tz: string ): Date {
	const instant = parseUtc( dateStr );
	const formatter = new Intl.DateTimeFormat( 'en-US', {
		timeZone: tz,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	} );

	const parts: Partial< Record< Intl.DateTimeFormatPartTypes, string > > = {};
	for ( const part of formatter.formatToParts( instant ) ) {
		parts[ part.type ] = part.value;
	}

	const year = requirePart( parts, 'year' );
	const month = requirePart( parts, 'month' );
	const day = requirePart( parts, 'day' );
	// hourCycle 'h23' still prints "24" for local midnight on some ICU builds - fold it back to 0.
	const hour = requirePart( parts, 'hour' ) % 24;
	const minute = requirePart( parts, 'minute' );
	const second = requirePart( parts, 'second' );

	return new Date( year, month - 1, day, hour, minute, second );
}

function bookingEvent( parent: CalendarBooking, index: number, item: CalendarBookingItem, tz: string ): CalEvent {
	const service = item.service_name ?? __( 'Booking', 'reservant' );
	return {
		id: `${ parent.uuid }:${ index }`,
		kind: 'booking',
		title: `${ parent.customer_name } - ${ service }`,
		start: utcToSite( item.block_start_utc, tz ),
		end: utcToSite( item.block_end_utc, tz ),
		resourceId: item.resource_id,
		status: parent.status,
	};
}

/**
 * The staff member is free during a processing gap (AGENTS.md section 2.2) - it blocks nothing and
 * is not part of the booking's own occupied range, so it earns its own visually-distinct event
 * `[end_utc, processing_ends_utc]` rather than being folded into the booking block. `null` when
 * there is no gap to show.
 */
function maybeGapEvent(
	parent: CalendarBooking,
	index: number,
	item: CalendarBookingItem,
	tz: string
): CalEvent | null {
	if ( null === item.processing_ends_utc ) {
		return null;
	}
	if ( parseUtc( item.processing_ends_utc ).getTime() <= parseUtc( item.end_utc ).getTime() ) {
		return null;
	}
	return {
		id: `${ parent.uuid }:${ index }:gap`,
		kind: 'gap',
		title: __( 'Processing gap', 'reservant' ),
		start: utcToSite( item.end_utc, tz ),
		end: utcToSite( item.processing_ends_utc, tz ),
		resourceId: item.resource_id,
		status: parent.status,
	};
}

function occurrenceEvent( occurrence: CalendarOccurrence, tz: string ): CalEvent {
	const name = occurrence.service_name ?? __( 'Event', 'reservant' );
	const booked = occurrence.capacity - occurrence.remaining;
	return {
		id: `occurrence:${ occurrence.id }`,
		kind: 'occurrence',
		title: `${ name } (${ booked }/${ occurrence.capacity })`,
		start: utcToSite( occurrence.start_utc, tz ),
		end: utcToSite( occurrence.end_utc, tz ),
		resourceId: null,
		status: occurrence.remaining <= 0 ? 'full' : 'open',
	};
}

/**
 * The calendar's whole event set for a window (Task 14 brief): one `booking` event per item,
 * spanning its staff-occupied `block_start/end_utc` (not the bare service `start/end_utc`, so
 * buffers show on the grid too), plus a `gap` event for any item whose processing time runs past
 * its own end, plus one `occurrence` event per event-service instance.
 */
export function toEvents( bookings: CalendarBooking[], occurrences: CalendarOccurrence[], tz: string ): CalEvent[] {
	const events: CalEvent[] = [];

	for ( const booking of bookings ) {
		booking.items.forEach( ( item, index ) => {
			events.push( bookingEvent( booking, index, item, tz ) );
			const gap = maybeGapEvent( booking, index, item, tz );
			if ( null !== gap ) {
				events.push( gap );
			}
		} );
	}

	for ( const occurrence of occurrences ) {
		events.push( occurrenceEvent( occurrence, tz ) );
	}

	return events;
}
