/**
 * The booking journey (P5 plan, Task 14): an explicit six-step state machine -
 * `service -> staff -> when -> details -> review -> done` - whose state lives in `Flow` while
 * each step renders through its own dumb, props-driven component below. Preselected
 * `serviceId`/`resourceId` from the mount node skip their steps; the staff step also skips
 * itself when no segment's service lists anyone to choose (which covers events, whose staff is
 * nobody's pick).
 *
 * Load-bearing choices, each pinned by `__tests__/bookingFlow.test.tsx`:
 *
 * - The hold fires at DETAILS SUBMIT, not at slot choice: `POST /holds` 400s any request without
 *   a customer name and valid email (`HoldsController::customer()`), and the details step is
 *   where those first exist. The countdown then covers the review step - the only stretch where
 *   a visitor can dawdle between hold and confirm.
 * - The countdown anchors on the server's `hold_expires_at` (via `parseUtc`), never on a deadline
 *   computed from `checkoutTtlMin`: the engine stamps that timestamp itself and clocks skew, so a
 *   client-derived deadline can outlive the server's and offer a confirm that is guaranteed to
 *   fail. A null `hold_expires_at` renders no countdown and never blocks confirm - expiry is the
 *   sweeper's job either way.
 * - A hold 409 is a NORMAL outcome, not an error screen: the server's own `data.detail` sentence
 *   renders verbatim in a polite status region (design section 6 - the widget does not invent its
 *   own wording) and the visitor returns to the slot step. The availability refetch is
 *   `useCreateHold()`'s 409-only invalidation; repeating it here would double-fetch and fork that
 *   policy into two places that can drift (the hook's docblock owns the why).
 * - The outcome branches on the RETURNED booking status alone, never on the catalog's
 *   `requires_approval` flag - the two can disagree and the server is authoritative. A hold that
 *   lands on anything but `pending` IS the outcome (there is nothing a visitor could confirm:
 *   `ConfirmBooking` refuses every non-pending state), so the flow goes straight to done.
 * - `manage_token` lives in `Flow`'s state and the release hook's ref, nowhere else: no storage,
 *   no cookie, no URL, no attribute, no log line. The hold 201 shows it exactly once.
 * - The when step branches on the availability RESPONSE union (`'starts' in data`), not on a
 *   local guess about the service type - the response already says whether this chain answers
 *   feasible starts or event occurrences.
 * - `same_staff` is never sent: it appears in no spec for this widget and both server paths
 *   default it false.
 * - `checkoutTtlMin` is deliberately unused: the only sanctioned use would be a pre-hold "you
 *   will have N minutes" hint, which would state the wrong class for a hold that lands on
 *   approval (~48h), so no hint is shown at all.
 * - NO CONFIRM REFUSAL IS A DEAD END: every refusal re-reads the booking with the credentials
 *   in hand (`fetchBooking`) and branches on what the server says it IS. A settled status
 *   (confirmed / awaiting_approval / awaiting_payment) renders its outcome, because the journey
 *   succeeded even though this particular request was refused (a double submit, a lost first
 *   answer); anything else keeps the visitor on review with the server's sentence AND the
 *   restart control, which renders on any refusal - not only after the countdown reaches zero.
 *   A released-then-confirmed hold answers 409 `not_confirmable`, NOT 410: the DELETE moves the
 *   row out of `pending`, `ConfirmBooking` checks status before deadline, and `Errors` maps
 *   every non-`hold_expired` reason to 409 - so a status-code map cannot tell "your hold died"
 *   from "you are already booked"; only the re-read can.
 * - Both mutations carry a SYNCHRONOUS `useRef` latch set before `mutate()`: the observer
 *   notifies through a deferred scheduler, so the rendered `disabled` only stops a second click
 *   when some other render happens to land in the gap - and a second `mutate()` detaches the
 *   first mutation's callbacks. A double-fired hold conflicts against the visitor's own
 *   orphaned hold; a double-fired confirm reports a committed booking as unconfirmable. The
 *   `disabled` attribute stays as the visible affordance; the latch is the correctness guard,
 *   and Back honours the same latch so an answer cannot land on a step the visitor already left.
 * - The customer draft lives HERE, not in `CustomerForm`: a 409 unmounts the details form, and
 *   a conflict is a normal outcome that must never cost a retype.
 * - A `data-staff` preselection rides along only onto a service that lists that resource -
 *   stamped onto any other pick, the server would refuse a pairing the visitor never chose.
 * - Step changes move focus to the new step's first control (or the flow container): submitting
 *   a step otherwise leaves focus on a button that no longer exists, and a screen reader user
 *   never hears that anything changed. The initial mount never steals focus from the page.
 */
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, errorMessage, parseUtc, siteNow, utcToSite } from '../shared';
import { fetchBooking, widgetBootstrap } from './api/client';
import {
	useAvailability,
	useConfirm,
	useCreateHold,
	useReleaseHold,
	useServices,
	type ManageCredentials,
} from './api/queries';
import type {
	AvailabilityResponse,
	Booking,
	HeldBooking,
	HoldInput,
	OccurrenceOption,
	PublicService,
	SlotStart,
} from './api/types';
import { ChainBuilder, toChainItems } from './components/ChainBuilder';
import type { Segment } from './components/ChainBuilder';
import { CustomerForm } from './components/CustomerForm';
import type { CustomerDraft } from './components/CustomerForm';
import { DateStrip } from './components/DateStrip';
import { HoldCountdown } from './components/HoldCountdown';
import { OccurrencePicker } from './components/OccurrencePicker';
import { Outcome } from './components/Outcome';
import { ReviewStep } from './components/ReviewStep';
import { ServicePicker } from './components/ServicePicker';
import { SlotGrid } from './components/SlotGrid';
import { StaffPicker } from './components/StaffPicker';
import type { WidgetConfig } from './index';

