/**
 * The per-query staleness policy over the shared QueryClient factory (`api/queryClient.ts`).
 * The three widget reads age at genuinely different speeds, so each query in `api/queries.ts`
 * pins its own number HERE, behaviourally - by counting requests across a remount on one shared
 * client, never by reading configuration back:
 *
 * - the services catalog changes only when the owner edits it, so a remount inside its five
 *   minutes must refetch nothing - and must refetch once they are up;
 * - availability goes stale the moment another visitor holds a slot, so every fresh subscriber
 *   refetches - these two pins together are what keeps a blanket factory-level `staleTime` out;
 * - a booking's status changes without the guest acting (the owner approves or rejects, a hold
 *   expires), so every fresh subscriber refetches there too.
 *
 * The remount pattern (unmount, mount again, on the SAME client) is the test-visible stand-in
 * for every staleness-gated trigger TanStack shares one rule across: refetch-on-mount,
 * refetch-on-focus and refetch-on-reconnect all fire only when the data has outlived its
 * `staleTime`. The five-minutes-later pin mounts a SECOND subscriber instead of remounting so
 * the query keeps an active subscriber throughout - an unmounted query would be garbage-collected
 * over those five minutes and refetch for that reason, proving nothing about staleness.
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import type { QueryClient } from '@tanstack/react-query';
import { newQueryClient } from '../api/queryClient';
import { useAvailability, useBooking, useServices } from '../api/queries';
import type { WidgetBootstrap } from '../api/types';

const UUID = '33333333-3333-4333-8333-333333333333';
const TOKEN = 'policy-secret';

function bootstrapFixture(): WidgetBootstrap {
	return {
		restRoot: '/wp-json/',
		nonce: '',
		currency: 'EUR',
		timezone: 'Pacific/Kiritimati',
		granularityMin: 5,
		checkoutTtlMin: 15,
	};
}

function jsonResponse( body: unknown ): Response {
	return {
		ok: true,
		status: 200,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

/** Routes the three reads this suite exercises; everything else is an error, loudly. */
function installFetch(): jest.Mock {
	const fetchMock = jest.fn( async ( input: unknown ): Promise< Response > => {
		const url = String( input );
		if ( url.includes( '/services' ) ) {
			return jsonResponse( [] );
		}
		if ( url.includes( '/availability' ) ) {
			return jsonResponse( { granularity_min: 5, starts: [] } );
		}
		if ( url.includes( '/bookings/' ) ) {
			return jsonResponse( { uuid: UUID, status: 'confirmed', items: [] } );
		}
		throw new Error( `unrouted GET ${ url }` );
	} );
	global.fetch = fetchMock as unknown as typeof fetch;
	return fetchMock;
}

function callCount( fetchMock: jest.Mock, marker: string ): number {
	return fetchMock.mock.calls.filter( ( [ input ] ) => String( input ).includes( marker ) ).length;
}

function ServicesProbe(): JSX.Element {
	const { data } = useServices();
	return <p>{ undefined === data ? 'loading' : 'services ready' }</p>;
}

function AvailabilityProbe(): JSX.Element {
	const { data } = useAvailability(
		[ { service_id: 3, resource_id: null } ],
		'2026-06-01',
		'2026-06-15'
	);
	return <p>{ undefined === data ? 'loading' : 'availability ready' }</p>;
}

function BookingProbe(): JSX.Element {
	const { data } = useBooking( UUID, TOKEN );
	return <p>{ undefined === data ? 'loading' : 'booking ready' }</p>;
}

function mountProbe( client: QueryClient, probe: JSX.Element ): ReturnType< typeof render > {
	return render( <QueryClientProvider client={ client }>{ probe }</QueryClientProvider> );
}

beforeEach( () => {
	jest.useFakeTimers( { now: new Date( '2026-06-01T00:00:00Z' ) } );
	window.reservantWidget = bootstrapFixture();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'the widget query staleness policy', () => {
	it( 'keeps the services catalog across a remount inside its five minutes', async () => {
		const fetchMock = installFetch();
		const client = newQueryClient();

		const first = mountProbe( client, <ServicesProbe /> );
		await screen.findByText( 'services ready' );
		first.unmount();

		mountProbe( client, <ServicesProbe /> );
		await screen.findByText( 'services ready' );
		await act( async () => {} );

		expect( callCount( fetchMock, '/services' ) ).toBe( 1 );
	} );

	it( 'refetches the services catalog once its five minutes are up', async () => {
		const fetchMock = installFetch();
		const client = newQueryClient();

		mountProbe( client, <ServicesProbe /> );
		await screen.findByText( 'services ready' );

		// The first probe stays mounted across the wait - see the header on why the query must
		// keep a subscriber. One millisecond past five minutes, a fresh subscriber refetches.
		act( () => {
			jest.advanceTimersByTime( 5 * 60 * 1000 + 1 );
		} );
		mountProbe( client, <ServicesProbe /> );

		await waitFor( () => expect( callCount( fetchMock, '/services' ) ).toBe( 2 ) );
	} );

	it( 'refetches availability for every fresh subscriber', async () => {
		const fetchMock = installFetch();
		const client = newQueryClient();

		const first = mountProbe( client, <AvailabilityProbe /> );
		await screen.findByText( 'availability ready' );
		first.unmount();

		mountProbe( client, <AvailabilityProbe /> );
		await waitFor( () => expect( callCount( fetchMock, '/availability' ) ).toBe( 2 ) );
	} );

	it( 'refetches the booking for every fresh subscriber', async () => {
		const fetchMock = installFetch();
		const client = newQueryClient();

		const first = mountProbe( client, <BookingProbe /> );
		await screen.findByText( 'booking ready' );
		first.unmount();

		mountProbe( client, <BookingProbe /> );
		await waitFor( () => expect( callCount( fetchMock, '/bookings/' ) ).toBe( 2 ) );
	} );
} );
