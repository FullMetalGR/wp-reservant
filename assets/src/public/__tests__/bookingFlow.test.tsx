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
 *
 * The recovery paths get the same rigor as the happy path - they are where the first pass's two
 * MAJOR findings hid. Two properties are non-negotiable and pinned below: (a) no confirm refusal
 * may ever leave the visitor with no way forward, and (b) a booking the server says is settled
 * (confirmed / awaiting approval / awaiting payment) must render its outcome, never a refusal.
 * The double-submit tests fire two clicks with NO await between them, inside one act(): TanStack's
 * mutation observer updates its snapshot synchronously and only the NOTIFICATION is deferred
 * (notifyManager schedules with setTimeout(0)), so the rendered `disabled` stops a second click
 * only when some other render happens to land in the gap - and a second mutate() detaches the
 * first mutation's callbacks. Only a synchronous guard holds that line, and only clicks with no
 * render between them measure the guard rather than render timing.
 *
 * Fixtures where `requires_approval` and `status` DISAGREE are deliberate: the returned status is
 * the only thing the UI may branch on (R3), so the confirmed booking carries the flag true and the
 * awaiting-approval booking carries it false - an implementation reading the flag fails loudly.
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
		id: 5,
		name: 'Massage',
		description: 'A relaxing hour.',
		type: 'appointment',
		duration_min: 60,
		price_minor: 6000,
		currency: 'EUR',
		requires_approval: false,
		resources: [ { id: 8, name: 'Sam' } ],
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

/**
 * The flag disagrees with the status ON PURPOSE: `requires_approval: true` under
 * `status: 'confirmed'` (an owner can flip approval settings after the hold). An implementation
 * branching on the flag instead of the returned status renders "request sent" here and fails
 * every confirmed-screen assertion in this suite - exactly what R3 forbids.
 */
