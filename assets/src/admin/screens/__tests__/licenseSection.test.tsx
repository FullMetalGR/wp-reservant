import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { LicenseState, LicenseStatus, SettingsPayload } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { ApiError } from '../../../shared';
import { SettingsScreen } from '../SettingsScreen';

// Only the network boundary is stubbed - `ApiError` and `errorMessage` stay real, and every hook in
// `api/queries.ts` runs against a real `QueryClient`, so these exercise the actual license wiring
// (`useLicense`, `useActivateLicense`, `useDeactivateLicense`) rather than a stand-in for it.
jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

/**
 * `ToastProvider`'s `SnackbarList` animates via framer-motion, which calls `window.scrollTo()` while
 * measuring layout - a method jsdom does not implement. Same environment gap
 * `staffScreenExceptions.test.tsx` stubs, and every activation here toasts.
 */
window.scrollTo = jest.fn();

/**
 * The plaintext key a test pastes into the field, and the masked form the server would answer with
 * (`LicenseRecord::mask()`: eight asterisks plus at most the last four characters).
 *
 * A fixture, never a real key: the plaintext is a credential and one has no business in a tracked
 * file. What these tests then assert is that this string never reaches the rendered screen once it
 * has done its job.
 */
const PLAINTEXT_KEY = 'RSVNT-AAAA-BBBB-2222';
const MASKED_KEY = '********2222';

function licenseFixture( overrides: Partial< LicenseStatus > = {} ): LicenseStatus {
	return {
		state: 'active',
		active: true,
		masked_key: MASKED_KEY,
		domain: 'salon.example',
		last_checked_at: '2026-08-19 08:30:00',
		grace_ends_at: null,
		...overrides,
	};
}

/** A never-activated site: no key, no domain, nothing ever checked (`LicenseRecord::statusAt()`). */
function unlicensedFixture(): LicenseStatus {
	return { state: 'inactive', active: false, masked_key: '', domain: '', last_checked_at: null, grace_ends_at: null };
}

function settingsFixture(): SettingsPayload {
	return {
		currency: 'EUR',
		checkout_ttl_min: 15,
		approval_ttl_hours: 48,
		payment_ttl_hours: 24,
		purge_on_uninstall: false,
		reminder_lead_hours: 24,
		emails_off: [],
	};
}

/**
 * The admin bootstrap, with the license the server would have printed into the page.
 *
 * `null` is the deliberate second case and NOT "unlicensed": `Admin\AdminPage::license()` answers
 * null when a `reservant/license_manager` threw while the page rendered, and the screen has to fall
 * back to `GET /admin/license` rather than draw an unlicensed site that is licensed.
 */
function stubBoot( license: LicenseStatus | null ): void {
	( window as { reservantAdmin?: unknown } ).reservantAdmin = {
		restRoot: 'https://salon.example/wp-json/',
		nonce: 'nonce',
		caps: [ 'reservant_manage_settings' ],
		currency: 'EUR',
		timezone: 'UTC',
		granularityMin: 5,
		emailChoices: [ { key: 'booking_confirmed', label: 'Customer: your booking is confirmed' } ],
		license,
	};
}

interface LicenseRoutes {
	get?: LicenseStatus | Error;
	post?: LicenseStatus | Error;
	remove?: LicenseStatus | Error;
}

function answer( value: LicenseStatus | Error | undefined, method: string ): Promise< unknown > {
	if ( undefined === value ) {
		return Promise.reject( new Error( `no license fixture for ${ method }` ) );
	}
	return value instanceof Error ? Promise.reject( value ) : Promise.resolve( value );
}

/** Routes the one mocked client call by path and method, the shape `staffScreenExceptions` uses. */
function stubApi( routes: LicenseRoutes ): void {
	mockedApiFetch.mockImplementation( ( path, init ) => {
		const method = init?.method ?? 'GET';
		if ( path.startsWith( '/admin/license' ) ) {
			if ( 'POST' === method ) {
				return answer( routes.post, method );
			}
			if ( 'DELETE' === method ) {
				return answer( routes.remove, method );
			}
			return answer( routes.get, method );
		}
		if ( path.startsWith( '/admin/settings' ) ) {
			return Promise.resolve( settingsFixture() );
		}
		return Promise.reject( new Error( `unexpected path: ${ path }` ) );
	} );
}

