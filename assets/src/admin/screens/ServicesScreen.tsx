import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, Modal, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { bootConfig } from '../boot';
import { isReferencedConflict } from '../api/client';
import { useDeleteService, useSaveService, useSeatMaps, useServices } from '../api/queries';
import type { ApprovalTimeout, PaymentMode, Service, ServiceType } from '../api/types';
import { useToasts } from '../components/Toasts';

function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : __( 'Something went wrong.', 'reservant' );
}

const TYPE_OPTIONS: { value: ServiceType; label: string }[] = [
	{ value: 'appointment', label: __( 'Appointment', 'reservant' ) },
	{ value: 'event', label: __( 'Event', 'reservant' ) },
];

const PAYMENT_MODE_OPTIONS: { value: PaymentMode; label: string }[] = [
	{ value: 'free', label: __( 'Free', 'reservant' ) },
	{ value: 'online', label: __( 'Online', 'reservant' ) },
	{ value: 'onsite', label: __( 'On-site', 'reservant' ) },
];

const APPROVAL_TIMEOUT_OPTIONS: { value: ApprovalTimeout; label: string }[] = [
	{ value: 'expire', label: __( 'Expire the booking', 'reservant' ) },
	{ value: 'auto_approve', label: __( 'Auto-approve', 'reservant' ) },
];

/** The editable form fields, string/boolean at the input layer - parsed to the wire's numeric types only on save. */
interface ServiceFormState {
	name: string;
	type: ServiceType;
	durationMin: string;
	processingTimeMin: string;
	bufferBeforeMin: string;
	bufferAfterMin: string;
	capacity: string;
	seatMapId: string;
	priceMinor: string;
	currency: string;
	paymentMode: PaymentMode;
	requiresApproval: boolean;
	approvalHoldHours: string;
	onApprovalTimeout: ApprovalTimeout;
	cancelWindowHours: string;
	rescheduleWindowHours: string;
	leadTimeMin: string;
	horizonDays: string;
	status: Service[ 'status' ];
}

function blankForm( currency: string ): ServiceFormState {
	return {
		name: '',
		type: 'appointment',
		durationMin: '30',
		processingTimeMin: '0',
		bufferBeforeMin: '0',
		bufferAfterMin: '0',
		capacity: '1',
		seatMapId: '',
		priceMinor: '0',
		currency,
		paymentMode: 'onsite',
		requiresApproval: false,
		approvalHoldHours: '48',
		onApprovalTimeout: 'expire',
		cancelWindowHours: '24',
		rescheduleWindowHours: '24',
		leadTimeMin: '0',
		horizonDays: '60',
		status: 'active',
	};
}

function formFromService( service: Service ): ServiceFormState {
	return {
		name: service.name,
		type: service.type,
		durationMin: String( service.duration_min ),
		processingTimeMin: String( service.processing_time_min ),
		bufferBeforeMin: String( service.buffer_before_min ),
		bufferAfterMin: String( service.buffer_after_min ),
		capacity: String( service.capacity ),
		seatMapId: null === service.seat_map_id ? '' : String( service.seat_map_id ),
		priceMinor: String( service.price_minor ),
		currency: service.currency,
		paymentMode: service.payment_mode,
		requiresApproval: service.requires_approval,
		approvalHoldHours: String( service.approval_hold_hours ),
		onApprovalTimeout: service.on_approval_timeout,
		cancelWindowHours: String( service.cancel_window_hours ),
		rescheduleWindowHours: String( service.reschedule_window_hours ),
		leadTimeMin: String( service.lead_time_min ),
		horizonDays: String( service.horizon_days ),
		status: service.status,
	};
}

/** `int(value)` for a control whose `onChange` only ever hands back digit-only text (`type="number"`); `''`/garbage folds to 0 rather than `NaN` leaking into the request body. */
function toInt( value: string ): number {
	const parsed = parseInt( value, 10 );
	return Number.isNaN( parsed ) ? 0 : parsed;
}

/** Everything `ServicesAdminController::FIELDS` accepts, built from the form state - `seat_map_id` folds a blank select back to `null`. */
function toPatch( form: ServiceFormState ): Record< string, unknown > {
	return {
		name: form.name,
		type: form.type,
		duration_min: toInt( form.durationMin ),
		processing_time_min: toInt( form.processingTimeMin ),
		buffer_before_min: toInt( form.bufferBeforeMin ),
		buffer_after_min: toInt( form.bufferAfterMin ),
		capacity: toInt( form.capacity ),
		seat_map_id: '' === form.seatMapId ? null : toInt( form.seatMapId ),
		price_minor: toInt( form.priceMinor ),
		currency: form.currency.toUpperCase(),
		payment_mode: form.paymentMode,
		requires_approval: form.requiresApproval,
		approval_hold_hours: toInt( form.approvalHoldHours ),
		on_approval_timeout: form.onApprovalTimeout,
		cancel_window_hours: toInt( form.cancelWindowHours ),
		reschedule_window_hours: toInt( form.rescheduleWindowHours ),
		lead_time_min: toInt( form.leadTimeMin ),
		horizon_days: toInt( form.horizonDays ),
		status: form.status,
	};
}

interface ServicesTableProps {
	services: Service[];
	selectedId: number | null;
	onSelect: ( service: Service ) => void;
}

