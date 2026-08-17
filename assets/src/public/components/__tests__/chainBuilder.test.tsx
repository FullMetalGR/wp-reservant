/**
 * Task 12 pins (P5 plan): an event service may start a chain, but a chain containing an event
 * never grows past one segment - the widget refuses with the exact sentence "Events are booked
 * one at a time" BEFORE the server has to 400 it (`AvailabilityController::availability()` and
 * the hold protocol both reject an event inside a multi-item chain). The add button disappears
 * at `max`.
 *
 * `toChainItems()` is pinned here too: ONE mapping serves both the availability read and the
 * hold body, and it always writes `resource_id` - explicit null, never an absent key - because
 * `JSON.stringify` (the wire `items` parameter and TanStack's query-key hash alike) keeps null
 * and drops undefined, so mixing the two forms would split one server answer across two cache
 * entries and two request URLs.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { ChainBuilder, toChainItems, type Segment } from '../ChainBuilder';
import type { PublicService, WidgetBootstrap } from '../../api/types';

function bootstrapFixture(): WidgetBootstrap {
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

function mockServices( services: PublicService[] ): void {
	( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( services ) );
}

const SERVICES: PublicService[] = [
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
	{
		id: 4,
		name: 'Beard trim',
		description: '',
		type: 'appointment',
		duration_min: 15,
		price_minor: 1500,
		currency: 'EUR',
		requires_approval: false,
		resources: [ { id: 7, name: 'Alex' } ],
	},
	{
		id: 9,
		name: 'Wine seminar',
		description: '',
		type: 'event',
		duration_min: 120,
		price_minor: 5000,
		currency: 'JPY',
		requires_approval: true,
		resources: [],
	},
];

const REFUSAL = 'Events are booked one at a time';

function withClient( ui: ReactElement ): ReactElement {
	const queryClient = new QueryClient( {
		defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
	} );
	return <QueryClientProvider client={ queryClient }>{ ui }</QueryClientProvider>;
}

function renderChain(
	segments: Segment[],
	onChange: ( next: Segment[] ) => void = jest.fn(),
	max = 3
): ReturnType< typeof render > {
	return render( withClient( <ChainBuilder segments={ segments } onChange={ onChange } max={ max } /> ) );
}

beforeEach( () => {
	window.reservantWidget = bootstrapFixture();
	global.fetch = jest.fn();
} );

describe( 'ChainBuilder', () => {
	it( 'refuses a second segment when the chain already holds an event', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain( [ { serviceId: 9, resourceId: null } ], onChange );
		await screen.findByText( 'Wine seminar' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );

		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
		// The picker never opened - after an event there is nothing to choose.
		expect( screen.queryByText( 'Haircut' ) ).not.toBeInTheDocument();
	} );

	it( 'refuses an event as the second segment of an appointment chain', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain( [ { serviceId: 3, resourceId: 7 } ], onChange );
		await screen.findByText( 'Haircut' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );

		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'appends the picked service with no staff preference', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain( [ { serviceId: 3, resourceId: 7 } ], onChange );
		await screen.findByText( 'Haircut' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /^Beard trim/ } ) );

		expect( onChange ).toHaveBeenCalledWith( [
			{ serviceId: 3, resourceId: 7 },
			{ serviceId: 4, resourceId: null },
		] );
	} );

	it( 'accepts an event as the only segment', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain( [], onChange );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );

		expect( onChange ).toHaveBeenCalledWith( [ { serviceId: 9, resourceId: null } ] );
		expect( screen.queryByText( REFUSAL ) ).not.toBeInTheDocument();
	} );

	it( 'the add button disappears at max', async () => {
		mockServices( SERVICES );
		const two: Segment[] = [
			{ serviceId: 3, resourceId: null },
			{ serviceId: 4, resourceId: null },
		];

		const atMax = renderChain( two, jest.fn(), 2 );
		await screen.findByText( 'Beard trim' );
		expect( screen.queryByRole( 'button', { name: 'Add another service' } ) ).not.toBeInTheDocument();
		atMax.unmount();

		renderChain( two, jest.fn(), 3 );
		await screen.findByText( 'Beard trim' );
		expect( screen.getByRole( 'button', { name: 'Add another service' } ) ).toBeInTheDocument();
	} );

	it( 'removes a segment', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain(
			[
				{ serviceId: 3, resourceId: 7 },
				{ serviceId: 4, resourceId: null },
			],
			onChange
		);

		fireEvent.click( await screen.findByRole( 'button', { name: 'Remove Haircut' } ) );

		expect( onChange ).toHaveBeenCalledWith( [ { serviceId: 4, resourceId: null } ] );
	} );
} );

describe( 'toChainItems', () => {
	it( 'maps the UI segments to the wire ChainItem shape', () => {
		expect(
			toChainItems( [
				{ serviceId: 3, resourceId: 7 },
				{ serviceId: 4, resourceId: null },
			] )
		).toEqual( [
			{ service_id: 3, resource_id: 7 },
			{ service_id: 4, resource_id: null },
		] );
	} );

	it( 'always writes resource_id - explicit null, never an absent key', () => {
		// Pinned through JSON.stringify because that IS the consumer: the wire `items` parameter
		// (`fetchAvailability`) and TanStack's query-key hash both stringify, and both would tell
		// `{"service_id":3}` apart from this - one server answer, two cache entries.
		expect( JSON.stringify( toChainItems( [ { serviceId: 3, resourceId: null } ] ) ) ).toBe(
			'[{"service_id":3,"resource_id":null}]'
		);
	} );
} );
