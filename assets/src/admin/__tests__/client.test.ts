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

	// review round 1 verification item: `namespace` (Task 16's WP-core user search) joins the URL
	// the same way, and `ApiError.fromResponse()` must tolerate a WP-CORE error body - no
	// `reservant/v1` envelope fields (`code`/`message` are core's own, `data` carries only `status`,
	// never `detail`/`segment`) - without throwing, degrading to sane values rather than blowing up
	// on a shape it was never written against.
	it( 'joins restRoot with a namespace override rather than the default reservant/v1', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( [] ) );

		await apiFetch( '/users?search=jane', {}, 'wp/v2' );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
		expect( url ).toBe( '/wp-json/wp/v2/users?search=jane' );
	} );

	it( 'tolerates a WP-core error body (no reservant envelope fields) from a namespace-override call, without throwing an unhandled error', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue(
			jsonResponse( { code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: { status: 403 } }, 403 )
		);

		let caught: unknown;
		try {
			await apiFetch( '/users?search=jane', {}, 'wp/v2' );
		} catch ( error ) {
			caught = error;
		}

		expect( caught ).toBeInstanceOf( ApiError );
		const error = caught as ApiError;
		expect( error.code ).toBe( 'rest_forbidden' );
		expect( error.status ).toBe( 403 );
		// No reservant-shaped `data.detail` on a core error - falls back to `message`, never throws
		// or leaves `detail` undefined.
		expect( error.detail ).toBe( 'Sorry, you are not allowed to do that.' );
		expect( error.segment ).toBeUndefined();
	} );

	it( 'tolerates a WP-core error body with no "data" object at all, falling back to the response status', async () => {
		( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( { code: 'rest_no_route', message: 'No route was found.' }, 404 ) );

		let caught: unknown;
		try {
			await apiFetch( '/users/999999', {}, 'wp/v2' );
		} catch ( error ) {
			caught = error;
		}

		expect( caught ).toBeInstanceOf( ApiError );
		const error = caught as ApiError;
		expect( error.code ).toBe( 'rest_no_route' );
		// No `data` at all -> falls back to the HTTP status actually observed, not a crash.
		expect( error.status ).toBe( 404 );
		expect( error.detail ).toBe( 'No route was found.' );
	} );
} );
