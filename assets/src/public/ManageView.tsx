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
 *   client renders those - and every other failed READ - byte-identically, one code path that
 *   never reads the error, no booking fields, no server detail. Distinguish by WHICH REQUEST
 *   failed, never by status code: `window_closed` on a cancel is a 403 too, and a refused
 *   MUTATION keeps the booking on screen with the server's `data.detail` verbatim. Getting this
 *   backwards either leaks the booking-existence oracle or tells a visitor with a perfectly good
 *   link that it is invalid.
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
import type { ChainItem, PublicService, RescheduleTarget } from './api/types';
import { NoticeRegion, RescheduleDialog, noticeOf } from './components/RescheduleDialog';
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
 * One identical panel for EVERY failed read - wrong token, unknown uuid, expired link, whatever
 * else - with no error read and no server wording, so a 403 and a 404 cannot be told apart from
 * the outside (the docblock header owns why). No booking fields, no controls.
 */
function NeutralPanel(): JSX.Element {
	return (
		<div className="reservant-manage__invalid">
			<p>{ __( 'This link is no longer valid.', 'reservant' ) }</p>
			<p>{ __( 'If you need to change your booking, please contact us directly.', 'reservant' ) }</p>
		</div>
	);
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

	const startCancel = (): void => {
		visitorMoved.current = true;
		setActionNotice( null );
		setConfirmingCancel( true );
	};

	const keepBooking = (): void => {
		// Abandons any in-flight cancel: bump the ticket so its late verdict renders nothing,
		// free the latch (and its visible twin) so a hung request cannot deaden the next attempt.
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
				onSuccess: () => {
					if ( ticket !== cancelTicket.current ) {
						return;
					}
					cancelBusy.current = false;
					setCancelling( false );
					// The visitor's own confirmation just resolved; the confirmation row
					// unmounts, so the change lands focus like every visitor-caused one. What
					// renders next is the QUERY's refreshed answer, never an assumption.
					visitorMoved.current = true;
					setConfirmingCancel( false );
				},
				onError: ( error: Error ) => {
					if ( ticket !== cancelTicket.current ) {
						return;
					}
					cancelBusy.current = false;
					setCancelling( false );
					// The server's own sentence, verbatim, beside a booking that stays fully on
					// screen - a refused cancel is an answer about THIS request, never about the
					// link (the header's which-request-failed rule).
					setActionNotice( noticeOf( error ) );
				},
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
		dialogTicket.current += 1;
		moveBusy.current = false;
		setMoving( false );
		setDialogNotice( null );
		setDialogOpen( false );
		rescheduleTrigger.current?.focus();
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
				onSuccess: () => {
					if ( ticket !== dialogTicket.current ) {
						return;
					}
					moveBusy.current = false;
					setMoving( false );
					dialogTicket.current += 1;
					setDialogOpen( false );
					rescheduleTrigger.current?.focus();
					// Announced in the details region the dialog leaves behind; the NEW time
					// itself arrives through the invalidated booking query - the server's
					// answer, never this client's assumption.
					setActionNotice( {
						text: __( 'Your booking has been moved.', 'reservant' ),
						alert: false,
					} );
				},
				onError: ( error: Error ) => {
					if ( ticket !== dialogTicket.current ) {
						return;
					}
					moveBusy.current = false;
					setMoving( false );
					// Shown inside the dialog, where the visitor is - who keeps every way
					// forward: the same slot, another one, or the close control.
					setDialogNotice( noticeOf( error ) );
				},
			}
		);
	};

	// A failed READ with nothing to show is the neutral panel - and ONLY a read: note the
	// data-undefined guard, which keeps a failed background refetch from nuking a booking that
	// is already on screen. Stripped credentials land here too, without any request having been
	// made (`useBooking` is suspended until both exist).
	if ( '' === uuid || '' === token || ( undefined === booking.data && null !== booking.error ) ) {
		return (
			<div className="reservant-manage">
				<NeutralPanel />
			</div>
		);
	}

	if ( undefined === booking.data ) {
		// The polite region rides on the loading message itself and dies with it - never on the
		// panel, which is about to hold buttons (the index.tsx convention).
		return (
			<div className="reservant-manage">
				<p className="reservant-manage__loading" role="status" aria-live="polite">
					{ __( 'Loading your booking...', 'reservant' ) }
				</p>
			</div>
		);
	}

	const data = booking.data;
	const cancelled = 'cancelled' === data.status;
	// Rendered through the always-mounted status region: for a booking that ARRIVES cancelled it
	// is simply the state of things; after a cancel resolves, the refreshed query lands this text
	// in a region that already existed, which is exactly what announces it.
	const statusNotice: ManageNotice | null = cancelled
		? { text: __( 'This booking has been cancelled.', 'reservant' ), alert: false }
		: null;
	// The availability request for a move, staff pinned AS SOLD from the booking's own items -
	// `RescheduleBooking::planAppointment()` keeps the sold resource, so any-staff starts could
	// offer times the move cannot land on. An event item's null resource_id means "any", which
	// is equally exact for occurrences.
	const chainItems: ChainItem[] = data.items.map( ( item ) => ( {
		service_id: item.service_id,
		resource_id: item.resource_id,
	} ) );
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );

	return (
		<div ref={ containerRef } tabIndex={ -1 } className="reservant-manage">
			<h2 className="reservant-manage__title">{ __( 'Your booking', 'reservant' ) }</h2>
			<ul className="reservant-manage__items">
				{ data.items.map( ( item ) => {
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
			{ ! cancelled && ! confirmingCancel && (
				<div className="reservant-manage__actions">
					<button
						ref={ rescheduleTrigger }
						type="button"
						className="reservant-manage__reschedule"
						onClick={ openDialog }
					>
						{ __( 'Pick a new time', 'reservant' ) }
					</button>
					<button
						type="button"
						className="reservant-manage__cancel"
						onClick={ startCancel }
					>
						{ __( 'Cancel booking', 'reservant' ) }
					</button>
				</div>
			) }
			{ ! cancelled && confirmingCancel && (
				<div className="reservant-manage__confirm-cancel">
					<p>{ __( 'Cancel this booking? This cannot be undone.', 'reservant' ) }</p>
					<button
						type="button"
						className="reservant-manage__confirm-cancel-yes"
						disabled={ cancelling }
						onClick={ confirmCancel }
					>
						{ __( 'Yes, cancel it', 'reservant' ) }
					</button>
					<button
						type="button"
						className="reservant-manage__confirm-cancel-no"
						onClick={ keepBooking }
					>
						{ __( 'Keep this booking', 'reservant' ) }
					</button>
				</div>
			) }
			{ ! cancelled && dialogOpen && (
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
