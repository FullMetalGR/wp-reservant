/**
 * The reschedule dialog (P5 plan, Task 15): pick a new time for an existing booking, reusing the
 * Task 13 pickers wholesale - `DateStrip` + `SlotGrid` for an appointment chain,
 * `OccurrencePicker` for an event - never a second picker implementation.
 *
 * The caller derives the availability `items` from the BOOKING'S OWN items, staff included:
 * `RescheduleBooking::planAppointment()` keeps the pinned resource "as sold rather than
 * re-picked", so a start computed for "any staff" could be one the move cannot land on. This
 * component just asks for those items' feasible starts and branches on the RESPONSE union
 * (`'starts' in data`, the BookingFlow convention) - a chain answers starts and reports a pick as
 * `{start_utc}`, an event answers occurrences and reports `{occurrence_id}`, so the wire's
 * exactly-one-target rule holds by construction.
 *
 * A real dialog, not `window.confirm()` and not a keypress-laden div (the P4 lesson):
 * `role="dialog"` named by its own heading, `aria-modal`, focus moved onto the dialog container
 * on open, Escape to close, and Tab/Shift+Tab contained by wrapping across the dialog's real
 * `<button>` elements. Restoring focus to the TRIGGER on close is the PARENT's half of the
 * contract: the trigger lives in the parent's tree, and the parent closes the dialog on success
 * too, so one owner covers every close path.
 *
 * While the move is in flight the pickers are replaced by a polite busy line - the visible
 * affordance against a second pick (the parent's `useRef` latch is the correctness guard, since
 * `isPending` reaches the DOM one macrotask late) - while the close control and Escape stay
 * reachable the whole time: the request is a fetch with no timeout, and a visitor must never be
 * trapped behind it (the Task 14 hung-recovery lesson). A pick's own continuation unmounts the
 * pickers, so the click handler parks focus on the dialog container first - focus must never die
 * on <body> mid-journey.
 *
 * Notices and progress lines come from the shared notice module (`./Notice`), one home for the
 * whole widget: the polite region mounts EMPTY and takes its text one effect later, so a message
 * always lands in a region that already existed - a region born with text announces nothing.
 */
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { availabilityWindow, siteNow, utcToSite, ymd } from '../../shared';
import { widgetBootstrap } from '../api/client';
import { useAvailability } from '../api/queries';
import type { ChainItem, RescheduleTarget, SlotStart } from '../api/types';
import { DateStrip } from './DateStrip';
import { NoticeRegion, ProgressStatus, noticeOf } from './Notice';
import type { Notice } from './Notice';
import { OccurrencePicker } from './OccurrencePicker';
import { SlotGrid } from './SlotGrid';

function startsOnDay( starts: SlotStart[], day: string, timezone: string ): SlotStart[] {
	return starts.filter( ( start ) => ymd( utcToSite( start.utc, timezone ) ) === day );
}

interface RescheduleDialogProps {
	/**
	 * The availability request for the whole chain, derived by the caller from the booking's own
	 * items WITH their sold staff pinned (see the header - the engine keeps staff as sold).
	 */
	items: ChainItem[];
	/** The current move refusal, shown politely inside the dialog; null when there is none. */
	notice: Notice | null;
	/** True while the move is in flight - replaces the pickers, never the way out. */
	busy: boolean;
	/** A picked target, exactly one field set - `{start_utc}` off a slot, `{occurrence_id}` off an occurrence. */
	onPick: ( target: RescheduleTarget ) => void;
	/** Escape and the close control both land here; the parent unmounts the dialog and restores focus. */
	onClose: () => void;
}

