import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { BookingDetail, Service } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { BookingDrawer } from '../BookingDrawer';
import { ManualBookingDrawer } from '../ManualBookingDrawer';

// The client layer is mocked, not React Query itself (task brief) - every hook in `api/queries.ts`
// runs for real against a real `QueryClient`, so these tests exercise the actual mutation wiring
// (`useApprove`, `useManualBooking`, ...) and only stub out the network boundary.
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

function setCaps( caps: string[] ): void {
	( window as { reservantAdmin?: unknown } ).reservantAdmin = {
		restRoot: '/wp-json/',
		nonce: 'test-nonce',
		caps,
		currency: 'EUR',
		timezone: 'Europe/Athens',
		granularityMin: 5,
	};
}

function bookingFixture( overrides: Partial< BookingDetail > = {} ): BookingDetail {
	return {
		uuid: 'uuid-1',
		status: 'awaiting_approval',
		hold_class: 'approval',
		hold_expires_at: '2026-08-08 00:00:00',
		customer_name: 'Jane Doe',
		customer_email: 'jane@example.com',
		total_minor: 5000,
		currency: 'EUR',
		payment_mode: 'onsite',
		requires_approval: true,
		created_at: '2026-08-06 10:00:00',
		updated_at: '2026-08-06 10:00:00',
		items: [
			{
				id: 1,
				sort: 0,
				service_id: 1,
				service_name: 'Haircut',
				resource_id: 1,
				resource_name: 'Alex',
				occurrence_id: null,
				start_utc: '2026-08-10 09:00:00',
				end_utc: '2026-08-10 09:30:00',
				block_start_utc: '2026-08-10 09:00:00',
				block_end_utc: '2026-08-10 09:30:00',
				processing_ends_utc: null,
				seats: 1,
				seat_claim: null,
				price_minor: 5000,
			},
		],
		audit: [],
		...overrides,
	};
}

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
		price_minor: 5000,
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

describe( 'BookingDrawer - Approve action', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'shows the Approve button only when the booking awaits approval and the caller can approve', async () => {
		setCaps( [ 'reservant_approve_bookings' ] );
		mockedApiFetch.mockResolvedValue( bookingFixture( { status: 'awaiting_approval' } ) );

		renderWithClient( <BookingDrawer uuid="uuid-1" onClose={ jest.fn() } /> );

		expect( await screen.findByRole( 'button', { name: 'Approve' } ) ).toBeInTheDocument();
	} );

	it( 'hides the Approve button once the booking is no longer awaiting approval', async () => {
		setCaps( [ 'reservant_approve_bookings' ] );
		mockedApiFetch.mockResolvedValue( bookingFixture( { status: 'confirmed' } ) );

		renderWithClient( <BookingDrawer uuid="uuid-1" onClose={ jest.fn() } /> );

		await screen.findByText( 'Jane Doe' );
		expect( screen.queryByRole( 'button', { name: 'Approve' } ) ).not.toBeInTheDocument();
	} );

	it( 'hides the Approve button when the caller holds neither approval capability', async () => {
		setCaps( [] );
		mockedApiFetch.mockResolvedValue( bookingFixture( { status: 'awaiting_approval' } ) );

		renderWithClient( <BookingDrawer uuid="uuid-1" onClose={ jest.fn() } /> );

		await screen.findByText( 'Jane Doe' );
		expect( screen.queryByRole( 'button', { name: 'Approve' } ) ).not.toBeInTheDocument();
	} );

	it( 'hides Approve and Reject for a manage-only caller - reservant_manage_bookings does not imply reservant_approve_bookings (AdminGuard::approveBookings() gates on the latter alone)', async () => {
		setCaps( [ 'reservant_manage_bookings' ] );
		mockedApiFetch.mockResolvedValue( bookingFixture( { status: 'awaiting_approval' } ) );

		renderWithClient( <BookingDrawer uuid="uuid-1" onClose={ jest.fn() } /> );

		await screen.findByText( 'Jane Doe' );
		expect( screen.queryByRole( 'button', { name: 'Approve' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Reject' } ) ).not.toBeInTheDocument();
	} );

	it( 'calls the approve endpoint with the booking uuid when Approve is clicked', async () => {
		setCaps( [ 'reservant_approve_bookings' ] );
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( '/admin/bookings/uuid-1' === path ) {
				return Promise.resolve( bookingFixture( { status: 'awaiting_approval' } ) );
			}
			if ( '/admin/bookings/uuid-1/approve' === path ) {
				return Promise.resolve( bookingFixture( { status: 'confirmed' } ) );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );

		renderWithClient( <BookingDrawer uuid="uuid-1" onClose={ jest.fn() } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( '/admin/bookings/uuid-1/approve', expect.objectContaining( { method: 'POST' } ) )
		);
	} );
} );

describe( 'ManualBookingDrawer - submit gating', () => {
	beforeEach( () => {
		setCaps( [ 'reservant_manage_bookings' ] );
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [ serviceFixture() ] } );
			}
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [] } );
			}
			if ( path.startsWith( '/admin/availability' ) ) {
				return Promise.resolve( {
					granularity_min: 5,
					starts: [ { utc: '2026-08-10 09:00:00', local: '2026-08-10T09:00:00+03:00' } ],
				} );
			}
			return Promise.reject( new Error( `unexpected path: ${ path }` ) );
		} );
	} );

	it( 'disables submit until both the customer and a slot are chosen', async () => {
		renderWithClient( <ManualBookingDrawer onClose={ jest.fn() } /> );

		const submit = await screen.findByRole( 'button', { name: 'Create booking' } );
		expect( submit ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Customer name' ), { target: { value: 'Jane' } } );
		fireEvent.change( screen.getByLabelText( 'Customer email' ), { target: { value: 'jane@example.com' } } );
		expect( submit ).toBeDisabled();

		const slotButton = await screen.findByRole( 'button', { name: '09:00' } );
		fireEvent.click( slotButton );

		expect( submit ).not.toBeDisabled();
	} );

	it( 'stays disabled with a slot chosen but no customer filled in', async () => {
		renderWithClient( <ManualBookingDrawer onClose={ jest.fn() } /> );

		const slotButton = await screen.findByRole( 'button', { name: '09:00' } );
		fireEvent.click( slotButton );

		expect( screen.getByRole( 'button', { name: 'Create booking' } ) ).toBeDisabled();
	} );
} );
