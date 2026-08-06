import { siteToUtc, toEvents, utcToSite } from '../adapter';
import type { CalendarBooking, CalendarBookingItem, CalendarOccurrence } from '../../api/types';

describe( 'utcToSite', () => {
	// Europe/Athens, week of 2026-10-25: DST ends Sunday 2026-10-25 at 04:00 EEST -> 03:00 EET
	// (01:00 UTC). Oct 24 is still EEST (+3); Oct 26 is already EET (+2).
	it( 'maps 09:00 UTC on 2026-10-24 to 12:00 local (still EEST, +3, before the fold)', () => {
		const site = utcToSite( '2026-10-24 09:00:00', 'Europe/Athens' );
		expect( site.getFullYear() ).toBe( 2026 );
		expect( site.getMonth() ).toBe( 9 ); // October is month index 9
		expect( site.getDate() ).toBe( 24 );
		expect( site.getHours() ).toBe( 12 );
		expect( site.getMinutes() ).toBe( 0 );
	} );

	it( 'maps 09:00 UTC on 2026-10-26 to 11:00 local (EET, +2, after the fold)', () => {
		const site = utcToSite( '2026-10-26 09:00:00', 'Europe/Athens' );
		expect( site.getDate() ).toBe( 26 );
		expect( site.getHours() ).toBe( 11 );
		expect( site.getMinutes() ).toBe( 0 );
	} );

	it( 'is a no-op shift for UTC itself', () => {
		const site = utcToSite( '2026-06-01 09:30:15', 'UTC' );
		expect( [ site.getFullYear(), site.getMonth(), site.getDate(), site.getHours(), site.getMinutes(), site.getSeconds() ] ).toEqual( [
			2026, 5, 1, 9, 30, 15,
		] );
	} );

	it( 'accepts an ISO-style "T" separator, not only the MySQL space form', () => {
		const site = utcToSite( '2026-10-24T09:00:00', 'Europe/Athens' );
		expect( site.getHours() ).toBe( 12 );
	} );

	it( 'is portable across the host machine\'s own timezone (does not depend on process.env.TZ)', () => {
		// Both fixture datetimes above are asserted via local Date getters (getHours/getDate/...),
		// which normally reflect the HOST's timezone. This spec exists so a future change that
		// breaks that portability (e.g. swapping the local Date constructor for `Date.UTC` without
		// also swapping every read site to the UTC getters) fails loudly: the wall-clock numbers
		// packed in must come back out unchanged regardless of what TZ the test runner happens to
		// have, which this repeat assertion (already covered above) exists specifically to pin.
		const site = utcToSite( '2026-01-15 00:00:00', 'Pacific/Kiritimati' ); // UTC+14, crosses a date
		expect( site.getDate() ).toBe( 15 );
		expect( site.getHours() ).toBe( 14 );
	} );
} );