function ServicesTable( { services, selectedId, onSelect }: ServicesTableProps ) {
	return (
		<table className="reservant-services-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'reservant' ) }</th>
					<th>{ __( 'Type', 'reservant' ) }</th>
					<th>{ __( 'Duration/Capacity', 'reservant' ) }</th>
					<th>{ __( 'Status', 'reservant' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ services.map( ( service ) => (
					<tr
						key={ service.id }
						className={
							service.id === selectedId
								? 'reservant-services-table__row reservant-services-table__row--selected'
								: 'reservant-services-table__row'
						}
						onClick={ () => onSelect( service ) }
					>
						<td>{ service.name }</td>
						<td>{ 'appointment' === service.type ? __( 'Appointment', 'reservant' ) : __( 'Event', 'reservant' ) }</td>
						<td>{ 'appointment' === service.type ? `${ service.duration_min } min` : service.capacity }</td>
						<td>{ 'active' === service.status ? __( 'Active', 'reservant' ) : __( 'Inactive', 'reservant' ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The service catalog screen (Task 16 brief): a table plus an edit form covering every
 * `ServicesAdminController::FIELDS` column, an appointment/event conditional split (`duration_min`
 * only for an appointment, `capacity`/`seat_map_id` only for an event), a duration stepper in
 * `granularityMin` multiples (`ServicesAdminController::validateBusinessRules()`'s own rule - a
 * server-side 400 catches anything this stepper cannot prevent, e.g. a pasted value), and a
 * delete-or-deactivate flow: deleting a service the booking history still references answers 409
 * `referenced`, rendered here as an inline suggestion to deactivate instead (`PUT {status:
 * 'inactive'}`, which the guard never blocks).
 */
export function ServicesScreen() {
	const { currency, granularityMin } = bootConfig();
	const { addToast } = useToasts();
	const servicesQuery = useServices();
	const seatMapsQuery = useSeatMaps();
	const saveService = useSaveService();
	const deleteService = useDeleteService();

	const [ selectedId, setSelectedId ] = useState< number | null >( null );
	const [ form, setForm ] = useState< ServiceFormState >( blankForm( currency ) );
	const [ deleteConflictId, setDeleteConflictId ] = useState< number | null >( null );
	const [ confirmDeleteOpen, setConfirmDeleteOpen ] = useState( false );

	const services = servicesQuery.data ?? [];

	function patchForm( fields: Partial< ServiceFormState > ): void {
		setForm( ( current ) => ( { ...current, ...fields } ) );
	}

	function selectService( service: Service ): void {
		setSelectedId( service.id );
		setForm( formFromService( service ) );
		setDeleteConflictId( null );
	}

	function startNew(): void {
		setSelectedId( null );
		setForm( blankForm( currency ) );
		setDeleteConflictId( null );
	}

	function handleSave(): void {
		saveService.mutate(
			{ id: selectedId ?? undefined, ...toPatch( form ) },
			{
				onSuccess: ( saved ) => {
					addToast( __( 'Service saved.', 'reservant' ) );
					setSelectedId( saved.id );
					setForm( formFromService( saved ) );
				},
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	function handleDelete(): void {
		if ( null === selectedId ) {
			return;
		}
		setConfirmDeleteOpen( false );
		const id = selectedId;
		deleteService.mutate( id, {
			onSuccess: () => {
				addToast( __( 'Service deleted.', 'reservant' ) );
				startNew();
			},
			onError: ( error ) => {
				if ( isReferencedConflict( error ) ) {
					setDeleteConflictId( id );
					return;
				}
				addToast( errorMessage( error ), 'error' );
			},
		} );
	}

	function handleDeactivateInstead(): void {
		if ( null === selectedId ) {
			return;
		}
		saveService.mutate(
			{ id: selectedId, status: 'inactive' },
			{
				onSuccess: ( saved ) => {
					addToast( __( 'Service deactivated.', 'reservant' ) );
					setForm( formFromService( saved ) );
					setDeleteConflictId( null );
				},
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	const canSave = '' !== form.name.trim();

	return (
		<div className="reservant-services-screen">
			{ servicesQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load services.', 'reservant' ) }
				</Notice>
			) }
			{ servicesQuery.isLoading && <Spinner /> }

			<Button variant="primary" onClick={ startNew }>
				{ __( 'New service', 'reservant' ) }
			</Button>

			<ServicesTable services={ services } selectedId={ selectedId } onSelect={ selectService } />

			<div className="reservant-services-screen__editor">
				<h2>{ null === selectedId ? __( 'New service', 'reservant' ) : __( 'Edit service', 'reservant' ) }</h2>

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'reservant' ) }
					value={ form.name }
					onChange={ ( value ) => patchForm( { name: value } ) }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Type', 'reservant' ) }
					value={ form.type }
					options={ TYPE_OPTIONS }
					onChange={ ( value ) => patchForm( { type: value as ServiceType } ) }
				/>

				{ 'appointment' === form.type && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						label={ __( 'Duration (minutes)', 'reservant' ) }
						help={ __( 'Must be a multiple of the slot granularity.', 'reservant' ) }
						step={ granularityMin }
						min={ granularityMin }
						value={ form.durationMin }
						onChange={ ( value ) => patchForm( { durationMin: value } ) }
					/>
				) }

				{ 'event' === form.type && (
					<>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							label={ __( 'Capacity', 'reservant' ) }
							help={ __( 'Ignored once a seat map is chosen below - capacity is then derived from the grid.', 'reservant' ) }
							value={ form.capacity }
							onChange={ ( value ) => patchForm( { capacity: value } ) }
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Seat map', 'reservant' ) }
							value={ form.seatMapId }
							options={ [
								{ label: __( 'None (plain capacity)', 'reservant' ), value: '' },
								...( seatMapsQuery.data ?? [] ).map( ( map ) => ( { label: map.name, value: String( map.id ) } ) ),
							] }
							onChange={ ( value ) => patchForm( { seatMapId: value } ) }
						/>
					</>
				) }

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Processing time (minutes)', 'reservant' ) }
					value={ form.processingTimeMin }
					onChange={ ( value ) => patchForm( { processingTimeMin: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Buffer before (minutes)', 'reservant' ) }
					value={ form.bufferBeforeMin }
					onChange={ ( value ) => patchForm( { bufferBeforeMin: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Buffer after (minutes)', 'reservant' ) }
					value={ form.bufferAfterMin }
					onChange={ ( value ) => patchForm( { bufferAfterMin: value } ) }
				/>

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Price (minor units)', 'reservant' ) }
					help={ __( 'e.g. 2500 for 25.00.', 'reservant' ) }
					value={ form.priceMinor }
					onChange={ ( value ) => patchForm( { priceMinor: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Currency', 'reservant' ) }
					maxLength={ 3 }
					value={ form.currency }
					onChange={ ( value ) => patchForm( { currency: value.toUpperCase() } ) }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Payment mode', 'reservant' ) }
					value={ form.paymentMode }
					options={ PAYMENT_MODE_OPTIONS }
					onChange={ ( value ) => patchForm( { paymentMode: value as PaymentMode } ) }
				/>

				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Requires approval', 'reservant' ) }
					checked={ form.requiresApproval }
					onChange={ ( value ) => patchForm( { requiresApproval: value } ) }
				/>

				{ form.requiresApproval && (
					<>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							label={ __( 'Approval hold (hours)', 'reservant' ) }
							value={ form.approvalHoldHours }
							onChange={ ( value ) => patchForm( { approvalHoldHours: value } ) }
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'When the approval hold expires', 'reservant' ) }
							value={ form.onApprovalTimeout }
							options={ APPROVAL_TIMEOUT_OPTIONS }
							onChange={ ( value ) => patchForm( { onApprovalTimeout: value as ApprovalTimeout } ) }
						/>
					</>
				) }

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Cancel window (hours)', 'reservant' ) }
					value={ form.cancelWindowHours }
					onChange={ ( value ) => patchForm( { cancelWindowHours: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Reschedule window (hours)', 'reservant' ) }
					value={ form.rescheduleWindowHours }
					onChange={ ( value ) => patchForm( { rescheduleWindowHours: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Lead time (minutes)', 'reservant' ) }
					value={ form.leadTimeMin }
					onChange={ ( value ) => patchForm( { leadTimeMin: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Booking horizon (days)', 'reservant' ) }
					value={ form.horizonDays }
					onChange={ ( value ) => patchForm( { horizonDays: value } ) }
				/>

				{ null !== selectedId && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'reservant' ) }
						value={ form.status }
						options={ [
							{ label: __( 'Active', 'reservant' ), value: 'active' },
							{ label: __( 'Inactive', 'reservant' ), value: 'inactive' },
						] }
						onChange={ ( value ) => patchForm( { status: value as Service[ 'status' ] } ) }
					/>
				) }

				<Button variant="primary" disabled={ ! canSave } isBusy={ saveService.isPending } onClick={ handleSave }>
					{ __( 'Save service', 'reservant' ) }
				</Button>

				{ null !== selectedId && (
					<Button variant="secondary" isDestructive isBusy={ deleteService.isPending } onClick={ () => setConfirmDeleteOpen( true ) }>
						{ __( 'Delete service', 'reservant' ) }
					</Button>
				) }

				{ deleteConflictId === selectedId && null !== selectedId && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'This service is still used by existing bookings and cannot be deleted.', 'reservant' ) }
						{ ' ' }
						<Button variant="link" onClick={ handleDeactivateInstead }>
							{ __( 'Deactivate it instead.', 'reservant' ) }
						</Button>
					</Notice>
				) }
			</div>

			{ confirmDeleteOpen && (
				<Modal title={ __( 'Delete service?', 'reservant' ) } onRequestClose={ () => setConfirmDeleteOpen( false ) }>
					<p>{ __( 'This cannot be undone.', 'reservant' ) }</p>
					<Button variant="primary" isDestructive onClick={ handleDelete }>
						{ __( 'Delete', 'reservant' ) }
					</Button>
					<Button variant="tertiary" onClick={ () => setConfirmDeleteOpen( false ) }>
						{ __( 'Cancel', 'reservant' ) }
					</Button>
				</Modal>
			) }
		</div>
	);
}
