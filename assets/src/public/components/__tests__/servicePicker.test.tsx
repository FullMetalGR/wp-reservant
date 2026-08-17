/**
 * Task 12 pins (P5 plan): the catalog renders each service's name, duration and price; choosing
 * one reports its id; an event service is selectable like any other. The price pin runs against a
 * zero-decimal currency on purpose - `price_minor` is minor units, and JPY and EUR do not divide
 * by the same number, so a hardcoded `/ 100` would show a 5000-yen seminar as fifty yen.
 *
 * Price expectations are BUILT with `Intl.NumberFormat`, never written as glyph literals: the
 * component formats in the machine's own locale (the `undefined` locale is deliberate and pinned
 * by review), so the glyphs differ per machine - `el_GR` renders "45,00 EUR-sign" where `en_US`
 * renders "EUR-sign45.00" - and a literal would pass CI (C.UTF-8) while failing the same suite on
 * a Greek workstation. The tests hardcode the MAJOR amount (45 euros, 5000 yen) and delegate only
 * the glyph rendering, so the minor-unit scaling stays pinned: a `/ 100` mutation renders
 * `format(50)`, which matches `format(5000)` in no locale.
 *
 * Same fixture idiom as `../../api/__tests__/client.test.ts`: the real client runs against a
 * mocked `global.fetch` and the exact six-key bootstrap `Assets::config()` prints.
 */
import { act, fireEvent, render, screen, type RenderResult } from '@testing-library/react';
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

/** The component's own options, verbatim - only the amount below is this test's contribution. */
function currencyText( amount: number, currency: string ): string {
	return new Intl.NumberFormat( undefined, { style: 'currency', currency } ).format( amount );
}

/**
 * Exact-text matching for Intl output: several locales pad with no-break spaces, which Testing
 * Library's default normalizer collapses to plain spaces on the DOM side only - the expected
 * string would then never match. Comparing raw text against raw text sidesteps that; the price
 * sits alone in its own span, so raw equality is well-defined.
 */
const EXACT_TEXT = { normalizer: ( text: string ): string => text };

function newQueryClient(): QueryClient {
	return new QueryClient( {
		defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
	} );
}

function withClient( ui: ReactElement, client: QueryClient = newQueryClient() ): ReactElement {
	return <QueryClientProvider client={ client }>{ ui }</QueryClientProvider>;
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
		expect( screen.getByText( currencyText( 45, 'EUR' ), EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( 'Wine seminar' ) ).toBeInTheDocument();
		expect( screen.getByText( '120 min' ) ).toBeInTheDocument();
	} );

	it( 'scales the minor units by the currency, never by a hardcoded 100', async () => {
		mockServices( SERVICES );
		renderPicker( null );
		await screen.findByText( 'Wine seminar' );

		// JPY is zero-decimal: 5000 minor units ARE 5000 yen. This positive assertion alone
		// carries the pin - a hardcoded `/ 100` renders format(50), which cannot equal
		// format(5000) in any locale, so no separate negative assertion is needed (and the one
		// this replaces was never reached under the mutation anyway).
		expect( screen.getByText( currencyText( 5000, 'JPY' ), EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'falls back to a plain string when a row carries a currency Intl refuses', async () => {
		// Unreachable through the admin path (ServicesAdminController enforces /^[A-Z]{3}$/) but
		// reachable by direct SQL, and with no error boundary around the widget yet (Task 16) a
		// RangeError here would blank the whole thing. The fallback divides by ISO 4217's common
		// 2-decimal scale - without a formatter there are no resolved digits to derive.
		mockServices( [
			{
				id: 12,
				name: 'Mystery rite',
				description: '',
				type: 'appointment',
				duration_min: 30,
				price_minor: 1500,
				currency: '',
				requires_approval: false,
				resources: [],
			},
		] );
		renderPicker( null );

		expect( await screen.findByText( 'Mystery rite' ) ).toBeInTheDocument();
		expect( screen.getByText( /15\.00/ ) ).toBeInTheDocument();
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

	it( 'reports a first-load failure destructively - there is nothing better to show', async () => {
		( global.fetch as jest.Mock ).mockRejectedValue( new Error( 'Network down' ) );
		renderPicker( null );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'Network down' );
	} );

	it( 'keeps the cached catalog when a background refetch fails', async () => {
		// React Query keeps `data` intact on a background error (the "error" reducer spreads the
		// previous state), and `useServices()` refetches on every window focus - so a transient
		// blip mid-choice must degrade to a notice, never wipe the list the visitor is using.
		( global.fetch as jest.Mock )
			.mockResolvedValueOnce( jsonResponse( SERVICES ) )
			.mockRejectedValue( new Error( 'Network down' ) );
		const client = newQueryClient();
		render( withClient( <ServicePicker value={ null } onChange={ jest.fn() } />, client ) );
		await screen.findByText( 'Haircut' );

		await act( async () => {
			await client.refetchQueries( { queryKey: [ 'services' ] } );
		} );

		// findBy, not getBy: query-core notifies its observers on a scheduled tick that `act`
		// does not flush, so the errored state lands just after `refetchQueries` resolves. Once
		// the notice is up, the other two assertions are checked against the settled state.
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'The service list could not be refreshed and may be out of date.'
		);
		expect( screen.getByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );
