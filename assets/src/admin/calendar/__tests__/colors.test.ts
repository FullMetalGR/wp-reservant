import { colorFor, DEFAULT_PALETTE } from '../colors';

describe( 'DEFAULT_PALETTE', () => {
	it( 'has exactly eight colors (Task 14 brief: "8 accessible colors")', () => {
		expect( DEFAULT_PALETTE ).toHaveLength( 8 );
	} );

	it( 'has no duplicate colors', () => {
		expect( new Set( DEFAULT_PALETTE ).size ).toBe( DEFAULT_PALETTE.length );
	} );
} );

describe( 'colorFor', () => {
	it( 'is stable for the same resource id', () => {
		expect( colorFor( 1 ) ).toBe( colorFor( 1 ) );
		expect( colorFor( 42 ) ).toBe( colorFor( 42 ) );
	} );

	it( 'spreads eight distinct resource ids across eight distinct colors', () => {
		const colors = new Set( Array.from( { length: 8 }, ( _unused, id ) => colorFor( id ) ) );
		expect( colors.size ).toBe( 8 );
	} );

	it( 'wraps around modulo the palette length', () => {
		expect( colorFor( 0 ) ).toBe( colorFor( 8 ) );
		expect( colorFor( 1 ) ).toBe( colorFor( 9 ) );
		expect( colorFor( 7 ) ).toBe( colorFor( 15 ) );
	} );

	it( 'accepts a custom palette', () => {
		const palette = [ '#111111', '#222222', '#333333' ];
		expect( colorFor( 0, palette ) ).toBe( '#111111' );
		expect( colorFor( 1, palette ) ).toBe( '#222222' );
		expect( colorFor( 2, palette ) ).toBe( '#333333' );
		expect( colorFor( 3, palette ) ).toBe( '#111111' );
	} );

	it( 'throws on an empty palette rather than silently returning undefined', () => {
		expect( () => colorFor( 1, [] ) ).toThrow();
	} );
} );
