import {
	useMutation,
	useQuery,
	useQueryClient,
	type UseMutationResult,
	type UseQueryResult,
} from '@tanstack/react-query';
import { addDays, format, parseISO } from 'date-fns';
import { bootConfig } from '../boot';
import { apiFetch } from './client';
import type {
	AvailabilityResponse,
	BookingDetail,
	BookingFilters,
	BookingListResponse,
	BookingSummary,
	CalendarResponse,
	ManualBookingRequest,
	ManualBookingSegment,
	Occurrence,
	OccurrencesResponse,
	Resource,
	ResourcesResponse,
	SeatMap,
	SeatMapsResponse,
	Service,
	ServicesResponse,
	SettingsPayload,
} from './types';

/** A date-only range, `to` exclusive - matches `CalendarAdminController::window()`. */
export interface CalendarRange {
	from: string;
	to: string;
}

/** Builds a `?a=b&c=d` query string, dropping keys that are absent, empty, or zero. */
function toQueryString( params: Record< string, string | number | boolean | undefined > ): string {
	const search = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( undefined === value || '' === value || 0 === value ) {
			continue;
		}
		search.set( key, String( value ) );
	}
	const query = search.toString();
	return '' === query ? '' : `?${ query }`;
}

/**
 * `BookingRepository::search()` compares `to` exclusively (`i.start_utc < %s`, midnight of that
 * date) - a filter bar's "to" date picker is naturally inclusive ("show me through this day"), so
 * the query layer advances it one day before it ever reaches the wire. Doing it once here, rather
 * than at every call site, is what keeps that exclusive-boundary quirk from needing to be
 * remembered by whichever screen builds the filters.
 */
function normalizeBookingFilters( filters: BookingFilters ): BookingFilters {
	if ( undefined === filters.to || '' === filters.to ) {
		return filters;
	}
	return { ...filters, to: format( addDays( parseISO( filters.to ), 1 ), 'yyyy-MM-dd' ) };
}

export function useBookings( filters: BookingFilters ): UseQueryResult< BookingListResponse, Error > {
	return useQuery( {
		queryKey: [ 'bookings', filters ],
		queryFn: () =>
			apiFetch< BookingListResponse >( `/admin/bookings${ toQueryString( { ...normalizeBookingFilters( filters ) } ) }` ),
	} );
}

/** `GET /admin/bookings/{uuid}` - the summary shape plus the full audit trail, for `BookingDrawer`. */
export function useBooking( uuid: string ): UseQueryResult< BookingDetail, Error > {
	return useQuery( {
		queryKey: [ 'booking', uuid ],
		queryFn: () => apiFetch< BookingDetail >( `/admin/bookings/${ uuid }` ),
		enabled: '' !== uuid,
	} );
}

export function useCalendar( range: CalendarRange, resourceId?: number ): UseQueryResult< CalendarResponse, Error > {
	return useQuery( {
		queryKey: [ 'calendar', range, resourceId ?? null ],
		queryFn: () =>
			apiFetch< CalendarResponse >(
				`/admin/calendar${ toQueryString( { from: range.from, to: range.to, resource_id: resourceId } ) }`
			),
	} );
}

export function useServices(): UseQueryResult< Service[], Error > {
	return useQuery( {
		queryKey: [ 'services' ],
		queryFn: async () => ( await apiFetch< ServicesResponse >( '/admin/services' ) ).services,
	} );
}

export function useResources(): UseQueryResult< Resource[], Error > {
	return useQuery( {
		queryKey: [ 'resources' ],
		queryFn: async () => ( await apiFetch< ResourcesResponse >( '/admin/resources' ) ).resources,
	} );
}

export function useOccurrences( serviceId: number ): UseQueryResult< Occurrence[], Error > {
	return useQuery( {
		queryKey: [ 'occurrences', serviceId ],
		queryFn: async () =>
			( await apiFetch< OccurrencesResponse >( `/admin/occurrences${ toQueryString( { service_id: serviceId } ) }` ) )
				.occurrences,
		enabled: serviceId > 0,
	} );
}

