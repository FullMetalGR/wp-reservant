/**
 * Task 14 pins (P5 plan): the whole booking journey - hold, countdown, conflict recovery, outcome.
 *
 * The suite runs under modern jest fake timers with a FIXED system time, for two reasons: the
 * countdown test must advance time without sleeping, and every fixture date can then be a literal
 * that is "today" on any machine, in any timezone, forever. The bootstrap timezone is
 * Pacific/Kiritimati (UTC+14, no DST) - the portability zone the Task 13 suites pinned - so any
 * site-clock string this suite builds differs from the runner's own wall clock and a component
 * that read the browser clock could not pass by accident. Expected Intl strings are BUILT with
 * the components' own Intl options over the expected site wall-clock digits, never written as
 * glyph literals, and exact-text lookups use the identity normalizer (the servicePicker
 * precedent) so locale no-break-space padding cannot desync the two sides of a comparison.
 *
 * The countdown display is asserted by PARSING the rendered m:ss back to seconds and bounding it,
 * not by matching a precomputed literal: waitFor advances the fake clock by its polling interval
 * while it waits, so the exact second on screen when an assertion runs is not knowable in
 * advance - but "about five minutes, nowhere near the bootstrap's fifteen" is, and that is the
 * actual ruling under test (the anchor is the server's hold_expires_at, never checkoutTtlMin).
 *
 * The fetch mock mirrors the real wire envelopes exactly: the 409 carries the engine's own
 * {code, message, data: {status, detail, segment}} shape with the very sentence Errors::detail()
 * produces server-side, because the widget renders that detail verbatim (design spec section 6)
 * rather than inventing its own wording.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { BookingFlow } from '../BookingFlow';
import type { WidgetConfig } from '../index';
import type { Booking, HeldBooking, HoldInput, PublicService, WidgetBootstrap } from '../api/types';

const SITE_TZ = 'Pacific/Kiritimati';
const UUID = '11111111-1111-4111-8111-111111111111';
const TOKEN = 'secret-token';
/** The frozen "now": 2026-06-01 00:00:00 UTC, which is 14:00 on June 1st at the site (UTC+14). */
const NOW_UTC = '2026-06-01T00:00:00Z';

function bootstrapFixture(): WidgetBootstrap {
	return {
		restRoot: '/wp-json/',
		nonce: '',
		currency: 'EUR',
		timezone: SITE_TZ,
		granularityMin: 5,
		checkoutTtlMin: 15,
	};
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

/** 09:00 UTC on June 1st is 23:00 the same site day at UTC+14 - today on the DateStrip. */
const APPOINTMENT_AVAILABILITY = {
	granularity_min: 5,
	starts: [ { utc: '2026-06-01 09:00:00', local: '2026-06-01T23:00:00+14:00' } ],
};

const EVENT_AVAILABILITY = {
	occurrences: [
		{ id: 21, start_utc: '2026-06-05 07:00:00', end_utc: '2026-06-05 09:00:00', remaining: 8 },
	],
};

/** DateStrip's own Intl options over today's site date - only the digits are this suite's. */
const DAY_LABEL = new Intl.DateTimeFormat( undefined, {
	weekday: 'short',
	month: 'short',
	day: 'numeric',
} ).format( new Date( 2026, 5, 1 ) );

/** SlotGrid's own Intl options over the expected site wall clock (23:00). */
const SLOT_LABEL = new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } ).format(
	new Date( 2026, 5, 1, 23, 0, 0 )
);

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

function heldBooking( overrides: Partial< HeldBooking > = {} ): HeldBooking {
	return {
		uuid: UUID,
		status: 'pending',
		hold_class: 'checkout',
		hold_expires_at: '2026-06-01 00:15:00',
		customer_name: 'Ada',
		customer_email: 'ada@example.com',
		customer_phone: '',
		total_minor: 4500,
		currency: 'EUR',
		payment_mode: 'free',
		requires_approval: false,
		created_at: '2026-06-01 00:00:00',
		updated_at: '2026-06-01 00:00:00',
		items: [
			{
				id: 1,
				sort: 0,
				service_id: 3,
				resource_id: 7,
				occurrence_id: null,
				start_utc: '2026-06-01 09:00:00',
				end_utc: '2026-06-01 09:45:00',
				block_start_utc: '2026-06-01 09:00:00',
				block_end_utc: '2026-06-01 09:45:00',
				processing_ends_utc: null,
				seats: 1,
				seat_claim: null,
				price_minor: 4500,
			},
		],
		manage_token: TOKEN,
		...overrides,
	};
}