export function RescheduleDialog( {
	items,
	notice,
	busy,
	onPick,
	onClose,
}: RescheduleDialogProps ): JSX.Element {
	const { timezone } = widgetBootstrap();
	const headingId = useId();
	const dialogRef = useRef< HTMLDivElement | null >( null );
	const [ selectedDay, setSelectedDay ] = useState< string | null >( null );

	// The window starts on the SITE's own today (`siteNow`, never `new Date()` - an evening
	// visitor west of the salon is often a day behind it), spanning the strip's fourteen days
	// (`availabilityWindow`, `to` exclusive).
	const today = siteNow( timezone );
	const { from: fromDay, to: toDay } = availabilityWindow( today );
	const availability = useAvailability( items, fromDay, toDay );

	// Focus moves INTO the dialog on open - onto the container, not a first control, so a screen
	// reader starts at the heading rather than past it. Restoring on close is the parent's half.
	useEffect( () => {
		dialogRef.current?.focus();
	}, [] );

	const handleKeyDown = ( event: ReactKeyboardEvent< HTMLDivElement > ): void => {
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			onClose();
			return;
		}
		if ( 'Tab' !== event.key ) {
			return;
		}
		const dialog = dialogRef.current;
		if ( null === dialog ) {
			return;
		}
		// Real buttons are the only focusable things this dialog renders, so the trap can query
		// exactly them; the wrap fires only at the edges and lets the browser handle the middle.
		const focusables = dialog.querySelectorAll< HTMLElement >( 'button:not([disabled])' );
		const first = focusables[ 0 ];
		const last = focusables[ focusables.length - 1 ];
		if ( undefined === first || undefined === last ) {
			return;
		}
		const active = document.activeElement;
		if ( event.shiftKey && ( active === first || active === dialog ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	/** A pick unmounts the pickers (the busy line replaces them) - park focus on the dialog first. */
	const pick = ( target: RescheduleTarget ): void => {
		dialogRef.current?.focus();
		onPick( target );
	};

	// A destructive availability failure (no data at all to show) renders through the same polite
	// convention as every refusal; the mutation refusal has its own region above it.
	const queryNotice =
		null !== availability.error && undefined === availability.data
			? noticeOf( availability.error )
			: null;

	return (
		<div
			ref={ dialogRef }
			tabIndex={ -1 }
			role="dialog"
			aria-modal="true"
			aria-labelledby={ headingId }
			className="reservant-reschedule"
			onKeyDown={ handleKeyDown }
		>
			<h2 id={ headingId } className="reservant-reschedule__title">
				{ __( 'Pick a new time', 'reservant' ) }
			</h2>
			<NoticeRegion classBase="reservant-manage" notice={ notice } />
			<NoticeRegion classBase="reservant-manage" notice={ queryNotice } />
			{ busy ? (
				<ProgressStatus
					text={ __( 'Moving your booking...', 'reservant' ) }
					className="reservant-reschedule__busy"
				/>
			) : (
				<>
					{ availability.isPending && (
						<ProgressStatus
							text={ __( 'Checking availability...', 'reservant' ) }
							className="reservant-reschedule__status"
						/>
					) }
					{ undefined !== availability.data &&
						( 'starts' in availability.data ? (
							<>
								<DateStrip
									from={ today }
									value={ selectedDay }
									onSelect={ setSelectedDay }
								/>
								{ null === selectedDay ? (
									<p className="reservant-reschedule__hint">
										{ __(
											'Pick a day to see the available times.',
											'reservant'
										) }
									</p>
								) : (
									<SlotGrid
										starts={ startsOnDay(
											availability.data.starts,
											selectedDay,
											timezone
										) }
										onSelect={ ( start ) => pick( { start_utc: start.utc } ) }
									/>
								) }
							</>
						) : (
							<OccurrencePicker
								occurrences={ availability.data.occurrences }
								onSelect={ ( occurrence ) =>
									pick( { occurrence_id: occurrence.id } )
								}
							/>
						) ) }
				</>
			) }
			<button
				type="button"
				className="reservant-reschedule__close"
				onClick={ onClose }
			>
				{ __( 'Keep the current time', 'reservant' ) }
			</button>
		</div>
	);
}
