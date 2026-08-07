import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { SettingsPayload } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { SettingsScreen } from '../SettingsScreen';

// Same mocking shape as `bookingactions.test.tsx`: only the network boundary is stubbed, every
// hook in `api/queries.ts` runs for real against a real `QueryClient`.
jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

function renderWithClient( ui: ReactElement ) {
	const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
	return render(
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>{ ui }</ToastProvider>
		</QueryClientProvider>
	);
}

function settingsFixture( overrides: Partial< SettingsPayload > = {} ): SettingsPayload {
	return {
		currency: 'EUR',
		checkout_ttl_min: 15,
		approval_ttl_hours: 48,
		payment_ttl_hours: 24,
		purge_on_uninstall: false,
		...overrides,
	};
}

/**
 * Review round 1 finding: `canSave` used to check only `currency`, so blanking (or zeroing) a TTL
 * field and clicking Save folded straight through `toPatch()`'s `parseInt(...) || 0` into a
 * 0-minute/0-hour hold sent to the server. These pin that `Save` is disabled - and the mutation
 * never fires - the moment any TTL field is not a positive whole number, mirroring
 * `EventsScreen`'s own `canAdd` gating pattern.
 */
describe( 'SettingsScreen - TTL gating (review round 1)', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( settingsFixture() );
	} );

	it( 'enables Save once the loaded settings are all valid', async () => {
		renderWithClient( <SettingsScreen /> );
		await screen.findByLabelText( 'Checkout hold (minutes)' );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).not.toBeDisabled();
	} );

	it( 'disables Save when the checkout TTL is blanked out, and never calls the save mutation', async () => {
		renderWithClient( <SettingsScreen /> );

		const checkoutField = await screen.findByLabelText( 'Checkout hold (minutes)' );
		fireEvent.change( checkoutField, { target: { value: '' } } );

		const save = screen.getByRole( 'button', { name: 'Save settings' } );
		expect( save ).toBeDisabled();

		// A disabled native <button> never dispatches click, but assert the mutation never ran too -
		// only the initial GET /admin/settings should have happened.
		fireEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'disables Save when a TTL field is exactly "0"', async () => {
		renderWithClient( <SettingsScreen /> );

		const checkoutField = await screen.findByLabelText( 'Checkout hold (minutes)' );
		fireEvent.change( checkoutField, { target: { value: '0' } } );

		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).toBeDisabled();
	} );

	it( 'disables Save when a TTL field holds non-numeric garbage', async () => {
		renderWithClient( <SettingsScreen /> );

		const approvalField = await screen.findByLabelText( 'Approval hold (hours)' );
		fireEvent.change( approvalField, { target: { value: 'abc' } } );

		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).toBeDisabled();
	} );

	it( 're-enables Save once the blanked field is filled back in with a positive integer', async () => {
		renderWithClient( <SettingsScreen /> );

		const paymentField = await screen.findByLabelText( 'Payment hold (hours)' );
		fireEvent.change( paymentField, { target: { value: '' } } );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).toBeDisabled();

		fireEvent.change( paymentField, { target: { value: '12' } } );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).not.toBeDisabled();
	} );
} );
