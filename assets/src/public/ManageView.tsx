/**
 * The magic-link manage journey (P5 plan, Task 15; design spec 5.4): show the booking, offer
 * cancel (policy-checked, explicitly confirmed) and reschedule (the Task 13 pickers inside a real
 * dialog). The credentials arrive as `data-` attributes from the magic link and live in the
 * config prop and React state ALONE - never storage, a cookie, a URL this code constructs, an
 * attribute, or a log line. `useBooking` is suspended until both are present, so a mount with a
 * stripped token renders its neutral panel without a doomed request.
 *
 * Load-bearing choices, each pinned by `__tests__/manageView.test.tsx`:
 *
 * - The neutral "no longer valid" panel collapses what the server deliberately keeps distinct:
 *   `GET /bookings/{uuid}` answers a wrong token 403 and an unknown uuid 404 on purpose (the
 *   `reschedule()` docblock in `BookingsController.php` owns why that asymmetry exists), and this
 *   client renders every read failure that could ANSWER that existence question - any 4xx -
 *   byte-identically: no booking fields, no server wording. A read failure that answers nothing
 *   about the uuid (a transport drop, a 5xx - the request never reached an authorization
 *   decision) is NOT a verdict on the link: it renders a could-not-load state with a retry
 *   control instead, because the dead-link sentence would be a lie to a guest on a flaky
 *   connection. `answersOracle` owns the split. Distinguish by WHICH REQUEST failed, never by
 *   status code alone: `window_closed` on a cancel is a 403 too, and a refused MUTATION keeps
 *   the booking on screen with the server's `data.detail` verbatim. Getting this backwards
 *   either leaks the booking-existence oracle or tells a visitor with a perfectly good link
 *   that it is invalid.
 * - What the booking IS comes from its STATUS, exhaustively: every non-confirmed status states
 *   itself in one sentence through the always-mounted status region, and both controls derive
 *   from the server's own guards - cancellable mirrors `BookingStatus::canTransitionTo(
 *   Cancelled )`, reschedulable mirrors `RescheduleBooking::assertReschedulable()` (confirmed,
 *   or a held status with a live `hold_expires_at`). An expired or completed booking never
 *   dangles buttons guaranteed to fail; an awaiting-approval one is never dressed up as
 *   confirmed (Task 14's R3, on the way back in). The details render for EVERY status - the
 *   guest came to see their booking; only the controls and the sentence change.
 * - Nothing renders optimistically: the view reads the booking QUERY alone, and the mutations
 *   only invalidate it (`useCancel`/`useReschedule` already invalidate `['booking', uuid]` and
 *   `['availability']` on success - repeating that here would fork one policy into two places).
 *   A refused reschedule therefore leaves the original time standing by construction.
 * - Service names come from the `useServices()` catalog - the public booking read is a bare
 *   projection with no joined names (`types.ts` on `BookingItem`). A row never blanks for it: the
 *   time always renders; the name is omitted until the catalog exists and falls back to the
 *   ChainBuilder/ReviewStep placeholder for a service that has left the catalog.
 * - Cancel is destructive, so it takes TWO clicks: an in-page confirmation with real buttons -
 *   never `window.confirm()`, which is unstyleable, untestable and thread-blocking. A magic link
 *   can be opened by anyone holding it, including by accident from an email preview.
 * - Both mutations carry a SYNCHRONOUS `useRef` latch set before `mutate()`: the observer
 *   notifies through a deferred scheduler, so the rendered `disabled` only stops a second click
 *   when some other render lands in the gap - and a second `mutate()` detaches the first
 *   mutation's callbacks. A double-clicked cancel would report a committed cancellation as a
 *   failure.
 * - Every request the visitor can walk away from mid-flight is ticket-gated (the Task 14
 *   `recoveryTicket` lesson): closing the dialog or leaving the cancel confirmation bumps a
 *   ticket AND frees the latch (a hung request must not deaden the next journey), and a
 *   resolution whose ticket is stale renders nothing - it belongs to a journey that no longer
 *   exists. The hook-level invalidation still lands, so a stale SUCCESS still refreshes the
 *   booking to whatever the server now says.
 * - Focus follows the VISITOR, never the network: the first render is driven entirely by the
 *   booking answer and moves nothing (`focus()` scrolls by default). A visitor-caused view
 *   change - swapping the action row for the cancel confirmation and back - lands focus on the
 *   view container (`tabIndex={-1}`), because their click just unmounted the control it was on.
 *   The dialog focuses itself on open; every close path restores focus to the trigger from here,
 *   the one owner that covers Escape, the close control and a successful move alike.
 * - The dialog's `aria-modal` must hold by MOUSE as well as by Tab: while it is open, both
 *   background action buttons are `disabled`, so nothing behind the dialog can be clicked or
 *   focused (jsdom and real browsers alike refuse focus on a disabled button; `inert` is not
 *   honoured by this jsdom, so it cannot be the pinned mechanism). Disabling rather than
 *   unmounting keeps `rescheduleTrigger` attached to the SAME element across the dialog's
 *   lifetime - and it forces the focus restore into an effect: at the moment a close path runs,
 *   the trigger is still disabled (React has not re-rendered), so a synchronous `.focus()`
 *   would silently no-op and drop focus on `<body>`. Close paths arm `restoreToTrigger`; the
 *   `[dialogOpen]` effect consumes it one render later, when the trigger is live again.
 * - The document title and the page's caching are PHP's (`ManageRoute::render()` sends core's
 *   nocache headers and applies `wp_robots_sensitive_page`); nothing here touches either.
 *
 * `newManageClient` and the notice mechanism mirror BookingFlow's `newWidgetClient` and
 * `StepNotice`/`noticeOf` - deliberately unexported there and out of this task's territory, so
 * the manage bundle carries its own copies (the notice pair lives in `RescheduleDialog.tsx`,
 * the `formatPrice`-in-ServicePicker precedent).
 */
