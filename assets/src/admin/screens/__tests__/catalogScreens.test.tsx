import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { Resource, Service } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { ApiError } from '../../../shared';
import { ServicesScreen } from '../ServicesScreen';
import { StaffScreen } from '../StaffScreen';

jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

window.scrollTo = jest.fn();

function setBootConfig(): void {
	( window as { reservantAdmin?: unknown } ).reservantAdmin = {
		restRoot: '/wp-json/',
		nonce: 'test-nonce',
		caps: [ 'reservant_manage_bookings', 'reservant_manage_settings' ],
		currency: 'EUR',
		timezone: 'Europe/Athens',
		granularityMin: 5,
	};
}

function renderWithClient( ui: ReactElement ) {
	const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
	return render(
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>{ ui }</ToastProvider>
		</QueryClientProvider>
	);
}

/** `ServicesAdminController::present()`'s shape - every field the `Service` type declares. */
function serviceFixture( overrides: Partial< Service > = {} ): Service {
	return {
		id: 1,
		name: 'Haircut',
		type: 'appointment',
		duration_min: 30,
		processing_time_min: 0,
		buffer_before_min: 0,
		buffer_after_min: 0,
		capacity: 1,
		seat_map_id: null,
		price_minor: 2500,
		currency: 'EUR',
		payment_mode: 'onsite',
		requires_approval: false,
		approval_hold_hours: 48,
		on_approval_timeout: 'expire',
		cancel_window_hours: 24,
		reschedule_window_hours: 24,
		lead_time_min: 0,
		horizon_days: 60,
		wc_product_id: null,
		status: 'active',
		created_at: '2026-01-01 00:00:00',
		updated_at: '2026-01-01 00:00:00',
		...overrides,
	};
}

