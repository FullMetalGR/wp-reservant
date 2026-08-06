import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, ButtonGroup, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { bootConfig } from '../boot';
import { useBookings, useResources, useServices } from '../api/queries';
import type { BookingFilters, BookingListResponse, BookingSummary, Resource, Service } from '../api/types';
import { utcToSite } from '../calendar/adapter';
import { BookingDrawer, formatMoney, statusLabel } from './BookingDrawer';

const PER_PAGE = 20;

const STATUS_OPTIONS: { value: string; label: string }[] = [
	{ value: '', label: __( 'Any status', 'reservant' ) },
	{ value: 'pending', label: __( 'Pending', 'reservant' ) },
	{ value: 'awaiting_approval', label: __( 'Awaiting approval', 'reservant' ) },
	{ value: 'awaiting_payment', label: __( 'Awaiting payment', 'reservant' ) },
	{ value: 'confirmed', label: __( 'Confirmed', 'reservant' ) },
	{ value: 'completed', label: __( 'Completed', 'reservant' ) },
	{ value: 'no_show', label: __( 'No-show', 'reservant' ) },
	{ value: 'cancelled', label: __( 'Cancelled', 'reservant' ) },
	{ value: 'rejected', label: __( 'Rejected', 'reservant' ) },
	{ value: 'expired', label: __( 'Expired', 'reservant' ) },
];

/**
 * The booking's own start for the list column - `items[0]` is the chain's first segment
 * (`sort ASC` server-side; AGENTS.md: chain segments run forward in time), so it is the booking's
 * own start. `null` for a booking whose items somehow came back empty (never expected in practice).
 */
function summaryStart( booking: BookingSummary ): string | null {
	return booking.items[ 0 ]?.start_utc ?? null;
}

/** `useBookings()`'s data, defaulted for the loading/error window before it arrives. */
function totalPagesFor( data: BookingListResponse | undefined ): number {
	return Math.max( 1, Math.ceil( ( data?.total ?? 0 ) / PER_PAGE ) );
}

interface FilterBarState {
	from: string;
	to: string;
	status: string;
	resourceId: number;
	serviceId: number;
	search: string;
}

interface FilterBarProps {
	state: FilterBarState;
	/** `undefined` while `useResources()`/`useServices()` are still loading. */
	resources: Resource[] | undefined;
	services: Service[] | undefined;
	onChange: ( patch: Partial< FilterBarState > ) => void;
	onApprovalInbox: () => void;
}

