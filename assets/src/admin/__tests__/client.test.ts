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

	// The bug this guards against: `rest_url()` (`src/Admin/AdminPage.php`) returns a plain
	// directory URL under WordPress's PRETTY permalinks (`.../wp-json/`), but under PLAIN
	// permalinks - WP core's own DEFAULT for a fresh install - it instead returns
	// `.../index.php?rest_route=/`, a URL that already owns a `?` and treats the route itself as
	// that parameter's value. Naive `${restRoot}${namespace}${path}` concatenation of a
	// query-bearing path then opens a SECOND `?`, which PHP's query parser (splits only on `&`)
	// folds into the `rest_route` value up to the path's own first `&` - 404 `rest_no_route` -
	// while the remaining path args become bogus top-level params. Each case below asserts the
	// exact final URL `fetch` is called with, under both permalink modes, with and without a
	// query-bearing path.
	describe( 'URL construction under both WordPress permalink modes', () => {
		function setRestRoot( restRoot: string ): void {
			( window as { reservantAdmin?: { restRoot: string } } ).reservantAdmin!.restRoot = restRoot;
		}

		it( 'pretty permalinks, path with no query string: plain concatenation', async () => {
			setRestRoot( 'http://site.test/wp-json/' );
			( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( {} ) );

			await apiFetch( '/admin/availability' );

			const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
			expect( url ).toBe( 'http://site.test/wp-json/reservant/v1/admin/availability' );
		} );

		it( 'pretty permalinks, query-bearing path: the path\'s own "?" is left untouched', async () => {
			setRestRoot( 'http://site.test/wp-json/' );
			( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( {} ) );

			await apiFetch( '/admin/calendar?from=2026-08-01&to=2026-08-31' );

			const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
			expect( url ).toBe( 'http://site.test/wp-json/reservant/v1/admin/calendar?from=2026-08-01&to=2026-08-31' );
		} );

		it( 'plain permalinks, path with no query string: the route lands inside rest_route, no second "?"', async () => {
			setRestRoot( 'http://site.test/index.php?rest_route=/' );
			( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( {} ) );

			await apiFetch( '/admin/availability' );

			const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
			expect( url ).toBe( 'http://site.test/index.php?rest_route=/reservant/v1/admin/availability' );
		} );

		it( 'plain permalinks, query-bearing path: the path\'s own "?" is folded into "&" rather than opening a second "?"', async () => {
			setRestRoot( 'http://site.test/index.php?rest_route=/' );
			( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( {} ) );

			await apiFetch( '/admin/calendar?from=2026-08-01&to=2026-08-31' );

			const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
			// Exactly one "?" in the whole URL - `rest_route` carries the bare route, `from`/`to`
			// are ordinary sibling query params PHP's `&`-only parser reads correctly.
			expect( url ).toBe( 'http://site.test/index.php?rest_route=/reservant/v1/admin/calendar&from=2026-08-01&to=2026-08-31' );
			expect( url.match( /\?/g ) ).toHaveLength( 1 );
		} );

		it( 'plain permalinks with a namespace override (the wp/v2 lookup call): still exactly one "?"', async () => {
			setRestRoot( 'http://site.test/index.php?rest_route=/' );
			( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( [] ) );

			await apiFetch( '/users?search=jane', {}, 'wp/v2' );

			const [ url ] = ( global.fetch as jest.Mock ).mock.calls[ 0 ] as [ string, RequestInit ];
			expect( url ).toBe( 'http://site.test/index.php?rest_route=/wp/v2/users&search=jane' );
		} );
	} );
} );
