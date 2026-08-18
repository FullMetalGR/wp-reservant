/**
 * Task 15 pins (P5 plan): the magic-link manage journey - read, cancel, reschedule.
 *
 * The suite runs under modern jest fake timers with a FIXED system time and a
 * Pacific/Kiritimati (UTC+14, no DST) bootstrap - the Task 13/14 portability zone - so every
 * site-clock string asserted here differs from the runner's own wall clock and a component that
 * read the browser clock could not pass by accident. Expected Intl strings are BUILT with the
 * components' own Intl options over the expected site wall-clock digits, never written as glyph
 * literals, and exact-text lookups use the identity normalizer (the servicePicker precedent) so
 * locale no-break-space padding cannot desync the two sides of a comparison.
 *
 * The non-negotiable properties, each pinned below:
 *
 * - The neutral panel collapses what the server deliberately keeps distinct: `GET /bookings/{uuid}`
 *   answers a wrong token 403 and an unknown uuid 404 on purpose (the reschedule() docblock in
 *   BookingsController.php owns why), and the CLIENT must render those - and any other failed
 *   read - byte-identically, with no booking fields and no server detail. A failed cancel or
 *   reschedule MUTATION is the opposite case: same status codes possible (`window_closed` is a
 *   403 too), but the booking stays on screen with the server's own sentence - which request
 *   failed decides, never the status code.
 * - No optimistic updates: the screen shows only what the server has confirmed, so a reschedule
 *   409 leaves the ORIGINAL time standing (the engine's atomic release-and-re-hold guarantee,
 *   mirrored) and a success shows the NEW time only once the server said so.
 * - Both mutations carry a synchronous `useRef` latch: the mutation observer notifies through a
 *   deferred scheduler, so `disabled` lands one macrotask late and a second `mutate()` detaches
 *   the first mutation's callbacks. The double-click pins dispatch two clicks with NO await
 *   between them inside one act(), and count requests on the wire.
 * - Focus follows the VISITOR, never the network: the first render is driven entirely by a
 *   network answer and must move nothing; swapping to the cancel confirmation is the visitor's
 *   own act and lands focus on the view container. The reschedule dialog is a real dialog -
 *   named, focused on open, Escape to close, focus restored to the trigger, focus contained.
 * - A resolution that lands after the visitor left the dialog applies to a journey that no
 *   longer exists: it must render nothing, and it must not leave the latch behind for the next
 *   journey (the Task 14 hung-recovery lesson).
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ManageView } from '../ManageView';
import type { WidgetConfig } from '../index';
import type { Booking, PublicService, WidgetBootstrap } from '../api/types';

const SITE_TZ = 'Pacific/Kiritimati';
const UUID = '22222222-2222-4222-8222-222222222222';
const TOKEN = 'manage-secret';
/** The frozen "now": 2026-06-01 00:00:00 UTC, which is 14:00 on June 1st at the site (UTC+14). */
const NOW_UTC = '2026-06-01T00:00:00Z';

const NEUTRAL = 'This link is no longer valid.';
const WINDOW_SENTENCE = 'It is too late to change this booking. Please contact us.';
const CONFLICT_SENTENCE = 'That time was just taken. Please pick another.';

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
		currency: 'EUR',
		requires_approval: false,
		resources: [],
	},
];

type ManagedItem = Booking[ 'items' ][ number ];

/** 20:00 UTC on June 2nd is 10:00 on June 3rd at the site (UTC+14). */
const ITEM_A: ManagedItem = {
	id: 11,
	sort: 0,
	service_id: 3,
	resource_id: 7,
	occurrence_id: null,
	start_utc: '2026-06-02 20:00:00',
	end_utc: '2026-06-02 20:45:00',
	block_start_utc: '2026-06-02 20:00:00',
	block_end_utc: '2026-06-02 20:45:00',
	processing_ends_utc: null,
	seats: 1,
	seat_claim: null,
	price_minor: 4500,
};

const ITEM_B: ManagedItem = {
	...ITEM_A,
	id: 12,
	sort: 1,
	service_id: 5,
	resource_id: 8,
	start_utc: '2026-06-02 20:45:00',
	end_utc: '2026-06-02 21:45:00',
	block_start_utc: '2026-06-02 20:45:00',
	block_end_utc: '2026-06-02 21:45:00',
	price_minor: 6000,
};