/**
 * Every query is scoped to the rendered container rather than `screen`, because
 * `@wordpress/components`' `Notice` mirrors its own text into `@wordpress/a11y`'s live region on
 * `document.body` - so every sentence here legitimately appears twice in the document, and an
 * unscoped `getByText` finds both. The `Modal` is the deliberate exception: it portals to
 * `document.body`, so its own queries go through `screen`.
 */
function renderScreen( ui: ReactElement ): HTMLElement {
	const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
	const { container } = render(
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>{ ui }</ToastProvider>
		</QueryClientProvider>
	);
	return container;
}

/** Every call the screen made to a license route, as `[method, parsed body]`. */
function licenseCalls(): { method: string; body: Record< string, unknown > | null }[] {
	return mockedApiFetch.mock.calls
		.filter( ( call ) => call[ 0 ].startsWith( '/admin/license' ) )
		.map( ( call ) => {
			const init = call[ 1 ] as { method?: string; body?: string } | undefined;
			return {
				method: init?.method ?? 'GET',
				body: undefined === init?.body ? null : ( JSON.parse( init.body ) as Record< string, unknown > ),
			};
		} );
}

/**
 * One whole sentence per state, because the FIX is different in each one
 * (`Licensing\LicenseState`). Collapsing them into "unlicensed" would leave an owner re-pasting a
 * key that was never going to work - which is exactly the failure `AdminGuard::licenseRequired()`
 * refuses to make on the server side, and this screen is the other half of it.
 */
const STATES: { state: LicenseState; active: boolean; says: RegExp }[] = [
	{ state: 'inactive', active: false, says: /Reservant is not licensed on this site/ },
	{ state: 'active', active: true, says: /Your license is active on this site/ },
	{ state: 'grace', active: true, says: /running on a grace period/ },
	{ state: 'invalid', active: false, says: /license key is no longer valid/ },
	{ state: 'domain_mismatch', active: false, says: /registered to a different domain/ },
];

describe( 'SettingsScreen license section - each state says its own thing', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		stubApi( { get: licenseFixture() } );
	} );

	STATES.forEach( ( { state, active, says } ) => {
		it( `explains "${ state }" in its own words`, async () => {
			stubBoot( licenseFixture( { state, active, grace_ends_at: 'grace' === state ? '2026-09-02 08:30:00' : null } ) );

			const container = renderScreen( <SettingsScreen /> );

			expect( within( container ).getByText( says ) ).toBeInTheDocument();
			// And never a second state's sentence alongside it.
			STATES.filter( ( other ) => other.state !== state ).forEach( ( other ) => {
				expect( within( container ).queryByText( other.says ) ).not.toBeInTheDocument();
			} );
		} );
	} );

	it( 'reads `active` off the payload rather than deciding for itself, so grace is not a freeze', async () => {
		stubBoot( licenseFixture( { state: 'grace', active: true, grace_ends_at: '2026-09-02 08:30:00' } ) );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).queryByText( /Only changes to your setup/ ) ).not.toBeInTheDocument();
		expect( within( container ).getByText( 'Grace period ends' ) ).toBeInTheDocument();
	} );

	it( 'shows no grace deadline outside the grace window - a stale one reads as a threat that is not real', async () => {
		stubBoot( licenseFixture() );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).queryByText( 'Grace period ends' ) ).not.toBeInTheDocument();
	} );
} );

/**
 * The single most expensive thing this screen could get wrong is implying the site has stopped
 * working. It has not: an unlicensed site loses configuration WRITES and nothing else - every
 * public route, every read and the whole admin booking lifecycle stay open (AGENTS.md section 5,
 * `AdminGuard::configureSite()`). An owner who believes their bookings are down at 9am on a
 * Saturday will do something drastic about it.
 */
