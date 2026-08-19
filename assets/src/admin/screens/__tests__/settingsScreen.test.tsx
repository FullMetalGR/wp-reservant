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
		reminder_lead_hours: 24,
		emails_off: [],
		...overrides,
	};
}

/**
 * The body of the last PUT the screen sent, parsed.
 *
 * Reached by finding the PUT rather than by taking the last call, because a successful save
 * invalidates the settings query and the refetch that follows is the last call. `body` is a JSON
 * STRING (`useSaveSettings` stringifies the patch), so an `objectContaining` matcher against it
 * silently matches nothing.
 */
function lastPutBody(): Record< string, unknown > {
	const put = [ ...mockedApiFetch.mock.calls ]
		.reverse()
		.find( ( call ) => 'PUT' === ( call[ 1 ] as { method?: string } | undefined )?.method );
	if ( undefined === put ) {
		throw new Error( 'no PUT was sent' );
	}
	return JSON.parse( ( put[ 1 ] as { body: string } ).body ) as Record< string, unknown >;
}

/**
 * The screen reads the switchable-message catalog off the admin bootstrap
 * (`Notifications\EmailCatalog::choices()`), and `bootConfig()` throws when the global is absent -
 * so every test here needs one, even the ones that only touch the TTL fields.
 */
function stubBootConfig(): void {
	( window as { reservantAdmin?: unknown } ).reservantAdmin = {
		restRoot: 'https://example.test/wp-json/',
		nonce: 'nonce',
		caps: [ 'reservant_manage_settings' ],
		currency: 'EUR',
		timezone: 'UTC',
		granularityMin: 5,
		emailChoices: [
			{ key: 'booking_confirmed', label: 'Customer: your booking is confirmed' },
			{ key: 'booking_reminder', label: 'Customer: reminder before the appointment' },
		],
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
		stubBootConfig();
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

/**
 * The reminder lead time is the one number on this screen where zero is an answer rather than a
 * blanked-out field - it is how "send no reminders" is stored - so it deliberately does NOT share
 * the positive-integer gate the three TTL fields use. These pin that the two rules stay apart.
 */
describe( 'SettingsScreen - reminder lead time', () => {
	beforeEach( () => {
		stubBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( settingsFixture() );
	} );

	it( 'keeps Save enabled when the lead time is exactly "0", and sends the zero through', async () => {
		renderWithClient( <SettingsScreen /> );

		const lead = await screen.findByLabelText( 'Reminder lead time (hours)' );
		fireEvent.change( lead, { target: { value: '0' } } );

		const save = screen.getByRole( 'button', { name: 'Save settings' } );
		expect( save ).not.toBeDisabled();

		fireEvent.click( save );
		await screen.findByLabelText( 'Reminder lead time (hours)' );
		expect( lastPutBody().reminder_lead_hours ).toBe( 0 );
	} );

	it( 'disables Save when the lead time is blanked out or holds garbage', async () => {
		renderWithClient( <SettingsScreen /> );
		const lead = await screen.findByLabelText( 'Reminder lead time (hours)' );

		fireEvent.change( lead, { target: { value: '' } } );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).toBeDisabled();

		fireEvent.change( lead, { target: { value: 'soon' } } );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).toBeDisabled();

		fireEvent.change( lead, { target: { value: '2' } } );
		expect( screen.getByRole( 'button', { name: 'Save settings' } ) ).not.toBeDisabled();
	} );
} );

/**
 * The stored value is an OFF list and the checkbox reads "send this", so every one of these is
 * about the inversion holding in both directions. Getting it backwards would silently switch off
 * every message the moment an owner opened the screen and saved.
 */
describe( 'SettingsScreen - email switches', () => {
	beforeEach( () => {
		stubBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( settingsFixture() );
	} );

	it( 'renders one checkbox per bootstrapped message, all on for a site that switched nothing off', async () => {
		renderWithClient( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Customer: your booking is confirmed' ) ).toBeChecked();
		expect( screen.getByLabelText( 'Customer: reminder before the appointment' ) ).toBeChecked();
	} );

	it( 'shows a switched-off message as unchecked', async () => {
		mockedApiFetch.mockResolvedValue( settingsFixture( { emails_off: [ 'booking_reminder' ] } ) );
		renderWithClient( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Customer: your booking is confirmed' ) ).toBeChecked();
		expect( screen.getByLabelText( 'Customer: reminder before the appointment' ) ).not.toBeChecked();
	} );

	it( 'unchecking a message adds its key to the off list it sends', async () => {
		renderWithClient( <SettingsScreen /> );

		fireEvent.click( await screen.findByLabelText( 'Customer: reminder before the appointment' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );

		await screen.findByLabelText( 'Reminder lead time (hours)' );
		expect( lastPutBody().emails_off ).toEqual( [ 'booking_reminder' ] );
	} );

	it( 'checking a switched-off message takes its key back out of the off list', async () => {
		mockedApiFetch.mockResolvedValue( settingsFixture( { emails_off: [ 'booking_reminder' ] } ) );
		renderWithClient( <SettingsScreen /> );

		fireEvent.click( await screen.findByLabelText( 'Customer: reminder before the appointment' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );

		await screen.findByLabelText( 'Reminder lead time (hours)' );
		expect( lastPutBody().emails_off ).toEqual( [] );
	} );
} );

/**
 * Nothing asserted what the save actually SENDS until now, and adding the two notification fields
 * to `toPatch()` quietly dropped `purge_on_uninstall` out of it - every save silently stopped
 * carrying the uninstall choice, and eleven passing tests said nothing. A patch that names every
 * key is cheap; discovering the omission from a support ticket is not.
 */
describe( 'SettingsScreen - the saved patch', () => {
	beforeEach( () => {
		stubBootConfig();
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( settingsFixture( { purge_on_uninstall: true } ) );
	} );

	it( 'carries every settings field, not merely the ones most recently edited', async () => {
		renderWithClient( <SettingsScreen /> );
		await screen.findByLabelText( 'Checkout hold (minutes)' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
		await screen.findByLabelText( 'Reminder lead time (hours)' );

		expect( lastPutBody() ).toEqual( {
			currency: 'EUR',
			checkout_ttl_min: 15,
			approval_ttl_hours: 48,
			payment_ttl_hours: 24,
			purge_on_uninstall: true,
			reminder_lead_hours: 24,
			emails_off: [],
		} );
	} );
} );