import { useEffect, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, utcToSite } from '../shared';
import { widgetBootstrap } from './api/client';
import { useBooking, useCancel, useReschedule, useServices } from './api/queries';
import type { Booking, ChainItem, PublicService, RescheduleTarget } from './api/types';
import {
	NoticeRegion,
	ProgressStatus,
	RescheduleDialog,
	noticeOf,
} from './components/RescheduleDialog';
import type { ManageNotice } from './components/RescheduleDialog';
import { formatPrice } from './components/ServicePicker';
import type { WidgetConfig } from './index';

/**
 * The service name for a row, or null while the catalog has not answered (the name is then
 * omitted rather than guessed - the row's time still renders either way). A service that has
 * left the catalog since the sale gets the ReviewStep placeholder, never a blank.
 */
function serviceName( catalog: PublicService[] | undefined, serviceId: number ): string | null {
	if ( undefined === catalog ) {
		return null;
	}
	return (
		catalog.find( ( service ) => service.id === serviceId )?.name ??
		__( 'Unavailable service', 'reservant' )
	);
}

/**
 * The statuses that hold capacity behind a TTL - `BookingStatus::heldStatuses()`, mirrored.
 * Both affordance rules below branch on STATUS alone, never on `requires_approval` or
 * `payment_mode` (Task 14's R3), and mirror the server guard that would refuse the mutation -
 * a rendered control must never be one guaranteed to fail (the OccurrencePicker sold-out
 * precedent: suppress the doomed act, keep everything visible).
 */
const HELD_STATUSES: ReadonlyArray< Booking[ 'status' ] > = [
	'pending',
	'awaiting_approval',
	'awaiting_payment',
];

/** `CancelBooking` refuses unless `canTransitionTo( Cancelled )`: the held three plus confirmed. */
function isCancellable( status: Booking[ 'status' ] ): boolean {
	return 'confirmed' === status || HELD_STATUSES.includes( status );
}

/** UTC now in the wire's own `Y-m-d H:i:s`, for the same string comparison the server makes. */
function utcNowStamp(): string {
	return new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' );
}

/**
 * `RescheduleBooking::assertReschedulable()`, mirrored: `confirmed`, or a held status whose
 * `hold_expires_at` is non-null and still ahead of UTC now - a lapsed or absent hold has
 * nothing to release, so a move is a guaranteed `not_reschedulable`. Computed from the DTO's
 * own `hold_expires_at`, never approximated through `hold_class`.
 */