describe( 'SettingsScreen license section - what is actually frozen', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		stubApi( { get: licenseFixture() } );
	} );

	it( 'tells an unlicensed owner that bookings keep running and customers are unaffected', async () => {
		stubBoot( unlicensedFixture() );

		const container = renderScreen( <SettingsScreen /> );

		const frozen = within( container ).getByText( /Your bookings keep running/ );
		expect( frozen ).toHaveTextContent( 'your customers are unaffected' );
		expect( frozen ).toHaveTextContent( 'Only changes to your setup' );
	} );

	it( 'says nothing about a freeze on a licensed site', async () => {
		stubBoot( licenseFixture() );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).queryByText( /Your bookings keep running/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'SettingsScreen license section - activation', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'sends the trimmed key and reports the activation', async () => {
		stubBoot( unlicensedFixture() );
		stubApi( { post: licenseFixture() } );

		const container = renderScreen( <SettingsScreen /> );
		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: `  ${ PLAINTEXT_KEY }  ` } } );
		fireEvent.click( within( container ).getByRole( 'button', { name: 'Activate license' } ) );

		expect( await screen.findByTestId( 'snackbar' ) ).toHaveTextContent( 'License activated.' );
		expect( licenseCalls() ).toEqual( [ { method: 'POST', body: { key: PLAINTEXT_KEY } } ] );
		expect( within( container ).getByText( /Your license is active on this site/ ) ).toBeInTheDocument();
		expect( within( container ).getByText( MASKED_KEY ) ).toBeInTheDocument();
	} );

	it( 'does not dress a refused key up as a successful activation', async () => {
		stubBoot( unlicensedFixture() );
		// A key the validator refuses is a 200 with `state: 'invalid'`, never an HTTP error
		// (`LicenseAdminController::create()`), so a screen keying off HTTP alone would celebrate it.
		stubApi( { post: licenseFixture( { state: 'invalid', active: false, last_checked_at: null } ) } );

		const container = renderScreen( <SettingsScreen /> );
		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: PLAINTEXT_KEY } } );
		fireEvent.click( within( container ).getByRole( 'button', { name: 'Activate license' } ) );

		const snackbar = await screen.findByTestId( 'snackbar' );
		expect( snackbar ).toHaveTextContent( 'Error: That license key was refused.' );
		expect( snackbar ).not.toHaveTextContent( 'License activated.' );
		expect( within( container ).getByText( /license key is no longer valid/ ) ).toBeInTheDocument();
		// The key stays in the field: the commonest cause is a typo, and retyping it is a tax.
		expect( within( container ).getByLabelText( 'License key' ) ).toHaveValue( PLAINTEXT_KEY );
	} );

	it( 'reports a request that genuinely failed through the shared error sentence', async () => {
		stubBoot( unlicensedFixture() );
		stubApi( {
			post: new ApiError(
				'reservant_license_unavailable',
				'license_unavailable',
				503,
				'The licensing service could not be reached. Please try again in a few minutes.'
			),
		} );

		const container = renderScreen( <SettingsScreen /> );
		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: PLAINTEXT_KEY } } );
		fireEvent.click( within( container ).getByRole( 'button', { name: 'Activate license' } ) );

		expect( await screen.findByTestId( 'snackbar' ) ).toHaveTextContent(
			'Error: The licensing service could not be reached.'
		);
	} );

	/**
	 * An empty key is a documented server-side NO-OP that answers 200 with whatever was already
	 * stored (`LicenseManager::activate()` - a blank field posted by accident must not cost a site
	 * the license it paid for). Which means it would read on screen as a successful activation, so
	 * it never leaves here in the first place.
	 */
	it( 'never submits an empty or whitespace-only key', async () => {
		stubBoot( unlicensedFixture() );
		stubApi( { post: licenseFixture() } );

		const container = renderScreen( <SettingsScreen /> );
		const activate = within( container ).getByRole( 'button', { name: 'Activate license' } );
		expect( activate ).toBeDisabled();

		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: '   ' } } );
		expect( activate ).toBeDisabled();

		fireEvent.click( activate );
		expect( licenseCalls() ).toEqual( [] );

		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: PLAINTEXT_KEY } } );
		expect( activate ).not.toBeDisabled();
	} );
} );