/** What presentBooking() sends everywhere except the hold 201: no manage_token on the wire. */
function presentedBooking( overrides: Partial< Booking > = {} ): Booking {
	const { manage_token: _stripped, ...booking } = heldBooking();
	return { ...booking, ...overrides };
}

function confirmedBooking(): Booking {
	return presentedBooking( { status: 'confirmed', hold_class: null, hold_expires_at: null } );
}

function jsonResponse( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

/** The engine's real 409 envelope, sentence included - the widget shows data.detail verbatim. */
function conflictResponse(): Response {
	return jsonResponse(
		{
			code: 'reservant_conflict',
			message: 'overlap',
			data: {
				status: 409,
				segment: 0,
				detail: 'That time was just taken. Please pick another.',
			},
		},
		409
	);
}

interface RouteHandlers {
	availability?: () => unknown;
	hold?: ( body: HoldInput ) => Response;
	confirm?: () => Response;
	release?: () => Response;
}

type FetchCall = [ unknown, RequestInit | undefined ];

function installFetch( handlers: RouteHandlers ): jest.Mock {
	const fetchMock = jest.fn( async ( input: unknown, init?: RequestInit ): Promise< Response > => {
		const url = String( input );
		const method = ( init?.method ?? 'GET' ).toUpperCase();
		if ( url.includes( '/services' ) ) {
			return jsonResponse( SERVICES );
		}
		if ( url.includes( '/availability' ) && handlers.availability ) {
			return jsonResponse( handlers.availability() );
		}
		if ( 'POST' === method && url.includes( '/holds' ) && handlers.hold ) {
			return handlers.hold( JSON.parse( String( init?.body ) ) as HoldInput );
		}
		if ( 'DELETE' === method && url.includes( '/holds/' ) ) {
			return ( handlers.release ?? ( (): Response => jsonResponse( presentedBooking( { status: 'cancelled' } ) ) ) )();
		}
		if ( 'POST' === method && url.includes( '/confirm' ) && handlers.confirm ) {
			return handlers.confirm();
		}
		throw new Error( `unrouted ${ method } ${ url }` );
	} );
	global.fetch = fetchMock as unknown as typeof fetch;
	return fetchMock;
}

function callsTo( fetchMock: jest.Mock, marker: string, method = 'GET' ): FetchCall[] {
	return ( fetchMock.mock.calls as FetchCall[] ).filter(
		( [ input, init ] ) =>
			String( input ).includes( marker ) && method === ( init?.method ?? 'GET' ).toUpperCase()
	);
}

function bodyOf( call: FetchCall | undefined ): unknown {
	if ( ! call ) {
		throw new Error( 'expected a request to have been made' );
	}
	return JSON.parse( String( call[ 1 ]?.body ) ) as unknown;
}

function renderFlow( overrides: Partial< WidgetConfig > = {} ): void {
	render(
		<BookingFlow
			config={ {
				mode: 'book',
				serviceId: 3,
				resourceId: 7,
				uuid: null,
				token: null,
				...overrides,
			} }
		/>
	);
}

/** Preselected service+staff mounts land on the when step: pick today, pick the 23:00 slot. */
async function pickTodaySlot(): Promise< void > {
	fireEvent.click( await screen.findByText( DAY_LABEL, EXACT_TEXT ) );
	fireEvent.click( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) );
}