/** `ResourcesAdminController::present()`'s shape - note NO `exceptions`, which the server no longer attaches. */
function resourceFixture( overrides: Partial< Resource > = {} ): Resource {
	return {
		id: 1,
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

/**
 * Final-review finding: `useServices()`/`useResources()` never sent `include_inactive`, and the
 * route defaults it to `false`, so both catalog screens could only ever see active rows - yet both
 * ship an "Inactive" status option, and `ServicesScreen` actively steers the admin into it on a 409
 * `referenced` delete ("Deactivate it instead."). Accepting that made the row vanish with no filter,
 * toggle or affordance to bring it back: a one-way door.
 */
describe( 'ServicesScreen - inactive services stay reachable', () => {
	beforeEach( () => {
		setBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( {
					services: [ serviceFixture( { id: 1, name: 'Haircut' } ), serviceFixture( { id: 2, name: 'Retired Shave', status: 'inactive' } ) ],
				} );
			}
			if ( path.startsWith( '/admin/seat-maps' ) ) {
				return Promise.resolve( { seat_maps: [] } );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'asks the server for the full catalog, inactive rows included', async () => {
		renderWithClient( <ServicesScreen /> );

		await screen.findByRole( 'button', { name: 'Haircut' } );
		const servicePaths = mockedApiFetch.mock.calls.map( ( [ path ] ) => path ).filter( ( path ) => path.startsWith( '/admin/services' ) );
		expect( servicePaths ).not.toHaveLength( 0 );
		for ( const path of servicePaths ) {
			expect( path ).toContain( 'include_inactive=1' );
		}
	} );

	it( 'hides inactive rows by default but reveals them through the toggle', async () => {
		renderWithClient( <ServicesScreen /> );

		await screen.findByRole( 'button', { name: 'Haircut' } );
		expect( screen.queryByRole( 'button', { name: 'Retired Shave' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( 'Show inactive services (1)' ) );

		expect( screen.getByRole( 'button', { name: 'Retired Shave' } ) ).toBeInTheDocument();
	} );

	it( 'lets an inactive service be selected and flipped back to Active', async () => {
		renderWithClient( <ServicesScreen /> );

		await screen.findByRole( 'button', { name: 'Haircut' } );
		fireEvent.click( screen.getByLabelText( 'Show inactive services (1)' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Retired Shave' } ) );

		// The Status select only renders for a SELECTED row - which is exactly why an unlistable row
		// could never be reactivated.
		const status = await screen.findByLabelText( 'Status' );
		expect( status ).toHaveValue( 'inactive' );

		fireEvent.change( status, { target: { value: 'active' } } );
		mockedApiFetch.mockImplementationOnce( () => Promise.resolve( serviceFixture( { id: 2, name: 'Retired Shave', status: 'active' } ) ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save service' } ) );

		await waitFor( () => {
			const put = mockedApiFetch.mock.calls.find( ( [ , init ] ) => 'PUT' === init?.method );
			expect( put ).toBeDefined();
			expect( JSON.parse( String( put?.[ 1 ]?.body ) ) ).toMatchObject( { status: 'active' } );
		} );
	} );

	it( 'keeps the row being edited visible after deactivating it, toggle off', async () => {
		renderWithClient( <ServicesScreen /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Haircut' } ) );
		const status = await screen.findByLabelText( 'Status' );
		fireEvent.change( status, { target: { value: 'inactive' } } );

		mockedApiFetch.mockImplementationOnce( () => Promise.resolve( serviceFixture( { id: 1, name: 'Haircut', status: 'inactive' } ) ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save service' } ) );

		// The list still answers `active` for id 1 (the refetch has its own fixture), but the row must
		// not disappear out from under the open form regardless.
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Haircut' } ) ).toBeInTheDocument() );
	} );
} );

/**
 * Final-review finding: every 400 the admin API answers carries `message = 'invalid_request'` and
 * the real, translated, per-field sentence in `data.detail` - and each screen's own private
 * `errorMessage()` showed the former. `errorMessage()` now lives next to `ApiError` and prefers
 * `detail`; this pins that the sentence actually reaches the admin's screen.
 */
describe( 'ServicesScreen - a 400 shows the translated sentence, not "invalid_request"', () => {
	beforeEach( () => {
		setBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path, init ) => {
			if ( 'PUT' === init?.method || 'POST' === init?.method ) {
				return Promise.reject(
					ApiError.fromResponse(
						{
							code: 'rest_invalid_param',
							message: 'invalid_request',
							data: { status: 400, detail: '"duration_min" must be a positive multiple of 5.' },
						},
						400
					)
				);
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [ serviceFixture() ] } );
			}
			if ( path.startsWith( '/admin/seat-maps' ) ) {
				return Promise.resolve( { seat_maps: [] } );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'toasts the per-field detail sentence', async () => {
		renderWithClient( <ServicesScreen /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Haircut' } ) );
		fireEvent.change( screen.getByLabelText( 'Duration (minutes)' ), { target: { value: '7' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save service' } ) );

		// Scoped to the snackbar itself: `@wordpress/a11y` also mirrors every notice into its own
		// `aria-live` region, so the same sentence legitimately appears twice in the document.
		const snackbar = await screen.findByTestId( 'snackbar' );
		expect( snackbar ).toHaveTextContent( 'Error: "duration_min" must be a positive multiple of 5.' );
		expect( snackbar ).not.toHaveTextContent( 'invalid_request' );
	} );
} );

describe( 'StaffScreen - inactive staff stay reachable', () => {
	beforeEach( () => {
		setBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( {
					resources: [ resourceFixture( { id: 1, name: 'Alex' } ), resourceFixture( { id: 2, name: 'Departed Dana', status: 'inactive' } ) ],
				} );
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [ serviceFixture( { id: 9, name: 'Retired Shave', status: 'inactive' } ) ] } );
			}
			if ( path.startsWith( '/admin/exceptions' ) ) {
				return Promise.resolve( { exceptions: [] } );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'asks the server for the full catalog, inactive rows included', async () => {
		renderWithClient( <StaffScreen /> );

		await screen.findByRole( 'button', { name: 'Alex' } );
		const resourcePaths = mockedApiFetch.mock.calls.map( ( [ path ] ) => path ).filter( ( path ) => path.startsWith( '/admin/resources' ) );
		expect( resourcePaths ).not.toHaveLength( 0 );
		for ( const path of resourcePaths ) {
			expect( path ).toContain( 'include_inactive=1' );
		}
	} );

	it( 'hides inactive staff by default but reveals and selects them through the toggle', async () => {
		renderWithClient( <StaffScreen /> );

		await screen.findByRole( 'button', { name: 'Alex' } );
		expect( screen.queryByRole( 'button', { name: 'Departed Dana' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( 'Show inactive staff (1)' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Departed Dana' } ) );

		const status = await screen.findByLabelText( 'Status' );
		expect( status ).toHaveValue( 'inactive' );
	} );

	it( 'marks an inactive service in the services checklist rather than hiding the link', async () => {
		renderWithClient( <StaffScreen /> );

		await screen.findByRole( 'button', { name: 'Alex' } );
		expect( screen.getByLabelText( 'Retired Shave (inactive)' ) ).toBeInTheDocument();
	} );
} );

/**
 * Final-review finding: `<tr onClick>` with no `tabIndex`, `role` or `onKeyDown` was the ONLY way to
 * open a record on four screens, so editing a service or a staff member was mouse-only.
 */
describe( 'catalog tables - keyboard-operable row selection', () => {
	beforeEach( () => {
		setBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [ serviceFixture( { id: 1, name: 'Haircut' } ) ] } );
			}
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [ resourceFixture( { id: 1, name: 'Alex' } ) ] } );
			}
			if ( path.startsWith( '/admin/seat-maps' ) ) {
				return Promise.resolve( { seat_maps: [] } );
			}
			if ( path.startsWith( '/admin/exceptions' ) ) {
				return Promise.resolve( { exceptions: [] } );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'ServicesScreen: the row control is focusable, named, and opens the editor', async () => {
		renderWithClient( <ServicesScreen /> );

		const control = await screen.findByRole( 'button', { name: 'Haircut' } );
		expect( within( control.closest( 'tr' ) as HTMLElement ).getByRole( 'button', { name: 'Haircut' } ) ).toBe( control );

		control.focus();
		expect( control ).toHaveFocus();
		fireEvent.click( control );

		expect( await screen.findByLabelText( 'Status' ) ).toBeInTheDocument();
		expect( control ).toHaveAttribute( 'aria-current', 'true' );
	} );

	it( 'StaffScreen: the row control is focusable, named, and opens the editor', async () => {
		renderWithClient( <StaffScreen /> );

		const control = await screen.findByRole( 'button', { name: 'Alex' } );
		control.focus();
		expect( control ).toHaveFocus();
		fireEvent.click( control );

		expect( await screen.findByLabelText( 'Status' ) ).toBeInTheDocument();
		expect( control ).toHaveAttribute( 'aria-current', 'true' );
	} );
} );