export function useSeatMaps(): UseQueryResult< SeatMap[], Error > {
	return useQuery( {
		queryKey: [ 'seat-maps' ],
		queryFn: async () => ( await apiFetch< SeatMapsResponse >( '/admin/seat-maps' ) ).seat_maps,
	} );
}

export function useSettings(): UseQueryResult< SettingsPayload, Error > {
	return useQuery( {
		queryKey: [ 'settings' ],
		queryFn: () => apiFetch< SettingsPayload >( '/admin/settings' ),
	} );
}

/**
 * Every booking lifecycle mutation (approve/reject/cancel/outcome/manual create) shares the same
 * aftermath: the list, the calendar and (if `BookingDrawer` is open on this same booking) the
 * detail view may now all be showing a stale row, so every cache key is invalidated together
 * rather than each call site repeating the trio.
 */
function useBookingMutation< TVariables >(
	mutationFn: ( variables: TVariables ) => Promise< BookingSummary >
): UseMutationResult< BookingSummary, Error, TVariables > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn,
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'bookings' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'booking' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}

export function useApprove(): UseMutationResult< BookingSummary, Error, string > {
	return useBookingMutation( ( uuid: string ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/approve`, { method: 'POST' } )
	);
}

export interface RejectVariables {
	uuid: string;
	reason?: string;
}

export function useReject(): UseMutationResult< BookingSummary, Error, RejectVariables > {
	return useBookingMutation( ( { uuid, reason }: RejectVariables ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/reject`, {
			method: 'POST',
			body: JSON.stringify( { reason: reason ?? '' } ),
		} )
	);
}

export function useCancelBooking(): UseMutationResult< BookingSummary, Error, string > {
	return useBookingMutation( ( uuid: string ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/cancel`, { method: 'POST' } )
	);
}

export interface OutcomeVariables {
	uuid: string;
	outcome: 'completed' | 'no_show';
}

export function useOutcome(): UseMutationResult< BookingSummary, Error, OutcomeVariables > {
	return useBookingMutation( ( { uuid, outcome }: OutcomeVariables ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/${ 'completed' === outcome ? 'complete' : 'no_show' }`, {
			method: 'POST',
		} )
	);
}

export function useManualBooking(): UseMutationResult< BookingSummary, Error, ManualBookingRequest > {
	return useBookingMutation( ( request: ManualBookingRequest ) =>
		apiFetch< BookingSummary >( '/admin/bookings', { method: 'POST', body: JSON.stringify( request ) } )
	);
}

export interface AvailabilityOptions {
	/** The chain-wide "prefer one staff member throughout" preference; defaults to `false`. */
	sameStaff?: boolean;
	/** Set `false` to suspend the query even when `items`/`range` would otherwise be valid. */
	enabled?: boolean;
}

/**
 * `GET /admin/availability` (`AvailabilityAdminController`, appointment branch): the manual
 * booking drawer's slot list. `items` is the ordered chain (`ManualBookingSegment` - the same
 * `{service_id, resource_id?}` shape `POST /admin/bookings`'s own `appointment.segments` takes, so
 * a chosen start/segment combination here is guaranteed accepted there too - AGENTS.md Task 10's
 * "every start this endpoint offers is a start `POST /admin/bookings` accepts"), JSON-encoded
 * exactly as the endpoint's `items` query param expects. Disabled until every segment names a real
 * service - an empty or half-built chain would 400.
 */
export function useAdminAvailability(
	items: ManualBookingSegment[],
	range: CalendarRange,
	options: AvailabilityOptions = {}
): UseQueryResult< AvailabilityResponse, Error > {
	const { timezone } = bootConfig();
	const itemsJson = JSON.stringify( items );
	const sameStaff = options.sameStaff ?? false;
	const enabled =
		( options.enabled ?? true ) && items.length > 0 && items.every( ( item ) => item.service_id > 0 );

	return useQuery( {
		queryKey: [ 'availability', itemsJson, range, sameStaff, timezone ],
		queryFn: () =>
			apiFetch< AvailabilityResponse >(
				`/admin/availability${ toQueryString( {
					items: itemsJson,
					from: range.from,
					to: range.to,
					same_staff: sameStaff,
					tz: timezone,
				} ) }`
			),
		enabled,
	} );
}
