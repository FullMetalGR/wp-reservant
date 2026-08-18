/**
 * The chain as the visitor builds it (P5 plan, Task 12): the segments so far, a remove per
 * segment, and an add flow that opens a `ServicePicker` for the next one. Fully controlled -
 * every change flows out through `onChange`; held here are only the picker's open state and the
 * transient answers to the visitor's last action (a refusal, a removal announcement, a focus
 * re-seat).
 *
 * The rule it enforces early: a chain containing an EVENT must not grow past one segment.
 * Enforcement lives at the single commit point (`handlePick`), which judges the candidate chain
 * the pick would produce - so both directions (event first, then anything; anything first, then
 * an event) are refused with "Events are booked one at a time" even when the chain changed under
 * an open picker. Best-effort, not absolute: a segment whose service the active catalog no longer
 * lists cannot be classified, and the server stays the authority. It refuses the two directions
 * through different doors: with the event FIRST, `AvailabilityController::availability()` reads
 * only `$items[0]` and 400s the multi-item chain ("Events are booked one at a time - send a
 * single item."); with the event LATER, the request falls through to
 * `AvailabilityQuery::appointmentStarts()`, which throws `SlotConflict('not_found', $index)` for
 * the non-appointment segment, mapped by `Rest\Errors` to a 404 `reservant_not_found` ("That
 * booking is no longer available."). Either way an event holds through `occurrence_id`, not
 * segments - the refusal here spares the visitor the doomed round trip.
 */