function chainBooking( overrides: Partial< Booking > = {} ): Booking {
	return {
		uuid: UUID,
		status: 'confirmed',
		hold_class: null,
		hold_expires_at: null,
		customer_name: 'Ada',
		customer_email: 'ada@example.com',
		customer_phone: '',
		total_minor: 10500,
		currency: 'EUR',
		payment_mode: 'onsite',
		requires_approval: false,
		created_at: '2026-05-20 10:00:00',
		updated_at: '2026-05-20 10:00:00',
		items: [ ITEM_A, ITEM_B ],
		...overrides,
	};
}

/** The chain after the move the suite books: 01:00/01:45 UTC on June 3rd - 15:00/15:45 site. */
function movedChainBooking(): Booking {
	return chainBooking( {
		items: [
			{
				...ITEM_A,
				start_utc: '2026-06-03 01:00:00',
				end_utc: '2026-06-03 01:45:00',
				block_start_utc: '2026-06-03 01:00:00',
				block_end_utc: '2026-06-03 01:45:00',
			},
			{
				...ITEM_B,
				start_utc: '2026-06-03 01:45:00',
				end_utc: '2026-06-03 02:45:00',
				block_start_utc: '2026-06-03 01:45:00',
				block_end_utc: '2026-06-03 02:45:00',
			},
		],
	} );
}

/** 07:00 UTC on June 5th is 21:00 the same site day at UTC+14. */
const EVENT_ITEM: ManagedItem = {
	...ITEM_A,
	id: 31,
	service_id: 9,
	resource_id: null,
	occurrence_id: 21,
	start_utc: '2026-06-05 07:00:00',
	end_utc: '2026-06-05 09:00:00',
	block_start_utc: '2026-06-05 07:00:00',
	block_end_utc: '2026-06-05 09:00:00',
	seats: 2,
	price_minor: 5000,
};

function eventBooking( overrides: Partial< Booking > = {} ): Booking {
	return chainBooking( {
		total_minor: 10000,
		items: [ EVENT_ITEM ],
		...overrides,
	} );
}

const CHAIN_AVAILABILITY = {
	granularity_min: 5,
	starts: [ { utc: '2026-06-03 01:00:00', local: '2026-06-03T15:00:00+14:00' } ],
};

const EVENT_AVAILABILITY = {
	occurrences: [
		{ id: 21, start_utc: '2026-06-05 07:00:00', end_utc: '2026-06-05 09:00:00', remaining: 8 },
		{ id: 22, start_utc: '2026-06-06 07:00:00', end_utc: '2026-06-06 09:00:00', remaining: 5 },
	],
};

/** The manage rows' own Intl options over the expected SITE wall-clock digits. */
const ROW_TIME = new Intl.DateTimeFormat( undefined, {
	weekday: 'short',
	month: 'short',
	day: 'numeric',
	hour: 'numeric',
	minute: '2-digit',
} );
const FIRST_TIME = ROW_TIME.format( new Date( 2026, 5, 3, 10, 0 ) );
const SECOND_TIME = ROW_TIME.format( new Date( 2026, 5, 3, 10, 45 ) );
const MOVED_TIME = ROW_TIME.format( new Date( 2026, 5, 3, 15, 0 ) );
const EVENT_MOVED_TIME = ROW_TIME.format( new Date( 2026, 5, 6, 21, 0 ) );

/** DateStrip's own Intl options over the site dates the suite clicks. */
const STRIP_DAY = new Intl.DateTimeFormat( undefined, {
	weekday: 'short',
	month: 'short',
	day: 'numeric',
} );
const TODAY_LABEL = STRIP_DAY.format( new Date( 2026, 5, 1 ) );
const DAY3_LABEL = STRIP_DAY.format( new Date( 2026, 5, 3 ) );

/** SlotGrid's own Intl options over the offered start's site wall clock (15:00). */
const NEW_SLOT_LABEL = new Intl.DateTimeFormat( undefined, {
	hour: 'numeric',
	minute: '2-digit',
} ).format( new Date( 2026, 5, 3, 15, 0 ) );

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