function isReschedulable( booking: Booking ): boolean {
	if ( 'confirmed' === booking.status ) {
		return true;
	}
	return (
		HELD_STATUSES.includes( booking.status ) &&
		null !== booking.hold_expires_at &&
		booking.hold_expires_at > utcNowStamp()
	);
}

/**
 * One sentence per status for the always-mounted status region - null only for `confirmed`,
 * the one state that needs no explanation. The switch is exhaustive over the DTO union ON
 * PURPOSE, with no default: the explicit `string | null` return type makes the compiler refuse
 * a tenth status (a non-exhaustive switch no longer returns on every path) instead of letting
 * it fall through to silence or to somebody else's sentence.
 */
function statusSentence( status: Booking[ 'status' ] ): string | null {
	switch ( status ) {
		case 'confirmed':
			return null;
		case 'pending':
			return __( 'This booking has not been confirmed yet.', 'reservant' );
		case 'awaiting_approval':
			return __(
				'This booking is waiting for our approval. We will email you as soon as it is decided.',
				'reservant'
			);
		case 'awaiting_payment':
			return __( 'This booking is approved and waiting for payment.', 'reservant' );
		case 'completed':
			return __( 'This booking has already taken place.', 'reservant' );
		case 'no_show':
			return __( 'This booking was recorded as missed.', 'reservant' );
		case 'cancelled':
			return __( 'This booking has been cancelled.', 'reservant' );
		case 'rejected':
			return __( 'This booking request was declined.', 'reservant' );
		case 'expired':
			return __( 'This booking request expired before it was completed.', 'reservant' );
	}
}

/**
 * Could this read failure ANSWER the question the neutral panel exists to hide - whether the
 * uuid exists? Only an authorization-shaped 4xx can: the server looked and answered. A transport
 * failure or a 5xx never reached an authorization decision, so it says nothing about the link
 * and must not be dressed up as a verdict on it.
 */
function answersOracle( error: Error ): boolean {
	return error instanceof ApiError && error.status >= 400 && error.status < 500;
}

/**
 * One identical panel for every failed read that could answer the oracle question
 * (`answersOracle`) - wrong token, unknown uuid, expired link - with no server wording read,
 * so a 403 and a 404 cannot be told apart from the outside (the docblock header owns why).
 * No booking fields, no controls.
 */
function NeutralPanel(): JSX.Element {
	return (
		<div className="reservant-manage__invalid">
			<p>{ __( 'This link is no longer valid.', 'reservant' ) }</p>
			<p>{ __( 'If you need to change your booking, please contact us directly.', 'reservant' ) }</p>
		</div>
	);
}

/**
 * The could-not-load state for a read failure that answered nothing (see `answersOracle`): a
 * plain sentence and a control that refetches - never the dead-link verdict, and never the
 * transport error's own jargon. The alert may mount with its text (the NoticeRegion convention:
 * an alert announces on appearance).
 */
function RetryPanel( { busy, onRetry }: { busy: boolean; onRetry: () => void } ): JSX.Element {
	return (
		<div className="reservant-manage__unreachable">
			<p className="reservant-manage__unreachable-text" role="alert">
				{ __(
					'We could not load your booking. Please check your connection and try again.',
					'reservant'
				) }
			</p>
			<button
				type="button"
				className="reservant-manage__retry"
				disabled={ busy }
				onClick={ onRetry }
			>
				{ __( 'Try again', 'reservant' ) }
			</button>
		</div>
	);
}

/** What a manage mount can render right now - `readView` is the one owner of the decision. */
type ReadView =
	| { kind: 'neutral' }
	| { kind: 'retry' }
	| { kind: 'loading' }
	| { kind: 'ready'; data: Booking };

/**
 * Classify the read: stripped credentials are the neutral panel with no request ever made
 * (`useBooking` stays suspended); present data always renders, whatever a background refetch
 * just did (a failed refresh must not nuke a booking already on screen); and a failed read
 * with nothing to show splits on the oracle rule (`answersOracle`) - the header owns why.
 */
