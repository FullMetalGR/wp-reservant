import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { AvailabilityExceptionListItem } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { StaffScreen } from '../StaffScreen';

/**
 * Task 16b gap-filler: the blackouts panel used to be session-only (only what THIS screen had
 * added/removed, forgotten on reload - see the Task 16 report's "known limitation"). It now reads
 * through `useExceptions()` (`GET /admin/exceptions`), so a row already on the server must render
 * on first load with no local action taken. Same mocking shape as `bookingactions.test.tsx`/
 * `settingsScreen.test.tsx`: only `apiFetch` is stubbed, every hook in `api/queries.ts` runs for real.
 */
jest.mock( '../../api/client', () => ( { apiFetch: jest.fn() } ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

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