function jsonResponse( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

/** The route guard's wrong-token answer - no reservant detail on purpose. */
function forbiddenResponse(): Response {
	return jsonResponse(
		{ code: 'reservant_forbidden', message: 'forbidden', data: { status: 403 } },
		403
	);
}

/** `Errors::notFound()` - carries a worded detail that must NEVER reach the neutral panel. */
function notFoundResponse(): Response {
	return jsonResponse(
		{
			code: 'reservant_not_found',
			message: 'not_found',
			data: { status: 404, detail: 'That booking is no longer available.' },
		},
		404
	);
}

/** A lifecycle refusal exactly as `Errors::failure()` wires it - the sentence in data.detail. */
function refusalResponse( reason: string, status: number, detail: string ): Response {
	return jsonResponse(
		{ code: `reservant_${ reason }`, message: reason, data: { status, detail } },
		status
	);
}

/** The engine's real 409 envelope, sentence included. */
function conflictResponse(): Response {
	return jsonResponse(
		{
			code: 'reservant_conflict',
			message: 'overlap',
			data: { status: 409, segment: 0, detail: CONFLICT_SENTENCE },
		},
		409
	);
}

interface ManageHandlers {
	booking?: () => Response | Promise< Response >;
	services?: () => Response | Promise< Response >;
	availability?: () => unknown;
	cancel?: () => Response | Promise< Response >;
	reschedule?: () => Response | Promise< Response >;
}

type FetchCall = [ unknown, RequestInit | undefined ];

function isMockResponse( value: unknown ): value is Response {
	return (
		null !== value &&
		'object' === typeof value &&
		'function' === typeof ( value as { text?: unknown } ).text
	);
}

function installFetch( handlers: ManageHandlers ): jest.Mock {
	const fetchMock = jest.fn( async ( input: unknown, init?: RequestInit ): Promise< Response > => {
		const url = String( input );
		const method = ( init?.method ?? 'GET' ).toUpperCase();
		if ( 'POST' === method && url.includes( '/cancel' ) && handlers.cancel ) {
			return handlers.cancel();
		}
		if ( 'POST' === method && url.includes( '/reschedule' ) && handlers.reschedule ) {
			return handlers.reschedule();
		}
		if ( 'GET' === method && url.includes( '/services' ) ) {
			return handlers.services ? handlers.services() : jsonResponse( SERVICES );
		}
		if ( 'GET' === method && url.includes( '/availability' ) && handlers.availability ) {
			const answer = handlers.availability();
			return isMockResponse( answer ) ? answer : jsonResponse( answer );
		}
		if ( 'GET' === method && url.includes( '/bookings/' ) && handlers.booking ) {
			return handlers.booking();
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

function renderManage( overrides: Partial< WidgetConfig > = {} ): ReturnType< typeof render > {
	return render(
		<ManageView
			config={ {
				mode: 'manage',
				serviceId: null,
				resourceId: null,
				uuid: UUID,
				token: TOKEN,
				...overrides,
			} }
		/>
	);
}

/** Open the dialog and book the June 3rd 15:00 site slot - the shared reschedule preamble. */
async function pickNewSlot(): Promise< void > {
	fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );
	fireEvent.click( await screen.findByText( DAY3_LABEL, EXACT_TEXT ) );
	fireEvent.click( await screen.findByText( NEW_SLOT_LABEL, EXACT_TEXT ) );
}

beforeEach( () => {
	jest.useFakeTimers( { now: new Date( NOW_UTC ) } );
	window.reservantWidget = bootstrapFixture();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'ManageView', () => {
	it( 'renders the booking with its segments on the site clock for a valid token', async () => {
		installFetch( { booking: () => jsonResponse( chainBooking() ) } );
		renderManage();

		expect( await screen.findByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Massage' ) ).toBeInTheDocument();
		// The site clock, not the runner's: 20:00/20:45 UTC render as June 3rd 10:00/10:45.
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( SECOND_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		const total = `Total: ${ new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: 'EUR',
		} ).format( 105 ) }`;
		expect( screen.getByText( total, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'renders the neutral panel and no booking details for a bad token', async () => {
		installFetch( { booking: () => forbiddenResponse() } );
		renderManage();

		expect( await screen.findByText( NEUTRAL ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Haircut' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Ada', { exact: false } ) ).not.toBeInTheDocument();
		expect( screen.queryByText( FIRST_TIME, EXACT_TEXT ) ).not.toBeInTheDocument();
		// Nothing to act on either - no cancel, no reschedule, no dialog.
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'answers a wrong-token 403 and an unknown-uuid 404 byte-identically', async () => {
		// The server keeps them distinct ON PURPOSE (BookingsController's reschedule() docblock);
		// the client must not - or the manage page becomes the booking-existence oracle the
		// ManageRoute went to such lengths not to be.
		installFetch( { booking: () => forbiddenResponse() } );
		const first = renderManage();
		await screen.findByText( NEUTRAL );
		const forbiddenHtml = first.container.innerHTML;
		first.unmount();

		installFetch( { booking: () => notFoundResponse() } );
		const second = renderManage();
		await screen.findByText( NEUTRAL );
		expect( second.container.innerHTML ).toBe( forbiddenHtml );
		// The 404's own worded detail never leaks into the panel.
		expect( second.container.innerHTML ).not.toContain( 'That booking is no longer available.' );
	} );

	it( 'renders the neutral panel without a doomed request when the token is stripped', async () => {
		const fetchMock = installFetch( {} );
		renderManage( { token: null } );

		expect( await screen.findByText( NEUTRAL ) ).toBeInTheDocument();
		await act( async () => {} );
		expect( callsTo( fetchMock, '/bookings/' ) ).toHaveLength( 0 );
	} );

	it( 'shows the engine refusal and keeps the booking when cancel is refused outside the window', async () => {
		// window_closed arrives as a 403 - the SAME status a bad token gets. Which request failed
		// decides: a refused MUTATION keeps the booking on screen with the server's sentence,
		// never the neutral panel.
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			cancel: () => refusalResponse( 'window_closed', 403, WINDOW_SENTENCE ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );

		const sentence = await screen.findByText( WINDOW_SENTENCE );
		// A 4xx is a worded answer, politely - and in a region that existed before the text.
		expect( sentence ).toHaveAttribute( 'role', 'status' );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		// The booking is still fully on screen...
		expect( screen.getByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByText( NEUTRAL ) ).not.toBeInTheDocument();
		// ...and the refusal is not a dead end: the retry is still there.
		expect( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) ).toBeInTheDocument();
	} );

	it( 'cancels only after an explicit confirmation and reflects the server state', async () => {
		let bookingNow = chainBooking();
		const fetchMock = installFetch( {
			booking: () => jsonResponse( bookingNow ),
			cancel: () => {
				bookingNow = chainBooking( { status: 'cancelled' } );
				return jsonResponse( bookingNow );
			},
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		// The first click only asked - nothing reached the wire yet.
		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 0 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );

		expect( await screen.findByText( 'This booking has been cancelled.' ) ).toBeInTheDocument();
		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 1 );
		// A cancelled booking offers no further actions.
		expect( screen.queryByRole( 'button', { name: 'Cancel booking' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Pick a new time' } ) ).not.toBeInTheDocument();
	} );

	it( 'does not fire the cancel when the visitor keeps the booking', async () => {
		const fetchMock = installFetch( { booking: () => jsonResponse( chainBooking() ) } );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Keep this booking' } ) );

		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 0 );
		expect( screen.getByRole( 'button', { name: 'Cancel booking' } ) ).toBeInTheDocument();
	} );

	it( 'sends exactly one cancel when the confirmation is clicked twice in one beat', async () => {
		// Two clicks with NO await between them, inside one act(): isPending reaches `disabled`
		// one macrotask late, and a second mutate() detaches the first mutation's callbacks -
		// only the synchronous latch holds this line, and the wire count is the proof.
		let bookingNow = chainBooking();
		const fetchMock = installFetch( {
			booking: () => jsonResponse( bookingNow ),
			cancel: () => {
				bookingNow = chainBooking( { status: 'cancelled' } );
				return jsonResponse( bookingNow );
			},
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		const confirm = screen.getByRole( 'button', { name: 'Yes, cancel it' } );
		act( () => {
			confirm.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			confirm.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		} );

		expect( await screen.findByText( 'This booking has been cancelled.' ) ).toBeInTheDocument();
		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 1 );
	} );

	it( 'reschedules to the new time the server confirmed', async () => {
		let bookingNow = chainBooking();
		const fetchMock = installFetch( {
			booking: () => jsonResponse( bookingNow ),
			availability: () => CHAIN_AVAILABILITY,
			reschedule: () => {
				bookingNow = movedChainBooking();
				return jsonResponse( bookingNow );
			},
		} );
		renderManage();
		await screen.findByText( FIRST_TIME, EXACT_TEXT );

		await pickNewSlot();

		// The NEW time renders only because the server confirmed it - and the old one is gone.
		expect( await screen.findByText( MOVED_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByText( FIRST_TIME, EXACT_TEXT ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( await screen.findByText( 'Your booking has been moved.' ) ).toBeInTheDocument();
		// Exactly one target field - the chosen utc round-tripped untouched, no occurrence_id.
		expect( bodyOf( callsTo( fetchMock, '/reschedule', 'POST' )[ 0 ] ) ).toEqual( {
			token: TOKEN,
			start_utc: '2026-06-03 01:00:00',
		} );
		await act( async () => {} );
	} );

	it( 'leaves the original time on screen when the reschedule 409s', async () => {
		// The engine guarantee from Task 4, mirrored: a refused move rolls back whole, so the UI
		// must not show a move that did not happen.
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
			reschedule: () => conflictResponse(),
		} );
		renderManage();
		await screen.findByText( FIRST_TIME, EXACT_TEXT );

		await pickNewSlot();

		const sentence = await screen.findByText( CONFLICT_SENTENCE );
		expect( sentence ).toHaveAttribute( 'role', 'status' );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		// The original time still stands...
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByText( MOVED_TIME, EXACT_TEXT ) ).not.toBeInTheDocument();
		// ...and every way forward is real: retry the slot, pick another, or leave the dialog.
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( screen.getByText( NEW_SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Keep the current time' } ) ).toBeInTheDocument();
	} );

	it( 'sends exactly one reschedule when the slot is clicked twice in one beat', async () => {
		let bookingNow = chainBooking();
		const fetchMock = installFetch( {
			booking: () => jsonResponse( bookingNow ),
			availability: () => CHAIN_AVAILABILITY,
			reschedule: () => {
				bookingNow = movedChainBooking();
				return jsonResponse( bookingNow );
			},
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );
		fireEvent.click( await screen.findByText( DAY3_LABEL, EXACT_TEXT ) );
		const slot = await screen.findByText( NEW_SLOT_LABEL, EXACT_TEXT );
		act( () => {
			slot.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			slot.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		} );

		expect( await screen.findByText( MOVED_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( callsTo( fetchMock, '/reschedule', 'POST' ) ).toHaveLength( 1 );
		await act( async () => {} );
	} );

	it( 'asks availability for the staff the booking sold, per segment', async () => {
		// RescheduleBooking::planAppointment() keeps the pinned resource "as sold rather than
		// re-picked" - so offering starts computed for ANY staff would offer times the move
		// cannot land on. The request must pin each segment's resource from the booking itself.
		const fetchMock = installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );
		await screen.findByText( DAY3_LABEL, EXACT_TEXT );

		const [ availUrl ] = callsTo( fetchMock, '/availability' )[ 0 ] ?? [];
		const items = new URLSearchParams( String( availUrl ).split( '?' )[ 1 ] ).get( 'items' );
		expect( JSON.parse( String( items ) ) ).toEqual( [
			{ service_id: 3, resource_id: 7 },
			{ service_id: 5, resource_id: 8 },
		] );
	} );

	it( 'moves an event booking to another occurrence, decided from its own items', async () => {
		let bookingNow = eventBooking();
		const fetchMock = installFetch( {
			booking: () => jsonResponse( bookingNow ),
			availability: () => EVENT_AVAILABILITY,
			reschedule: () => {
				bookingNow = eventBooking( {
					items: [
						{
							...EVENT_ITEM,
							occurrence_id: 22,
							start_utc: '2026-06-06 07:00:00',
							end_utc: '2026-06-06 09:00:00',
							block_start_utc: '2026-06-06 07:00:00',
							block_end_utc: '2026-06-06 09:00:00',
						},
					],
				} );
				return jsonResponse( bookingNow );
			},
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: /5 places left/ } ) );

		await waitFor( () =>
			expect( callsTo( fetchMock, '/reschedule', 'POST' ) ).toHaveLength( 1 )
		);
		// Exactly one target field - occurrence_id, never start_utc, for an event booking.
		expect( bodyOf( callsTo( fetchMock, '/reschedule', 'POST' )[ 0 ] ) ).toEqual( {
			token: TOKEN,
			occurrence_id: 22,
		} );
		expect( await screen.findByText( EVENT_MOVED_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		await act( async () => {} );
	} );

	it( 'is a real dialog: named, focused on open, Escape closes and restores focus', async () => {
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
		} );
		renderManage();

		const trigger = await screen.findByRole( 'button', { name: 'Pick a new time' } );
		fireEvent.click( trigger );

		const dialog = await screen.findByRole( 'dialog', { name: 'Pick a new time' } );
		expect( dialog ).toHaveFocus();

		fireEvent.keyDown( dialog, { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( trigger ).toHaveFocus();
	} );

	it( 'contains focus while the dialog is open', async () => {
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );
		await screen.findByRole( 'dialog', { name: 'Pick a new time' } );
		const firstDay = await screen.findByText( TODAY_LABEL, EXACT_TEXT );
		const close = screen.getByRole( 'button', { name: 'Keep the current time' } );

		close.focus();
		fireEvent.keyDown( close, { key: 'Tab' } );
		expect( firstDay ).toHaveFocus();

		fireEvent.keyDown( firstDay, { key: 'Tab', shiftKey: true } );
		expect( close ).toHaveFocus();
	} );

	it( 'leaves focus alone when the booking answer renders the view', async () => {
		// The whole first render is driven by a network answer - it must not scroll the page or
		// steal focus from whatever the visitor is doing.
		installFetch( { booking: () => jsonResponse( chainBooking() ) } );
		renderManage();

		expect( await screen.findByText( 'Haircut' ) ).toBeInTheDocument();
		await act( async () => {} );
		expect( document.body ).toHaveFocus();
	} );

	it( 'lands focus on the view when the visitor swaps to the cancel confirmation', async () => {
		// The visitor's own click unmounts the button they pressed - focus would die on <body>
		// and a screen reader would hear nothing. Their change lands on the view container.
		installFetch( { booking: () => jsonResponse( chainBooking() ) } );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		const container = document.querySelector( '.reservant-manage' );
		expect( container ).toHaveFocus();
		expect( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Keep this booking' } ) );
		expect( container ).toHaveFocus();
		expect( screen.getByRole( 'button', { name: 'Cancel booking' } ) ).toBeInTheDocument();
	} );

	it( 'ignores a reschedule verdict that lands after the visitor left the dialog', async () => {
		// The mutation is a fetch with no timeout, and Escape stays reachable while it hangs -
		// so a resolution can land on a journey that no longer exists. It must render nothing,
		// and it must not clobber the latch the NEXT journey now owns (the Task 14 lesson).
		let resolveMove: ( response: Response ) => void = () => {
			throw new Error( 'reschedule resolver was never captured' );
		};
		const fetchMock = installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
			reschedule: () =>
				new Promise< Response >( ( resolve ) => {
					resolveMove = resolve;
				} ),
		} );
		renderManage();

		await pickNewSlot();
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();

		// The abandoned flight's refusal resolves with nobody in the dialog - and with no second
		// mutate() yet, so its callbacks are still attached and only the ticket guard stands
		// between this verdict and the screen. It must render nothing: no sentence, no dialog.
		await act( async () => {
			resolveMove( conflictResponse() );
		} );
		expect( screen.queryByText( CONFLICT_SENTENCE ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();

		// And the stale path did not reclaim the latch closing the dialog already freed: the
		// next journey fires its own move.
		fireEvent.click( screen.getByRole( 'button', { name: 'Pick a new time' } ) );
		fireEvent.click( await screen.findByText( DAY3_LABEL, EXACT_TEXT ) );
		fireEvent.click( await screen.findByText( NEW_SLOT_LABEL, EXACT_TEXT ) );
		await act( async () => {} );
		expect( callsTo( fetchMock, '/reschedule', 'POST' ) ).toHaveLength( 2 );
		expect( screen.queryByText( CONFLICT_SENTENCE ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the row whole while the catalog is still loading', async () => {
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			services: () => new Promise< Response >( () => {} ),
		} );
		renderManage();

		// The catalog never answers, but the segment row still shows its time.
		expect( await screen.findByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( SECOND_TIME, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'keeps the row whole when a service has left the catalog', async () => {
		installFetch( {
			booking: () =>
				jsonResponse( chainBooking( { items: [ { ...ITEM_A, service_id: 99 } ] } ) ),
		} );
		renderManage();

		expect( await screen.findByText( 'Unavailable service' ) ).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
	} );
} );
