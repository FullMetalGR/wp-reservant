import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, Modal, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { addDays, format } from 'date-fns';
import type { UseQueryResult } from '@tanstack/react-query';
import { bootConfig } from '../boot';
import { useAdminAvailability, useManualBooking, useResources, useServices } from '../api/queries';
import type { AvailabilityResponse, AvailabilityStart, ManualBookingSegment, Resource, Service } from '../api/types';
import { siteToday } from '../calendar/navigation';
import { useToasts } from '../components/Toasts';
import { errorMessage } from '../../shared';

interface SegmentState {
	serviceId: number;
	resourceId: number | undefined;
}

/** `SelectControl`'s "unselected" placeholder - `0` is never a real service id. */
const NO_SERVICE = '0';
/** `SelectControl`'s "no pin" option for the per-segment staff select. */
const ANY_STAFF = '';

/**
 * Both selects below filter on `status === 'active'`, and those filters are load-bearing:
 * `useServices()`/`useResources()` fetch the FULL catalog (`include_inactive=1` - the catalog
 * screens need inactive rows to be reactivatable, and `BookingsScreen` needs them to filter history
 * by a departed staff member). A retired service or a departed staff member must never be offered
 * as the target of a booking being created right now.
 */
function serviceOptions( services: Service[] ): { label: string; value: string }[] {
	return [
		{ label: __( 'Select a service', 'reservant' ), value: NO_SERVICE },
		// The chain builder only ever books appointment services - an event is a single fixed
		// occurrence, not a chainable segment, and has no `useAdminAvailability` appointment branch.
		...services
			.filter( ( service ) => 'appointment' === service.type && 'active' === service.status )
			.map( ( service ) => ( { label: service.name, value: String( service.id ) } ) ),
	];
}

function staffOptionsForService( resources: Resource[], serviceId: number ): { label: string; value: string }[] {
	return [
		{ label: __( 'Any staff', 'reservant' ), value: ANY_STAFF },
		...resources
			.filter( ( resource ) => 'active' === resource.status && resource.service_ids.includes( serviceId ) )
			.map( ( resource ) => ( { label: resource.name, value: String( resource.id ) } ) ),
	];
}

/**
 * Reads the wall-clock hour:minute straight off the server's own `local` ISO string
 * (`AvailabilityAdminController`: `$start->setTimezone($display)->format('c')`) rather than
 * round-tripping it through a `Date` and re-formatting - `date-fns`'s `format()` only ever reads a
 * `Date` through the HOST machine's local getters (the same caveat `shared/time.ts`'s
 * `utcToSite` docblock explains at length), which would silently show the wrong hour on any runner
 * not already in the site's own timezone. The string already carries the right wall-clock digits;
 * this only ever needs to extract them, never convert anything.
 */
function formatSlotLabel( start: AvailabilityStart ): string {
	const match = /T(\d{2}):(\d{2})/.exec( start.local );
	return null === match ? start.local : `${ match[ 1 ] }:${ match[ 2 ] }`;
}

/** The calendar date one day after `dateStr` (`yyyy-MM-dd`), computed on real calendar dates only. */
function nextDay( dateStr: string ): string {
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	return format( addDays( new Date( year ?? 1970, ( month ?? 1 ) - 1, day ?? 1 ), 1 ), 'yyyy-MM-dd' );
}

interface SegmentsEditorProps {
	segments: SegmentState[];
	services: Service[];
	resources: Resource[];
	sameStaff: boolean;
	onUpdateSegment: ( index: number, patch: Partial< SegmentState > ) => void;
	onAddSegment: () => void;
	onRemoveSegment: ( index: number ) => void;
	onSameStaffChange: ( value: boolean ) => void;
}

/** The chain builder: one row per segment (service + optional staff pin + remove), add, and the
 * "same staff throughout" preference - offered only once there is a chain to prefer it for. */