type Step = 'service' | 'staff' | 'when' | 'details' | 'review' | 'done';

/** One rendered notice: polite (`role="status"`) for a server-worded refusal, alert otherwise. */
interface FlowNotice {
	text: string;
	alert: boolean;
}

/** How many days one availability request covers - the DateStrip's own strip length. */
const WINDOW_DAYS = 14;

function pad( n: number ): string {
	return String( n ).padStart( 2, '0' );
}

/** The `Y-m-d` business date of a site-packed Date, read through the local getters that unpack it. */
function ymd( day: Date ): string {
	return `${ day.getFullYear() }-${ pad( day.getMonth() + 1 ) }-${ pad( day.getDate() ) }`;
}

function initialStep( config: WidgetConfig ): Step {
	if ( null === config.serviceId ) {
		return 'service';
	}
	return null === config.resourceId ? 'staff' : 'when';
}

function initialSegments( config: WidgetConfig ): Segment[] {
	return null === config.serviceId
		? []
		: [ { serviceId: config.serviceId, resourceId: config.resourceId } ];
}

/** Whether any segment's service lists staff to choose from - the staff step skips when none do. */
function hasStaffChoice( segments: Segment[], catalog: PublicService[] ): boolean {
	return segments.some(
		( segment ) =>
			( catalog.find( ( service ) => service.id === segment.serviceId )?.resources.length ??
				0 ) > 0
	);
}

function nextAfterService( segments: Segment[], catalog: PublicService[] | undefined ): Step {
	return undefined !== catalog && ! hasStaffChoice( segments, catalog ) ? 'when' : 'staff';
}

/** Which step "Back" returns to, mirroring the forward path's skips; null hides the control. */
function prevOf(
	step: Step,
	config: WidgetConfig,
	segments: Segment[],
	catalog: PublicService[] | undefined
): Step | null {
	if ( 'staff' === step ) {
		return null === config.serviceId ? 'service' : null;
	}
	if ( 'when' === step ) {
		if (
			null === config.resourceId &&
			undefined !== catalog &&
			hasStaffChoice( segments, catalog )
		) {
			return 'staff';
		}
		return null === config.serviceId ? 'service' : null;
	}
	if ( 'details' === step ) {
		return 'when';
	}
	return null;
}

/**
 * A 4xx carries a sentence the server worded for the visitor (`data.detail`), a polite answer to
 * what they just did; anything else (network, 5xx) is a genuine failure with nothing better to
 * show, which is the one case the widget's live-region convention reserves `role="alert"` for.
 */
function noticeOf( error: unknown ): FlowNotice {
	return {
		text: errorMessage( error ),
		alert: ! ( error instanceof ApiError && error.status < 500 ),
	};
}

