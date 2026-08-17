import { createElement, type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { ApiError } from '../../../shared';
import {
	cancelBooking,
	confirmBooking,
	createHold,
	fetchAvailability,
	fetchBooking,
	fetchServices,
	releaseHold,
	rescheduleBooking,
	widgetBootstrap,
} from '../client';
import {
	useAvailability,
	useBooking,
	useCancel,
	useConfirm,
	useCreateHold,
	useReleaseHold,
	useReschedule,
	useServices,
} from '../queries';
import type { AvailabilityResponse, Booking, ChainItem, HoldInput, PublicService, WidgetBootstrap } from '../types';

const UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const TOKEN = 'u_-secret';

function bootstrapFixture(): WidgetBootstrap {
	// The exact six keys `Assets::config()` emits, values included: `nonce` is ALWAYS the empty
	// string, for logged-in visitors too (its docblock owns the reasoning).
	return {
		restRoot: '/wp-json/',
		nonce: '',
		currency: 'EUR',
		timezone: 'Europe/Athens',
		granularityMin: 5,
		checkoutTtlMin: 15,
	};
}

function jsonResponse( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

function mockJson( body: unknown, status = 200 ): void {
	( global.fetch as jest.Mock ).mockResolvedValueOnce( jsonResponse( body, status ) );
}

function fetchCall( index = 0 ): [ string, RequestInit ] {
	return ( global.fetch as jest.Mock ).mock.calls[ index ] as [ string, RequestInit ];
}

/** Parses a call's URL against a throwaway origin, so relative `/wp-json/` URLs parse too. */
function fetchUrl( index = 0 ): URL {
	return new URL( fetchCall( index )[ 0 ], 'http://jest.test' );
}

function bookingFixture( overrides: Partial< Booking > = {} ): Booking {
	return {
		uuid: UUID,
		status: 'pending',
		hold_class: 'checkout',
		hold_expires_at: '2026-06-01 09:15:00',
		customer_name: 'Ada',
		customer_email: 'ada@example.com',
		customer_phone: '',
		total_minor: 4500,
		currency: 'EUR',
		payment_mode: 'onsite',
		requires_approval: false,
		created_at: '2026-06-01 09:00:00',
		updated_at: '2026-06-01 09:00:00',
		items: [
			{
				id: 11,
				sort: 0,
				service_id: 3,
				resource_id: 7,
				occurrence_id: null,
				start_utc: '2026-06-01 09:00:00',
				end_utc: '2026-06-01 09:45:00',
				block_start_utc: '2026-06-01 09:00:00',
				block_end_utc: '2026-06-01 09:45:00',
				processing_ends_utc: null,
				seats: 1,
				seat_claim: null,
				price_minor: 4500,
			},
		],
		...overrides,
	};
}

const HOLD_BODY: HoldInput = {
	customer: { name: 'Ada', email: 'ada@example.com' },
	appointment: {
		start_utc: '2026-06-01 09:00:00',
		segments: [ { service_id: 3, resource_id: 7 } ],
	},
};

/** The `Rest\Errors::conflict()` envelope, `detail` written by the engine for humans. */
const CONFLICT_ENVELOPE = {
	code: 'reservant_conflict',
	message: 'overlap',
	data: { status: 409, segment: 0, detail: 'That time was just taken. Please pick another.' },
};

const SERVICES_FIXTURE: PublicService[] = [
	{
		id: 3,
		name: 'Haircut',
		description: '',
		type: 'appointment',
		duration_min: 45,
		price_minor: 4500,
		currency: 'EUR',
		requires_approval: false,
		resources: [ { id: 7, name: 'Alex' } ],
	},
];

const AVAILABILITY_FIXTURE: AvailabilityResponse = {
	granularity_min: 5,
	starts: [ { utc: '2026-06-01 09:00:00', local: '2026-06-01T12:00:00+03:00' } ],
};

function hookClient(): QueryClient {
	return new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
}

function wrapperFor( queryClient: QueryClient ): ( props: { children: ReactNode } ) => JSX.Element {
	return function Wrapper( { children }: { children: ReactNode } ): JSX.Element {
		return createElement( QueryClientProvider, { client: queryClient }, children );
	};
}

beforeEach( () => {
	window.reservantWidget = bootstrapFixture();
	global.fetch = jest.fn();
} );

describe( 'widgetBootstrap', () => {
	it( 'refuses to run without the inline boot object', () => {
		delete window.reservantWidget;

		expect( () => widgetBootstrap() ).toThrow( 'reservantWidget missing' );
	} );
} );

describe( 'the client', () => {
	// THE one behaviour that must never regress: `Assets::config()` emits `nonce: ''` for EVERY
	// visitor, and core's `rest_cookie_check_errors()` treats a PRESENT-but-empty `X-WP-Nonce`
	// header as a nonce to verify (`rest-api.php` tests `isset()`, not truthiness) - so an empty
	// header 403s every route for every visitor, the fully public `GET /services` included.
	// The header must be ABSENT. Asserting `=== ''` here would pass against the fatal client.
	it( 'never sends an X-WP-Nonce header - the live bootstrap nonce is empty for everyone', async () => {
		mockJson( SERVICES_FIXTURE );
		await fetchServices();
		mockJson( { ...bookingFixture(), manage_token: TOKEN }, 201 );
		await createHold( HOLD_BODY );

		const calls = ( global.fetch as jest.Mock ).mock.calls as Array< [ string, RequestInit ] >;
		expect( calls ).toHaveLength( 2 );
		for ( const [ , init ] of calls ) {
			const headers = new Headers( init.headers );
			expect( headers.has( 'X-WP-Nonce' ) ).toBe( false );
		}
	} );

	// The guard is a falsy BRANCH, not header-never: were `Assets::config()` ever to carry a real
	// nonce again (a deliberate change - its docblock argues against one), silently dropping it
	// would strip authentication the server asked for. Both sides pinned so neither can rot into
	// the other.
	it( 'sends the header only for a non-empty nonce', async () => {
		window.reservantWidget = { ...bootstrapFixture(), nonce: 'live-nonce' };
		mockJson( SERVICES_FIXTURE );

		await fetchServices();

		const headers = new Headers( fetchCall()[ 1 ].headers );
		expect( headers.get( 'X-WP-Nonce' ) ).toBe( 'live-nonce' );
	} );

	// Pins the contract that the ENGINE, not the widget, writes the customer-facing sentence.
	it( 'reads the human sentence out of the core error envelope', async () => {
		mockJson( CONFLICT_ENVELOPE, 409 );

		await expect( createHold( HOLD_BODY ) ).rejects.toMatchObject( {
			status: 409,
			code: 'reservant_conflict',
			detail: 'That time was just taken. Please pick another.',
			segment: 0,
		} );
	} );

	it( 'the rejection is the shared ApiError, so errorMessage() reads it unchanged', async () => {
		mockJson( CONFLICT_ENVELOPE, 409 );

		await expect( createHold( HOLD_BODY ) ).rejects.toBeInstanceOf( ApiError );
	} );

	// The admin client shipped this bug and had to be fixed: under PLAIN permalinks `rest_url()`
	// already owns a `?`, so a query-bearing route must fold its own `?` into `&` or PHP reads
	// the whole thing as a 404 `rest_no_route`. Pinning it here proves the client goes through
	// `buildRequestUrl` rather than concatenating.
	it( 'folds a query-bearing route into the one query string under plain permalinks', async () => {
		window.reservantWidget = { ...bootstrapFixture(), restRoot: 'http://site.test/index.php?rest_route=/' };
		mockJson( AVAILABILITY_FIXTURE );

		await fetchAvailability( [ { service_id: 3 } ], '2026-06-01', '2026-06-08' );

		const [ url ] = fetchCall();
		expect( url.startsWith( 'http://site.test/index.php?rest_route=/reservant/v1/availability&' ) ).toBe( true );
		expect( url.match( /\?/g ) ).toHaveLength( 1 );
	} );

	it( 'sends the chain as the items JSON the endpoint decodes, with the Y-m-d window', async () => {
		mockJson( AVAILABILITY_FIXTURE );
		const items: ChainItem[] = [
			{ service_id: 3, resource_id: 7 },
			{ service_id: 4, resource_id: null },
		];

		const result = await fetchAvailability( items, '2026-06-01', '2026-06-08' );

		const url = fetchUrl();
		expect( url.pathname ).toBe( '/wp-json/reservant/v1/availability' );
		expect( url.searchParams.get( 'items' ) ).toBe( '[{"service_id":3,"resource_id":7},{"service_id":4,"resource_id":null}]' );
		expect( url.searchParams.get( 'from' ) ).toBe( '2026-06-01' );
		expect( url.searchParams.get( 'to' ) ).toBe( '2026-06-08' );
		expect( result ).toEqual( AVAILABILITY_FIXTURE );
	} );

	it( 'returns the held booking with its once-only manage token', async () => {
		mockJson( { ...bookingFixture(), manage_token: TOKEN }, 201 );

		const held = await createHold( HOLD_BODY );

		const [ url, init ] = fetchCall();
		expect( url ).toBe( '/wp-json/reservant/v1/holds' );
		expect( init.method ).toBe( 'POST' );
		expect( JSON.parse( init.body as string ) ).toEqual( HOLD_BODY );
		expect( held.manage_token ).toBe( TOKEN );
		expect( held.status ).toBe( 'pending' );
	} );

	it( 'carries the manage token in the query string for the GET and the DELETE', async () => {
		mockJson( bookingFixture() );
		await fetchBooking( UUID, TOKEN );
		mockJson( bookingFixture( { status: 'cancelled' } ) );
		await releaseHold( UUID, TOKEN );

		const shown = fetchUrl( 0 );
		expect( shown.pathname ).toBe( `/wp-json/reservant/v1/bookings/${ UUID }` );
		expect( shown.searchParams.get( 'token' ) ).toBe( TOKEN );
		expect( fetchCall( 0 )[ 1 ].method ).toBeUndefined();

		const released = fetchUrl( 1 );
		expect( released.pathname ).toBe( `/wp-json/reservant/v1/holds/${ UUID }` );
		expect( released.searchParams.get( 'token' ) ).toBe( TOKEN );
		expect( fetchCall( 1 )[ 1 ].method ).toBe( 'DELETE' );
	} );

	it( 'carries the manage token in the JSON body for confirm, cancel and reschedule', async () => {
		mockJson( bookingFixture( { status: 'confirmed' } ) );
		await confirmBooking( UUID, TOKEN );
		mockJson( bookingFixture( { status: 'cancelled' } ) );
		await cancelBooking( UUID, TOKEN );
		mockJson( bookingFixture() );
		await rescheduleBooking( UUID, TOKEN, { start_utc: '2026-06-02 10:00:00' } );
		mockJson( bookingFixture() );
		await rescheduleBooking( UUID, TOKEN, { occurrence_id: 12 } );

		const paths = [ 0, 1, 2, 3 ].map( ( index ) => fetchUrl( index ).pathname );
		expect( paths ).toEqual( [
			`/wp-json/reservant/v1/bookings/${ UUID }/confirm`,
			`/wp-json/reservant/v1/bookings/${ UUID }/cancel`,
			`/wp-json/reservant/v1/bookings/${ UUID }/reschedule`,
			`/wp-json/reservant/v1/bookings/${ UUID }/reschedule`,
		] );
		expect( JSON.parse( fetchCall( 0 )[ 1 ].body as string ) ).toEqual( { token: TOKEN } );
		expect( JSON.parse( fetchCall( 1 )[ 1 ].body as string ) ).toEqual( { token: TOKEN } );
		expect( JSON.parse( fetchCall( 2 )[ 1 ].body as string ) ).toEqual( { token: TOKEN, start_utc: '2026-06-02 10:00:00' } );
		expect( JSON.parse( fetchCall( 3 )[ 1 ].body as string ) ).toEqual( { token: TOKEN, occurrence_id: 12 } );
	} );
} );

describe( 'the query hooks', () => {
	it( 'caches the catalog under the plain services key', async () => {
		mockJson( SERVICES_FIXTURE );
		const queryClient = hookClient();

		const { result } = renderHook( () => useServices(), { wrapper: wrapperFor( queryClient ) } );

		await waitFor( () => expect( result.current.isSuccess ).toBe( true ) );
		expect( result.current.data ).toEqual( SERVICES_FIXTURE );
		expect( queryClient.getQueryData( [ 'services' ] ) ).toEqual( SERVICES_FIXTURE );
		expect( fetchUrl().pathname ).toBe( '/wp-json/reservant/v1/services' );
	} );

	it( 'keys availability on the chain and window, and stays idle until the chain names a service', async () => {
		mockJson( AVAILABILITY_FIXTURE );
		const queryClient = hookClient();
		const items: ChainItem[] = [ { service_id: 3, resource_id: null } ];

		const { result } = renderHook( () => useAvailability( items, '2026-06-01', '2026-06-08' ), {
			wrapper: wrapperFor( queryClient ),
		} );
		await waitFor( () => expect( result.current.isSuccess ).toBe( true ) );

		expect( queryClient.getQueryData( [ 'availability', items, '2026-06-01', '2026-06-08' ] ) ).toEqual(
			AVAILABILITY_FIXTURE
		);

		const idle = renderHook( () => useAvailability( [], '2026-06-01', '2026-06-08' ), {
			wrapper: wrapperFor( queryClient ),
		} );
		expect( idle.result.current.fetchStatus ).toBe( 'idle' );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reads the booking only once it holds both the uuid and the token', async () => {
		const queryClient = hookClient();

		const idle = renderHook( () => useBooking( UUID, '' ), { wrapper: wrapperFor( queryClient ) } );
		expect( idle.result.current.fetchStatus ).toBe( 'idle' );
		expect( global.fetch ).not.toHaveBeenCalled();

		mockJson( bookingFixture() );
		const { result } = renderHook( () => useBooking( UUID, TOKEN ), { wrapper: wrapperFor( queryClient ) } );
		await waitFor( () => expect( result.current.isSuccess ).toBe( true ) );
		expect( queryClient.getQueryData( [ 'booking', UUID ] ) ).toEqual( bookingFixture() );
	} );

	it( 'a 409 on hold refreshes availability - a vanished slot is normal, not exceptional', async () => {
		const queryClient = hookClient();
		const invalidate = jest.spyOn( queryClient, 'invalidateQueries' );
		const { result } = renderHook( () => useCreateHold(), { wrapper: wrapperFor( queryClient ) } );

		mockJson( CONFLICT_ENVELOPE, 409 );
		await act( async () => {
			await expect( result.current.mutateAsync( HOLD_BODY ) ).rejects.toBeInstanceOf( ApiError );
		} );
		expect( invalidate ).toHaveBeenCalledWith( { queryKey: [ 'availability' ] } );

		// Only a conflict means the offer was stale - a 429 or a validation 400 says nothing
		// about availability, and refetching on those would hammer a server that just said stop.
		invalidate.mockClear();
		mockJson( { code: 'reservant_rate_limited', message: 'rate_limited', data: { status: 429, detail: 'Wait.' } }, 429 );
		await act( async () => {
			await expect( result.current.mutateAsync( HOLD_BODY ) ).rejects.toBeInstanceOf( ApiError );
		} );
		expect( invalidate ).not.toHaveBeenCalled();
	} );

	it( 'release resolves with the released booking and touches no cache', async () => {
		const queryClient = hookClient();
		const invalidate = jest.spyOn( queryClient, 'invalidateQueries' );
		const { result } = renderHook( () => useReleaseHold(), { wrapper: wrapperFor( queryClient ) } );

		mockJson( bookingFixture( { status: 'cancelled' } ) );
		let released: Booking | undefined;
		await act( async () => {
			released = await result.current.mutateAsync( { uuid: UUID, token: TOKEN } );
		} );

		expect( released?.status ).toBe( 'cancelled' );
		expect( fetchCall()[ 1 ].method ).toBe( 'DELETE' );
		expect( invalidate ).not.toHaveBeenCalled();
	} );

	// The plan's invalidation contract: a successful confirm, cancel or reschedule invalidates
	// ['booking', uuid] AND ['availability'] - the move just changed what other visitors can book.
	async function expectsManageInvalidation(
		useHook: () => { mutateAsync: ( variables: never ) => Promise< Booking > },
		variables: Record< string, unknown >,
		response: Booking
	): Promise< void > {
		const queryClient = hookClient();
		const invalidate = jest.spyOn( queryClient, 'invalidateQueries' );
		const { result } = renderHook( useHook, { wrapper: wrapperFor( queryClient ) } );

		mockJson( response );
		await act( async () => {
			await result.current.mutateAsync( variables as never );
		} );

		expect( invalidate ).toHaveBeenCalledWith( { queryKey: [ 'booking', UUID ] } );
		expect( invalidate ).toHaveBeenCalledWith( { queryKey: [ 'availability' ] } );
	}

	it( 'a successful confirm invalidates the booking and availability', async () => {
		await expectsManageInvalidation( useConfirm, { uuid: UUID, token: TOKEN }, bookingFixture( { status: 'confirmed' } ) );
	} );

	it( 'a successful cancel invalidates the booking and availability', async () => {
		await expectsManageInvalidation( useCancel, { uuid: UUID, token: TOKEN }, bookingFixture( { status: 'cancelled' } ) );
	} );

	it( 'a successful reschedule invalidates the booking and availability', async () => {
		await expectsManageInvalidation(
			useReschedule,
			{ uuid: UUID, token: TOKEN, start_utc: '2026-06-02 10:00:00' },
			bookingFixture()
		);
	} );
} );