describe( 'siteToUtc', () => {
	it( 'is a no-op shift for UTC itself', () => {
		expect( siteToUtc( '2026-06-01', '09:30:15', 'UTC' ) ).toBe( '2026-06-01 09:30:15' );
	} );

	it( 'defaults seconds to :00 when the time string carries none (a bare HH:MM time input)', () => {
		expect( siteToUtc( '2026-06-01', '09:30', 'UTC' ) ).toBe( '2026-06-01 09:30:00' );
	} );

	it( 'converts Europe/Athens local noon (EEST, +3, before the fold) to 09:00 UTC', () => {
		expect( siteToUtc( '2026-06-01', '12:00', 'Europe/Athens' ) ).toBe( '2026-06-01 09:00:00' );
	} );

	it( 'converts Europe/Athens local 11:00 on 2026-10-26 (EET, +2, after the fold) to 09:00 UTC', () => {
		expect( siteToUtc( '2026-10-26', '11:00', 'Europe/Athens' ) ).toBe( '2026-10-26 09:00:00' );
	} );

	it( 'is the exact inverse of utcToSite for a normal (non-ambiguous) instant', () => {
		const utc = siteToUtc( '2026-06-01', '09:30:00', 'Europe/Athens' );
		const site = utcToSite( utc, 'Europe/Athens' );
		expect( [ site.getMonth(), site.getDate(), site.getHours(), site.getMinutes() ] ).toEqual( [ 5, 1, 9, 30 ] );
	} );

	// Europe/Athens, 2026-10-25: DST ends that day at 04:00 EEST -> 03:00 EET, so local 03:00-03:59
	// is ambiguous - it happens twice, once as EEST (+3, UTC 00:00-00:59) and once as EET (+2, UTC
	// 01:00-01:59). `siteToUtc` resolves the ambiguity deterministically to the LATER of the two
	// real instants (the post-transition, standard-time EET occurrence): its first pass samples the
	// site's offset by treating the target wall-clock digits themselves as a UTC instant on the same
	// calendar date, which - for any transition landing in the small hours local time, as every real
	// IANA rule does - already lies past the transition point in real UTC time, so the offset it
	// samples is always the POST-transition one. This is pinned exactly here rather than left
	// implicit, mirroring `utcToSite`'s own fold-boundary tests above.
	it( 'resolves the ambiguous fall-back hour (local 03:30 on 2026-10-25) to its later, post-transition (EET, +2) UTC instant', () => {
		expect( siteToUtc( '2026-10-25', '03:30', 'Europe/Athens' ) ).toBe( '2026-10-25 01:30:00' );
	} );

	it( 'is portable across the host machine\'s own timezone (does not depend on process.env.TZ)', () => {
		expect( siteToUtc( '2026-01-15', '14:00', 'Pacific/Kiritimati' ) ).toBe( '2026-01-15 00:00:00' ); // UTC+14
	} );
} );

const tz = 'UTC';

function item( overrides: Partial< CalendarBookingItem > = {} ): CalendarBookingItem {
	return {
		service_id: 1,
		service_name: 'Haircut',
		resource_id: 5,
		resource_name: 'Alex',
		start_utc: '2026-06-01 09:00:00',
		end_utc: '2026-06-01 09:30:00',
		block_start_utc: '2026-06-01 09:00:00',
		block_end_utc: '2026-06-01 09:30:00',
		processing_ends_utc: null,
		...overrides,
	};
}

function booking( overrides: Partial< CalendarBooking > = {} ): CalendarBooking {
	return {
		uuid: 'booking-1',
		status: 'confirmed',
		customer_name: 'Jane Doe',
		items: [ item() ],
		...overrides,
	};
}