/**
 * Whether the server's returned status means the journey already SUCCEEDED - the three states a
 * held booking settles into when things go right: confirmed outright, lodged for approval, or
 * reserved pending payment. Everything else (pending, cancelled, expired, rejected, ...) leaves
 * a confirm refusal standing.
 */
function isSettled( status: Booking[ 'status' ] ): boolean {
	return (
		'confirmed' === status || 'awaiting_approval' === status || 'awaiting_payment' === status
	);
}

function parseSeats( raw: string ): number {
	const value = Number.parseInt( raw, 10 );
	return Number.isInteger( value ) && value > 0 ? value : 1;
}

/** The server's deadline as a real instant, or null when the booking carries none. */
function expiryOf( held: HeldBooking | null ): Date | null {
	return null !== held && null !== held.hold_expires_at
		? parseUtc( held.hold_expires_at )
		: null;
}

function startsOnDay( starts: SlotStart[], day: string, timezone: string ): SlotStart[] {
	return starts.filter( ( start ) => ymd( utcToSite( start.utc, timezone ) ) === day );
}

/**
 * Exactly one of `appointment` or `event`, straight off which kind of pick exists. No
 * `same_staff`: `HoldInput` leaves it optional and both server paths default it false.
 */
function buildHoldInput(
	customer: HoldInput[ 'customer' ],
	chosen: SlotStart | null,
	occurrence: OccurrenceOption | null,
	segments: Segment[],
	seatsText: string
): HoldInput | null {
	if ( null !== chosen ) {
		return {
			customer,
			appointment: { start_utc: chosen.utc, segments: toChainItems( segments ) },
		};
	}
	if ( null !== occurrence ) {
		return {
			customer,
			event: { occurrence_id: occurrence.id, seats: parseSeats( seatsText ) },
		};
	}
	return null;
}

interface HoldActions {
	/** A `pending` hold: the checkout continues to review. */
	onHeld: ( booking: HeldBooking ) => void;
	/** Any other status IS the outcome - `ConfirmBooking` refuses every non-pending state. */
	onOutcome: ( booking: HeldBooking ) => void;
	/** A 409: the server's own sentence; the availability refetch is the hook's, never repeated. */
	onConflict: ( sentence: string ) => void;
	onRefusal: ( notice: FlowNotice ) => void;
}

/** How a hold answer routes the journey - the branching lives here, the state changes in Flow. */
function holdCallbacks( actions: HoldActions ): {
	onSuccess: ( booking: HeldBooking ) => void;
	onError: ( error: Error ) => void;
} {
	return {
		onSuccess: ( booking ) => {
			if ( 'pending' === booking.status ) {
				actions.onHeld( booking );
				return;
			}
			actions.onOutcome( booking );
		},
		onError: ( error ) => {
			if ( error instanceof ApiError && 409 === error.status ) {
				actions.onConflict( errorMessage( error ) );
				return;
			}
			actions.onRefusal( noticeOf( error ) );
		},
	};
}

/**
 * A confirm refusal with status 410 means the hold elapsed server-side - the countdown may
 * disagree by a skewed second, but the server is the authority, so confirm blocks too.
 */
function confirmCallbacks( actions: {
	onDone: ( booking: Booking ) => void;
	onRefusal: ( notice: FlowNotice, holdGone: boolean ) => void;
} ): { onSuccess: ( booking: Booking ) => void; onError: ( error: Error ) => void } {
	return {
		onSuccess: actions.onDone,
		onError: ( error ) =>
			actions.onRefusal( noticeOf( error ), error instanceof ApiError && 410 === error.status ),
	};
}

/**
 * The always-mounted polite region plus a conditional alert (the ChainBuilder precedent: the
 * status region exists before its message so the announcement lands in a region that was already
 * there; an alert announces on appearance, so it may mount with its text).
 *
 * "Exists before its message" must hold on the MOUNTING render too: a step that mounts with a
 * notice already in hand (the 409 sentence arriving with the when step) would otherwise paint
 * the region pre-filled, and a region born with text announces nothing. The polite text is
 * therefore held back until one effect after mount; from then on, notices render immediately.
 */
function StepNotice( { notice }: { notice: FlowNotice | null } ): JSX.Element {
	const [ settled, setSettled ] = useState( false );
	useEffect( () => {
		setSettled( true );
	}, [] );
	return (
		<>
			<p className="reservant-flow__notice" role="status">
				{ settled && null !== notice && ! notice.alert ? notice.text : '' }
			</p>
			{ null !== notice && notice.alert && (
				<p className="reservant-flow__alert" role="alert">
					{ notice.text }
				</p>
			) }
		</>
	);
}