function readView(
	uuid: string,
	token: string,
	data: Booking | undefined,
	error: Error | null
): ReadView {
	if ( '' === uuid || '' === token ) {
		return { kind: 'neutral' };
	}
	if ( undefined !== data ) {
		return { kind: 'ready', data };
	}
	if ( null === error ) {
		return { kind: 'loading' };
	}
	return answersOracle( error ) ? { kind: 'neutral' } : { kind: 'retry' };
}

/** The three not-ready read states, rendered. One home, so `Manage` itself stays readable. */
function ReadFallback( {
	view,
	busy,
	onRetry,
}: {
	view: Exclude< ReadView, { kind: 'ready' } >;
	busy: boolean;
	onRetry: () => void;
} ): JSX.Element {
	if ( 'neutral' === view.kind ) {
		return <NeutralPanel />;
	}
	if ( 'retry' === view.kind ) {
		return <RetryPanel busy={ busy } onRetry={ onRetry } />;
	}
	// The polite region rides on the loading message itself and dies with it - never on the
	// panel, which is about to hold buttons (the index.tsx convention). ProgressStatus mounts
	// it empty and fills it one effect later, so the load actually announces.
	return (
		<ProgressStatus
			text={ __( 'Loading your booking...', 'reservant' ) }
			className="reservant-manage__loading"
		/>
	);
}

/**
 * The booking's segments on the site clock: the time ALWAYS renders; the name is omitted while
 * the catalog loads and placeholdered when the service has left it (`serviceName` owns that).
 */