function confirmedBooking(): Booking {
	return presentedBooking( {
		status: 'confirmed',
		hold_class: null,
		hold_expires_at: null,
		requires_approval: true,
	} );
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

/**
 * A lifecycle refusal exactly as `Errors::failure()` wires it: the machine reason in `message`,
 * the sentence in `data.detail`. The sentences below are the literal `Errors::detail()` strings.
 */
function refusalResponse( reason: string, status: number, detail: string ): Response {
	return jsonResponse(
		{ code: `reservant_${ reason }`, message: reason, data: { status, detail } },
		status
	);
}

/** A `Rest\Errors::badRequest()` 400 - `invalid_request` with the per-field sentence in detail. */
function badRequestResponse( detail: string ): Response {
	return jsonResponse(
		{ code: 'rest_invalid_param', message: 'invalid_request', data: { status: 400, detail } },
		400
	);
}

interface RouteHandlers {
	/** Returns an availability body, or a full mock Response for a non-200 answer. */
	availability?: () => unknown;
	services?: () => Response;
	hold?: ( body: HoldInput ) => Response | Promise< Response >;
	confirm?: () => Response;
	release?: () => Response;
	/** `GET /bookings/{uuid}` - the manage-token re-read the refusal recovery performs. */
	booking?: () => Response | Promise< Response >;
}

type FetchCall = [ unknown, RequestInit | undefined ];

/** Whether a handler answered with a mock Response rather than a body to wrap in a 200. */
function isMockResponse( value: unknown ): value is Response {
	return (
		null !== value &&
		'object' === typeof value &&
		'function' === typeof ( value as { text?: unknown } ).text
	);
}

/** Routes the GET reads: the catalog, availability, and the manage re-read. Null = unrouted. */
function answerRead( handlers: RouteHandlers, url: string ): Response | Promise< Response > | null {
	if ( url.includes( '/services' ) ) {
		return handlers.services ? handlers.services() : jsonResponse( SERVICES );
	}
	if ( url.includes( '/availability' ) && handlers.availability ) {
		const answer = handlers.availability();
		return isMockResponse( answer ) ? answer : jsonResponse( answer );
	}
	if ( url.includes( '/bookings/' ) && handlers.booking ) {
		return handlers.booking();
	}
	return null;
}

/** Routes the writes: hold, release, confirm. Null = unrouted. */
function answerWrite(
	handlers: RouteHandlers,
	url: string,
	method: string,
	init?: RequestInit
): Response | Promise< Response > | null {
	if ( 'POST' === method && url.includes( '/holds' ) && handlers.hold ) {
		return handlers.hold( JSON.parse( String( init?.body ) ) as HoldInput );
	}
	if ( 'DELETE' === method && url.includes( '/holds/' ) ) {
		return ( handlers.release ?? ( (): Response => jsonResponse( presentedBooking( { status: 'cancelled' } ) ) ) )();
	}
	if ( 'POST' === method && url.includes( '/confirm' ) && handlers.confirm ) {
		return handlers.confirm();
	}
	return null;
}

function installFetch( handlers: RouteHandlers ): jest.Mock {
	const fetchMock = jest.fn( async ( input: unknown, init?: RequestInit ): Promise< Response > => {
		const url = String( input );
		const method = ( init?.method ?? 'GET' ).toUpperCase();
		const answer =
			'GET' === method
				? answerRead( handlers, url )
				: answerWrite( handlers, url, method, init );
		if ( null === answer ) {
			throw new Error( `unrouted ${ method } ${ url }` );
		}
		return answer;
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

function renderFlow(
	overrides: Partial< WidgetConfig > = {},
	navigate?: ( url: string ) => void
): void {
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
			navigate={ navigate }
		/>
	);
}

/**
 * A hold the server says must be paid before anything can confirm it: the `checkout_url` the
 * `POST /holds` 201 carries beside the manage token, on an `online` booking. The URL is the one
 * `CartBridge::entryUrl()` builds - the query arg and the token in it are the whole credential.
 */
const CHECKOUT_URL = `https://shop.test/?reservant_checkout=${ UUID }&token=${ TOKEN }`;

function payableHold(): HeldBooking {
	return heldBooking( { payment_mode: 'online', checkout_url: CHECKOUT_URL } );
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

	it( 'says when the slot list could not be refreshed after a conflict', async () => {
		// React Query keeps `data` through a failed refetch, so a 409 whose triggered refetch
		// fails would otherwise show the conflict sentence over a list that was never
		// refreshed, silently. The ServicePicker stale-list precedent, ported to the when
		// step: degraded and labelled, never destroyed and never silent.
		let reads = 0;
		installFetch( {
			availability: () =>
				1 === ( reads += 1 )
					? APPOINTMENT_AVAILABILITY
					: badRequestResponse( 'That date is too far ahead to book.' ),
			hold: () => conflictResponse(),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		await screen.findByText( 'That time was just taken. Please pick another.' );
		expect(
			await screen.findByText(
				'The available times could not be refreshed and may be out of date.'
			)
		).toBeInTheDocument();
		// The stale offer is still on screen, and politely: nothing here earns an alert.
		expect( screen.getByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
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

	/**
	 * The non-approval online path, end to end on the client: a hold that must be paid before it
	 * can be confirmed sends the visitor to the checkout the server named, and never to a confirm
	 * that would answer 402 with nowhere to go - the dead end this route exists to remove.
	 */
	it( 'leaves for the checkout instead of confirming when the hold must be paid online', async () => {
		const navigate = jest.fn();
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( payableHold(), 201 ),
		} );
		renderFlow( {}, navigate );

		await pickTodaySlot();
		await submitDetails();

		// The button says what pressing it does, and there is no confirm to press instead.
		const pay = await screen.findByRole( 'button', { name: 'Continue to payment' } );
		expect(
			screen.queryByRole( 'button', { name: 'Confirm booking' } )
		).not.toBeInTheDocument();

		fireEvent.click( pay );
		await act( async () => {} );

		expect( navigate ).toHaveBeenCalledWith( CHECKOUT_URL );
		expect( callsTo( fetchMock, '/confirm', 'POST' ) ).toHaveLength( 0 );
	} );

	it( 'does not release the hold on the way to the checkout - the cart needs it alive', async () => {
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( payableHold(), 201 ),
		} );
		renderFlow( {}, () => {} );

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Continue to payment' } ) );
		await act( async () => {} );

		// Leaving the page IS a pagehide, and the release-on-hide would fire on it. Releasing here
		// cancels the booking the cart is about to be handed, and the cart admits `pending` only -
		// the guest would arrive to "this booking cannot be taken to checkout", slot already gone.
		act( () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
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

		// The review step shows the open-seating count through the plural rule.
		expect( screen.getByText( '2 places' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm booking' } ) );
		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		await act( async () => {} );
	} );

	it( 'offers a way forward when the confirm 409s after a background release', async () => {
		// Finding 1's end-to-end scenario: the visitor reaches review on a phone, switches apps
		// (visibilitychange releases the hold), returns, and taps Confirm. The engine answers
		// 409 not_confirmable - NOT 410: the DELETE moved the row out of pending, ConfirmBooking
		// checks status before deadline, and Errors maps every non-hold_expired reason to 409.
		// The re-read answers a dead status, so the refusal stands - with the server's sentence
		// and a rendered way out. Without the restart control this step is a dead end: Confirm
		// re-enables and 409s forever, and review has no Back.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () =>
				refusalResponse(
					'not_confirmable',
					409,
					'This booking cannot be confirmed in its current state.'
				),
			booking: () =>
				jsonResponse(
					presentedBooking( {
						status: 'cancelled',
						hold_class: null,
						hold_expires_at: null,
					} )
				),
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
		forceVisibilityState( 'visible' );
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm booking' } ) );

		// The refusal shows the server's own sentence, politely - nothing is wrong, the visitor
		// just needs a new slot...
		expect(
			await screen.findByText( 'This booking cannot be confirmed in its current state.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		// ...and property (a): a way forward always renders, not only after the countdown hit
		// zero. Taking it lands back on the slot step with the offer list still there.
		fireEvent.click( screen.getByRole( 'button', { name: 'Pick another time' } ) );
		expect( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'renders the outcome, never a refusal, when a refused confirm has actually settled', async () => {
		// Property (b): the engine answers 409 approval_required for a booking that IS lodged
		// and waiting (a lost first answer, a second attempt). The server is the authority on
		// the booking's state, so the flow re-reads it and renders the outcome. The re-read
		// fixture disagrees on requires_approval (false) per R3: only the returned status may
		// drive the screen.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () =>
				refusalResponse(
					'approval_required',
					409,
					'This booking is waiting for our approval.'
				),
			booking: () =>
				jsonResponse(
					presentedBooking( {
						status: 'awaiting_approval',
						hold_class: 'approval',
						hold_expires_at: '2026-06-03 00:00:00',
						requires_approval: false,
					} )
				),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );

		expect( await screen.findByText( /request sent/i ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'This booking is waiting for our approval.' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Confirm booking' } )
		).not.toBeInTheDocument();
	} );

	it( 'renders confirmed when the refused confirm turns out to have committed', async () => {
		// The double-submit shape of property (b): the first confirm committed, its answer was
		// lost, the retry 409s not_confirmable - and the re-read says confirmed (with the R3
		// flag disagreement), so the visitor sees their success, never an error.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () =>
				refusalResponse(
					'not_confirmable',
					409,
					'This booking cannot be confirmed in its current state.'
				),
			booking: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );

		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText( 'This booking cannot be confirmed in its current state.' )
		).not.toBeInTheDocument();
	} );

	it( 'releases a live hold exactly once when the visitor picks another time', async () => {
		// The deterministic route: an online-payment service's confirm answers 402
		// online_payment_required on EVERY attempt (ConfirmBooking throws it unless the
		// allow_direct_confirm filter says otherwise), the re-read comes back still pending,
		// and the restart control renders beside the refusal. Taking it abandons a hold that
		// is still ALIVE - skipping the release would leave the visitor's own pending hold
		// blocking the very slot they are sent back to re-pick, and nulling `held` also wipes
		// useReleaseOnHide's target, so even pagehide could never release it after the fact.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () =>
				refusalResponse(
					'online_payment_required',
					402,
					'This booking must be paid for online.'
				),
			booking: () => jsonResponse( presentedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );
		await screen.findByText( 'This booking must be paid for online.' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Pick another time' } ) );

		await waitFor( () => expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 1 ) );
		const [ url ] = callsTo( fetchMock, '/holds/', 'DELETE' )[ 0 ] ?? [];
		expect( String( url ) ).toContain( `/holds/${ UUID }?token=${ TOKEN }` );

		// Exactly once: the flow released it itself, so a later hidden signal has no target.
		forceVisibilityState( 'hidden' );
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await act( async () => {} );
		expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 1 );

		// And the visitor really is back on the offer list.
		expect( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'leaves an expired hold to the sweeper when the visitor starts over', async () => {
		// The other half of the split, which is on the DEADLINE, not on which trigger rendered
		// the control: past expiry the sweeper owns the row, and a client-side DELETE would be
		// a wasted request the server refuses anyway.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking( { hold_expires_at: '2026-06-01 00:05:00' } ), 201 ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		act( () => {
			jest.advanceTimersByTime( 5 * 60 * 1000 );
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Pick another time' } ) );
		await act( async () => {} );

		expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 0 );
		expect( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'offers the way out while a refusal re-read hangs, and the next journey still confirms', async () => {
		// The re-read is a raw fetch with no timeout. While it hangs, Confirm is disabled and
		// - before this fix - the restart control did not render; with hold_expires_at null
		// (a supported shape) there is no countdown to expire the way out into view, so the
		// visitor was simply stuck. "No refusal is a dead end" must not depend on a deadline
		// existing: the restart control renders during the recovery itself. Taking it releases
		// the still-live hold, and the next journey's confirm latch is clean.
		let confirms = 0;
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking( { hold_expires_at: null } ), 201 ),
			confirm: () =>
				1 === ( confirms += 1 )
					? refusalResponse(
							'not_confirmable',
							409,
							'This booking cannot be confirmed in its current state.'
					  )
					: jsonResponse( confirmedBooking() ),
			booking: () =>
				new Promise< Response >( () => {
					// Never settles - the recovery hangs for the rest of the test.
				} ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick another time' } ) );
		await waitFor( () => expect( callsTo( fetchMock, '/holds/', 'DELETE' ) ).toHaveLength( 1 ) );

		// The journey restarts cleanly: re-pick the slot, the kept details submit again, and
		// the second confirm goes through - the hung recovery left no latch behind.
		fireEvent.click( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Continue' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );
		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		await act( async () => {} );
	} );

	it( 'ignores a re-read verdict that lands after the visitor started over', async () => {
		// The visitor took the way out mid-recovery; the old journey's re-read then resolves
		// "confirmed" - about a hold they already walked away from (and whose release was
		// already requested). Teleporting them to an outcome screen mid-re-pick would apply a
		// verdict to a journey that no longer exists.
		let resolveRead: ( response: Response ) => void = () => {
			throw new Error( 'booking re-read resolver was never captured' );
		};
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking( { hold_expires_at: null } ), 201 ),
			confirm: () =>
				refusalResponse(
					'not_confirmable',
					409,
					'This booking cannot be confirmed in its current state.'
				),
			booking: () =>
				new Promise< Response >( ( resolve ) => {
					resolveRead = resolve;
				} ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Confirm booking' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick another time' } ) );
		await screen.findByText( SLOT_LABEL, EXACT_TEXT );

		await act( async () => {
			resolveRead( jsonResponse( confirmedBooking() ) );
		} );

		expect( screen.queryByText( 'Your booking is confirmed.' ) ).not.toBeInTheDocument();
		expect( screen.getByText( SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'sends exactly one hold when the details submit fires twice in one beat', async () => {
		// Two clicks with NO await between them: isPending reaches the disabled attribute one
		// macrotask late (notifyManager schedules with setTimeout(0)), and a second mutate()
		// detaches the first mutation's callbacks - the visitor would then hold the slot twice
		// and be told "just taken" about their own orphaned hold. Only a synchronous latch can
		// hold this line; the count on the wire is the proof.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
		} );
		renderFlow();

		await pickTodaySlot();
		fireEvent.change( await screen.findByLabelText( 'Name' ), { target: { value: 'Ada' } } );
		fireEvent.change( screen.getByLabelText( 'Email' ), {
			target: { value: 'ada@example.com' },
		} );
		const submit = screen.getByRole( 'button', { name: 'Continue' } );
		// Both submits inside a single act(), for the same reason as the confirm twin below: a
		// render between the clicks could disable the button first and the pin would measure
		// render timing instead of the synchronous guard.
		act( () => {
			submit.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			submit.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		} );

		await screen.findByRole( 'button', { name: 'Confirm booking' } );
		expect( callsTo( fetchMock, '/holds', 'POST' ) ).toHaveLength( 1 );
	} );

	it( 'sends exactly one confirm when the button is clicked twice in one beat', async () => {
		// Same latch, other mutation: without it the first confirm commits, the second answers
		// 409, and a successfully confirmed booking is reported as unconfirmable.
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
			confirm: () => jsonResponse( confirmedBooking() ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		const confirmButton = await screen.findByRole( 'button', { name: 'Confirm booking' } );
		// One beat, literally: both clicks dispatch inside a single act(), so no render can
		// disable the button between them. (Two fireEvent calls each flush a render, and that
		// render reads the mutation observer's synchronously-updated snapshot - the second click
		// then lands on a disabled button and the pin would measure render timing, not the
		// guard. A real browser gives no such guarantee: input events can beat the deferred
		// notify, and the bail-out that forces the timely render here is an accident of state.)
		act( () => {
			confirmButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			confirmButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		} );

		expect( await screen.findByText( 'Your booking is confirmed.' ) ).toBeInTheDocument();
		expect( callsTo( fetchMock, '/confirm', 'POST' ) ).toHaveLength( 1 );
		await act( async () => {} );
	} );

	it( 'keeps the typed details across a hold 409 - a conflict never costs a retype', async () => {
		// The 409 is "normal, not exceptional" (the design's words), so it must not punish: the
		// visitor picks a new slot and finds everything they typed still in place.
		let holds = 0;
		const fetchMock = installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => ( 1 === ( holds += 1 ) ? conflictResponse() : jsonResponse( heldBooking(), 201 ) ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByText( 'That time was just taken. Please pick another.' );

		fireEvent.click( await screen.findByText( SLOT_LABEL, EXACT_TEXT ) );
		expect( await screen.findByLabelText( 'Name' ) ).toHaveValue( 'Ada' );
		expect( screen.getByLabelText( 'Email' ) ).toHaveValue( 'ada@example.com' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await screen.findByRole( 'button', { name: 'Confirm booking' } );
		expect( bodyOf( callsTo( fetchMock, '/holds', 'POST' )[ 1 ] ) ).toEqual( {
			customer: { name: 'Ada', email: 'ada@example.com' },
			appointment: {
				start_utc: '2026-06-01 09:00:00',
				segments: [ { service_id: 3, resource_id: 7 } ],
			},
		} );
	} );

	it( 'stays on the details form when the hold 400s, with the sentence shown politely', async () => {
		// The details-refusal path (zero coverage in the first pass): a validation 400 keeps the
		// visitor exactly where the problem is, form still filled, the server's per-field
		// sentence rendered verbatim in the polite region - a 4xx is an answer, not an emergency.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => badRequestResponse( '"customer" needs a name and a valid email.' ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		expect(
			await screen.findByText( '"customer" needs a name and a valid email.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Name' ) ).toHaveValue( 'Ada' );
		expect( screen.getByLabelText( 'Email' ) ).toHaveValue( 'ada@example.com' );
	} );

	it( 'stays on the details form when the rate limiter answers 429', async () => {
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () =>
				refusalResponse(
					'rate_limited',
					429,
					'Too many booking attempts. Please wait a minute and try again.'
				),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		expect(
			await screen.findByText( 'Too many booking attempts. Please wait a minute and try again.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Name' ) ).toHaveValue( 'Ada' );
	} );

	it( 'never shows browser jargon when the hold dies on the network', async () => {
		// A dead connection rejects the fetch with TypeError('Failed to fetch') - developer
		// jargon no guest should be handed, least of all in an alert region. The manage journey
		// pinned this rule in Task 15; the shared noticeOf makes it one rule for both journeys.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => Promise.reject( new TypeError( 'Failed to fetch' ) ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			'We could not reach the server. Please check your connection and try again.'
		);
		expect( screen.queryByText( 'Failed to fetch' ) ).not.toBeInTheDocument();
	} );

	it( 'disables Back while the hold request is in flight', async () => {
		// Back live during the POST would let the answer land on whatever step the visitor
		// reached. The never-settling response keeps the request in flight for the whole test.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () =>
				new Promise< Response >( () => {
					// Never settles - the hold stays in flight.
				} ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();

		// The busy submit label proves the in-flight state reached the DOM before Back is judged.
		expect( await screen.findByRole( 'button', { name: 'Reserving...' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Back' } ) ).toBeDisabled();
	} );

	it( 'drops a preselected staff who does not perform the chosen service', async () => {
		// A mount node's data-staff preselect rides along only onto a service that actually
		// lists that resource - Massage is Sam's alone, so pinning Alex (7) onto it would have
		// the server refuse a pairing the visitor never chose.
		const fetchMock = installFetch( { availability: () => APPOINTMENT_AVAILABILITY } );
		renderFlow( { serviceId: null, resourceId: 7 } );

		fireEvent.click( await screen.findByRole( 'button', { name: /^Massage/ } ) );

		await waitFor( () => expect( callsTo( fetchMock, '/availability' ) ).toHaveLength( 1 ) );
		const [ url ] = callsTo( fetchMock, '/availability' )[ 0 ] ?? [];
		const items = new URLSearchParams( String( url ).split( '?' )[ 1 ] ).get( 'items' );
		expect( JSON.parse( String( items ) ) ).toEqual( [ { service_id: 5, resource_id: null } ] );
	} );

	it( 'carries the preselected staff onto a service they do perform', async () => {
		// The counter-case, so the fix cannot overshoot: Alex (7) performs Haircut, and the
		// preselection then behaves exactly like a picked staff member.
		const fetchMock = installFetch( { availability: () => APPOINTMENT_AVAILABILITY } );
		renderFlow( { serviceId: null, resourceId: 7 } );

		fireEvent.click( await screen.findByRole( 'button', { name: /^Haircut/ } ) );

		await waitFor( () => expect( callsTo( fetchMock, '/availability' ) ).toHaveLength( 1 ) );
		const [ url ] = callsTo( fetchMock, '/availability' )[ 0 ] ?? [];
		const items = new URLSearchParams( String( url ).split( '?' )[ 1 ] ).get( 'items' );
		expect( JSON.parse( String( items ) ) ).toEqual( [ { service_id: 3, resource_id: 7 } ] );
	} );

	it( 'routes an availability refusal to the polite region - a 4xx is an answer, not an alarm', async () => {
		// noticeOf() exists precisely to route a 4xx politely and reserve role="alert" for
		// network/5xx; the query-error paths must follow the same rule as the mutation paths.
		installFetch( {
			availability: () => badRequestResponse( 'That date is too far ahead to book.' ),
		} );
		renderFlow();

		expect(
			await screen.findByText( 'That date is too far ahead to book.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	it( 'routes a services refusal on the staff step to the polite region too', async () => {
		// A WP-core-shaped 403 (no reservant envelope): ApiError falls back to the message, and
		// a 4xx still lands politely on the staff step.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			services: () =>
				jsonResponse(
					{
						code: 'rest_forbidden',
						message: 'Sorry, you are not allowed to do that.',
						data: { status: 403 },
					},
					403
				),
		} );
		renderFlow( { resourceId: null } );

		expect(
			await screen.findByText( 'Sorry, you are not allowed to do that.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	it( 'moves focus to the flow container when the visitor advances a step', async () => {
		// A visitor who submits a step would otherwise land with focus on a button that no
		// longer exists, so a screen reader says nothing about the change. The landing spot is
		// the CONTAINER (tabIndex -1), never a control: a first-in-document-order control is
		// the Confirm button on review - focusing it would put the entire summary being
		// approved ABOVE the reading point, unread - and a step whose content has not loaded
		// falls through to Back, the one control that undoes the visitor's action. From the
		// container a screen reader reads the new step from its top.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
		} );
		renderFlow();

		expect( document.body ).toHaveFocus();
		const flow = document.querySelector( '.reservant-flow' );

		await pickTodaySlot();
		expect( flow ).toHaveFocus();

		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );
		expect( flow ).toHaveFocus();
	} );

	it( 'leaves focus on the page when a mount auto-skips the staff step', async () => {
		// A preselected event service enters the staff step with nobody to pick; when the
		// catalog ANSWER arrives, the skip effect moves the step - a network response, not a
		// visitor action. Focus must stay wherever the visitor put it: HTMLElement.focus()
		// scrolls by default, so a widget below the fold would otherwise jerk-scroll the page
		// and swallow the keystrokes of whatever the visitor was typing elsewhere.
		installFetch( { availability: () => EVENT_AVAILABILITY } );
		renderFlow( { serviceId: 9, resourceId: null } );

		// The when step is fully on screen - the skip ran, the availability answered...
		expect( await screen.findByLabelText( 'Number of places' ) ).toBeInTheDocument();
		await act( async () => {} );
		// ...and with ZERO visitor interactions, focus never left the page.
		expect( document.body ).toHaveFocus();
	} );

	it( 'reviews what the server held: the service, the site-clock time and the priced total', async () => {
		// The review step renders the HELD booking, not the widget's own selections: the catalog
		// name, the start on the SITE clock (23:00 at UTC+14 while the runner's own zone shows
		// something else - R5's non-vacuity), and the total through formatPrice's
		// currency-derived scale. Expected strings are built with the component's own Intl
		// options over the expected site digits.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => jsonResponse( heldBooking(), 201 ),
		} );
		renderFlow();

		await pickTodaySlot();
		await submitDetails();
		await screen.findByRole( 'button', { name: 'Confirm booking' } );

		expect( screen.getByText( 'Haircut' ) ).toBeInTheDocument();
		const whenLabel = new Intl.DateTimeFormat( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} ).format( new Date( 2026, 5, 1, 23, 0 ) );
		expect( screen.getByText( whenLabel, EXACT_TEXT ) ).toBeInTheDocument();
		const totalLabel = `Total: ${ new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: 'EUR',
		} ).format( 45 ) }`;
		expect( screen.getByText( totalLabel, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( 'Ada (ada@example.com)' ) ).toBeInTheDocument();
	} );

	it( 'mounts the conflict region empty - the sentence arrives only once the region exists', async () => {
		// The live-region discipline, pinned on DOM-write ORDER: render() cannot see it
		// (Testing Library flushes effects inside act, so by assertion time the region always
		// holds its text), so a MutationObserver watches the raw writes. The record sequence
		// must show the role="status" node entering the document first and the sentence landing
		// in it in a LATER write: a region born pre-filled produces one insertion record, no
		// text record at all, and no announcement for a screen reader.
		installFetch( {
			availability: () => APPOINTMENT_AVAILABILITY,
			hold: () => conflictResponse(),
		} );
		renderFlow();
		await pickTodaySlot();

		// Records are COLLECTED IN THE CALLBACK, not only via takeRecords(): the awaits below
		// run microtasks, and jsdom delivers every pending record to the callback then -
		// takeRecords() after the fact answers an empty list (verified the hard way).
		const records: MutationRecord[] = [];
		const observer = new MutationObserver( ( batch ) => records.push( ...batch ) );
		observer.observe( document.body, {
			childList: true,
			subtree: true,
			characterData: true,
		} );
		await submitDetails();
		const region = await screen.findByText(
			'That time was just taken. Please pick another.'
		);
		records.push( ...observer.takeRecords() );
		observer.disconnect();

		// findByText answers the element holding the sentence - the polite region itself. Its
		// insertion MUST be inside the observation window: the when step mounts its own
		// StepNotice, so no region survives the step change to carry the announcement - which
		// is exactly why the mount-empty deferral has to exist.
		expect( region ).toHaveAttribute( 'role', 'status' );
		const insertedAt = records.findIndex( ( record ) =>
			Array.from( record.addedNodes ).some(
				( node ) =>
					node === region || ( node instanceof Element && node.contains( region ) )
			)
		);
		const textAt = records.findIndex(
			( record ) =>
				( 'characterData' === record.type && region.contains( record.target ) ) ||
				( 'childList' === record.type &&
					record.target === region &&
					Array.from( record.addedNodes ).some(
						( node ) => Node.TEXT_NODE === node.nodeType
					) )
		);
		expect( insertedAt ).toBeGreaterThanOrEqual( 0 );
		expect( textAt ).toBeGreaterThan( insertedAt );
	} );
} );
