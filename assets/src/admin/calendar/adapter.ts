import { __ } from '@wordpress/i18n';
import { parseUtc, utcToSite } from '../../shared';
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
