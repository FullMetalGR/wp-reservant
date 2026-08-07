import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { bootConfig } from '../boot';
import { errorMessage, isReferencedConflict } from '../api/client';
import { useCancelOccurrence, useOccurrences, useSaveOccurrence, useServices } from '../api/queries';
import type { Occurrence, Service } from '../api/types';
import { siteToUtc, utcToSite } from '../calendar/adapter';
import { useToasts } from '../components/Toasts';

/**
 * Event services only - an occurrence has no meaning for an appointment service. Inactive event
 * services are offered too, marked as such: their existing occurrences still exist and may still
 * need cancelling, which this screen is the only place to do.
 */
function eventServiceOptions( services: Service[] ): { label: string; value: string }[] {
	return [
		{ label: __( 'Select an event', 'reservant' ), value: '0' },
		...services
			.filter( ( service ) => 'event' === service.type )
			.map( ( service ) => ( {
				label:
					'inactive' === service.status
						? sprintf(
								/* translators: %s: service name. */
								__( '%s (inactive)', 'reservant' ),
								service.name
						  )
						: service.name,
				value: String( service.id ),
			} ) ),
	];
}

interface OccurrencesTableProps {
	occurrences: Occurrence[];
	timezone: string;
	onCancel: ( occurrence: Occurrence ) => void;
}

