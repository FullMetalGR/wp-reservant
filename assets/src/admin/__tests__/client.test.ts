import { apiFetch, ApiError } from '../api/client';

function jsonResponse( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

describe( 'apiFetch', () => {
	beforeEach( () => {
		( window as { reservantAdmin?: unknown } ).reservantAdmin = {
			restRoot: '/wp-json/',
			nonce: 'test-nonce',
			caps: [ 'reservant_manage_bookings' ],
			currency: 'EUR',
			timezone: 'Europe/Athens',
			granularityMin: 5,
		};
		global.fetch = jest.fn();
	} );

	it( 'returns typed json on the success path', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( { total: 1, bookings: [] } ) );

		const result = await apiFetch< { total: number; bookings: unknown[] } >( '/admin/bookings' );

		expect( result ).toEqual( { total: 1, bookings: [] } );
	} );

	it( 'joins restRoot, the namespace and the path, and sets the nonce header', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( {} ) );

		await apiFetch( '/admin/bookings' );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, init ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
		expect( url ).toBe( '/wp-json/reservant/v1/admin/bookings' );
		const headers = new Headers( init.headers );
		expect( headers.get( 'X-WP-Nonce' ) ).toBe( 'test-nonce' );
	} );

	it( 'throws a typed ApiError parsed from the engine error envelope on a non-ok response', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue(
			jsonResponse(
				{
					code: 'reservant_conflict',
					message: 'overlap',
					data: { status: 409, detail: 'That time was just taken.', segment: 0 },
				},
				409
			)
		);

		await expect( apiFetch( '/admin/bookings' ) ).rejects.toMatchObject( {
			code: 'reservant_conflict',
			message: 'overlap',
			status: 409,
			detail: 'That time was just taken.',
			segment: 0,
		} );
	} );

	it( 'the rejection is an instance of ApiError', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue(
			jsonResponse( { code: 'reservant_not_found', message: 'not_found', data: { status: 404, detail: 'Gone.' } }, 404 )
		);

		let caught: unknown;
		try {
			await apiFetch( '/admin/bookings/does-not-exist' );
		} catch ( error ) {
			caught = error;
		}

		expect( caught ).toBeInstanceOf( ApiError );
		expect( ( caught as ApiError ).segment ).toBeUndefined();
	} );
} );
