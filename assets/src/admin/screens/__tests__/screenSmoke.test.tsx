import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import type { BookingSummary, Occurrence, Resource, Service } from '../../api/types';
import { ToastProvider } from '../../components/Toasts';
import { BookingsScreen } from '../BookingsScreen';
import { CalendarScreen } from '../CalendarScreen';
import { EventsScreen } from '../EventsScreen';
import { ManualBookingDrawer } from '../ManualBookingDrawer';
import { MyCalendarScreen } from '../MyCalendarScreen';

jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

window.scrollTo = jest.fn();

function setTimezone( timezone: string ): void {
	( window as { reservantAdmin?: unknown } ).reservantAdmin = {
		restRoot: '/wp-json/',
		nonce: 'test-nonce',
		caps: [ 'reservant_manage_bookings', 'reservant_manage_settings' ],
		currency: 'EUR',
		timezone,
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

function serviceFixture( overrides: Partial< Service > = {} ): Service {
	return {
		id: 1,
		name: 'Haircut',
		description: '',
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

function resourceFixture( overrides: Partial< Resource > = {} ): Resource {
	return {
		id: 1,
		wp_user_id: null,
		name: 'Alex',
		email: null,
		status: 'active',
		created_at: '2026-01-01 00:00:00',
		service_ids: [ 1 ],
		rules: [],
		...overrides,
	};
}

function bookingFixture( overrides: Partial< BookingSummary > = {} ): BookingSummary {
	return {
		uuid: 'uuid-1',
		status: 'confirmed',
		hold_class: null,
		hold_expires_at: null,
		customer_name: 'Jane Doe',
		total_minor: 5000,
		currency: 'EUR',
		payment_mode: 'onsite',
		requires_approval: false,
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
				start_utc: '2026-08-07 08:00:00',
				end_utc: '2026-08-07 08:30:00',
				block_start_utc: '2026-08-07 08:00:00',
				block_end_utc: '2026-08-07 08:30:00',
				processing_ends_utc: null,
				seats: 1,
				seat_claim: null,
				price_minor: 5000,
			},
		],
		...overrides,
	};
}

function occurrenceFixture( overrides: Partial< Occurrence > = {} ): Occurrence {
	return {
		id: 1,
		service_id: 2,
		start_utc: '2026-08-07 18:00:00',
		end_utc: '2026-08-07 20:00:00',
		capacity: 40,
		booked: 3,
		status: 'active',
		...overrides,
	};
}

/** Every route these screens touch, answered with a well-formed payload of the real wire shape. */
function respondWithCatalog( path: string ): Promise< unknown > {
	if ( path.startsWith( '/admin/services' ) ) {
		return Promise.resolve( {
			services: [ serviceFixture(), serviceFixture( { id: 2, name: 'Gala Night', type: 'event', capacity: 40 } ) ],
		} );
	}
	if ( path.startsWith( '/admin/resources' ) ) {
		return Promise.resolve( { resources: [ resourceFixture() ] } );
	}
	if ( path.startsWith( '/admin/bookings' ) ) {
		return Promise.resolve( { total: 1, bookings: [ bookingFixture() ] } );
	}
	if ( path.startsWith( '/admin/occurrences' ) ) {
		return Promise.resolve( { occurrences: [ occurrenceFixture() ] } );
	}
	if ( path.startsWith( '/admin/calendar' ) ) {
		return Promise.resolve( { bookings: [], occurrences: [] } );
	}
	if ( path.startsWith( '/admin/seat-maps' ) ) {
		return Promise.resolve( { seat_maps: [] } );
	}
	if ( path.startsWith( '/admin/availability' ) ) {
		return Promise.resolve( { granularity_min: 5, starts: [] } );
	}
	return Promise.reject( new Error( `unexpected path: ${ path }` ) );
}

/**
 * Final-review finding on TEST COVERAGE, not on the code: six of eight screens were never mounted
 * by any test, which is how a `TypeError` on first paint reached HEAD. These are deliberately cheap
 * - mount the screen against a well-formed payload and assert it painted something identifying -
 * but they are what turns "the response shape changed" from a silent blank page into a red test.
 */
describe( 'screen smoke - every screen mounts against a well-formed payload', () => {
	beforeEach( () => {
		setTimezone( 'Europe/Athens' );
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( respondWithCatalog );
	} );

	it( 'BookingsScreen renders its filter bar and results table', async () => {
		renderWithClient( <BookingsScreen /> );

		expect( await screen.findByRole( 'button', { name: 'Jane Doe' } ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Status' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Approval inbox' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 1' ) ).toBeInTheDocument();
	} );

	it( 'BookingsScreen lists inactive staff and services in its filters, marked as such', async () => {
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( { resources: [ resourceFixture( { id: 7, name: 'Departed Dana', status: 'inactive' } ) ] } );
			}
			if ( path.startsWith( '/admin/services' ) ) {
				return Promise.resolve( { services: [ serviceFixture( { id: 8, name: 'Retired Shave', status: 'inactive' } ) ] } );
			}
			return respondWithCatalog( path );
		} );

		renderWithClient( <BookingsScreen /> );

		// The whole point of the knock-on fix: a booking taken by a since-departed staff member must
		// still be filterable for.
		const staff = await screen.findByLabelText( 'Staff' );
		await waitFor( () => expect( within( staff ).getByText( 'Departed Dana (inactive)' ) ).toBeInTheDocument() );
		expect( within( screen.getByLabelText( 'Service' ) ).getByText( 'Retired Shave (inactive)' ) ).toBeInTheDocument();
	} );

	it( 'CalendarScreen renders its toolbar and grid', async () => {
		renderWithClient( <CalendarScreen /> );

		expect( await screen.findByLabelText( 'Staff' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Today' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Week' } ) ).toBeInTheDocument();
	} );

	it( 'CalendarScreen offers only ACTIVE staff in its picker - that filter feeds NEW bookings', async () => {
		mockedApiFetch.mockImplementation( ( path ) => {
			if ( path.startsWith( '/admin/resources' ) ) {
				return Promise.resolve( {
					resources: [ resourceFixture( { id: 1, name: 'Alex' } ), resourceFixture( { id: 7, name: 'Departed Dana', status: 'inactive' } ) ],
				} );
			}
			return respondWithCatalog( path );
		} );

		renderWithClient( <CalendarScreen /> );

		const staff = await screen.findByLabelText( 'Staff' );
		await waitFor( () => expect( within( staff ).getByText( 'Alex' ) ).toBeInTheDocument() );
		expect( within( staff ).queryByText( 'Departed Dana' ) ).not.toBeInTheDocument();
	} );

	it( 'MyCalendarScreen renders read-only, with no staff filter of its own', async () => {
		renderWithClient( <MyCalendarScreen /> );

		expect( await screen.findByRole( 'button', { name: 'Today' } ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Staff' ) ).not.toBeInTheDocument();
	} );

	it( 'EventsScreen renders its occurrence table once an event service is picked', async () => {
		renderWithClient( <EventsScreen /> );

		const eventSelect = await screen.findByLabelText( 'Event' );
		await waitFor( () => expect( within( eventSelect ).getByText( 'Gala Night' ) ).toBeInTheDocument() );

		fireEvent.change( eventSelect, { target: { value: '2' } } );

		expect( await screen.findByText( '3 / 40' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Add occurrence' } ) ).toBeInTheDocument();
	} );

	it( 'ManualBookingDrawer renders its chain builder and customer fields', async () => {
		renderWithClient( <ManualBookingDrawer onClose={ () => undefined } /> );

		expect( await screen.findByLabelText( 'Customer name' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Date' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Add segment' } ) ).toBeInTheDocument();
	} );
} );

/**
 * Final-review finding: the bookings table made a row click the only way to open a booking, so the
 * drawer was unreachable from the keyboard.
 */
describe( 'BookingsScreen - keyboard-operable row selection', () => {
	beforeEach( () => {
		setTimezone( 'Europe/Athens' );
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( respondWithCatalog );
		window.location.hash = '';
	} );

	it( 'exposes a focusable control named after the customer that hash-routes to the booking', async () => {
		renderWithClient( <BookingsScreen /> );

		const control = await screen.findByRole( 'button', { name: 'Jane Doe' } );
		control.focus();
		expect( control ).toHaveFocus();

		fireEvent.click( control );
		expect( window.location.hash ).toBe( '#/uuid-1' );
	} );
} );

/**
 * Final-review finding: `ManualBookingDrawer` defaulted its date to `format( new Date(),
 * 'yyyy-MM-dd' )`, and `date-fns`'s `format()` reads a `Date` through the HOST machine's local
 * getters - the exact hazard `calendar/adapter.ts` documents and `siteNow()` exists to solve. An
 * owner on a US/Pacific laptop at 16:00 local (= 02:00 the NEXT day at a Europe/Athens business)
 * opening "New booking" got YESTERDAY in site terms, and fetched the wrong day's slots.
 *
 * Two cases, at the two extremes of the tz range, deliberately: whatever offset the machine running
 * these tests happens to be at, at least one of them has a host-local date that genuinely differs
 * from the site's - so this can never quietly degrade into a tautology on some runner. Each asserts
 * that difference explicitly before asserting the default.
 */
describe( 'ManualBookingDrawer - the default date is the SITE\'s today, not the host machine\'s', () => {
	/** `YYYY-MM-DD` for an instant in a named zone, derived independently of any app code. */
	function dateIn( timeZone: string, instant: Date ): string {
		return new Intl.DateTimeFormat( 'en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' } ).format( instant );
	}

	/** `YYYY-MM-DD` the way `format( date, 'yyyy-MM-dd' )` reads it - through the HOST's local getters. */
	function hostLocalDate( instant: Date ): string {
		return [
			String( instant.getFullYear() ).padStart( 4, '0' ),
			String( instant.getMonth() + 1 ).padStart( 2, '0' ),
			String( instant.getDate() ).padStart( 2, '0' ),
		].join( '-' );
	}

	beforeEach( () => {
		mockedApiFetch.mockReset();
		// Never-resolving: the assertion is on the state initializer, and no pending query may settle
		// under fake timers and update state outside `act`.
		mockedApiFetch.mockImplementation( () => new Promise( () => undefined ) );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it.each( [
		// UTC+14 at midday UTC: already tomorrow at the business for any host west of UTC+12.
		[ 'Pacific/Kiritimati', '2026-08-07T12:00:00.000Z' ],
		// UTC-12 just after midnight UTC: still yesterday at the business for any host at or east of UTC.
		[ 'Etc/GMT+12', '2026-08-07T00:30:00.000Z' ],
	] )( 'defaults to the business day in %s', ( timeZone, isoInstant ) => {
		const instant = new Date( isoInstant );
		jest.setSystemTime( instant );
		setTimezone( timeZone );

		const siteDate = dateIn( timeZone, instant );
		const hostDate = hostLocalDate( instant );

		renderWithClient( <ManualBookingDrawer onClose={ () => undefined } /> );

		const dateInput = screen.getByLabelText( 'Date' );
		expect( dateInput ).toHaveValue( siteDate );

		// Only assert the two genuinely differ when they do on this runner - the pair of cases above
		// guarantees at least one of them does at every possible host offset.
		if ( hostDate !== siteDate ) {
			expect( dateInput ).not.toHaveValue( hostDate );
		}
	} );

	it( 'is meaningful on this runner: at least one case has a host date that differs from the site date', () => {
		const cases: [ string, string ][] = [
			[ 'Pacific/Kiritimati', '2026-08-07T12:00:00.000Z' ],
			[ 'Etc/GMT+12', '2026-08-07T00:30:00.000Z' ],
		];
		const differing = cases.filter( ( [ timeZone, iso ] ) => dateIn( timeZone, new Date( iso ) ) !== hostLocalDate( new Date( iso ) ) );
		expect( differing.length ).toBeGreaterThan( 0 );
	} );

	it( 'still honours an explicit initialDate from a calendar slot click', () => {
		jest.setSystemTime( new Date( '2026-08-07T12:00:00.000Z' ) );
		setTimezone( 'Pacific/Kiritimati' );

		renderWithClient( <ManualBookingDrawer onClose={ () => undefined } initialDate="2026-12-24" /> );

		expect( screen.getByLabelText( 'Date' ) ).toHaveValue( '2026-12-24' );
	} );
} );