/**
 * The preselected resource, kept only when the picked service lists them: a mount node's
 * `data-staff` names ONE staff member, not a promise that every service is theirs, and an
 * unusable `resource_id` on the wire is a 400, never a silent fallback (`HoldsController`).
 */
function carriedPreselection(
	serviceId: number,
	resourceId: number | null,
	catalog: PublicService[] | undefined
): number | null {
	if ( null === resourceId ) {
		return null;
	}
	const service = catalog?.find( ( entry ) => entry.id === serviceId );
	return undefined !== service &&
		service.resources.some( ( resource ) => resource.id === resourceId )
		? resourceId
		: null;
}

interface ServiceStepProps {
	segments: Segment[];
	/** A `data-staff` preselect rides along on the first segment - `carriedPreselection`. */
	preselectedResourceId: number | null;
	/** For judging whether the preselected staff performs the picked service. */
	catalog: PublicService[] | undefined;
	onSegments: ( segments: Segment[] ) => void;
	onContinue: () => void;
}

/** The catalog pick plus the chain builder - the design's "service -> [+ add another]*". */
function ServiceStep( {
	segments,
	preselectedResourceId,
	catalog,
	onSegments,
	onContinue,
}: ServiceStepProps ): JSX.Element {
	if ( 0 === segments.length ) {
		return (
			<ServicePicker
				value={ null }
				onChange={ ( id ) =>
					onSegments( [
						{
							serviceId: id,
							resourceId: carriedPreselection(
								id,
								preselectedResourceId,
								catalog
							),
						},
					] )
				}
			/>
		);
	}
	return (
		<>
			<ChainBuilder segments={ segments } onChange={ onSegments } />
			<button type="button" className="reservant-flow__continue" onClick={ onContinue }>
				{ __( 'Continue', 'reservant' ) }
			</button>
		</>
	);
}

interface StaffStepProps {
	segments: Segment[];
	catalog: PublicService[] | undefined;
	pending: boolean;
	error: Error | null;
	onSegments: ( segments: Segment[] ) => void;
	onContinue: () => void;
}

/**
 * One `StaffPicker` per segment whose service lists staff. The preselected-service mount enters
 * this step before the catalog exists; once it arrives, a chain with nobody to pick moves on by
 * itself through the effect (the interactive path decides the same thing at click time in
 * `nextAfterService`). "No preference" is the default - a null `resourceId` reaches the wire as
 * "any staff" and `ChainResolver` assigns at hold time.
 */
function StaffStep( {
	segments,
	catalog,
	pending,
	error,
	onSegments,
	onContinue,
}: StaffStepProps ): JSX.Element | null {
	const skip = undefined !== catalog && ! hasStaffChoice( segments, catalog );
	useEffect( () => {
		if ( skip ) {
			onContinue();
		}
	}, [ skip, onContinue ] );
	if ( skip ) {
		return null;
	}
	if ( pending ) {
		return (
			<p className="reservant-flow__status" role="status">
				{ __( 'Loading services...', 'reservant' ) }
			</p>
		);
	}
	if ( null !== error && undefined === catalog ) {
		// Through noticeOf, like every refusal in the flow: a 4xx carries a worded answer and
		// lands politely; only network/5xx earns the alert.
		return <StepNotice notice={ noticeOf( error ) } />;
	}
	return (
		<>
			{ segments.map( ( segment, index ) => {
				const service = catalog?.find( ( entry ) => entry.id === segment.serviceId );
				if ( undefined === service || 0 === service.resources.length ) {
					return null;
				}
				return (
					// Index keys, the ChainBuilder precedent: the same service twice is a legal
					// chain, so no stable id exists.
					<div key={ index } className="reservant-flow__staff">
						<p className="reservant-flow__staff-service">{ service.name }</p>
						<StaffPicker
							serviceId={ service.id }
							resources={ service.resources }
							value={ segment.resourceId }
							onChange={ ( id ) =>
								onSegments(
									segments.map( ( entry, i ) =>
										i === index ? { ...entry, resourceId: id } : entry
									)
								)
							}
						/>
					</div>
				);
			} ) }
			<button type="button" className="reservant-flow__continue" onClick={ onContinue }>
				{ __( 'Continue', 'reservant' ) }
			</button>
		</>
	);
}