function OccurrencesTable( { occurrences, timezone, onCancel }: OccurrencesTableProps ) {
	return (
		<table className="reservant-events-table">
			<thead>
				<tr>
					<th>{ __( 'Start', 'reservant' ) }</th>
					<th>{ __( 'End', 'reservant' ) }</th>
					<th>{ __( 'Booked / Capacity', 'reservant' ) }</th>
					<th>{ __( 'Status', 'reservant' ) }</th>
					<th />
				</tr>
			</thead>
			<tbody>
				{ occurrences.map( ( occurrence ) => (
					<tr key={ occurrence.id }>
						<td>{ utcToSite( occurrence.start_utc, timezone ).toLocaleString() }</td>
						<td>{ utcToSite( occurrence.end_utc, timezone ).toLocaleString() }</td>
						<td>{ `${ occurrence.booked } / ${ occurrence.capacity }` }</td>
						<td>{ 'active' === occurrence.status ? __( 'Active', 'reservant' ) : __( 'Cancelled', 'reservant' ) }</td>
						<td>
							{ 'active' === occurrence.status && (
								<Button variant="tertiary" isDestructive onClick={ () => onCancel( occurrence ) }>
									{ __( 'Cancel', 'reservant' ) }
								</Button>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

interface AddOccurrenceFormState {
	date: string;
	startTime: string;
	endTime: string;
	capacity: string;
}

function blankAddForm(): AddOccurrenceFormState {
	return { date: '', startTime: '09:00', endTime: '10:00', capacity: '10' };
}

/**
 * The events (occurrences) screen (Task 16 brief): an event-service select, the occurrence table
 * (booked/capacity, status), an add form whose site-tz date+time inputs are converted to the wire's
 * UTC strings via `siteToUtc` (never sent as raw local strings - the server's `utcDateTime()`
 * parser expects an unambiguous UTC wall-clock, per `OccurrencesAdminController`'s own docblock),
 * and a guarded cancel behind a confirm `Modal` (never `window.confirm` - a soft cancel that answers
 * 409 `referenced` while any booking still actively holds the occurrence).
 */
export function EventsScreen() {
	const { timezone } = bootConfig();
	const { addToast } = useToasts();
	const servicesQuery = useServices();

	const [ serviceId, setServiceId ] = useState( 0 );
	const [ addForm, setAddForm ] = useState< AddOccurrenceFormState >( blankAddForm() );
	const [ cancelTarget, setCancelTarget ] = useState< Occurrence | null >( null );
	const [ cancelConflict, setCancelConflict ] = useState< number | null >( null );

	const occurrencesQuery = useOccurrences( serviceId );
	const saveOccurrence = useSaveOccurrence();
	const cancelOccurrence = useCancelOccurrence();

	const selectedService = ( servicesQuery.data ?? [] ).find( ( service ) => service.id === serviceId ) ?? null;
	const needsCapacity = null !== selectedService && null === selectedService.seat_map_id;

	function patchAddForm( patch: Partial< AddOccurrenceFormState > ): void {
		setAddForm( ( current ) => ( { ...current, ...patch } ) );
	}

	function handleAdd(): void {
		if ( 0 === serviceId ) {
			return;
		}
		const startUtc = siteToUtc( addForm.date, addForm.startTime, timezone );
		const endUtc = siteToUtc( addForm.date, addForm.endTime, timezone );

		saveOccurrence.mutate(
			{
				service_id: serviceId,
				start_utc: startUtc,
				end_utc: endUtc,
				capacity: needsCapacity ? parseInt( addForm.capacity, 10 ) || 0 : undefined,
			},
			{
				onSuccess: () => {
					addToast( __( 'Occurrence added.', 'reservant' ) );
					setAddForm( blankAddForm() );
				},
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	function handleConfirmCancel(): void {
		if ( null === cancelTarget ) {
			return;
		}
		const target = cancelTarget;
		setCancelTarget( null );
		cancelOccurrence.mutate( target.id, {
			onSuccess: () => addToast( __( 'Occurrence cancelled.', 'reservant' ) ),
			onError: ( error ) => {
				if ( isReferencedConflict( error ) ) {
					setCancelConflict( target.id );
					return;
				}
				addToast( errorMessage( error ), 'error' );
			},
		} );
	}

	const canAdd = 0 !== serviceId && '' !== addForm.date.trim() && ( ! needsCapacity || '' !== addForm.capacity.trim() );

	return (
		<div className="reservant-events-screen">
			{ servicesQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load services.', 'reservant' ) }
				</Notice>
			) }

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Event', 'reservant' ) }
				value={ String( serviceId ) }
				options={ eventServiceOptions( servicesQuery.data ?? [] ) }
				onChange={ ( value ) => setServiceId( Number( value ) ) }
			/>

			{ 0 !== serviceId && (
				<>
					{ occurrencesQuery.isError && (
						<Notice status="error" isDismissible={ false }>
							{ __( 'Could not load occurrences.', 'reservant' ) }
						</Notice>
					) }
					{ occurrencesQuery.isLoading && <Spinner /> }

					{ cancelConflict !== null && (
						<Notice status="warning" isDismissible={ false } onRemove={ () => setCancelConflict( null ) }>
							{ __( 'This occurrence still has active bookings and cannot be cancelled.', 'reservant' ) }
						</Notice>
					) }

					<OccurrencesTable
						occurrences={ occurrencesQuery.data ?? [] }
						timezone={ timezone }
						onCancel={ ( occurrence ) => setCancelTarget( occurrence ) }
					/>

					<h3>{ __( 'Add an occurrence', 'reservant' ) }</h3>
					<p>{ `${ __( 'Times are entered in', 'reservant' ) } ${ timezone }` }</p>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'Date', 'reservant' ) }
						value={ addForm.date }
						onChange={ ( value ) => patchAddForm( { date: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'Start time', 'reservant' ) }
						value={ addForm.startTime }
						onChange={ ( value ) => patchAddForm( { startTime: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'End time', 'reservant' ) }
						value={ addForm.endTime }
						onChange={ ( value ) => patchAddForm( { endTime: value } ) }
					/>
					{ needsCapacity && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							label={ __( 'Capacity', 'reservant' ) }
							value={ addForm.capacity }
							onChange={ ( value ) => patchAddForm( { capacity: value } ) }
						/>
					) }
					{ ! needsCapacity && null !== selectedService && (
						<p>{ __( 'Capacity is derived from this event\'s seat map.', 'reservant' ) }</p>
					) }
					<Button variant="primary" disabled={ ! canAdd } isBusy={ saveOccurrence.isPending } onClick={ handleAdd }>
						{ __( 'Add occurrence', 'reservant' ) }
					</Button>
				</>
			) }

			{ null !== cancelTarget && (
				<Modal title={ __( 'Cancel this occurrence?', 'reservant' ) } onRequestClose={ () => setCancelTarget( null ) }>
					<p>{ __( 'Any existing bookings will remain, but no new ones can be made against it.', 'reservant' ) }</p>
					<Button variant="primary" isDestructive isBusy={ cancelOccurrence.isPending } onClick={ handleConfirmCancel }>
						{ __( 'Cancel occurrence', 'reservant' ) }
					</Button>
					<Button variant="tertiary" onClick={ () => setCancelTarget( null ) }>
						{ __( 'Keep it', 'reservant' ) }
					</Button>
				</Modal>
			) }
		</div>
	);
}
