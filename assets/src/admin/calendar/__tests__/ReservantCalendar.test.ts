import { visibleEvents } from '../ReservantCalendar';
import type { CalEvent } from '../adapter';

function event( overrides: Partial< CalEvent > = {} ): CalEvent {
	return {
		id: 'evt-1',
		kind: 'booking',
		title: 'Haircut',
		start: new Date( 2026, 5, 1, 9, 0 ),
		end: new Date( 2026, 5, 1, 9, 30 ),
		resourceId: 5,
		status: 'confirmed',
		...overrides,
	};
}

describe( 'visibleEvents', () => {
	it( 'returns every event unfiltered when staffFilter is "all"', () => {
		const events = [ event( { id: 'a', resourceId: 5 } ), event( { id: 'b', resourceId: 6 } ) ];
		expect( visibleEvents( events, 'all' ) ).toEqual( events );
	} );

	it( 'keeps only bookings/gaps whose resourceId matches a specific staff filter', () => {
		const mine = event( { id: 'mine', kind: 'booking', resourceId: 5 } );
		const theirs = event( { id: 'theirs', kind: 'booking', resourceId: 6 } );
		const myGap = event( { id: 'my-gap', kind: 'gap', resourceId: 5 } );
		const theirGap = event( { id: 'their-gap', kind: 'gap', resourceId: 6 } );

		const result = visibleEvents( [ mine, theirs, myGap, theirGap ], 5 );

		expect( result.map( ( evt ) => evt.id ) ).toEqual( [ 'mine', 'my-gap' ] );
	} );

	it( 'keeps an occurrence visible under a specific staff filter - occurrences are business-wide', () => {
		const occurrence = event( { id: 'occ', kind: 'occurrence', resourceId: null } );
		const someonesBooking = event( { id: 'not-mine', kind: 'booking', resourceId: 999 } );

		const result = visibleEvents( [ occurrence, someonesBooking ], 5 );

		expect( result.map( ( evt ) => evt.id ) ).toEqual( [ 'occ' ] );
	} );

	it( 'keeps every occurrence when "all" is selected too', () => {
		const occurrence = event( { id: 'occ', kind: 'occurrence', resourceId: null } );
		expect( visibleEvents( [ occurrence ], 'all' ) ).toEqual( [ occurrence ] );
	} );
} );
