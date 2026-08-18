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
 *   BookingsController.php owns why), and the CLIENT must render every read failure that could
 *   answer that existence question - any 4xx - byte-identically, with no booking fields and no
 *   server detail. A read failure that answers NOTHING about the uuid (a transport drop, a 5xx)
 *   must never claim the link is invalid: it gets a could-not-load state with a real retry. A
 *   failed cancel or reschedule MUTATION is different again: same status codes possible
 *   (`window_closed` is a 403 too), but the booking stays on screen with the server's own
 *   sentence - which request failed decides, never the status code alone.
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
 * - A resolution that lands after the visitor abandoned its journey - left the dialog, kept
 *   the booking - applies to a journey that no longer exists: it must render nothing, announce
 *   nothing and move no focus, and it must not leave the latch behind for the next journey
 *   (the Task 14 hung-recovery lesson). Only the hook-level invalidation may act on a stale
 *   success: the refreshed booking is the server's truth however the visitor got there. On the
 *   cancel path `keepBooking` is the ONLY latch release (`startCancel` resets nothing), so a
 *   withdrawn-then-retried cancel must reach the wire a second time.
 * - A reschedule slot is filed under its SITE calendar day (`startsOnDay`), pinned with a start
 *   that straddles the site-day boundary so a browser-day filing cannot pass by accident.
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

/**
 * A start chosen to STRADDLE the site-day boundary: 12:00 UTC on June 3rd is 02:00 on June 4th
 * at the site (UTC+14), while the runner's own Europe/Athens clock (UTC+3 in June) - and a bare
 * `new Date()` parse of the wire string - keep it on June 3rd. That gap is the whole point of
 * the fixture: a start whose site day and browser day agree would file identically under both
 * clocks and make the site-day pin below vacuous. Only an instant in the 10:00-23:59 UTC band
 * (past site midnight, before runner midnight) forces `startsOnDay` to consult the site zone.
 */
const STRADDLE_AVAILABILITY = {
	granularity_min: 5,
	starts: [ { utc: '2026-06-03 12:00:00', local: '2026-06-04T02:00:00+14:00' } ],
};
const DAY4_LABEL = STRIP_DAY.format( new Date( 2026, 5, 4 ) );
const STRADDLE_SLOT_LABEL = new Intl.DateTimeFormat( undefined, {
	hour: 'numeric',
	minute: '2-digit',
} ).format( new Date( 2026, 5, 4, 2, 0 ) );

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

function jsonResponse( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: () => Promise.resolve( JSON.stringify( body ) ),
	} as Response;
}