interface WhenStepProps {
	/** The 409 sentence, shown politely above the pickers for the retry. */
	conflict: string | null;
	pending: boolean;
	error: Error | null;
	data: AvailabilityResponse | undefined;
	timezone: string;
	/** The site's own today (`siteNow`) - the DateStrip contract. */
	today: Date;
	selectedDay: string | null;
	onDay: ( day: string ) => void;
	seatsText: string;
	onSeatsText: ( raw: string ) => void;
	onSlot: ( start: SlotStart ) => void;
	onOccurrence: ( occurrence: OccurrenceOption ) => void;
}

/**
 * The when step, branched on the RESPONSE union: feasible starts render a date strip plus the
 * chosen day's slots; occurrences render the event's fixed list plus an open-seating places
 * count. `remaining` stays advisory either way - the hold is the only authority.
 */
function WhenStep( {
	conflict,
	pending,
	error,
	data,
	timezone,
	today,
	selectedDay,
	onDay,
	seatsText,
	onSeatsText,
	onSlot,
	onOccurrence,
}: WhenStepProps ): JSX.Element {
	const seatsId = useId();
	// One notice per step: the 409 sentence when there is one (always polite - a conflict is a
	// normal outcome), else a query failure through noticeOf (polite for a 4xx, an alert only
	// for network/5xx). The two never coexist: the failure only renders with no data at all,
	// and a conflict presupposes a successfully loaded offer list.
	const notice =
		null !== conflict
			? { text: conflict, alert: false }
			: null !== error && undefined === data
				? noticeOf( error )
				: null;
	return (
		<>
			<StepNotice notice={ notice } />
			{ pending && (
				<p className="reservant-flow__status" role="status">
					{ __( 'Checking availability...', 'reservant' ) }
				</p>
			) }
			{ undefined !== data &&
				( 'starts' in data ? (
					<>
						<DateStrip from={ today } value={ selectedDay } onSelect={ onDay } />
						{ null === selectedDay ? (
							<p className="reservant-flow__hint">
								{ __( 'Pick a day to see the available times.', 'reservant' ) }
							</p>
						) : (
							<SlotGrid
								starts={ startsOnDay( data.starts, selectedDay, timezone ) }
								onSelect={ onSlot }
							/>
						) }
					</>
				) : (
					<>
						<div className="reservant-flow__seats">
							<label htmlFor={ seatsId }>
								{ __( 'Number of places', 'reservant' ) }
							</label>
							<input
								id={ seatsId }
								type="number"
								inputMode="numeric"
								min={ 1 }
								value={ seatsText }
								onChange={ ( event ) => onSeatsText( event.target.value ) }
							/>
						</div>
						<OccurrencePicker occurrences={ data.occurrences } onSelect={ onOccurrence } />
					</>
				) ) }
		</>
	);
}

interface ReviewPanelProps {
	booking: HeldBooking;
	catalog: PublicService[] | undefined;
	timezone: string;
	expiresAt: Date | null;
	expired: boolean;
	confirmPending: boolean;
	notice: FlowNotice | null;
	onExpire: () => void;
	onConfirm: () => void;
	onStartOver: () => void;
}

/**
 * The held state: countdown (when the server gave a deadline), summary, confirm, way out. The
 * restart control renders after expiry AND alongside any refusal notice: a refused confirm has
 * no Back and can re-refuse forever (a released hold answers 409 on every retry), so a visitor
 * with a refusal in front of them always has a way forward that is not a page reload.
 */
function ReviewPanel( {
	booking,
	catalog,
	timezone,
	expiresAt,
	expired,
	confirmPending,
	notice,
	onExpire,
	onConfirm,
	onStartOver,
}: ReviewPanelProps ): JSX.Element {
	return (
		<>
			{ null !== expiresAt && <HoldCountdown expiresAt={ expiresAt } onExpire={ onExpire } /> }
			<ReviewStep
				booking={ booking }
				services={ catalog ?? [] }
				timezone={ timezone }
				onConfirm={ onConfirm }
				confirmDisabled={ expired || confirmPending }
			/>
			<StepNotice notice={ notice } />
			{ ( expired || null !== notice ) && (
				<button
					type="button"
					className="reservant-flow__restart"
					onClick={ onStartOver }
				>
					{ __( 'Pick another time', 'reservant' ) }
				</button>
			) }
		</>
	);
}

