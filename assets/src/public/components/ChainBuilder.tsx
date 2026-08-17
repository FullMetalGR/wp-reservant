/**
 * The chain as the visitor builds it (P5 plan, Task 12): the segments so far, a remove per
 * segment, and an add flow that opens a `ServicePicker` for the next one. Fully controlled -
 * every change flows out through `onChange`, nothing is held here but the picker's open state.
 *
 * The one rule it enforces early: a chain containing an EVENT never grows past one segment, in
 * either direction (event first, then anything; or anything first, then an event). The engine
 * rejects both anyway (`AvailabilityController::availability()` 400s an event with more than one
 * item, and an event holds through `occurrence_id`, not segments), so refusing here with the
 * sentence "Events are booked one at a time" spares the visitor a doomed round trip.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useServices } from '../api/queries';
import type { ChainItem } from '../api/types';
import { ServicePicker } from './ServicePicker';

/** One chain link as the UI holds it. `resourceId` null means "any staff" (picked in Task 13). */
export type Segment = {
	serviceId: number;
	resourceId: number | null;
};

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

interface ChainBuilderProps {
	segments: Segment[];
	onChange: ( segments: Segment[] ) => void;
	/** The most segments a chain may hold - the add button is not rendered once it is reached. */
	max: number;
}

export function ChainBuilder( { segments, onChange, max }: ChainBuilderProps ): JSX.Element {
	const services = useServices().data ?? [];
	const [ pickerOpen, setPickerOpen ] = useState( false );
	const [ refused, setRefused ] = useState( false );

	const isEvent = ( serviceId: number ): boolean =>
		'event' === services.find( ( service ) => service.id === serviceId )?.type;
	const nameOf = ( serviceId: number ): string =>
		services.find( ( service ) => service.id === serviceId )?.name ?? '';

	const handleAddClick = (): void => {
		if ( segments.some( ( segment ) => isEvent( segment.serviceId ) ) ) {
			setRefused( true );
			return;
		}
		setRefused( false );
		setPickerOpen( ( open ) => ! open );
	};

	const handlePick = ( serviceId: number ): void => {
		if ( segments.length > 0 && isEvent( serviceId ) ) {
			setRefused( true );
			return;
		}
		setRefused( false );
		setPickerOpen( false );
		onChange( [ ...segments, { serviceId, resourceId: null } ] );
	};

	const handleRemove = ( index: number ): void => {
		onChange( segments.filter( ( _segment, i ) => i !== index ) );
	};

	const belowMax = segments.length < max;

	return (
		<div className="reservant-chain">
			<ul className="reservant-chain__list">
				{ segments.map( ( segment, index ) => (
					<li key={ index } className="reservant-chain__segment">
						<span className="reservant-chain__service">{ nameOf( segment.serviceId ) }</span>
						<button
							type="button"
							className="reservant-chain__remove"
							aria-label={ sprintf(
								/* translators: %s: service name. */
								__( 'Remove %s', 'reservant' ),
								nameOf( segment.serviceId )
							) }
							onClick={ () => handleRemove( index ) }
						>
							{ __( 'Remove', 'reservant' ) }
						</button>
					</li>
				) ) }
			</ul>
			{ refused && (
				<p className="reservant-chain__notice" role="status">
					{ __( 'Events are booked one at a time', 'reservant' ) }
				</p>
			) }
			{ belowMax && (
				<button
					type="button"
					className="reservant-chain__add"
					aria-expanded={ pickerOpen }
					onClick={ handleAddClick }
				>
					{ __( 'Add another service', 'reservant' ) }
				</button>
			) }
			{ belowMax && pickerOpen && (
				<div className="reservant-chain__picker">
					<ServicePicker value={ null } onChange={ handlePick } />
				</div>
			) }
		</div>
	);
}