/** `Routes::forbidden()` verbatim - its worded detail must never reach the neutral panel. */
function forbiddenResponse(): Response {
	return jsonResponse(
		{
			code: 'reservant_forbidden',
			message: 'forbidden',
			data: { status: 403, detail: 'That link is not valid for this booking.' },
		},
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

/**
 * Run the booking read all the way to its settled failure: `newManageClient` keeps three retries
 * for anything that is not a 4xx, each behind its own backoff timer, so a single flush leaves the
 * query mid-retry rather than settled. Each round runs the pending timer and drains the
 * microtasks its rejection queues.
 */
async function settleReadRetries(): Promise< void > {
	for ( let round = 0; round < 8; round++ ) {
		await act( async () => {
			jest.runOnlyPendingTimers();
		} );
	}
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
		// Neither side's worded detail ever leaks into the panel - the 403 carries one too
		// (`Routes::forbidden()`), so suppression must be demonstrated on both.
		expect( forbiddenHtml ).not.toContain( 'That link is not valid for this booking.' );
		expect( second.container.innerHTML ).not.toContain( 'That booking is no longer available.' );
	} );

	it( 'renders the neutral panel without a doomed request when the token is stripped', async () => {
		const fetchMock = installFetch( {} );
		renderManage( { token: null } );

		expect( await screen.findByText( NEUTRAL ) ).toBeInTheDocument();
		await act( async () => {} );
		expect( callsTo( fetchMock, '/bookings/' ) ).toHaveLength( 0 );
	} );

	it( 'offers a retry instead of the dead-link panel when the connection drops', async () => {
		// A transport failure answers NOTHING about whether the uuid exists - the request never
		// reached an authorization decision - so the oracle-collapsing neutral panel would tell
		// a guest on a flaky connection that their perfectly good link is dead, with no way to
		// try again. This state must never claim the link is invalid and must always refetch.
		const fetchMock = installFetch( {
			booking: () => Promise.reject( new TypeError( 'Failed to fetch' ) ),
		} );
		renderManage();
		await settleReadRetries();

		const retry = screen.getByRole( 'button', { name: 'Try again' } );
		expect(
			screen.getByText( 'We could not load your booking. Please check your connection and try again.' )
		).toBeInTheDocument();
		// Not the dead-link lie, no booking fields, and no browser jargon.
		expect( screen.queryByText( NEUTRAL ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Haircut' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( FIRST_TIME, EXACT_TEXT ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Failed to fetch' ) ).not.toBeInTheDocument();

		// The control is a real retry: it puts the read back on the wire.
		const readsBefore = callsTo( fetchMock, '/bookings/' ).length;
		fireEvent.click( retry );
		await act( async () => {} );
		expect( callsTo( fetchMock, '/bookings/' ).length ).toBeGreaterThan( readsBefore );
	} );

	it( 'offers a retry instead of the dead-link panel when the read fails with a 500', async () => {
		// A 5xx is the server failing, not the server answering the oracle question - only an
		// authorization-shaped 4xx may collapse into the neutral panel.
		let failing = true;
		installFetch( {
			booking: () =>
				failing
					? jsonResponse(
							{
								code: 'internal_server_error',
								message: 'Internal server error',
								data: { status: 500 },
							},
							500
					  )
					: jsonResponse( chainBooking() ),
		} );
		renderManage();
		await settleReadRetries();

		expect( screen.queryByText( NEUTRAL ) ).not.toBeInTheDocument();
		expect( screen.queryByText( FIRST_TIME, EXACT_TEXT ) ).not.toBeInTheDocument();

		// The server recovers; the retry control brings the booking up without a reload.
		failing = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( await screen.findByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
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

	it( 'gives the destructive action its own progress feedback', async () => {
		// A screen-reader guest confirming a cancel used to get only `disabled` - silence -
		// while the request flew. The busy line is a polite status (the SlotGrid empty-answer
		// precedent), mounted empty and filled one effect later so it actually announces (the
		// NoticeRegion mechanism).
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			cancel: () => new Promise< Response >( () => {} ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );
		await act( async () => {} );

		expect( screen.getByText( 'Cancelling your booking...' ) ).toHaveAttribute(
			'role',
			'status'
		);
	} );

	it( 'never shows browser jargon when a cancel dies on the network', async () => {
		// A dead connection rejects the fetch with TypeError('Failed to fetch') - developer
		// wording no guest should be handed. It is a genuine failure (role="alert"), worded
		// for a human, and the booking stays fully on screen with every way forward intact.
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			cancel: () => Promise.reject( new TypeError( 'Failed to fetch' ) ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			'We could not reach the server. Please check your connection and try again.'
		);
		expect( screen.queryByText( 'Failed to fetch' ) ).not.toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByText( NEUTRAL ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) ).toBeInTheDocument();
	} );

	it( 'ignores a cancel refusal that lands after the visitor kept the booking', async () => {
		// The visitor confirms a cancel, changes their mind while it is in flight, and clicks
		// "Keep this booking" - which bumps the abandonment ticket. When the request then
		// settles with a refusal, that verdict belongs to a journey the visitor already
		// withdrew: painting "too late to cancel" for a cancel they no longer want would read
		// as the booking being stuck.
		let resolveCancel: ( response: Response ) => void = () => {
			throw new Error( 'cancel resolver was never captured' );
		};
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			cancel: () =>
				new Promise< Response >( ( resolve ) => {
					resolveCancel = resolve;
				} ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );
		// The mutationFn runs a microtask after mutate() - flush so the resolver is captured.
		await act( async () => {} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Keep this booking' } ) );

		await act( async () => {
			resolveCancel( refusalResponse( 'window_closed', 403, WINDOW_SENTENCE ) );
		} );

		// The stale refusal renders nothing, and the view stays fully usable.
		expect( screen.queryByText( WINDOW_SENTENCE ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel booking' } ) ).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'ignores a cancel success that lands after the visitor kept the booking', async () => {
		// The withdrawn cancel COMMITTED server-side, and its stale success settles while the
		// visitor is already inside a NEW confirmation with focus parked on its confirm
		// button. Only the hook-level invalidation may act on the stale verdict (the booking
		// refetch is what tells the visitor the truth); the component half must not close a
		// confirmation the visitor just opened, and must not steal their focus. The refetch is
		// held pending here on purpose: the window between the stale settling and the refresh
		// is exactly where an unguarded success path would act ahead of the server.
		let resolveCancel: ( response: Response ) => void = () => {
			throw new Error( 'cancel resolver was never captured' );
		};
		let bookingReads = 0;
		installFetch( {
			booking: () => {
				bookingReads += 1;
				return 1 === bookingReads
					? jsonResponse( chainBooking() )
					: new Promise< Response >( () => {} );
			},
			cancel: () =>
				new Promise< Response >( ( resolve ) => {
					resolveCancel = resolve;
				} ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );
		// The mutationFn runs a microtask after mutate() - flush so the resolver is captured.
		await act( async () => {} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Keep this booking' } ) );

		// A fresh journey: the visitor re-opens the confirmation and focuses its button.
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel booking' } ) );
		const confirm = screen.getByRole( 'button', { name: 'Yes, cancel it' } );
		act( () => {
			confirm.focus();
		} );

		await act( async () => {
			resolveCancel( jsonResponse( chainBooking( { status: 'cancelled' } ) ) );
		} );

		// The new confirmation still stands, focus exactly where the visitor put it.
		expect( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) ).toBeInTheDocument();
		expect( confirm ).toHaveFocus();
	} );

	it( 'frees the cancel latch when the visitor keeps the booking mid-flight', async () => {
		// `startCancel` deliberately resets nothing on the way back in, so `keepBooking`'s
		// latch release is the ONLY thing between a hung cancel and a permanently dead Cancel
		// button - unlike the dialog path, where `openDialog` re-arms on the sole route back.
		// Withdraw a hanging cancel, then cancel again: the second confirmation must reach the
		// wire instead of dying on the first journey's latch.
		const fetchMock = installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			cancel: () => new Promise< Response >( () => {} ),
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );
		// The mutationFn runs a microtask after mutate() - flush before counting the wire.
		await act( async () => {} );
		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 1 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Keep this booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel booking' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) );
		await act( async () => {} );

		expect( callsTo( fetchMock, '/cancel', 'POST' ) ).toHaveLength( 2 );
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

	it( 'files an offered slot under its SITE day, not the browser day', async () => {
		// `startsOnDay` is the whole reason a slot appears under the right date, and the salon
		// day is the SITE zone's, never the visitor's browser's. The straddle fixture (see its
		// docblock) is what gives this test teeth: 12:00 UTC on June 3rd is already 02:00 on
		// June 4th at the site, while a browser-day filing - on this Europe/Athens runner or
		// via a bare `new Date()` parse - would leave it on June 3rd.
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => STRADDLE_AVAILABILITY,
		} );
		renderManage();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Pick a new time' } ) );

		// On the site's June 4th the 02:00 slot is offered...
		fireEvent.click( await screen.findByText( DAY4_LABEL, EXACT_TEXT ) );
		expect( await screen.findByText( STRADDLE_SLOT_LABEL, EXACT_TEXT ) ).toBeInTheDocument();

		// ...and on June 3rd - the slot's UTC and runner-local calendar day - it is not.
		fireEvent.click( screen.getByText( DAY3_LABEL, EXACT_TEXT ) );
		expect(
			screen.queryByText( STRADDLE_SLOT_LABEL, EXACT_TEXT )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'No times are available on this day. Please pick another date.' )
		).toBeInTheDocument();
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

	it.each< [ Booking[ 'status' ], string ] >( [
		[ 'completed', 'This booking has already taken place.' ],
		[ 'no_show', 'This booking was recorded as missed.' ],
		[ 'rejected', 'This booking request was declined.' ],
		[ 'expired', 'This booking request expired before it was completed.' ],
	] )( 'says what a %s booking is and offers no doomed controls', async ( status, sentence ) => {
		// The server refuses both mutations for these statuses (`CancelBooking`'s
		// canTransitionTo guard, `RescheduleBooking::assertReschedulable()`) - rendering the
		// buttons anyway promises actions guaranteed to fail. And the guest still came to see
		// their booking: the details render for EVERY status; only the controls and the
		// sentence change.
		installFetch( { booking: () => jsonResponse( chainBooking( { status } ) ) } );
		renderManage();

		expect( await screen.findByText( sentence ) ).toBeInTheDocument();
		expect( screen.getByText( 'Haircut' ) ).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Pick a new time' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Cancel booking' } ) ).not.toBeInTheDocument();
	} );

	it( 'tells an awaiting-approval guest their booking is not settled, keeping both controls', async () => {
		// Task 14's R3 forbids flattening the held statuses into "confirmed" on the way in;
		// the page the guest returns to must not flatten them either. A live approval hold is
		// both cancellable (canTransitionTo) and reschedulable (assertReschedulable's
		// held-with-live-deadline arm), so the sentence changes and both controls stay.
		installFetch( {
			booking: () =>
				jsonResponse(
					chainBooking( {
						status: 'awaiting_approval',
						hold_class: 'approval',
						hold_expires_at: '2026-06-02 00:00:00',
					} )
				),
		} );
		renderManage();

		expect(
			await screen.findByText(
				'This booking is waiting for our approval. We will email you as soon as it is decided.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( FIRST_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Pick a new time' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel booking' } ) ).toBeInTheDocument();
	} );

	it.each< [ string | null ] >( [ [ '2026-05-31 23:59:59' ], [ null ] ] )(
		'offers cancel but never reschedule for a pending booking with a dead hold (expiry %p)',
		async ( holdExpiresAt ) => {
			// assertReschedulable wants a LIVE deadline on a held status - a lapsed or absent
			// hold has nothing to release, so "Pick a new time" would be a guaranteed
			// not_reschedulable. Cancel stays: canTransitionTo allows Pending -> Cancelled
			// regardless of the hold clock.
			installFetch( {
				booking: () =>
					jsonResponse(
						chainBooking( {
							status: 'pending',
							hold_class: 'checkout',
							hold_expires_at: holdExpiresAt,
						} )
					),
			} );
			renderManage();

			expect(
				await screen.findByText( 'This booking has not been confirmed yet.' )
			).toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'Cancel booking' } ) ).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Pick a new time' } )
			).not.toBeInTheDocument();
		}
	);

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

	it( 'blocks the background controls while the dialog is open', async () => {
		// R-J's Tab containment is pinned above - but a modal must hold by MOUSE too: nothing
		// behind the open dialog may be clicked or focused. Unguarded, "Cancel booking" sat
		// clickable behind the modal, arming the destructive confirmation UNDER the dialog and
		// yanking focus out of it with two competing flows live.
		installFetch( {
			booking: () => jsonResponse( chainBooking() ),
			availability: () => CHAIN_AVAILABILITY,
		} );
		renderManage();

		const trigger = await screen.findByRole( 'button', { name: 'Pick a new time' } );
		const cancelButton = screen.getByRole( 'button', { name: 'Cancel booking' } );
		fireEvent.click( trigger );
		const dialog = await screen.findByRole( 'dialog', { name: 'Pick a new time' } );

		// Clicking the background cancel affordance does nothing - and prove it with a full
		// flush, since a stolen focus or a swapped row could land a beat late.
		fireEvent.click( cancelButton );
		await act( async () => {} );
		act( () => {
			jest.runOnlyPendingTimers();
		} );
		await act( async () => {} );
		expect( screen.queryByRole( 'button', { name: 'Yes, cancel it' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'dialog', { name: 'Pick a new time' } ) ).toBeInTheDocument();
		expect( dialog ).toHaveFocus();

		// Nor can the background take focus while the dialog is open.
		act( () => {
			cancelButton.focus();
		} );
		expect( cancelButton ).not.toHaveFocus();
		act( () => {
			trigger.focus();
		} );
		expect( trigger ).not.toHaveFocus();

		// Closing re-enables the background: focus comes home to the trigger, and the cancel
		// affordance works again.
		fireEvent.keyDown( dialog, { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( trigger ).toHaveFocus();
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel booking' } ) );
		expect( screen.getByRole( 'button', { name: 'Yes, cancel it' } ) ).toBeInTheDocument();
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
		// Drain every scheduler the answer could have parked a focus() on before asserting -
		// an under-flushed probe here would false-negative a LATE-landing steal (the Task 14
		// auto-skip lesson).
		await act( async () => {} );
		act( () => {
			jest.runOnlyPendingTimers();
		} );
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
		// so a resolution can land on a journey that no longer exists. Where that must show is
		// chosen with care: `onSuccess` closes the dialog anyway, so "the dialog stays closed"
		// would hold with or without the ticket guard - what the guard ALONE prevents is the
		// stale verdict SPEAKING. The visitor here has already re-opened the dialog for a
		// fresh journey when the abandoned flight's refusal settles; unguarded, that refusal
		// would surface inside a journey that never made a request.
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

		// A fresh journey: the dialog is open again - no pick made yet - when the abandoned
		// flight's refusal finally lands. Its callbacks are still attached (no second
		// mutate() yet), so only the ticket guard stands between this verdict and the screen.
		fireEvent.click( screen.getByRole( 'button', { name: 'Pick a new time' } ) );
		await screen.findByRole( 'dialog', { name: 'Pick a new time' } );

		await act( async () => {
			resolveMove( conflictResponse() );
		} );
		// The stale refusal renders nothing - not in the new dialog, not anywhere.
		expect( screen.queryByText( CONFLICT_SENTENCE ) ).not.toBeInTheDocument();

		// And the stale path did not reclaim the latch closing the dialog already freed: the
		// new journey fires its own move.
		fireEvent.click( await screen.findByText( DAY3_LABEL, EXACT_TEXT ) );
		fireEvent.click( await screen.findByText( NEW_SLOT_LABEL, EXACT_TEXT ) );
		await act( async () => {} );
		expect( callsTo( fetchMock, '/reschedule', 'POST' ) ).toHaveLength( 2 );
		expect( screen.queryByText( CONFLICT_SENTENCE ) ).not.toBeInTheDocument();
	} );

	it( 'ignores a reschedule success that lands after the visitor left the dialog', async () => {
		// The abandoned move COMMITTED. The hook-level invalidation may refresh the booking -
		// the moved time is the server's truth and must show - but the component half stays
		// silent: no "has been moved" announcement for a journey the visitor walked out of,
		// and no focus steal seconds after they moved on to something else.
		let resolveMove: ( response: Response ) => void = () => {
			throw new Error( 'reschedule resolver was never captured' );
		};
		let bookingNow = chainBooking();
		installFetch( {
			booking: () => jsonResponse( bookingNow ),
			availability: () => CHAIN_AVAILABILITY,
			reschedule: () =>
				new Promise< Response >( ( resolve ) => {
					resolveMove = resolve;
				} ),
		} );
		renderManage();

		await pickNewSlot();
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );

		// The visitor has moved on: focus sits on the cancel affordance, not the trigger.
		const cancelButton = screen.getByRole( 'button', { name: 'Cancel booking' } );
		act( () => {
			cancelButton.focus();
		} );

		bookingNow = movedChainBooking();
		await act( async () => {
			resolveMove( jsonResponse( movedChainBooking() ) );
		} );

		// The refreshed query shows the server's new reality...
		expect( await screen.findByText( MOVED_TIME, EXACT_TEXT ) ).toBeInTheDocument();
		// ...but the abandoned journey announces nothing and steals nothing.
		expect( screen.queryByText( 'Your booking has been moved.' ) ).not.toBeInTheDocument();
		expect( cancelButton ).toHaveFocus();
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
		// And the name is OMITTED rather than guessed (R-D): no service-name span exists at
		// all until the catalog answers - any guess, including the left-the-catalog
		// placeholder, would have to render one.
		expect( document.querySelector( '.reservant-manage__service' ) ).toBeNull();
		expect( screen.queryByText( 'Unavailable service' ) ).not.toBeInTheDocument();
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