/**
 * Best-effort, fire-and-forget release on visibilitychange-to-hidden and pagehide. It must NOT
 * fire with no hold, after the journey is done (a confirmed booking is no longer a hold, and a
 * lodged approval request must survive the tab closing), after the deadline passed (the sweeper
 * owns it then), or twice for the same hold. `Application\ExpireHolds` is the real guarantee -
 * the client is never trusted to release, it only gives slots back early when it can.
 *
 * The target lives in a ref OUTSIDE the once-registered handlers so they always judge the
 * current journey; it is re-derived on every render, which is why the released uuid is
 * remembered separately - nulling the target inside `fire()` alone would not stick past the
 * release mutation's own re-render, and a second hidden signal would DELETE twice.
 */
function useReleaseOnHide( held: HeldBooking | null, journeyDone: boolean ): void {
	const release = useReleaseHold();
	const releaseTarget = useRef< {
		uuid: string;
		token: string;
		deadlineMs: number | null;
	} | null >( null );
	const releasedUuid = useRef< string | null >( null );
	releaseTarget.current =
		null !== held && ! journeyDone && releasedUuid.current !== held.uuid
			? {
					uuid: held.uuid,
					token: held.manage_token,
					deadlineMs:
						null !== held.hold_expires_at
							? parseUtc( held.hold_expires_at ).getTime()
							: null,
			  }
			: null;

	const releaseMutate = release.mutate;
	useEffect( () => {
		const fire = (): void => {
			const target = releaseTarget.current;
			if ( null === target ) {
				return;
			}
			if ( null !== target.deadlineMs && target.deadlineMs <= Date.now() ) {
				return;
			}
			releasedUuid.current = target.uuid;
			releaseTarget.current = null;
			releaseMutate( { uuid: target.uuid, token: target.token } );
		};
		const onVisibility = (): void => {
			if ( 'hidden' === document.visibilityState ) {
				fire();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );
		window.addEventListener( 'pagehide', fire );
		return () => {
			document.removeEventListener( 'visibilitychange', onVisibility );
			window.removeEventListener( 'pagehide', fire );
		};
	}, [ releaseMutate ] );
}

function Flow( { config }: { config: WidgetConfig } ): JSX.Element {
	const { timezone } = widgetBootstrap();
	const { data: catalog, isPending: catalogPending, error: catalogError } = useServices();

	const [ step, setStep ] = useState< Step >( () => initialStep( config ) );
	const [ segments, setSegments ] = useState< Segment[] >( () => initialSegments( config ) );
	const [ selectedDay, setSelectedDay ] = useState< string | null >( null );
	const [ chosen, setChosen ] = useState< SlotStart | null >( null );
	const [ occurrence, setOccurrence ] = useState< OccurrenceOption | null >( null );
	const [ seatsText, setSeatsText ] = useState( '1' );
	const [ customer, setCustomer ] = useState< CustomerDraft >( {
		name: '',
		email: '',
		phone: '',
	} );
	const [ held, setHeld ] = useState< HeldBooking | null >( null );
	const [ outcome, setOutcome ] = useState< Booking | null >( null );
	const [ expired, setExpired ] = useState( false );
	const [ conflictNotice, setConflictNotice ] = useState< string | null >( null );
	const [ detailsNotice, setDetailsNotice ] = useState< FlowNotice | null >( null );
	const [ reviewNotice, setReviewNotice ] = useState< FlowNotice | null >( null );
	/** True while a refused confirm's booking re-read is in flight - Confirm stays disabled. */
	const [ recovering, setRecovering ] = useState( false );

	const createHold = useCreateHold();
	const confirm = useConfirm();
	useReleaseOnHide( held, 'done' === step );

	// The synchronous double-submit guards (see the header): set before mutate(), cleared where
	// each answer settles. The confirm latch stays held through the refusal re-read -
	// recoverRefusedConfirm owns clearing it - so no second confirm can start mid-recovery.
	const holdBusy = useRef( false );
	const confirmBusy = useRef( false );

	// The window starts on the SITE's own today (`siteNow`, never `new Date()` - an evening
	// visitor west of the salon is often a day behind it) and spans the strip's fourteen days,
	// `to` exclusive. The hook stays subscribed across every step, so useCreateHold's 409
	// invalidation refetches an ACTIVE query the moment a conflict answers - the second GET the
	// suite observes is that path working end to end.
	const today = siteNow( timezone );
	const fromDay = ymd( today );
	const toDay = ymd(
		new Date( today.getFullYear(), today.getMonth(), today.getDate() + WINDOW_DAYS )
	);
	const availability = useAvailability( toChainItems( segments ), fromDay, toDay );

	const expiresAt = useMemo( () => expiryOf( held ), [ held ] );

	// Focus follows the journey (the ChainBuilder setFocusAfterRemove precedent): a step change
	// unmounts the control the visitor just used, which would drop focus on <body> and tell a
	// screen reader user nothing. Each change lands focus on the new step's first control - or
	// on the container itself when a step renders none (the outcome, whose news arrives through
	// its status region). The first commit only records the step: mounting must never steal
	// focus from the surrounding page.
	const flowRef = useRef< HTMLDivElement | null >( null );
	const focusedStep = useRef< Step | null >( null );
	useEffect( () => {
		if ( focusedStep.current === step ) {
			return;
		}
		const firstStep = null === focusedStep.current;
		focusedStep.current = step;
		const root = flowRef.current;
		if ( firstStep || null === root ) {
			return;
		}
		const target = root.querySelector< HTMLElement >(
			'button:not([disabled]), input, select, textarea, a[href]'
		);
		( target ?? root ).focus();
	}, [ step ] );

	/** Both pick kinds land here: store the one that happened, clear the other, move to details. */
	const handlePick = ( start: SlotStart | null, picked: OccurrenceOption | null ): void => {
		setChosen( start );
		setOccurrence( picked );
		setConflictNotice( null );
		setDetailsNotice( null );
		setStep( 'details' );
	};

	const handleDetails = ( submitted: HoldInput[ 'customer' ] ): void => {
		const input = buildHoldInput( submitted, chosen, occurrence, segments, seatsText );
		if ( null === input || holdBusy.current ) {
			return;
		}
		holdBusy.current = true;
		setDetailsNotice( null );
		const routes = holdCallbacks( {
			onHeld: ( booking ) => {
				setHeld( booking );
				setExpired( false );
				setReviewNotice( null );
				setStep( 'review' );
			},
			onOutcome: ( booking ) => {
				setHeld( booking );
				setOutcome( booking );
				setStep( 'done' );
			},
			onConflict: ( sentence ) => {
				setConflictNotice( sentence );
				setChosen( null );
				setOccurrence( null );
				setStep( 'when' );
			},
			onRefusal: setDetailsNotice,
		} );
		createHold.mutate( input, {
			onSuccess: ( booking ) => {
				holdBusy.current = false;
				routes.onSuccess( booking );
			},
			onError: ( error ) => {
				holdBusy.current = false;
				routes.onError( error );
			},
		} );
	};

	/**
	 * Any confirm refusal asks the server what the booking actually IS before showing anything:
	 * the refusal alone cannot describe the journey (a 409 `approval_required` can follow a
	 * confirm that LANDED the request; a second click's 409 `not_confirmable` can follow one
	 * that committed). A settled status renders its outcome - the journey succeeded and a
	 * refusal screen would be a lie. Anything else, or a failed re-read, keeps the visitor on
	 * review with the server's sentence and the restart control - never a dead end. The confirm
	 * latch stays held until this settles, so no second confirm can start mid-read.
	 */
	const recoverRefusedConfirm = async (
		credentials: ManageCredentials,
		notice: FlowNotice,
		holdGone: boolean
	): Promise< void > => {
		setRecovering( true );
		try {
			const booking = await fetchBooking( credentials.uuid, credentials.token );
			if ( isSettled( booking.status ) ) {
				setOutcome( booking );
				setStep( 'done' );
				return;
			}
		} catch {
			// The re-read failed too - the refusal below stands, and the restart control is the
			// way out either way.
		} finally {
			setRecovering( false );
			confirmBusy.current = false;
		}
		setExpired( ( previous ) => previous || holdGone );
		setReviewNotice( notice );
	};

	const handleConfirm = (): void => {
		if ( null === held || confirmBusy.current ) {
			return;
		}
		confirmBusy.current = true;
		const credentials = { uuid: held.uuid, token: held.manage_token };
		setReviewNotice( null );
		confirm.mutate(
			credentials,
			confirmCallbacks( {
				onDone: ( booking ) => {
					confirmBusy.current = false;
					setOutcome( booking );
					setStep( 'done' );
				},
				onRefusal: ( notice, holdGone ) => {
					void recoverRefusedConfirm( credentials, notice, holdGone );
				},
			} )
		);
	};

	// The expired hold is left to the sweeper - never released client-side past its deadline.
	const handleStartOver = (): void => {
		setHeld( null );
		setExpired( false );
		setChosen( null );
		setOccurrence( null );
		setReviewNotice( null );
		setStep( 'when' );
	};

	const prev = prevOf( step, config, segments, catalog );

	return (
		<div ref={ flowRef } tabIndex={ -1 } className="reservant-flow" data-step={ step }>
			{ 'service' === step && (
				<ServiceStep
					segments={ segments }
					preselectedResourceId={ config.resourceId }
					catalog={ catalog }
					onSegments={ setSegments }
					onContinue={ () => setStep( nextAfterService( segments, catalog ) ) }
				/>
			) }
			{ 'staff' === step && (
				<StaffStep
					segments={ segments }
					catalog={ catalog }
					pending={ catalogPending }
					error={ catalogError }
					onSegments={ setSegments }
					onContinue={ () => setStep( 'when' ) }
				/>
			) }
			{ 'when' === step && (
				<WhenStep
					conflict={ conflictNotice }
					pending={ availability.isPending }
					error={ availability.error }
					data={ availability.data }
					timezone={ timezone }
					today={ today }
					selectedDay={ selectedDay }
					onDay={ setSelectedDay }
					seatsText={ seatsText }
					onSeatsText={ setSeatsText }
					onSlot={ ( start ) => handlePick( start, null ) }
					onOccurrence={ ( picked ) => handlePick( null, picked ) }
				/>
			) }
			{ 'details' === step && (
				<>
					<CustomerForm
						value={ customer }
						onChange={ setCustomer }
						onSubmit={ handleDetails }
						busy={ createHold.isPending }
					/>
					<StepNotice notice={ detailsNotice } />
				</>
			) }
			{ 'review' === step && null !== held && (
				<ReviewPanel
					booking={ held }
					catalog={ catalog }
					timezone={ timezone }
					expiresAt={ expiresAt }
					expired={ expired }
					confirmPending={ confirm.isPending || recovering }
					notice={ reviewNotice }
					onExpire={ () => setExpired( true ) }
					onConfirm={ handleConfirm }
					onStartOver={ handleStartOver }
				/>
			) }
			{ 'done' === step && null !== outcome && <Outcome booking={ outcome } /> }
			{ null !== prev && (
				// Held still while the hold is in flight: a mid-request Back would let the
				// answer land on whatever step the visitor reached. `disabled` is the visible
				// affordance; the latch check is the guard the scheduler cannot outrun. Only the
				// hold latch matters here - no step that renders Back can have a confirm going.
				<button
					type="button"
					className="reservant-flow__back"
					disabled={ createHold.isPending }
					onClick={ () => {
						if ( holdBusy.current ) {
							return;
						}
						setStep( prev );
					} }
				>
					{ __( 'Back', 'reservant' ) }
				</button>
			) }
		</div>
	);
}

/**
 * The widget's one QueryClient (none existed before this task), created ONCE per mount through a
 * `useState` initializer - a render-body `new QueryClient()` would throw the whole cache away on
 * every render. `retry` is a PREDICATE, not a count: no 4xx is ever retried - a validation 400
 * fails identically forever, a 409 is a real answer the flow handles, a 404 means gone, and a 429
 * is the rate limiter saying stop, the one response retrying is guaranteed to make worse. Network
 * failures and 5xx keep the default three attempts. Mutations keep TanStack's no-retry default: a
 * blind second `POST /holds` could double-hold. `staleTime` is DELIBERATELY left at its default -
 * Task 16 owns that tuning.
 */
function newWidgetClient(): QueryClient {
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

export function BookingFlow( { config }: { config: WidgetConfig } ): JSX.Element {
	const [ client ] = useState( newWidgetClient );
	return (
		<QueryClientProvider client={ client }>
			<Flow config={ config } />
		</QueryClientProvider>
	);
}
