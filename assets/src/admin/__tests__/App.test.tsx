import { parseHash } from '../components/App';

describe( 'parseHash', () => {
	it( 'reads the detail id off the hash for the current screen', () => {
		expect( parseHash( 'bookings', '#/57c9' ) ).toEqual( { screen: 'bookings', id: '57c9' } );
	} );

	it( 'has no id when the hash is empty', () => {
		expect( parseHash( 'calendar', '' ) ).toEqual( { screen: 'calendar' } );
	} );

	it( 'has no id for a bare hash with nothing after the slash', () => {
		expect( parseHash( 'calendar', '#/' ) ).toEqual( { screen: 'calendar' } );
	} );

	it( 'decodes a URL-encoded id', () => {
		expect( parseHash( 'bookings', '#/abc%2Fdef' ) ).toEqual( { screen: 'bookings', id: 'abc/def' } );
	} );
} );
