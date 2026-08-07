import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { AvailabilityExceptionListItem, Resource } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { StaffScreen } from '../StaffScreen';

/**
 * Task 16b gap-filler: the blackouts panel used to be session-only (only what THIS screen had
 * added/removed, forgotten on reload - see the Task 16 report's "known limitation"). It now reads
 * through `useExceptions()` (`GET /admin/exceptions`), so a row already on the server must render
 * on first load with no local action taken. Same mocking shape as `bookingactions.test.tsx`/
 * `settingsScreen.test.tsx`: only `apiFetch` is stubbed, every hook in `api/queries.ts` runs for real.
 */
jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

/**
 * `ToastProvider`'s `SnackbarList` (`@wordpress/components`) animates with framer-motion, which
 * schedules a `requestAnimationFrame` callback that calls `window.scrollTo()` while measuring
 * layout - a method jsdom does not implement, so it logs a `console.error` that would otherwise
 * fail these tests under `@wordpress/jest-console`. This is a jsdom/framer-motion environment gap
 * triggered by any toast (both new describe blocks below add/remove exceptions, which toast on
 * success), not a behavior these tests are exercising, so it is stubbed out module-wide.
 */
window.scrollTo = jest.fn();

function renderWithClient( ui: ReactElement ) {
	const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
	return render(
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>{ ui }</ToastProvider>
		</QueryClientProvider>
	);
}

function exceptionFixture( overrides: Partial< AvailabilityExceptionListItem > = {} ): AvailabilityExceptionListItem {
	return {
		id: 1,
		resource_id: null,
		date: '2026-08-10',
		start_time: null,
		end_time: null,
		reason: '',
		...overrides,
	};
}

function resourceFixture( overrides: Partial< Resource > = {} ): Resource {
	return {
		id: 42,
		wp_user_id: null,
		name: 'Alex',
		email: null,
		status: 'active',
		created_at: '2026-01-01 00:00:00',
		service_ids: [],
		rules: [],
		...overrides,
	};
}

/** Reads a mocked `apiFetch(path, init)` call's JSON body back into an object, for asserting exactly what a mutation sent. */
function parsedBody( init: RequestInit | undefined ): Record< string, unknown > {
	return JSON.parse( String( init?.body ?? '{}' ) ) as Record< string, unknown >;
}

describe( 'StaffScreen - business-wide blackouts panel (Task 16b)', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [] } );
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [] } );
			}
			if ( path.startsWith( '/admin/exceptions' ) ) {
				return Promise.resolve( {
					exceptions: [
						exceptionFixture( { id: 1, date: '2026-08-10' } ),
						exceptionFixture( { id: 2, date: '2026-08-12', start_time: '09:00', end_time: '10:00' } ),
					],
				} );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'renders rows from the GET /admin/exceptions response on load, with no local action taken', async () => {
		renderWithClient( <StaffScreen /> );

		expect( await screen.findByText( '2026-08-10' ) ).toBeInTheDocument();
		expect( screen.getByText( '2026-08-12 (09:00-10:00)' ) ).toBeInTheDocument();
		expect( mockedApiFetch ).toHaveBeenCalledWith( '/admin/exceptions' );
	} );

	it( 'shows the empty-state message rather than a row when the server has nothing on record', async () => {
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [] } );
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [] } );
			}
			if ( path.startsWith( '/admin/exceptions' ) ) {
				return Promise.resolve( { exceptions: [] } );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );

		renderWithClient( <StaffScreen /> );

		expect( await screen.findByText( 'No dates on record.' ) ).toBeInTheDocument();
	} );
} );

/**
 * Review round 1 finding: the suite above never selects a resource (its `resources` fixture is
 * always `[]`), so `selected` is always null and the per-resource panel -
 * `resourceExceptionsQuery = useExceptions( selectedId ?? undefined )` - is never rendered. That
 * left two things unproven: (a) that a real selected id actually reaches the request as
 * `?resource_id=`, rather than the business-wide and per-resource lists silently sharing rows, and
 * (b) that `invalidateExceptionCaches()` (`api/queries.ts`) really does cause the affected list to
 * refetch and re-render after add/remove, for BOTH the business-wide and resource-scoped paths -
 * the pre-16b behavior invalidated only `['resources']` for a resource-scoped change and nothing
 * at all for a business-wide one, which these tests would catch going back to.
 *
 * The mock below is stateful (a mutable array per scope, mutated by the POST/DELETE branches and
 * read back by the GET branch) specifically so a mutation's success can be observed to actually
 * change what the next fetch returns - reading the code is not enough to know the wiring works.
 */