/** The date range, status, staff, service and search filters, plus the "Approval inbox" preset. */
function BookingsFilterBar( { state, resources = [], services = [], onChange, onApprovalInbox }: FilterBarProps ) {
	return (
		<div className="reservant-bookings-screen__toolbar">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'From', 'reservant' ) }
				type="date"
				value={ state.from }
				onChange={ ( value ) => onChange( { from: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'To', 'reservant' ) }
				type="date"
				value={ state.to }
				onChange={ ( value ) => onChange( { to: value } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Status', 'reservant' ) }
				value={ state.status }
				options={ STATUS_OPTIONS }
				onChange={ ( value ) => onChange( { status: value } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Staff', 'reservant' ) }
				value={ String( state.resourceId ) }
				options={ [
					{ label: __( 'All staff', 'reservant' ), value: '0' },
					...resources.map( ( resource ) => ( { label: resource.name, value: String( resource.id ) } ) ),
				] }
				onChange={ ( value ) => onChange( { resourceId: Number( value ) } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Service', 'reservant' ) }
				value={ String( state.serviceId ) }
				options={ [
					{ label: __( 'All services', 'reservant' ), value: '0' },
					...services.map( ( service ) => ( { label: service.name, value: String( service.id ) } ) ),
				] }
				onChange={ ( value ) => onChange( { serviceId: Number( value ) } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Search', 'reservant' ) }
				value={ state.search }
				onChange={ ( value ) => onChange( { search: value } ) }
			/>
			<Button variant={ 'awaiting_approval' === state.status ? 'primary' : 'secondary' } onClick={ onApprovalInbox }>
				{ __( 'Approval inbox', 'reservant' ) }
			</Button>
		</div>
	);
}

interface BookingsTableProps {
	/** `undefined` while `useBookings()` is still loading. */
	bookings: BookingSummary[] | undefined;
	timezone: string;
	onRowClick: ( uuid: string ) => void;
}

/** The plain (no `DataViews`) results table - a row click hash-routes to that booking's drawer. */
function BookingsTable( { bookings = [], timezone, onRowClick }: BookingsTableProps ) {
	return (
		<table className="reservant-bookings-table">
			<thead>
				<tr>
					<th>{ __( 'Customer', 'reservant' ) }</th>
					<th>{ __( 'Status', 'reservant' ) }</th>
					<th>{ __( 'When', 'reservant' ) }</th>
					<th>{ __( 'Total', 'reservant' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ bookings.map( ( booking ) => {
					const start = summaryStart( booking );
					return (
						<tr key={ booking.uuid } className="reservant-bookings-table__row" onClick={ () => onRowClick( booking.uuid ) }>
							<td>{ booking.customer_name }</td>
							<td>{ statusLabel( booking.status ) }</td>
							<td>{ null === start ? '' : utcToSite( start, timezone ).toLocaleString() }</td>
							<td>{ formatMoney( booking.total_minor, booking.currency ) }</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

interface PagerProps {
	page: number;
	totalPages: number;
	onPrevious: () => void;
	onNext: () => void;
}

function BookingsPager( { page, totalPages, onPrevious, onNext }: PagerProps ) {
	return (
		<ButtonGroup>
			<Button disabled={ page <= 1 } onClick={ onPrevious }>
				{ __( 'Previous', 'reservant' ) }
			</Button>
			<span className="reservant-bookings-screen__page">
				{ sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$d of %2$d', 'reservant' ),
					page,
					totalPages
				) }
			</span>
			<Button disabled={ page >= totalPages } onClick={ onNext }>
				{ __( 'Next', 'reservant' ) }
			</Button>
		</ButtonGroup>
	);
}

export interface BookingsScreenProps {
	/** The hash route's detail id (`#/{uuid}`) - when present, `BookingDrawer` opens over the table. */
	id?: string;
}

/**
 * The bookings list + approval inbox (Task 15 brief): `BookingsFilterBar` (date range, status,
 * staff, service, search, plus the "Approval inbox" preset), `BookingsTable` (a plain paged table -
 * no `DataViews`, per the brief), and a row click that hash-routes to `#/{uuid}` - `App`'s router
 * then re-renders this same screen with `id` set, which is all `BookingDrawer` needs to open.
 */
export function BookingsScreen( { id }: BookingsScreenProps ) {
	const { timezone } = bootConfig();
	const [ filterState, setFilterState ] = useState< FilterBarState >( {
		from: '',
		to: '',
		status: '',
		resourceId: 0,
		serviceId: 0,
		search: '',
	} );
	const [ page, setPage ] = useState( 1 );

	function updateFilters( patch: Partial< FilterBarState > ): void {
		setFilterState( ( current ) => ( { ...current, ...patch } ) );
		setPage( 1 );
	}

	const filters: BookingFilters = useMemo(
		() => ( {
			from: '' === filterState.from ? undefined : filterState.from,
			to: '' === filterState.to ? undefined : filterState.to,
			status: '' === filterState.status ? undefined : filterState.status,
			resource_id: 0 === filterState.resourceId ? undefined : filterState.resourceId,
			service_id: 0 === filterState.serviceId ? undefined : filterState.serviceId,
			search: '' === filterState.search ? undefined : filterState.search,
			page,
			per_page: PER_PAGE,
		} ),
		[ filterState, page ]
	);

	const bookingsQuery = useBookings( filters );
	const resourcesQuery = useResources();
	const servicesQuery = useServices();

	return (
		<div className="reservant-bookings-screen">
			<BookingsFilterBar
				state={ filterState }
				resources={ resourcesQuery.data }
				services={ servicesQuery.data }
				onChange={ updateFilters }
				onApprovalInbox={ () => updateFilters( { status: 'awaiting_approval' } ) }
			/>

			{ bookingsQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load bookings.', 'reservant' ) }
				</Notice>
			) }
			{ bookingsQuery.isLoading && <Spinner /> }

			<BookingsTable
				bookings={ bookingsQuery.data?.bookings }
				timezone={ timezone }
				onRowClick={ ( uuid ) => {
					window.location.hash = `#/${ uuid }`;
				} }
			/>

			<BookingsPager
				page={ page }
				totalPages={ totalPagesFor( bookingsQuery.data ) }
				onPrevious={ () => setPage( ( current ) => current - 1 ) }
				onNext={ () => setPage( ( current ) => current + 1 ) }
			/>

			{ undefined !== id && '' !== id && (
				<BookingDrawer
					uuid={ id }
					onClose={ () => {
						window.location.hash = '#/';
					} }
				/>
			) }
		</div>
	);
}
