import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import type { UseMutationResult } from '@tanstack/react-query';
import { bootConfig } from '../boot';
import { useApprove, useBooking, useCancelBooking, useOutcome, useReject } from '../api/queries';
import type { BookingDetail, BookingStatus, BookingSummary } from '../api/types';
import { useToasts } from '../components/Toasts';
import { errorMessage, utcToSite } from '../../shared';

/** Every booking hold class (AGENTS.md section 2.3) plus `confirmed` - Cancel is offered on all four. */
const CANCELLABLE_STATUSES: readonly BookingStatus[] = [ 'pending', 'awaiting_approval', 'awaiting_payment', 'confirmed' ];

/**
 * Approve/reject route on `reservant_approve_bookings` ALONE (`AdminGuard::approveBookings()`,
 * `AdminRoutes.php`'s own docblock: "approve/reject only need `reservant_approve_bookings`") -
 * `reservant_manage_bookings` does NOT imply it. A caller who holds manage without approve (e.g. a
 * custom "booking manager" role built with a role editor) must not see a button that 403s.
 */
function canDecide( caps: string[], status: BookingStatus ): boolean {
	return 'awaiting_approval' === status && caps.includes( 'reservant_approve_bookings' );
}

/** Cancel is a manager override (`BookingsAdminController::cancel()` is manage-gated outright). */
function canCancel( caps: string[], status: BookingStatus ): boolean {
	return caps.includes( 'reservant_manage_bookings' ) && CANCELLABLE_STATUSES.includes( status );
}

/** Completed/No-show only make sense once the booking is confirmed AND its own start has passed. */
function canMarkOutcome( caps: string[], status: BookingStatus, started: boolean ): boolean {
	return caps.includes( 'reservant_manage_bookings' ) && 'confirmed' === status && started;
}

/**
 * `*_utc` fields are a `DATETIME` column, always `Y-m-d H:i:s` (occasionally `Y-m-dTH:i:s` on a
 * hand-built fixture) - never a bare `Date(str)` parse (the space-separated MySQL form is not part
 * of the ECMAScript-guaranteed grammar, `shared/time.ts`'s own `parseUtc` docblock explains why), but
 * once normalized to `Y-m-dTH:i:sZ` it is exactly that grammar, so every engine parses it alike.
 */
function parseUtcInstant( value: string ): number {
	return new Date( `${ value.replace( ' ', 'T' ).replace( 'Z', '' ) }Z` ).getTime();
}

/** The booking's own start is its earliest item's - chain segments run forward in time (AGENTS.md). */
function hasStarted( booking: BookingDetail, now: number = Date.now() ): boolean {
	if ( 0 === booking.items.length ) {
		return false;
	}
	return Math.min( ...booking.items.map( ( item ) => parseUtcInstant( item.start_utc ) ) ) <= now;
}

