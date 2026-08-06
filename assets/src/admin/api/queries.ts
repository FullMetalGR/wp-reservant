import {
	useMutation,
	useQuery,
	useQueryClient,
	type UseMutationResult,
	type UseQueryResult,
} from '@tanstack/react-query';
import { apiFetch } from './client';
import type {
	BookingFilters,
	BookingListResponse,
	BookingSummary,
	CalendarResponse,
	ManualBookingRequest,
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

export function useBookings( filters: BookingFilters ): UseQueryResult< BookingListResponse, Error > {
	return useQuery( {
		queryKey: [ 'bookings', filters ],
		queryFn: () => apiFetch< BookingListResponse >( `/admin/bookings${ toQueryString( { ...filters } ) }` ),
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
 * aftermath: the list and the calendar both may now be showing a stale row, so both cache keys
 * are invalidated together rather than each call site repeating the pair.
 */
function useBookingMutation< TVariables >(
	mutationFn: ( variables: TVariables ) => Promise< BookingSummary >
): UseMutationResult< BookingSummary, Error, TVariables > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn,
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'bookings' ] } );
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
