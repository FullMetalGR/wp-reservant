/**
 * Task 12 pins (P5 plan): the catalog renders each service's name, duration and price; choosing
 * one reports its id; an event service is selectable like any other. The price pin runs against a
 * zero-decimal currency on purpose - `price_minor` is minor units, and JPY and EUR do not divide
 * by the same number, so a hardcoded `/ 100` would show a 5000-yen seminar as "50".
 *
 * Same fixture idiom as `../../api/__tests__/client.test.ts`: the real client runs against a
 * mocked `global.fetch` and the exact six-key bootstrap `Assets::config()` prints.
 */
import { fireEvent, render, screen, type RenderResult } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { ServicePicker } from '../ServicePicker';
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

/** Persistent, not `Once`: one component tree may fetch the catalog once per fresh QueryClient. */
function mockServices( services: PublicService[] ): void {
	( global.fetch as jest.Mock ).mockResolvedValue( jsonResponse( services ) );
}

const SERVICES: PublicService[] = [
	{
		id: 3,
		name: 'Haircut',
		description: 'A classic cut.',
		type: 'appointment',
		duration_min: 45,
		price_minor: 4500,
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

function withClient( ui: ReactElement ): ReactElement {
	const queryClient = new QueryClient( {
		defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
	} );
	return <QueryClientProvider client={ queryClient }>{ ui }</QueryClientProvider>;
}

function renderPicker( value: number | null, onChange: ( id: number ) => void = jest.fn() ): RenderResult {
	return render( withClient( <ServicePicker value={ value } onChange={ onChange } /> ) );
}

beforeEach( () => {
	window.reservantWidget = bootstrapFixture();
	global.fetch = jest.fn();
} );

describe( 'ServicePicker', () => {
	it( 'renders each service with its name, duration and price', async () => {
		mockServices( SERVICES );
		renderPicker( null );

		expect( await screen.findByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.getByText( '45 min' ) ).toBeInTheDocument();
		expect( screen.getByText( /45\.00/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Wine seminar' ) ).toBeInTheDocument();
		expect( screen.getByText( '120 min' ) ).toBeInTheDocument();
	} );

	it( 'scales the minor units by the currency, never by a hardcoded 100', async () => {
		mockServices( SERVICES );
		renderPicker( null );
		await screen.findByText( 'Wine seminar' );

		// JPY is zero-decimal: 5000 minor units ARE 5000 yen.
		expect( screen.getByText( /5,000/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /50\.00/ ) ).not.toBeInTheDocument();
	} );

	it( 'is a plain list of real buttons, every element on the reservant- prefix', async () => {
		mockServices( SERVICES );
		const { container } = renderPicker( null );
		await screen.findByText( 'Haircut' );

		expect( container.querySelector( 'ul.reservant-service-picker' ) ).toBeInTheDocument();
		const buttons = screen.getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 2 );
		for ( const button of buttons ) {
			expect( button.className ).toContain( 'reservant-service-picker__choice' );
		}
	} );

	it( 'reports the chosen id through onChange', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderPicker( null, onChange );

		fireEvent.click( await screen.findByRole( 'button', { name: /^Haircut/ } ) );

		expect( onChange ).toHaveBeenCalledWith( 3 );
	} );

	it( 'an event service is selectable like any other', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderPicker( null, onChange );

		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );

		expect( onChange ).toHaveBeenCalledWith( 9 );
	} );

	it( 'marks the chosen service pressed', async () => {
		mockServices( SERVICES );
		renderPicker( 3 );

		expect( await screen.findByRole( 'button', { name: /^Haircut/ } ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		expect( screen.getByRole( 'button', { name: /^Wine seminar/ } ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
	} );
} );
