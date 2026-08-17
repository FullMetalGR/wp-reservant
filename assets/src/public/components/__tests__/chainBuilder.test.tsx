/**
 * Task 12 pins (P5 plan): an event service may start a chain, but a chain containing an event
 * must not grow past one segment - the widget refuses with the exact sentence "Events are booked
 * one at a time" at the COMMIT POINT (`handlePick` judges the candidate chain the pick would
 * produce), so both directions are caught even when the chain changed under an open picker. The
 * server refuses both directions itself, through two different doors: with the event FIRST,
 * `AvailabilityController::availability()` reads only `$items[0]` and 400s the multi-item chain
 * ("Events are booked one at a time - send a single item."); with the event LATER, the request
 * falls through to `AvailabilityQuery::appointmentStarts()`, which throws
 * `SlotConflict('not_found', $index)` for the non-appointment segment, and `Rest\Errors` maps
 * that reason to a 404 `reservant_not_found` ("That booking is no longer available."). The
 * client-side refusal spares the visitor either doomed round trip; the server stays the authority.
 *
 * The add button disappears at `max`, and `max` DEFAULTS to the server's own cap of 5
 * (`AvailabilityController::MAX_SEGMENTS` / `HoldsController::MAX_SEGMENTS`), pinned below.
 *
 * `toChainItems()` is pinned here too: ONE mapping serves both the availability read and the
 * hold body, and it always writes `resource_id` - explicit null, never an absent key - because
 * `JSON.stringify` (the wire `items` parameter and TanStack's query-key hash alike) keeps null
 * and drops undefined, so mixing the two forms would split one server answer across two cache
 * entries and two request URLs.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState, type ReactElement } from 'react';
import { ChainBuilder, MAX_CHAIN_SEGMENTS, toChainItems, type Segment } from '../ChainBuilder';
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

function newQueryClient(): QueryClient {
	return new QueryClient( {
		defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
	} );
}

function withClient( ui: ReactElement, client: QueryClient = newQueryClient() ): ReactElement {
	return <QueryClientProvider client={ client }>{ ui }</QueryClientProvider>;
}

function renderChain(
	segments: Segment[],
	onChange: ( next: Segment[] ) => void = jest.fn(),
	max = 3
): ReturnType< typeof render > {
	return render( withClient( <ChainBuilder segments={ segments } onChange={ onChange } max={ max } /> ) );
}

/**
 * The component is fully controlled, so behaviours that depend on the chain actually changing
 * (focus after a removal, the refusal retiring with the segment that caused it) need a live
 * parent, not a `jest.fn()` that swallows the change.
 */