async function submitDetails(): Promise< void > {
	fireEvent.change( await screen.findByLabelText( 'Name' ), { target: { value: 'Ada' } } );
	fireEvent.change( screen.getByLabelText( 'Email' ), { target: { value: 'ada@example.com' } } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
}

/** Parses the rendered countdown back to seconds - see the header for why no literal can work. */
function shownCountdownSeconds(): number {
	const shown = screen.getByText( /Time left to confirm:/ ).textContent ?? '';
	const match = /(\d+):(\d\d)/.exec( shown );
	if ( null === match ) {
		throw new Error( `no m:ss in "${ shown }"` );
	}
	return Number( match[ 1 ] ) * 60 + Number( match[ 2 ] );
}

function forceVisibilityState( state: 'hidden' | 'visible' ): void {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
}

beforeEach( () => {
	jest.useFakeTimers( { now: new Date( NOW_UTC ) } );
	window.reservantWidget = bootstrapFixture();
} );

afterEach( () => {
	// An instance-level override shadows jsdom's prototype getter; deleting it restores 'visible'.
	Reflect.deleteProperty( document, 'visibilityState' );
	jest.useRealTimers();
} );

describe( 'BookingFlow', () => {
	it( 'holds the slot when one is chosen and confirms it into a booking', async () => {
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		// The review step is the held state: a real confirm button, enabled.
		expect( await screen.findByRole( 'button', { name: 'Confirm booking' } ) ).toBeEnabled();

		// The hold body is exactly the wire shape: the chosen utc round-trips untouched, the
		// segments carry the preselected staff, and there is NO same_staff key (toEqual pins the
		// absence - the engine defaults it false and the plan never asked for a toggle).
		expect( bodyOf( callsTo( fetchMock, '/holds', 'POST' )[ 0 ] ) ).toEqual( {
			customer: { name: 'Ada', email: 'ada@example.com' },
			appointment: {
				start_utc: '2026-06-01 09:00:00',
				segments: [ { service_id: 3, resource_id: 7 } ],
			},
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm booking' } ) );

		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		// The confirm carried the once-shown manage token, and nothing else.
		expect( bodyOf( callsTo( fetchMock, '/confirm', 'POST' )[ 0 ] ) ).toEqual( { token: TOKEN } );
		// Flush the availability invalidation useConfirm fires on success.
		await act( async () => {} );
	} );

	it( 'shows the engine sentence and refreshes the slots when the hold 409s', async () => {
		// assert the customer sees "That time was just taken. Please pick another."
		// and that availability was refetched - a 409 is a normal outcome, not an error screen
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => conflictResponse(),
		} );
		renderFlow();

		await pickTodaySlot();
		expect( callsTo( fetchMock, '/availability' ) ).toHaveLength( 1 );
		await submitDetails();

		// The sentence is the server's own data.detail, rendered verbatim, in a polite status
		// region - never role="alert", nothing is wrong.
		expect(
			await screen.findByText( 'That time was just taken. Please pick another.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		// The visitor is back on the slot step and the offer list was refetched - the second GET
		// is useCreateHold's own 409-only invalidation doing its job end to end.
		await waitFor( () => expect( callsTo( fetchMock, '/availability' ) ).toHaveLength( 2 ) );
		expect( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'ends on "request sent" for a service that requires approval', async () => {
		// NOT "confirmed" - the engine distinguishes these and the UI must not flatten them
		// The catalog fixture says requires_approval: false for this service; the SERVER answers
		// awaiting_approval anyway (approval settings can change between page load and the hold),
		// and the server's returned status is the only thing the outcome may branch on.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () =>
				jsonResponse(
					heldBooking( {
						status: 'awaiting_approval',
						hold_class: 'approval',
						hold_expires_at: '2026-06-03 00:00:00',
						requires_approval: true,
					} ),
					201
				),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		expect( await screen.findByText( /request sent/i ) ).toBeInTheDocument();
		expect( screen.queryByText( /confirmed/i ) ).not.toBeInTheDocument();
		// Nothing to confirm: the lodged request IS the outcome, so no confirm button exists.
		expect( screen.queryByRole( 'button', { name: 'Confirm booking' } ) ).not.toBeInTheDocument();
	} );

	it( 'releases the hold when the page is hidden', async () => {
		/* visibilitychange -> DELETE /holds */
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		forceVisibilityState( 'hidden' );
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );

		await waitFor( () => expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 1 ) );
		const [ url ] = callsTo( fetchMock, '/holds/', 'DELETE' )[ 0 ] ?? [];
		expect( String( url ) ).toContain( `/holds/${ UUID }?token=${ TOKEN }` );

		// Best-effort and single-shot: a second hidden signal must not DELETE again.
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await act( async () => {} );
		expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 1 );
	} );

	it( 'counts down from the configured checkout ttl and blocks confirm at zero', async () => {
		// The NAME above is the plan's, kept verbatim; the ANCHOR under test is the ruling's: the
		// deadline is the server's hold_expires_at (here now+5min), never the bootstrap's
		// checkoutTtlMin (here 15). The server derives that timestamp FROM the configured ttl, so
		// the name still holds - but a widget computing 15 minutes client-side fails this test.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking( { hold_expires_at: '2026-06-01 00:05:00' } ), 201 ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		const seconds = shownCountdownSeconds();
		expect( seconds ).toBeLessThanOrEqual( 5 * 60 );
		expect( seconds ).toBeGreaterThan( 4 * 60 );

		expect( screen.getByRole( 'button', { name: 'Confirm booking' } ) ).toBeEnabled();

		act( () => {
			jest.advanceTimersByTime( 5 * 60 * 1000 );
		} );

		const confirmButton = screen.getByRole( 'button', { name: 'Confirm booking' } );
		expect( confirmButton ).toBeDisabled();
		fireEvent.click( confirmButton );
		expect( callsTo( fetchMock, '/confirm', 'POST' ) ).toHaveLength( 0 );
	} );

	it( 'skips the countdown when the server returns no hold expiry, and still confirms', async () => {
		// hold_expires_at is nullable on the wire; with no deadline there is nothing to count
		// down from and nothing to block confirm over - the sweeper owns expiry either way.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking( { hold_expires_at: null } ), 201 ),
			confirm: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		expect( screen.queryByText( /Time left to confirm:/ ) ).not.toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm booking' } ) );
		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		await act( async () => {} );
	} );

	it( 'does not release after the booking is confirmed - it is no longer a hold', async () => {
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );
		await screen.findByText( 'Your booking is confirmed.' );
		await act( async () => {} );

		forceVisibilityState( 'hidden' );
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await act( async () => {} );

		expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 0 );
	} );

	it( 'books an event through its occurrences and seat count, not date and slot', async () => {
		// No preselect: the service step picks the event, the chain gains its one segment, the
		// staff step self-skips (no resources to choose), and the when step branches on the
		// availability RESPONSE - occurrences, so OccurrencePicker plus a places count.
		const fetchMock = installFetch( {
			availability: () => EVENT_AVAILABILITY,
			hold: ( body ) =>
				jsonResponse(
					heldBooking( {
						total_minor: 10000,
						currency: 'JPY',
						items: [
							{
								id: 2,
								sort: 0,
								service_id: 9,
								resource_id: null,
								occurrence_id: 21,
								start_utc: '2026-06-05 07:00:00',
								end_utc: '2026-06-05 09:00:00',
								block_start_utc: '2026-06-05 07:00:00',
								block_end_utc: '2026-06-05 09:00:00',
								processing_ends_utc: null,
								seats: body.event?.seats ?? 0,
								seat_claim: null,
								price_minor: 5000,
							},
						],
					} ),
					201
				),
			confirm: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow( { serviceId: null, resourceId: null } );

		fireEvent.click( await screen.findByRole( 'button', { name: /^Wine seminar/ } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Continue' } ) );

		const seats = await screen.findByLabelText( 'Number of places' );
		fireEvent.change( seats, { target: { value: '2' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /places left/ } ) );

		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		expect( bodyOf( callsTo( fetchMock, '/holds', 'POST' )[ 0 ] ) ).toEqual( {
			customer: { name: 'Ada', email: 'ada@example.com' },
			event: { occurrence_id: 21, seats: 2 },
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm booking' } ) );
		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		await act( async () => {} );
	} );
} );