function SegmentsEditor( {
	segments,
	services,
	resources,
	sameStaff,
	onUpdateSegment,
	onAddSegment,
	onRemoveSegment,
	onSameStaffChange,
}: SegmentsEditorProps ) {
	return (
		<>
			{ segments.map( ( segment, index ) => (
				<div className="reservant-manual-booking-drawer__segment" key={ index }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Service', 'reservant' ) }
						value={ String( segment.serviceId ) }
						options={ serviceOptions( services ) }
						onChange={ ( value ) => onUpdateSegment( index, { serviceId: Number( value ) } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Staff', 'reservant' ) }
						value={ undefined === segment.resourceId ? ANY_STAFF : String( segment.resourceId ) }
						options={ staffOptionsForService( resources, segment.serviceId ) }
						onChange={ ( value ) => onUpdateSegment( index, { resourceId: ANY_STAFF === value ? undefined : Number( value ) } ) }
					/>
					{ segments.length > 1 && (
						<Button variant="tertiary" onClick={ () => onRemoveSegment( index ) }>
							{ __( 'Remove', 'reservant' ) }
						</Button>
					) }
				</div>
			) ) }

			<Button variant="secondary" onClick={ onAddSegment }>
				{ __( 'Add segment', 'reservant' ) }
			</Button>

			{ segments.length > 1 && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Prefer the same staff member for the whole chain', 'reservant' ) }
					checked={ sameStaff }
					onChange={ onSameStaffChange }
				/>
			) }
		</>
	);
}

/** The `useAdminAvailability` slot list: loading/error/empty states, then one button per start. */
function AvailabilitySlots( {
	query,
	selected,
	onSelect,
}: {
	query: UseQueryResult< AvailabilityResponse, Error >;
	selected: AvailabilityStart | null;
	onSelect: ( start: AvailabilityStart ) => void;
} ) {
	return (
		<>
			{ query.isLoading && <Spinner /> }
			{ query.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load availability.', 'reservant' ) }
				</Notice>
			) }
			{ query.data && 0 === query.data.starts.length && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No open times on this day.', 'reservant' ) }
				</Notice>
			) }
			<div className="reservant-manual-booking-drawer__slots">
				{ ( query.data?.starts ?? [] ).map( ( start ) => (
					<Button
						key={ start.utc }
						variant={ selected?.utc === start.utc ? 'primary' : 'secondary' }
						onClick={ () => onSelect( start ) }
					>
						{ formatSlotLabel( start ) }
					</Button>
				) ) }
			</div>
		</>
	);
}

/** The customer's own fields - always required (`ManualBookingCustomer`: name and email are mandatory). */
function CustomerFields( {
	name,
	email,
	phone,
	onChangeName,
	onChangeEmail,
	onChangePhone,
}: {
	name: string;
	email: string;
	phone: string;
	onChangeName: ( value: string ) => void;
	onChangeEmail: ( value: string ) => void;
	onChangePhone: ( value: string ) => void;
} ) {
	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Customer name', 'reservant' ) }
				value={ name }
				onChange={ onChangeName }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Customer email', 'reservant' ) }
				type="email"
				value={ email }
				onChange={ onChangeEmail }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Customer phone', 'reservant' ) }
				value={ phone }
				onChange={ onChangePhone }
			/>
		</>
	);
}

export interface ManualBookingDrawerProps {
	onClose: () => void;
	/** Prefill from a calendar slot click (`yyyy-MM-dd`); defaults to the SITE's today when omitted. */
	initialDate?: string;
	/** Prefill the first segment's staff pin from the calendar's own staff filter. */
	initialResourceId?: number;
}

/**
 * The owner's manual-booking drawer (Task 15 brief): `SegmentsEditor` (a chain of segments,
 * add/remove, each with an optional staff pin), a date, `AvailabilitySlots` (the resulting slot
 * list from `useAdminAvailability`), and `CustomerFields`. Submission always sends one of the
 * `starts[]` entries' own `utc` string verbatim - never a client-computed conversion - so the
 * segment/staff/start combination offered is exactly what `POST /admin/bookings` was already told
 * is feasible (AGENTS.md Task 10: "every start this endpoint offers is a start `POST
 * /admin/bookings` accepts").
 */