describe( 'toEvents', () => {
	it( 'emits one booking event per item, spanning block_start_utc..block_end_utc', () => {
		const events = toEvents( [ booking() ], [], tz );

		expect( events ).toHaveLength( 1 );
		const [ event ] = events;
		expect( event ).toMatchObject( {
			kind: 'booking',
			status: 'confirmed',
			resourceId: 5,
			title: 'Jane Doe - Haircut',
		} );
		expect( event?.start ).toEqual( utcToSite( '2026-06-01 09:00:00', tz ) );
		expect( event?.end ).toEqual( utcToSite( '2026-06-01 09:30:00', tz ) );
	} );

	it( 'falls back to a generic title when service_name is null', () => {
		const events = toEvents( [ booking( { items: [ item( { service_name: null } ) ] } ) ], [], tz );
		expect( events[ 0 ]?.title ).toBe( 'Jane Doe - Booking' );
	} );

	it( 'emits resourceId null for an event item (no staff member)', () => {
		const events = toEvents( [ booking( { items: [ item( { resource_id: null, resource_name: null } ) ] } ) ], [], tz );
		expect( events[ 0 ]?.resourceId ).toBeNull();
	} );

	it( 'also emits a gap event when processing_ends_utc runs past end_utc, spanning end_utc..processing_ends_utc', () => {
		const withGap = booking( {
			items: [
				item( {
					service_name: 'Colour',
					end_utc: '2026-06-01 09:20:00',
					block_end_utc: '2026-06-01 09:50:00',
					processing_ends_utc: '2026-06-01 09:40:00',
				} ),
			],
		} );

		const events = toEvents( [ withGap ], [], tz );
		expect( events ).toHaveLength( 2 );

		const [ bookingEvt, gapEvt ] = events;
		expect( bookingEvt ).toMatchObject( { kind: 'booking' } );
		expect( bookingEvt?.start ).toEqual( utcToSite( '2026-06-01 09:00:00', tz ) );
		expect( bookingEvt?.end ).toEqual( utcToSite( '2026-06-01 09:50:00', tz ) );

		expect( gapEvt ).toMatchObject( { kind: 'gap', resourceId: 5, status: 'confirmed' } );
		expect( gapEvt?.start ).toEqual( utcToSite( '2026-06-01 09:20:00', tz ) );
		expect( gapEvt?.end ).toEqual( utcToSite( '2026-06-01 09:40:00', tz ) );
		// ids must be distinct so React keys / drawer lookups never collide.
		expect( bookingEvt?.id ).not.toBe( gapEvt?.id );
	} );

	it( 'does not emit a gap event when processing_ends_utc equals end_utc', () => {
		const noGap = booking( { items: [ item( { processing_ends_utc: '2026-06-01 09:30:00' } ) ] } );
		expect( toEvents( [ noGap ], [], tz ) ).toHaveLength( 1 );
	} );

	it( 'does not emit a gap event when processing_ends_utc is before end_utc (defensive - should never happen server-side)', () => {
		const bad = booking( { items: [ item( { processing_ends_utc: '2026-06-01 09:10:00' } ) ] } );
		expect( toEvents( [ bad ], [], tz ) ).toHaveLength( 1 );
	} );

	it( 'gives every item in a multi-segment chain its own event with a unique id', () => {
		const chain = booking( {
			items: [
				item( { service_name: 'Cut', resource_id: 5 } ),
				item( {
					service_name: 'Colour',
					resource_id: 6,
					start_utc: '2026-06-01 09:30:00',
					end_utc: '2026-06-01 10:00:00',
					block_start_utc: '2026-06-01 09:30:00',
					block_end_utc: '2026-06-01 10:00:00',
				} ),
			],
		} );

		const events = toEvents( [ chain ], [], tz );
		expect( events ).toHaveLength( 2 );
		expect( new Set( events.map( ( event ) => event.id ) ).size ).toBe( 2 );
		expect( events.map( ( event ) => event.resourceId ) ).toEqual( [ 5, 6 ] );
	} );

	it( 'emits an occurrence event with resourceId null and a capacity-aware title', () => {
		const occurrence: CalendarOccurrence = {
			id: 42,
			service_id: 9,
			service_name: 'Yoga class',
			start_utc: '2026-06-02 08:00:00',
			end_utc: '2026-06-02 09:00:00',
			capacity: 10,
			remaining: 4,
		};

		const events = toEvents( [], [ occurrence ], tz );
		expect( events ).toHaveLength( 1 );
		const [ event ] = events;
		expect( event ).toMatchObject( {
			id: 'occurrence:42',
			kind: 'occurrence',
			resourceId: null,
			title: 'Yoga class (6/10)',
			status: 'open',
		} );
		expect( event?.start ).toEqual( utcToSite( '2026-06-02 08:00:00', tz ) );
		expect( event?.end ).toEqual( utcToSite( '2026-06-02 09:00:00', tz ) );
	} );

	it( 'marks a fully-booked occurrence with status "full"', () => {
		const occurrence: CalendarOccurrence = {
			id: 43,
			service_id: 9,
			service_name: 'Yoga class',
			start_utc: '2026-06-02 08:00:00',
			end_utc: '2026-06-02 09:00:00',
			capacity: 10,
			remaining: 0,
		};
		expect( toEvents( [], [ occurrence ], tz )[ 0 ]?.status ).toBe( 'full' );
	} );

	it( 'falls back to a generic title when an occurrence has no service_name', () => {
		const occurrence: CalendarOccurrence = {
			id: 44,
			service_id: 9,
			service_name: null,
			start_utc: '2026-06-02 08:00:00',
			end_utc: '2026-06-02 09:00:00',
			capacity: 10,
			remaining: 10,
		};
		expect( toEvents( [], [ occurrence ], tz )[ 0 ]?.title ).toBe( 'Event (0/10)' );
	} );

	it( 'returns an empty list for no bookings and no occurrences', () => {
		expect( toEvents( [], [], tz ) ).toEqual( [] );
	} );
} );