describe( 'SettingsScreen license section - deactivation', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		stubApi( { remove: unlicensedFixture() } );
		stubBoot( licenseFixture() );
	} );

	it( 'unbinds the site only after the owner confirms it', async () => {
		const container = renderScreen( <SettingsScreen /> );

		fireEvent.click( within( container ).getByRole( 'button', { name: 'Deactivate license' } ) );
		// One click has changed nothing yet - the confirmation is the guard.
		expect( licenseCalls() ).toEqual( [] );
		expect( await screen.findByText( /unbinds the site from your license/ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Deactivate' } ) );

		await waitFor( () => expect( licenseCalls() ).toEqual( [ { method: 'DELETE', body: null } ] ) );
		expect( await screen.findByTestId( 'snackbar' ) ).toHaveTextContent( 'License deactivated.' );
		expect( within( container ).getByText( /Reservant is not licensed on this site/ ) ).toBeInTheDocument();
		// Nothing left to unbind, so the button that would unbind it is gone.
		expect( within( container ).queryByRole( 'button', { name: 'Deactivate license' } ) ).not.toBeInTheDocument();
	} );

	it( 'sends nothing when the confirmation is dismissed', async () => {
		const container = renderScreen( <SettingsScreen /> );

		fireEvent.click( within( container ).getByRole( 'button', { name: 'Deactivate license' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel' } ) );

		expect( licenseCalls() ).toEqual( [] );
		expect( within( container ).getByText( /Your license is active on this site/ ) ).toBeInTheDocument();
	} );

	it( 'offers no deactivation on a site that never bound a key', async () => {
		stubBoot( unlicensedFixture() );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).queryByRole( 'button', { name: 'Deactivate license' } ) ).not.toBeInTheDocument();
	} );
} );

/**
 * The bootstrap is the primary source and the fetch is the fallback, not the other way round: an
 * unlicensed owner should not watch this screen render once wrongly and then correct itself.
 */
describe( 'SettingsScreen license section - bootstrap first, fetch only as a fallback', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'renders a bootstrapped status without asking the server at all', async () => {
		stubBoot( licenseFixture() );
		stubApi( { get: licenseFixture() } );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).getByText( /Your license is active on this site/ ) ).toBeInTheDocument();
		await waitFor( () => expect( within( container ).getByLabelText( 'Currency' ) ).toBeInTheDocument() );
		expect( licenseCalls() ).toEqual( [] );
	} );

	/**
	 * A null bootstrap means "not known right now" - `Admin\AdminPage::license()` answers null for a
	 * license manager that threw while the page rendered - and reading it as "unlicensed" would put
	 * a freeze warning in front of a paying owner over somebody else's fault.
	 */
	it( 'falls back to GET /admin/license when the bootstrap does not know', async () => {
		stubBoot( null );
		stubApi( { get: licenseFixture( { state: 'domain_mismatch', active: false, domain: 'old.example' } ) } );

		const container = renderScreen( <SettingsScreen /> );

		expect( await within( container ).findByText( /registered to a different domain/ ) ).toBeInTheDocument();
		expect( within( container ).getByText( 'old.example' ) ).toBeInTheDocument();
		expect( licenseCalls() ).toEqual( [ { method: 'GET', body: null } ] );
	} );

	it( 'shows the licensing service that could not answer, rather than an unlicensed site', async () => {
		stubBoot( null );
		stubApi( {
			get: new ApiError(
				'reservant_license_unavailable',
				'license_unavailable',
				503,
				'The licensing service could not be reached. Please try again in a few minutes.'
			),
		} );

		const container = renderScreen( <SettingsScreen /> );

		expect( await within( container ).findByText( /The licensing service could not be reached/ ) ).toBeInTheDocument();
		expect( within( container ).queryByText( /Reservant is not licensed on this site/ ) ).not.toBeInTheDocument();
	} );
} );

/**
 * The plaintext key is a credential. The server side cannot leak one by construction
 * (`Rest\Admin\LicensePayload` takes a `LicenseStatus`, which carries only the masked form), and
 * this is the client half of the same promise: what an owner typed leaves the screen the moment it
 * is no longer needed, and what stays on show is eight asterisks and four characters.
 */
describe( 'SettingsScreen license section - the key never stays on screen', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'renders only the masked form of a stored license', async () => {
		stubBoot( licenseFixture() );
		stubApi( { get: licenseFixture() } );

		const container = renderScreen( <SettingsScreen /> );

		expect( within( container ).getByText( MASKED_KEY ) ).toBeInTheDocument();
		expect( container.textContent ).not.toContain( PLAINTEXT_KEY );
		expect( within( container ).getByLabelText( 'License key' ) ).toHaveValue( '' );
	} );

	it( 'clears the field once the key has done its job', async () => {
		stubBoot( unlicensedFixture() );
		stubApi( { post: licenseFixture() } );

		const container = renderScreen( <SettingsScreen /> );
		fireEvent.change( within( container ).getByLabelText( 'License key' ), { target: { value: PLAINTEXT_KEY } } );
		fireEvent.click( within( container ).getByRole( 'button', { name: 'Activate license' } ) );

		await screen.findByTestId( 'snackbar' );
		expect( within( container ).getByLabelText( 'License key' ) ).toHaveValue( '' );
		expect( container.textContent ).not.toContain( PLAINTEXT_KEY );
	} );
} );