export function ManualBookingDrawer( { onClose, initialDate, initialResourceId }: ManualBookingDrawerProps ) {
	const { timezone } = bootConfig();
	const { addToast } = useToasts();

	// `siteToday( timezone )`, never `format( new Date(), ... )`: the default day must be the
	// BUSINESS's today, not the admin's laptop's. See `shared/time.ts`'s `siteNow` docblock -
	// an owner in US/Pacific opening this drawer at 16:00 local is already on the next day at a
	// Europe/Athens business, and a host-local default would fetch the wrong day's slots.
	const [ date, setDate ] = useState( () => initialDate ?? siteToday( timezone ) );
	const [ segments, setSegments ] = useState< SegmentState[] >( [ { serviceId: 0, resourceId: initialResourceId } ] );
	const [ sameStaff, setSameStaff ] = useState( false );
	const [ selectedStart, setSelectedStart ] = useState< AvailabilityStart | null >( null );
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );

	const servicesQuery = useServices();
	const resourcesQuery = useResources();

	// Defaults the first segment to the first bookable service once the catalog loads, so a
	// single-service business never has to touch the select at all.
	useEffect( () => {
		if ( undefined === servicesQuery.data ) {
			return;
		}
		const firstService = servicesQuery.data.find( ( service ) => 'appointment' === service.type && 'active' === service.status );
		if ( undefined === firstService ) {
			return;
		}
		setSegments( ( current ) => {
			const first = current[ 0 ];
			return undefined !== first && 0 === first.serviceId
				? [ { ...first, serviceId: firstService.id }, ...current.slice( 1 ) ]
				: current;
		} );
	}, [ servicesQuery.data ] );

	const items: ManualBookingSegment[] = useMemo(
		() => segments.map( ( segment ) => ( { service_id: segment.serviceId, resource_id: segment.resourceId } ) ),
		[ segments ]
	);
	const itemsKey = JSON.stringify( items );
	const range = useMemo( () => ( { from: date, to: nextDay( date ) } ), [ date ] );

	const availabilityQuery = useAdminAvailability( items, range, { sameStaff } );
	const manualBooking = useManualBooking();

	// A previously-chosen slot is only valid for the exact chain/day/preference it was offered
	// under - if any of those change, it must be re-chosen rather than silently submitted stale.
	useEffect( () => {
		setSelectedStart( null );
	}, [ date, itemsKey, sameStaff ] );

	function updateSegment( index: number, patch: Partial< SegmentState > ): void {
		setSegments( ( current ) => current.map( ( segment, i ) => ( i === index ? { ...segment, ...patch } : segment ) ) );
	}

	function addSegment(): void {
		setSegments( ( current ) => [ ...current, { serviceId: 0, resourceId: undefined } ] );
	}

	function removeSegment( index: number ): void {
		setSegments( ( current ) => ( current.length > 1 ? current.filter( ( _segment, i ) => i !== index ) : current ) );
	}

	const canSubmit = '' !== name.trim() && '' !== email.trim() && null !== selectedStart;

	function handleSubmit(): void {
		if ( null === selectedStart ) {
			return;
		}
		manualBooking.mutate(
			{
				customer: { name, email, phone: '' === phone.trim() ? undefined : phone },
				appointment: {
					start_utc: selectedStart.utc,
					segments: segments.map( ( segment ) => ( { service_id: segment.serviceId, resource_id: segment.resourceId } ) ),
					same_staff: sameStaff,
				},
			},
			{
				onSuccess: () => {
					addToast( __( 'Booking created.', 'reservant' ) );
					onClose();
				},
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	return (
		<Modal title={ __( 'New booking', 'reservant' ) } onRequestClose={ onClose } className="reservant-manual-booking-drawer">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Date', 'reservant' ) }
				type="date"
				value={ date }
				onChange={ setDate }
			/>

			<SegmentsEditor
				segments={ segments }
				services={ servicesQuery.data ?? [] }
				resources={ resourcesQuery.data ?? [] }
				sameStaff={ sameStaff }
				onUpdateSegment={ updateSegment }
				onAddSegment={ addSegment }
				onRemoveSegment={ removeSegment }
				onSameStaffChange={ setSameStaff }
			/>

			<h3>{ __( 'Available times', 'reservant' ) }</h3>
			<p>{ `${ __( 'Times shown in', 'reservant' ) } ${ timezone }` }</p>
			<AvailabilitySlots query={ availabilityQuery } selected={ selectedStart } onSelect={ setSelectedStart } />

			<CustomerFields
				name={ name }
				email={ email }
				phone={ phone }
				onChangeName={ setName }
				onChangeEmail={ setEmail }
				onChangePhone={ setPhone }
			/>

			<Button variant="primary" disabled={ ! canSubmit } isBusy={ manualBooking.isPending } onClick={ handleSubmit }>
				{ __( 'Create booking', 'reservant' ) }
			</Button>
		</Modal>
	);
}
