/**
 * The visible hold countdown (P5 plan, Task 14; design section 5.2 "the hold drives the UI").
 *
 * The deadline arrives as a real UTC instant PARSED FROM THE SERVER'S `hold_expires_at` - the
 * flow does that with `parseUtc()` and passes the `Date` in. It is never computed client-side
 * from `checkoutTtlMin`: the engine anchors the timestamp itself (at `max(nowUtc, wall-clock)`),
 * browser and server clocks skew, and a client-derived deadline can outlive the server's - the
 * widget would then offer a confirm that is already guaranteed to fail. What renders is a
 * DURATION (m:ss against `Date.now()`), so no timezone conversion exists here to get wrong.
 *
 * Live-region convention (the `index.tsx` precedent): the ticking m:ss is deliberately NOT in a
 * live region - a per-second announcement would be hostile - while the milestone paragraph is
 * `role="status"`: "less than a minute" and "run out" are polite answers to the visitor's own
 * pace, not emergencies, so `role="alert"` would interrupt for no reason.
 *
 * Seconds are counted with `ceil`, not `floor`: the display then reads "15:00" for the whole
 * first second and reaches "0:00" only at the true deadline, and a caller sampling mid-tick never
 * sees the countdown a second ahead of the deadline it enforces.
 */
import { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

interface HoldCountdownProps {
	/** The parsed `hold_expires_at` instant. A booking with no deadline renders no countdown. */
	expiresAt: Date;
	/** Fired exactly once, when the deadline passes - the flow blocks confirm on it. */
	onExpire: () => void;
}

function remainingLabel( remainingMs: number ): string {
	const totalSeconds = Math.max( 0, Math.ceil( remainingMs / 1000 ) );
	const minutes = Math.floor( totalSeconds / 60 );
	const seconds = String( totalSeconds % 60 ).padStart( 2, '0' );
	return `${ minutes }:${ seconds }`;
}

export function HoldCountdown( { expiresAt, onExpire }: HoldCountdownProps ): JSX.Element {
	const [ nowMs, setNowMs ] = useState( () => Date.now() );
	const firedRef = useRef( false );
	const remainingMs = Math.max( 0, expiresAt.getTime() - nowMs );

	useEffect( () => {
		const id = setInterval( () => setNowMs( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );

	useEffect( () => {
		if ( 0 === remainingMs && ! firedRef.current ) {
			firedRef.current = true;
			onExpire();
		}
	}, [ remainingMs, onExpire ] );

	return (
		<div className="reservant-countdown">
			<span className="reservant-countdown__time">
				{ sprintf(
					/* translators: %s: minutes and seconds left, e.g. 14:59. */
					__( 'Time left to confirm: %s', 'reservant' ),
					remainingLabel( remainingMs )
				) }
			</span>
			<p className="reservant-countdown__milestone" role="status">
				{ 0 === remainingMs
					? __( 'Your time to confirm has run out. Please pick another time.', 'reservant' )
					: remainingMs < 60000
						? __( 'Less than a minute left to confirm.', 'reservant' )
						: '' }
			</p>
		</div>
	);
}
