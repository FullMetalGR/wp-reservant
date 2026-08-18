/**
 * The end of the journey (P5 plan, Task 14), branched on the SERVER'S returned `status` and on
 * nothing else - never on the catalog's `requires_approval` flag, which can disagree with what
 * the engine actually decided (approval settings can change between page load and the hold).
 *
 * What can actually arrive here today: `POST /holds` creates `pending` (which the flow routes to
 * review, not here) or `awaiting_approval`; `ConfirmBooking` produces exactly one status -
 * `confirmed` (it refuses every other starting state; src/Application/ConfirmBooking.php); and
 * the flow's confirm-refusal recovery re-reads the booking and forwards any settled status it
 * finds. `awaiting_payment` cannot be produced by either write path yet - it arrives with the
 * WooCommerce bridge (P7) - but the wire type admits it, so it gets an honest sentence now
 * rather than a fall-through to a blank or, worse, to "confirmed". The default arm covers every
 * remaining status the union allows, honestly and without promising anything.
 *
 * "Request sent" and "confirmed" are deliberately distinct screens (design section 5.2): the
 * engine distinguishes them and the UI must not flatten them. `role="status"` per the widget's
 * live-region convention - the outcome is a polite answer to the visitor's own action - and the
 * region mounts EMPTY, taking its sentence one effect later, so the announcement lands in a
 * region that already existed instead of arriving pre-filled with the step change, which a
 * screen reader would never speak.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import type { Booking } from '../api/types';

function sentence( status: Booking[ 'status' ] ): string {
	switch ( status ) {
		case 'confirmed':
			return __( 'Your booking is confirmed.', 'reservant' );
		case 'awaiting_approval':
			return __( 'Request sent. We will be in touch once it has been reviewed.', 'reservant' );
		case 'awaiting_payment':
			return __( 'Your booking is reserved and will be completed once payment is arranged.', 'reservant' );
		default:
			return __( 'Your booking has been received.', 'reservant' );
	}
}

export function Outcome( { booking }: { booking: Booking } ): JSX.Element {
	const [ message, setMessage ] = useState( '' );
	const current = sentence( booking.status );
	useEffect( () => {
		setMessage( current );
	}, [ current ] );
	return (
		<div className="reservant-outcome">
			<p className="reservant-outcome__message" role="status">
				{ message }
			</p>
		</div>
	);
}