describe( 'StaffScreen - resource-scoped exceptions panel and cache invalidation (review round 1 fix)', () => {
	let businessExceptions: AvailabilityExceptionListItem[];
	let resourceExceptions: AvailabilityExceptionListItem[];

	beforeEach( () => {
		businessExceptions = [ exceptionFixture( { id: 1, resource_id: null, date: '2026-08-10' } ) ];
		resourceExceptions = [ exceptionFixture( { id: 2, resource_id: 42, date: '2026-08-20' } ) ];

		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path, init ) => {
			const method = init?.method ?? 'GET';

			// Resource-scoped mutations - checked before the generic `/admin/resources` branch below,
			// since `/admin/resources/42/exceptions` also starts with `/admin/resources`.
			if ( path.startsWith( '/admin/resources/42/exceptions' ) ) {
				if ( 'POST' === method ) {
					const body = parsedBody( init );
					const created = exceptionFixture( {
						id: 200 + resourceExceptions.length,
						resource_id: 42,
						date: String( body.date ),
						start_time: ( body.start_time as string | undefined ) ?? null,
						end_time: ( body.end_time as string | undefined ) ?? null,
					} );
					resourceExceptions = [ ...resourceExceptions, created ];
					return Promise.resolve( created );
				}
				if ( 'DELETE' === method ) {
					const body = parsedBody( init );
					resourceExceptions = resourceExceptions.filter( ( exception ) => exception.date !== body.date );
					return Promise.resolve( { deleted: 1 } );
				}
			}

			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [ resourceFixture() ] } );
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [] } );
			}

			if ( path.startsWith( '/admin/exceptions' ) ) {
				if ( 'POST' === method ) {
					const body = parsedBody( init );
					const created = exceptionFixture( {
						id: 100 + businessExceptions.length,
						resource_id: null,
						date: String( body.date ),
						start_time: ( body.start_time as string | undefined ) ?? null,
						end_time: ( body.end_time as string | undefined ) ?? null,
					} );
					businessExceptions = [ ...businessExceptions, created ];
					return Promise.resolve( created );
				}
				if ( 'DELETE' === method ) {
					const body = parsedBody( init );
					businessExceptions = businessExceptions.filter( ( exception ) => exception.date !== body.date );
					return Promise.resolve( { deleted: 1 } );
				}
				// GET, with or without `?resource_id=42` - the distinction under test.
				return Promise.resolve( {
					exceptions: path.includes( 'resource_id=42' ) ? resourceExceptions : businessExceptions,
				} );
			}

			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	/** Selects the one fixture resource by clicking its row, the same gesture `StaffTable` wires to `onSelect`. */
	async function selectTheOnlyResource(): Promise< void > {
		fireEvent.click( await screen.findByText( 'Alex' ) );
	}

	it( 'loads the per-resource panel from the resource_id-scoped request, and keeps it disjoint from the business-wide panel', async () => {
		const { container } = renderWithClient( <StaffScreen /> );

		await selectTheOnlyResource();

		const editorSection = container.querySelector( '.reservant-staff-screen__editor' ) as HTMLElement;
		const businessSection = container.querySelector( '.reservant-staff-screen__business-blackouts' ) as HTMLElement;

		// The resource-scoped row shows up under the resource panel, and the business-wide row does
		// NOT leak into it - this is what a hardcoded "resource_id always omitted" regression breaks:
		// both panels would fetch the same business-wide list and show the same rows.
		expect( await within( editorSection ).findByText( '2026-08-20' ) ).toBeInTheDocument();
		expect( within( editorSection ).queryByText( '2026-08-10' ) ).not.toBeInTheDocument();

		// And the reverse: the business-wide row stays in the business panel, the resource-scoped row
		// does not leak into it.
		expect( await within( businessSection ).findByText( '2026-08-10' ) ).toBeInTheDocument();
		expect( within( businessSection ).queryByText( '2026-08-20' ) ).not.toBeInTheDocument();

		expect( mockedApiFetch ).toHaveBeenCalledWith( '/admin/exceptions' );
		expect( mockedApiFetch ).toHaveBeenCalledWith( '/admin/exceptions?resource_id=42' );
	} );

	it( 'adding then removing a business-wide blackout re-renders the business list from the server, driven by invalidateExceptionCaches()', async () => {
		renderWithClient( <StaffScreen /> );

		await screen.findByText( '2026-08-10' );

		fireEvent.change( screen.getByLabelText( 'Date' ), { target: { value: '2026-09-01' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Add date' } ) );

		expect( await screen.findByText( '2026-09-01' ) ).toBeInTheDocument();
		expect( screen.getByText( '2026-08-10' ) ).toBeInTheDocument();
		expect( mockedApiFetch ).toHaveBeenCalledWith(
			'/admin/exceptions',
			expect.objectContaining( { method: 'POST', body: JSON.stringify( { date: '2026-09-01' } ) } )
		);

		const oldRow = screen.getByText( '2026-08-10' ).closest( 'li' ) as HTMLElement;
		fireEvent.click( within( oldRow ).getByRole( 'button', { name: 'Delete' } ) );

		await waitFor( () => expect( screen.queryByText( '2026-08-10' ) ).not.toBeInTheDocument() );
		expect( screen.getByText( '2026-09-01' ) ).toBeInTheDocument();
		expect( mockedApiFetch ).toHaveBeenCalledWith(
			'/admin/exceptions',
			expect.objectContaining( { method: 'DELETE', body: JSON.stringify( { date: '2026-08-10' } ) } )
		);
	} );

	it( 'adding then removing a resource-scoped blackout re-renders only that resource\'s list, leaving the business-wide list untouched', async () => {
		const { container } = renderWithClient( <StaffScreen /> );

		await selectTheOnlyResource();

		const editorSection = container.querySelector( '.reservant-staff-screen__editor' ) as HTMLElement;
		const businessSection = container.querySelector( '.reservant-staff-screen__business-blackouts' ) as HTMLElement;

		expect( await within( editorSection ).findByText( '2026-08-20' ) ).toBeInTheDocument();

		fireEvent.change( within( editorSection ).getByLabelText( 'Date' ), { target: { value: '2026-09-05' } } );
		fireEvent.click( within( editorSection ).getByRole( 'button', { name: 'Add date' } ) );

		expect( await within( editorSection ).findByText( '2026-09-05' ) ).toBeInTheDocument();
		expect( within( editorSection ).getByText( '2026-08-20' ) ).toBeInTheDocument();
		expect( mockedApiFetch ).toHaveBeenCalledWith(
			'/admin/resources/42/exceptions',
			expect.objectContaining( { method: 'POST', body: JSON.stringify( { date: '2026-09-05' } ) } )
		);
		// The addition is resource-scoped only - the business-wide panel never gains the new row, or
		// loses the one it already had.
		expect( within( businessSection ).queryByText( '2026-09-05' ) ).not.toBeInTheDocument();
		expect( await within( businessSection ).findByText( '2026-08-10' ) ).toBeInTheDocument();

		const oldRow = within( editorSection ).getByText( '2026-08-20' ).closest( 'li' ) as HTMLElement;
		fireEvent.click( within( oldRow ).getByRole( 'button', { name: 'Delete' } ) );

		await waitFor( () => expect( within( editorSection ).queryByText( '2026-08-20' ) ).not.toBeInTheDocument() );
		expect( within( editorSection ).getByText( '2026-09-05' ) ).toBeInTheDocument();
		expect( mockedApiFetch ).toHaveBeenCalledWith(
			'/admin/resources/42/exceptions',
			expect.objectContaining( { method: 'DELETE', body: JSON.stringify( { date: '2026-08-20' } ) } )
		);
	} );
} );