function ControlledChain( { initial }: { initial: Segment[] } ): JSX.Element {
	const [ segments, setSegments ] = useState( initial );
	return <ChainBuilder segments={ segments } onChange={ setSegments } max={ 3 } />;
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
		// The notice describes the control that triggered it.
		const add = screen.getByRole( 'button', { name: 'Add another service' } );
		const noticeId = add.getAttribute( 'aria-describedby' );
		expect( noticeId ).toBeTruthy();
		expect( document.getElementById( noticeId as string ) ).toHaveTextContent( REFUSAL );
	} );

	it( 'refuses an event as the second segment and closes the picker', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		renderChain( [ { serviceId: 3, resourceId: 7 } ], onChange );
		await screen.findByText( 'Haircut' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );

		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
		// The refusal closes the chooser - an open picker where every choice is refused, still
		// claiming aria-expanded="true", would be a dead end.
		expect( screen.queryByRole( 'button', { name: /^Wine seminar/ } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Add another service' } ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
	} );

	it( 'offers no way to grow the chain while the catalog is in flight', async () => {
		// With the catalog unresolved nothing can be classified, so an add flow here could commit
		// an event/appointment mix blind. The default block configuration reaches this state:
		// block.json presets serviceId, so a non-empty chain at mount is the NORMAL mount.
		let resolveCatalog!: ( value: Response ) => void;
		( global.fetch as jest.Mock ).mockReturnValue(
			new Promise< Response >( ( resolve ) => {
				resolveCatalog = resolve;
			} )
		);
		const onChange = jest.fn();
		renderChain( [ { serviceId: 9, resourceId: null } ], onChange );

		expect( screen.getByText( 'Loading services...' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Add another service' } ) ).not.toBeInTheDocument();

		await act( async () => {
			resolveCatalog( jsonResponse( SERVICES ) );
		} );
		await screen.findByText( 'Wine seminar' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add another service' } ) );

		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'judges the chain at the commit point when it changed under an open picker', async () => {
		mockServices( SERVICES );
		const onChange = jest.fn();
		const client = newQueryClient();
		const view = render(
			withClient( <ChainBuilder segments={ [] } onChange={ onChange } max={ 3 } />, client )
		);
		fireEvent.click( await screen.findByRole( 'button', { name: 'Add another service' } ) );
		await screen.findByRole( 'button', { name: /^Beard trim/ } );

		// The parent replaces the chain while the picker is open - the preselected-service mount
		// (Task 14 reading block.json's serviceId) makes this ordering real, not exotic.
		view.rerender(
			withClient(
				<ChainBuilder segments={ [ { serviceId: 9, resourceId: null } ] } onChange={ onChange } max={ 3 } />,
				client
			)
		);

		fireEvent.click( screen.getByRole( 'button', { name: /^Beard trim/ } ) );

		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'button', { name: /^Beard trim/ } ) ).not.toBeInTheDocument();
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

		fireEvent.click( await screen.findByRole( 'button', { name: 'Add another service' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );

		expect( onChange ).toHaveBeenCalledWith( [ { serviceId: 9, resourceId: null } ] );
		expect( screen.queryByText( REFUSAL ) ).not.toBeInTheDocument();
	} );

	it( 'clears the refusal when the offending segment is removed', async () => {
		mockServices( SERVICES );
		render( withClient( <ControlledChain initial={ [ { serviceId: 9, resourceId: null } ] } /> ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Add another service' } ) );
		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Remove Wine seminar' } ) );

		expect( screen.queryByText( REFUSAL ) ).not.toBeInTheDocument();
	} );

	it( 'drops the refusal when the parent replaces the chain', async () => {
		mockServices( SERVICES );
		const client = newQueryClient();
		const view = render(
			withClient(
				<ChainBuilder segments={ [ { serviceId: 9, resourceId: null } ] } onChange={ jest.fn() } max={ 3 } />,
				client
			)
		);
		fireEvent.click( await screen.findByRole( 'button', { name: 'Add another service' } ) );
		expect( screen.getByText( REFUSAL ) ).toBeInTheDocument();

		view.rerender(
			withClient( <ChainBuilder segments={ [] } onChange={ jest.fn() } max={ 3 } />, client )
		);

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

	it( 'defaults max to the server cap of five segments', async () => {
		// The cap is the engine's own: AvailabilityController::MAX_SEGMENTS and
		// HoldsController::MAX_SEGMENTS are both 5, so a Task 14 caller passing nothing gets the
		// widest chain the server will actually accept instead of one that 400s twice.
		expect( MAX_CHAIN_SEGMENTS ).toBe( 5 );

		mockServices( SERVICES );
		const four: Segment[] = [
			{ serviceId: 3, resourceId: null },
			{ serviceId: 4, resourceId: null },
			{ serviceId: 3, resourceId: null },
			{ serviceId: 4, resourceId: null },
		];

		const underCap = render( withClient( <ChainBuilder segments={ four } onChange={ jest.fn() } /> ) );
		await screen.findAllByText( 'Haircut' );
		expect( screen.getByRole( 'button', { name: 'Add another service' } ) ).toBeInTheDocument();
		underCap.unmount();

		render(
			withClient(
				<ChainBuilder
					segments={ [ ...four, { serviceId: 3, resourceId: null } ] }
					onChange={ jest.fn() }
				/>
			)
		);
		await screen.findAllByText( 'Haircut' );
		expect( screen.queryByRole( 'button', { name: 'Add another service' } ) ).not.toBeInTheDocument();
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

	it( 'announces a removal and re-seats focus on the surviving remove control', async () => {
		// `key` is the index (a chain may legally repeat a service, so no stable key exists);
		// removing a segment therefore unmounts the last remove button and, in a browser, dumps
		// focus on <body> with nothing announced. Focus must be re-seated deliberately.
		mockServices( SERVICES );
		render(
			withClient(
				<ControlledChain
					initial={ [
						{ serviceId: 3, resourceId: 7 },
						{ serviceId: 4, resourceId: null },
					] }
				/>
			)
		);

		fireEvent.click( await screen.findByRole( 'button', { name: 'Remove Haircut' } ) );

		expect( await screen.findByRole( 'button', { name: 'Remove Beard trim' } ) ).toHaveFocus();
		expect( screen.getByText( 'Haircut removed.' ) ).toHaveAttribute( 'role', 'status' );
	} );

	it( 'wires the add button and the picker together as one disclosure', async () => {
		mockServices( SERVICES );
		renderChain( [] );
		const add = await screen.findByRole( 'button', { name: 'Add another service' } );
		expect( add ).toHaveAttribute( 'aria-expanded', 'false' );

		fireEvent.click( add );

		// While open the same control closes, and says so - a button announcing "expanded" must
		// not keep a label promising to add.
		const close = screen.getByRole( 'button', { name: 'Close the service list' } );
		expect( close ).toHaveAttribute( 'aria-expanded', 'true' );
		const pickerId = close.getAttribute( 'aria-controls' );
		expect( pickerId ).toBeTruthy();
		expect( document.getElementById( pickerId as string ) ).toContainElement(
			screen.getByRole( 'button', { name: /^Haircut/ } )
		);

		fireEvent.click( close );
		expect( screen.getByRole( 'button', { name: 'Add another service' } ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
	} );

	it( 'names a segment missing from the active catalog with a placeholder', async () => {
		// GET /services projects only status='active' rows (ServicesController::index()), so a
		// service deactivated after page load never resolves again - the row must stay legible
		// and removable rather than rendering '' and a bare "Remove ".
		mockServices( SERVICES );
		renderChain( [ { serviceId: 999, resourceId: null } ] );

		expect( await screen.findByText( 'Unavailable service' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Remove Unavailable service' } )
		).toBeInTheDocument();
	} );

	it( 'reports a catalog failure instead of rendering a nameless chain', async () => {
		( global.fetch as jest.Mock ).mockRejectedValue( new Error( 'Network down' ) );
		renderChain( [ { serviceId: 3, resourceId: 7 } ] );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'Network down' );
		expect( screen.queryByRole( 'button', { name: 'Add another service' } ) ).not.toBeInTheDocument();
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