/** Minor-unit integer -> a locale-formatted currency string; falls back gracefully on a bad code. */
export function formatMoney( minor: number, currency: string ): string {
	try {
		return new Intl.NumberFormat( undefined, { style: 'currency', currency } ).format( minor / 100 );
	} catch ( error ) {
		return `${ ( minor / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}

const STATUS_LABELS: Record< BookingStatus, () => string > = {
	pending: () => __( 'Pending', 'reservant' ),
	awaiting_approval: () => __( 'Awaiting approval', 'reservant' ),
	awaiting_payment: () => __( 'Awaiting payment', 'reservant' ),
	confirmed: () => __( 'Confirmed', 'reservant' ),
	completed: () => __( 'Completed', 'reservant' ),
	no_show: () => __( 'No-show', 'reservant' ),
	cancelled: () => __( 'Cancelled', 'reservant' ),
	rejected: () => __( 'Rejected', 'reservant' ),
	expired: () => __( 'Expired', 'reservant' ),
};

/**
 * The translated status label - the single source both `BookingDetailBody` (below) and
 * `BookingsScreen`'s table use, so the same value never reads translated in one place and as the
 * raw enum in the other. Falls back to the raw value for anything `STATUS_LABELS` does not
 * recognize: `status` is typed as the closed `BookingStatus` union, but the wire is not statically
 * guaranteed to match it (a backend enum addition the frontend has not caught up to yet), so an
 * unrecognized value degrades to plain text rather than throwing.
 */
export function statusLabel( status: BookingStatus ): string {
	const label = STATUS_LABELS[ status ];
	return undefined === label ? status : label();
}

/** The read-only half of the drawer: status/customer/total, the item table, the audit trail. */
function BookingDetailBody( { booking, timezone }: { booking: BookingDetail; timezone: string } ) {
	return (
		<>
			<p>
				<strong>{ __( 'Status', 'reservant' ) }:</strong> { statusLabel( booking.status ) }
			</p>
			{ booking.customer_email && <p>{ booking.customer_email }</p> }
			{ booking.customer_phone && <p>{ booking.customer_phone }</p> }
			{ booking.rejection_reason && (
				<p>
					<strong>{ __( 'Rejection reason', 'reservant' ) }:</strong> { booking.rejection_reason }
				</p>
			) }
			<p>
				<strong>{ __( 'Total', 'reservant' ) }:</strong> { formatMoney( booking.total_minor, booking.currency ) }
			</p>

			<table className="reservant-booking-drawer__items">
				<thead>
					<tr>
						<th>{ __( 'Service', 'reservant' ) }</th>
						<th>{ __( 'Staff', 'reservant' ) }</th>
						<th>{ __( 'Start', 'reservant' ) }</th>
						<th>{ __( 'End', 'reservant' ) }</th>
						<th>{ __( 'Price', 'reservant' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ booking.items.map( ( item ) => (
						<tr key={ item.id }>
							<td>{ item.service_name ?? item.service_id }</td>
							<td>{ item.resource_name ?? __( 'Any staff', 'reservant' ) }</td>
							<td>{ utcToSite( item.start_utc, timezone ).toLocaleString() }</td>
							<td>{ utcToSite( item.end_utc, timezone ).toLocaleString() }</td>
							<td>{ formatMoney( item.price_minor, booking.currency ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<h3>{ __( 'Audit trail', 'reservant' ) }</h3>
			<ul className="reservant-booking-drawer__audit">
				{ booking.audit.map( ( entry ) => (
					<li key={ entry.id }>
						{ entry.created_at } - { entry.actor } - { entry.action }
					</li>
				) ) }
			</ul>
		</>
	);
}

interface BookingActionsProps {
	booking: BookingDetail;
	caps: string[];
	approve: UseMutationResult< BookingSummary, Error, string >;
	reject: UseMutationResult< BookingSummary, Error, { uuid: string; reason?: string } >;
	cancel: UseMutationResult< BookingSummary, Error, string >;
	outcome: UseMutationResult< BookingSummary, Error, { uuid: string; outcome: 'completed' | 'no_show' } >;
	onApprove: () => void;
	onReject: () => void;
	onCancel: () => void;
	onOutcome: ( value: 'completed' | 'no_show' ) => void;
}

/** The lifecycle action buttons, each gated by both the caller's caps and the booking's own status. */
function BookingActions( { booking, caps, approve, reject, cancel, outcome, onApprove, onReject, onCancel, onOutcome }: BookingActionsProps ) {
	return (
		<div className="reservant-booking-drawer__actions">
			{ canDecide( caps, booking.status ) && (
				<Button variant="primary" isBusy={ approve.isPending } onClick={ onApprove }>
					{ __( 'Approve', 'reservant' ) }
				</Button>
			) }
			{ canDecide( caps, booking.status ) && (
				<Button variant="secondary" isBusy={ reject.isPending } onClick={ onReject }>
					{ __( 'Reject', 'reservant' ) }
				</Button>
			) }
			{ canCancel( caps, booking.status ) && (
				<Button variant="secondary" isBusy={ cancel.isPending } onClick={ onCancel }>
					{ __( 'Cancel', 'reservant' ) }
				</Button>
			) }
			{ canMarkOutcome( caps, booking.status, hasStarted( booking ) ) && (
				<>
					<Button variant="secondary" isBusy={ outcome.isPending } onClick={ () => onOutcome( 'completed' ) }>
						{ __( 'Completed', 'reservant' ) }
					</Button>
					<Button variant="secondary" isBusy={ outcome.isPending } onClick={ () => onOutcome( 'no_show' ) }>
						{ __( 'No-show', 'reservant' ) }
					</Button>
				</>
			) }
		</div>
	);
}

export interface BookingDrawerProps {
	uuid: string;
	onClose: () => void;
}

/**
 * The booking detail drawer (Task 15 brief): full detail (`BookingDetailBody`), the audit trail,
 * and every lifecycle action (`BookingActions`) gated by both the caller's capabilities and the
 * booking's own status. Every action is a mutation + a toast + the shared
 * `['bookings','booking','calendar']` invalidation `useBookingMutation` already performs
 * (`api/queries.ts`) - this component only adds the toast and, for Reject, the reason prompt.
 */
export function BookingDrawer( { uuid, onClose }: BookingDrawerProps ) {
	const { caps, timezone } = bootConfig();
	const { addToast } = useToasts();
	const bookingQuery = useBooking( uuid );
	const approve = useApprove();
	const reject = useReject();
	const cancel = useCancelBooking();
	const outcome = useOutcome();

	const booking = bookingQuery.data;

	function handleApprove(): void {
		approve.mutate( uuid, {
			onSuccess: () => addToast( __( 'Booking approved.', 'reservant' ) ),
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	function handleReject(): void {
		const reason = window.prompt( __( 'Reason for rejection (optional):', 'reservant' ) );
		if ( null === reason ) {
			return;
		}
		reject.mutate(
			{ uuid, reason },
			{
				onSuccess: () => addToast( __( 'Booking rejected.', 'reservant' ) ),
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	function handleCancel(): void {
		if ( ! window.confirm( __( 'Cancel this booking?', 'reservant' ) ) ) {
			return;
		}
		cancel.mutate( uuid, {
			onSuccess: () => addToast( __( 'Booking cancelled.', 'reservant' ) ),
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	function handleOutcome( value: 'completed' | 'no_show' ): void {
		outcome.mutate(
			{ uuid, outcome: value },
			{
				onSuccess: () =>
					addToast(
						'completed' === value
							? __( 'Booking marked completed.', 'reservant' )
							: __( 'Booking marked no-show.', 'reservant' )
					),
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	return (
		<Modal
			title={ booking ? booking.customer_name : __( 'Booking', 'reservant' ) }
			onRequestClose={ onClose }
			className="reservant-booking-drawer"
		>
			{ bookingQuery.isLoading && <Spinner /> }
			{ bookingQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load this booking.', 'reservant' ) }
				</Notice>
			) }

			{ booking && (
				<>
					<BookingDetailBody booking={ booking } timezone={ timezone } />
					<BookingActions
						booking={ booking }
						caps={ caps }
						approve={ approve }
						reject={ reject }
						cancel={ cancel }
						outcome={ outcome }
						onApprove={ handleApprove }
						onReject={ handleReject }
						onCancel={ handleCancel }
						onOutcome={ handleOutcome }
					/>
				</>
			) }
		</Modal>
	);
}