import { Fragment, useEffect, useId, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../../shared';
import { useServices } from '../api/queries';
import type { ChainItem, PublicService } from '../api/types';
import { ServicePicker } from './ServicePicker';

/** One chain link as the UI holds it. `resourceId` null means "any staff" (picked in Task 13). */
export type Segment = {
	serviceId: number;
	resourceId: number | null;
};

/**
 * The server's own chain cap, mirrored: `AvailabilityController::MAX_SEGMENTS` and
 * `HoldsController::MAX_SEGMENTS` are both 5, enforced on `GET /availability` and `POST /holds`
 * alike - a longer chain would 400 twice before it could ever hold. `max` defaults to this so a
 * caller that passes nothing gets the widest chain the engine will actually accept.
 */
export const MAX_CHAIN_SEGMENTS = 5;

/**
 * The ONE camelCase-to-wire mapping, serving both consumers of `ChainItem` - `useAvailability()`'s
 * `items` and `AppointmentHoldInput.segments` - so the availability the visitor saw and the hold
 * they take are built from the same translation and cannot silently diverge.
 *
 * `resource_id` is ALWAYS written, as an explicit null for "any staff", never an absent key. The
 * server treats the two forms identically, but `JSON.stringify` does not: it keeps null and drops
 * undefined, and both the wire `items` parameter (`fetchAvailability`) and TanStack's query-key
 * hash stringify - mixing forms would split one server answer across two cache entries and two
 * request URLs. Explicit null is the form `Segment` itself dictates (`resourceId` is
 * `number | null`, never optional), so the mapping stays a plain rename with no branch.
 */
export function toChainItems( segments: Segment[] ): ChainItem[] {
	return segments.map( ( segment ) => ( {
		service_id: segment.serviceId,
		resource_id: segment.resourceId,
	} ) );
}

/**
 * The event rule as one pure predicate, judged on the chain a commit WOULD produce: more than one
 * segment and any of them an event. A segment absent from the active catalog cannot be classified
 * and counts as not-an-event - the server backstop covers that residue (see the header).
 */
function violatesEventRule( candidate: Segment[], catalog: PublicService[] ): boolean {
	return (
		candidate.length > 1 &&
		candidate.some(
			( segment ) =>
				'event' === catalog.find( ( service ) => service.id === segment.serviceId )?.type
		)
	);
}

/**
 * A segment can outlive its catalog entry: `GET /services` projects only status='active' rows, so
 * a service deactivated after page load never resolves again. The placeholder keeps the row
 * legible and the remove control meaningfully named - never '' and a bare "Remove ".
 */
function labelOf( serviceId: number, catalog: PublicService[] ): string {
	return (
		catalog.find( ( service ) => service.id === serviceId )?.name ??
		__( 'Unavailable service', 'reservant' )
	);
}

/**
 * Re-seats keyboard focus after a removal: the remove control now at the removed position, else
 * the last one, else the given fallback (the add button, when the chain just emptied).
 */
function focusRemoveControl(
	list: HTMLUListElement | null,
	index: number,
	fallback: HTMLButtonElement | null
): void {
	const removes = list?.querySelectorAll< HTMLButtonElement >( '.reservant-chain__remove' );
	const target =
		removes && removes.length > 0 ? removes[ Math.min( index, removes.length - 1 ) ] : fallback;
	target?.focus();
}

interface ChainBuilderProps {
	segments: Segment[];
	onChange: ( segments: Segment[] ) => void;
	/**
	 * The most segments a chain may hold - the add button is not rendered once it is reached.
	 * Defaults to `MAX_CHAIN_SEGMENTS`; passing more only invites the server's 400.
	 */
	max?: number;
}

export function ChainBuilder( {
	segments,
	onChange,
	max = MAX_CHAIN_SEGMENTS,
}: ChainBuilderProps ): JSX.Element {
	const { data: services, error, isPending } = useServices();
	const [ pickerOpen, setPickerOpen ] = useState( false );
	// The refusal keeps the exact segments array it was raised against, and `refused` below is
	// DERIVED from that reference still being the current prop. Deriving rather than latching a
	// boolean means a parent-driven replacement retires the notice by construction - no effect
	// watching props, no way to show a refusal about a chain that no longer exists.
	const [ refusedFor, setRefusedFor ] = useState< Segment[] | null >( null );
	// The nonce exists for the SECOND identical announcement: two consecutive removals of the
	// same service produce the same sentence, and a live region only speaks when its DOM
	// actually mutates - rendered as a plain string, React would diff the second, equal text
	// and write nothing, so the visitor hears one removal out of two. The nonce keys the text's
	// wrapper below, forcing a fresh text write per announcement while the region node itself
	// persists (a region must exist before its message lands).
	const [ announcement, setAnnouncement ] = useState< { text: string; nonce: number } | null >(
		null
	);
	const [ focusAfterRemove, setFocusAfterRemove ] = useState< number | null >( null );
	const listRef = useRef< HTMLUListElement | null >( null );
	const addRef = useRef< HTMLButtonElement | null >( null );
	const baseId = useId();
	const pickerId = `${ baseId }-picker`;
	const noticeId = `${ baseId }-notice`;

	// Removing a segment unmounts the last remove button (`key` is the index, and no stable key
	// exists - the same service twice is a legal chain), which would drop focus on <body>. Runs
	// after the parent has applied the new chain - the removal's `onChange` and this trigger
	// state land in the same batch.
	useEffect( () => {
		if ( null === focusAfterRemove ) {
			return;
		}
		focusRemoveControl( listRef.current, focusAfterRemove, addRef.current );
		setFocusAfterRemove( null );
	}, [ focusAfterRemove ] );

	if ( isPending ) {
		// No names, no add button, no picker while the catalog is in flight: nothing can be
		// classified yet, so offering to grow the chain would invite a commit no rule can judge -
		// and the preselected-service mount (block.json defaults `serviceId`) makes a non-empty
		// chain during this window the NORMAL case, not an exotic one.
		return (
			<p className="reservant-chain__status" role="status">
				{ __( 'Loading services...', 'reservant' ) }
			</p>
		);
	}

	if ( error && undefined === services ) {
		return (
			<p className="reservant-chain__status" role="alert">
				{ errorMessage( error ) }
			</p>
		);
	}

	const catalog = services ?? [];
	const refused = null !== refusedFor && refusedFor === segments;
	const belowMax = segments.length < max;

	const handleAddClick = (): void => {
		if ( pickerOpen ) {
			setPickerOpen( false );
			return;
		}
		// A UX nicety only - it saves opening a picker whose every choice would be refused. The
		// probe segment asks "would adding a plain appointment violate the rule?" (serviceId 0
		// matches no catalog row, so it can never read as an event): yes exactly when the chain
		// already holds an event. The complete check runs at the commit point in handlePick.
		if ( violatesEventRule( [ ...segments, { serviceId: 0, resourceId: null } ], catalog ) ) {
			setRefusedFor( segments );
			return;
		}
		setRefusedFor( null );
		setPickerOpen( true );
	};

	/**
	 * THE enforcement point for the event rule: judge the chain this pick would COMMIT, not the
	 * pick or the current chain alone. The chain may have changed under an open picker (a
	 * preselected service arriving from the parent, say), and only the candidate array sees both
	 * halves of every route there.
	 */
	const handlePick = ( serviceId: number ): void => {
		const next = [ ...segments, { serviceId, resourceId: null } ];
		if ( violatesEventRule( next, catalog ) ) {
			setRefusedFor( segments );
			setPickerOpen( false );
			return;
		}
		setRefusedFor( null );
		setPickerOpen( false );
		onChange( next );
	};

	const handleRemove = ( index: number, label: string ): void => {
		setRefusedFor( null );
		setAnnouncement( ( previous ) => ( {
			text: sprintf(
				/* translators: %s: service name. */
				__( '%s removed.', 'reservant' ),
				label
			),
			nonce: ( previous?.nonce ?? 0 ) + 1,
		} ) );
		setFocusAfterRemove( index );
		onChange( segments.filter( ( _segment, i ) => i !== index ) );
	};

	return (
		<div className="reservant-chain">
			<ul ref={ listRef } className="reservant-chain__list">
				{ segments.map( ( segment, index ) => {
					const label = labelOf( segment.serviceId, catalog );
					return (
						<li key={ index } className="reservant-chain__segment">
							<span className="reservant-chain__service">{ label }</span>
							<button
								type="button"
								className="reservant-chain__remove"
								aria-label={ sprintf(
									/* translators: %s: service name. */
									__( 'Remove %s', 'reservant' ),
									label
								) }
								onClick={ () => handleRemove( index, label ) }
							>
								{ __( 'Remove', 'reservant' ) }
							</button>
						</li>
					);
				} ) }
			</ul>
			{ /* role="status" on both regions, matching the widget's existing live-region
			     convention (assets/src/public/index.tsx): these are answers to the visitor's own
			     action, not emergencies, so a polite announcement is right and role="alert" would
			     interrupt. The removal region always exists so the announcement lands in a live
			     region that was already there. */ }
			<p className="reservant-chain__announcement" role="status">
				{ null !== announcement && (
					// The keyed fragment remounts its text node whenever the nonce moves, so an
					// announcement identical to the last one still mutates the region - the DOM
					// stays a bare text node inside the region, no wrapper element.
					<Fragment key={ announcement.nonce }>{ announcement.text }</Fragment>
				) }
			</p>
			{ refused && (
				<p id={ noticeId } className="reservant-chain__notice" role="status">
					{ __( 'Events are booked one at a time', 'reservant' ) }
				</p>
			) }
			{ belowMax && (
				<button
					ref={ addRef }
					type="button"
					className="reservant-chain__add"
					aria-expanded={ pickerOpen }
					aria-controls={ pickerOpen ? pickerId : undefined }
					aria-describedby={ refused ? noticeId : undefined }
					onClick={ handleAddClick }
				>
					{ pickerOpen
						? __( 'Close the service list', 'reservant' )
						: __( 'Add another service', 'reservant' ) }
				</button>
			) }
			{ belowMax && pickerOpen && (
				<div id={ pickerId } className="reservant-chain__picker">
					<ServicePicker value={ null } onChange={ handlePick } />
				</div>
			) }
		</div>
	);
}