function BookingItems( {
	items,
	catalog,
	timezone,
}: {
	items: Booking[ 'items' ];
	catalog: PublicService[] | undefined;
	timezone: string;
} ): JSX.Element {
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
	return (
		<ul className="reservant-manage__items">
			{ items.map( ( item ) => {
				const name = serviceName( catalog, item.service_id );
				return (
					<li key={ item.id } className="reservant-manage__item">
						{ null !== name && (
							<span className="reservant-manage__service">{ name }</span>
						) }
						<span className="reservant-manage__when">
							{ formatter.format( utcToSite( item.start_utc, timezone ) ) }
						</span>
						{ item.seats > 1 && (
							<span className="reservant-manage__seats">
								{ sprintf(
									/* translators: %d: number of places reserved. */
									_n( '%d place', '%d places', item.seats, 'reservant' ),
									item.seats
								) }
							</span>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

interface ManageActionsProps {
	/** The status-derived affordances (the header's status rule) - a missing one hides its control. */
	cancellable: boolean;
	reschedulable: boolean;
	confirmingCancel: boolean;
	/** Both action buttons are disabled while the dialog is open - the by-mouse half of its modality. */
	dialogOpen: boolean;
	cancelling: boolean;
	/** The dialog's focus-restore target - the parent owns the restore (see its close paths). */
	triggerRef: { current: HTMLButtonElement | null };
	onOpenDialog: () => void;
	onStartCancel: () => void;
	onConfirmCancel: () => void;
	onKeepBooking: () => void;
}

/**
 * The controls a booking's status actually supports: the action row while nothing was asked,
 * the two-click cancel confirmation once it was. Renders nothing when the status supports
 * neither act - the sentence in the status region says why.
 */
function ManageActions( {
	cancellable,
	reschedulable,
	confirmingCancel,
	dialogOpen,
	cancelling,
	triggerRef,
	onOpenDialog,
	onStartCancel,
	onConfirmCancel,
	onKeepBooking,
}: ManageActionsProps ): JSX.Element | null {
	if ( ! cancellable && ! reschedulable ) {
		return null;
	}
	if ( ! confirmingCancel ) {
		return (
			<div className="reservant-manage__actions">
				{ reschedulable && (
					<button
						ref={ triggerRef }
						type="button"
						className="reservant-manage__reschedule"
						disabled={ dialogOpen }
						onClick={ onOpenDialog }
					>
						{ __( 'Pick a new time', 'reservant' ) }
					</button>
				) }
				{ cancellable && (
					<button
						type="button"
						className="reservant-manage__cancel"
						disabled={ dialogOpen }
						onClick={ onStartCancel }
					>
						{ __( 'Cancel booking', 'reservant' ) }
					</button>
				) }
			</div>
		);
	}
	if ( ! cancellable ) {
		return null;
	}
	return (
		<div className="reservant-manage__confirm-cancel">
			<p>{ __( 'Cancel this booking? This cannot be undone.', 'reservant' ) }</p>
			<button
				type="button"
				className="reservant-manage__confirm-cancel-yes"
				disabled={ cancelling }
				onClick={ onConfirmCancel }
			>
				{ __( 'Yes, cancel it', 'reservant' ) }
			</button>
			<button
				type="button"
				className="reservant-manage__confirm-cancel-no"
				onClick={ onKeepBooking }
			>
				{ __( 'Keep this booking', 'reservant' ) }
			</button>
			{ cancelling && (
				// The destructive action's own progress feedback: `disabled` alone is
				// silence to a screen reader while the request flies.
				<ProgressStatus
					text={ __( 'Cancelling your booking...', 'reservant' ) }
					className="reservant-manage__cancelling"
				/>
			) }
		</div>
	);
}

/**
 * The abandonment-ticket discipline, in one place: run `settle` only while `ticket` is still
 * the ref's current value. A stale resolution belongs to a journey the visitor already left -
 * it renders nothing, announces nothing, moves no focus, and must not touch a latch the next
 * journey now owns (only the hook-level invalidation may act on it).
 */
function ifCurrent( ticketRef: { current: number }, ticket: number, settle: () => void ): void {
	if ( ticket === ticketRef.current ) {
		settle();
	}
}

function Manage( { config }: { config: WidgetConfig } ): JSX.Element {
	const { timezone } = widgetBootstrap();
	const uuid = config.uuid ?? '';
	const token = config.token ?? '';
	const booking = useBooking( uuid, token );
	const { data: catalog } = useServices();
	const cancel = useCancel();
	const reschedule = useReschedule();

	const [ confirmingCancel, setConfirmingCancel ] = useState( false );
	const [ dialogOpen, setDialogOpen ] = useState( false );
	/** Details-level notices: a cancel refusal, or the moved confirmation. */
	const [ actionNotice, setActionNotice ] = useState< ManageNotice | null >( null );
	/** The dialog's own notice: a reschedule refusal, shown where the visitor is. */
	const [ dialogNotice, setDialogNotice ] = useState< ManageNotice | null >( null );
	// The VISIBLE in-flight affordances - deliberately NOT the mutations' own `isPending`, which
	// describes the observer's latest mutation rather than this journey: an abandoned flight
	// (dialog closed mid-move, confirmation left mid-cancel) keeps `isPending` true until it
	// settles, which would paint the NEXT journey busy - or worse, never unbusy under a hung
	// request. These follow the same ticket discipline as the latches below.
	const [ moving, setMoving ] = useState( false );
	const [ cancelling, setCancelling ] = useState( false );

	// The synchronous double-submit guards and the abandonment tickets (see the header). The
	// latches are set before mutate() and freed either where a CURRENT resolution settles or
	// where the visitor abandons the flight - a stale resolution must not touch a latch the next
	// journey now owns.
	const cancelBusy = useRef( false );
	const moveBusy = useRef( false );
	const cancelTicket = useRef( 0 );
	const dialogTicket = useRef( 0 );

	// Focus follows the visitor (the header): armed only by their own view changes, consumed by
	// the effect below; a render caused by a network answer arms nothing and moves nothing.
	const containerRef = useRef< HTMLDivElement | null >( null );
	const visitorMoved = useRef( false );
	useEffect( () => {
		if ( ! visitorMoved.current ) {
			return;
		}
		visitorMoved.current = false;
		containerRef.current?.focus();
	}, [ confirmingCancel ] );

	/** Every close path restores focus here - the trigger outlives the dialog on all of them. */
	const rescheduleTrigger = useRef< HTMLButtonElement | null >( null );
	// Armed by every dialog-close path, consumed one render later: the trigger is `disabled`
	// while the dialog is open (the header's by-mouse modality), so a synchronous `.focus()`
	// inside the close path would no-op against a still-disabled element. The effect runs after
	// the re-render that re-enables it.
	const restoreToTrigger = useRef( false );
	useEffect( () => {
		if ( dialogOpen || ! restoreToTrigger.current ) {
			return;
		}
		restoreToTrigger.current = false;
		rescheduleTrigger.current?.focus();
	}, [ dialogOpen ] );

	const startCancel = (): void => {
		visitorMoved.current = true;
		setActionNotice( null );
		setConfirmingCancel( true );
	};

	const keepBooking = (): void => {
		// Abandons any in-flight cancel: bump the ticket so its late verdict renders nothing,
		// free the latch (and its visible twin) so a hung request cannot deaden the next attempt.
		// NOTE the asymmetry with `closeDialog`, which looks like this function's mirror image:
		// THIS latch release is load-bearing - `startCancel` resets nothing on the way back in,
		// so removing it deadens the Cancel button forever after one hung request - while
		// closeDialog's is defence in depth only (`openDialog`, the sole route back into the
		// dialog, re-arms). Do not "simplify" the pair by this resemblance.
		cancelTicket.current += 1;
		cancelBusy.current = false;
		setCancelling( false );
		visitorMoved.current = true;
		setConfirmingCancel( false );
	};

	const confirmCancel = (): void => {
		if ( cancelBusy.current ) {
			return;
		}
		cancelBusy.current = true;
		setCancelling( true );
		setActionNotice( null );
		const ticket = cancelTicket.current;
		cancel.mutate(
			{ uuid, token },
			{
				onSuccess: () =>
					ifCurrent( cancelTicket, ticket, () => {
						cancelBusy.current = false;
						setCancelling( false );
						// The visitor's own confirmation just resolved; the confirmation row
						// unmounts, so the change lands focus like every visitor-caused one.
						// What renders next is the QUERY's refreshed answer, never an
						// assumption.
						visitorMoved.current = true;
						setConfirmingCancel( false );
					} ),
				onError: ( error: Error ) =>
					ifCurrent( cancelTicket, ticket, () => {
						cancelBusy.current = false;
						setCancelling( false );
						// The server's own sentence, verbatim, beside a booking that stays
						// fully on screen - a refused cancel is an answer about THIS request,
						// never about the link (the header's which-request-failed rule).
						setActionNotice( noticeOf( error ) );
					} ),
			}
		);
	};

	const openDialog = (): void => {
		dialogTicket.current += 1;
		moveBusy.current = false;
		setMoving( false );
		setDialogNotice( null );
		setDialogOpen( true );
	};

	const closeDialog = (): void => {
		// The latch reset here is defence in depth, NOT load-bearing like `keepBooking`'s
		// (whose comment owns the asymmetry): `openDialog` is the only route back into the
		// dialog and resets `moveBusy`/`moving` itself, so this reset cannot change any
		// reachable behaviour - it just keeps the invariant local.
		dialogTicket.current += 1;
		moveBusy.current = false;
		setMoving( false );
		setDialogNotice( null );
		restoreToTrigger.current = true;
		setDialogOpen( false );
	};

	const handlePick = ( target: RescheduleTarget ): void => {
		if ( moveBusy.current ) {
			return;
		}
		moveBusy.current = true;
		setMoving( true );
		setDialogNotice( null );
		const ticket = dialogTicket.current;
		reschedule.mutate(
			{ uuid, token, ...target },
			{
				onSuccess: () =>
					ifCurrent( dialogTicket, ticket, () => {
						moveBusy.current = false;
						setMoving( false );
						dialogTicket.current += 1;
						restoreToTrigger.current = true;
						setDialogOpen( false );
						// Announced in the details region the dialog leaves behind; the NEW
						// time itself arrives through the invalidated booking query - the
						// server's answer, never this client's assumption.
						setActionNotice( {
							text: __( 'Your booking has been moved.', 'reservant' ),
							alert: false,
						} );
					} ),
				onError: ( error: Error ) =>
					ifCurrent( dialogTicket, ticket, () => {
						moveBusy.current = false;
						setMoving( false );
						// Shown inside the dialog, where the visitor is - who keeps every way
						// forward: the same slot, another one, or the close control.
						setDialogNotice( noticeOf( error ) );
					} ),
			}
		);
	};

	// A failed READ with nothing to show is classified by `readView` (the header's oracle rule
	// applies ONLY to reads - a refused mutation keeps the booking on screen).
	const view = readView( uuid, token, booking.data, booking.error );
	if ( 'ready' !== view.kind ) {
		return (
			<div className="reservant-manage">
				<ReadFallback
					view={ view }
					busy={ booking.isFetching }
					onRetry={ () => void booking.refetch() }
				/>
			</div>
		);
	}

	const data = view.data;
	// Both affordances derive from the STATUS, mirroring the server's own guards (the helpers
	// above own the mapping) - eight of nine statuses used to render as a live booking, with
	// controls guaranteed to fail. The details render for every status regardless: the guest
	// came to see their booking; only the controls and the sentence change.
	const cancellable = isCancellable( data.status );
	const reschedulable = isReschedulable( data );
	const sentence = statusSentence( data.status );
	// Rendered through the always-mounted status region: for a booking that ARRIVES in a
	// non-confirmed state it is simply the state of things; after a cancel resolves, the
	// refreshed query lands the cancelled sentence in a region that already existed, which is
	// exactly what announces it.
	const statusNotice: ManageNotice | null =
		null === sentence ? null : { text: sentence, alert: false };
	// The availability request for a move, staff pinned AS SOLD from the booking's own items -
	// `RescheduleBooking::planAppointment()` keeps the sold resource, so any-staff starts could
	// offer times the move cannot land on. An event item's null resource_id means "any", which
	// is equally exact for occurrences.
	const chainItems: ChainItem[] = data.items.map( ( item ) => ( {
		service_id: item.service_id,
		resource_id: item.resource_id,
	} ) );

	return (
		<div ref={ containerRef } tabIndex={ -1 } className="reservant-manage">
			<h2 className="reservant-manage__title">{ __( 'Your booking', 'reservant' ) }</h2>
			<BookingItems items={ data.items } catalog={ catalog } timezone={ timezone } />
			<p className="reservant-manage__total">
				{ sprintf(
					/* translators: %s: the formatted total price. */
					__( 'Total: %s', 'reservant' ),
					formatPrice( data.total_minor, data.currency )
				) }
			</p>
			<p className="reservant-manage__customer">
				{ data.customer_name } ({ data.customer_email })
			</p>
			<NoticeRegion notice={ statusNotice } />
			<NoticeRegion notice={ actionNotice } />
			<ManageActions
				cancellable={ cancellable }
				reschedulable={ reschedulable }
				confirmingCancel={ confirmingCancel }
				dialogOpen={ dialogOpen }
				cancelling={ cancelling }
				triggerRef={ rescheduleTrigger }
				onOpenDialog={ openDialog }
				onStartCancel={ startCancel }
				onConfirmCancel={ confirmCancel }
				onKeepBooking={ keepBooking }
			/>
			{ reschedulable && dialogOpen && (
				<RescheduleDialog
					items={ chainItems }
					notice={ dialogNotice }
					busy={ moving }
					onPick={ handlePick }
					onClose={ closeDialog }
				/>
			) }
		</div>
	);
}

/**
 * The manage bundle's QueryClient, the BookingFlow twin (unexported there, out of this task's
 * territory): created ONCE per mount, `retry` a predicate that never retries a 4xx - a 403/404
 * read is the neutral panel, immediately, and a 429 told us to stop. Network and 5xx keep the
 * default three attempts; mutations keep TanStack's no-retry default (a blind second cancel or
 * move could act twice). `staleTime` stays default - Task 16 owns that tuning.
 */
function newManageClient(): QueryClient {
	return new QueryClient( {
		defaultOptions: {
			queries: {
				retry: ( failureCount: number, error: Error ): boolean => {
					if ( error instanceof ApiError && error.status >= 400 && error.status < 500 ) {
						return false;
					}
					return failureCount < 3;
				},
			},
		},
	} );
}

export function ManageView( { config }: { config: WidgetConfig } ): JSX.Element {
	const [ client ] = useState( newManageClient );
	return (
		<QueryClientProvider client={ client }>
			<Manage config={ config } />
		</QueryClientProvider>
	);
}
