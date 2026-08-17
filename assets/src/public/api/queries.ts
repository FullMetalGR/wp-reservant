import {
	useMutation,
	useQuery,
	useQueryClient,
	type UseMutationResult,
	type UseQueryResult,
} from '@tanstack/react-query';
import { ApiError } from '../../shared';
import {
	cancelBooking,
	confirmBooking,
	createHold,
	fetchAvailability,
	fetchBooking,
	fetchServices,
	releaseHold,
	rescheduleBooking,
} from './client';
import type { AvailabilityResponse, Booking, ChainItem, HeldBooking, HoldInput, PublicService, RescheduleTarget } from './types';

export function useServices(): UseQueryResult< PublicService[], Error > {
	return useQuery( {
		queryKey: [ 'services' ],
		queryFn: fetchServices,
	} );
}

/**
 * Feasible starts (or, for an event service, its occurrences) for the chain as built so far.
 * Suspended until every segment names a real service - an empty or half-built chain would 400.
 * The chain itself is part of the key: TanStack hashes arrays/objects deterministically, so a
 * rebuilt-but-equal `items` array hits the same cache entry.
 */
export function useAvailability( items: ChainItem[], from: string, to: string ): UseQueryResult< AvailabilityResponse, Error > {
	return useQuery( {
		queryKey: [ 'availability', items, from, to ],
		queryFn: () => fetchAvailability( items, from, to ),
		enabled: items.length > 0 && items.every( ( item ) => item.service_id > 0 ) && '' !== from && '' !== to,
	} );
}

/**
 * `POST /holds`. On a `409` - and only a `409` - every cached availability read is invalidated:
 * a conflict is the server saying the offer this widget rendered is already stale, so the slot
 * list refreshes for the retry (the design's "a vanished slot is normal, not exceptional"). A
 * 429 or a validation 400 says nothing about availability, and refetching on those would hammer
 * a server that just said stop.
 */
export function useCreateHold(): UseMutationResult< HeldBooking, Error, HoldInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: createHold,
		onError: ( error: Error ) => {
			if ( error instanceof ApiError && 409 === error.status ) {
				void queryClient.invalidateQueries( { queryKey: [ 'availability' ] } );
			}
		},
	} );
}

/** The uuid/token pair every manage-side call authorizes on. */
export interface ManageCredentials {
	uuid: string;
	token: string;
}

/**
 * `DELETE /holds/{uuid}` - best-effort release when the visitor walks away. No cache work: the
 * fired-and-forgotten `visibilitychange`/`pagehide` path (Task 14) has no page left to refresh.
 */
export function useReleaseHold(): UseMutationResult< Booking, Error, ManageCredentials > {
	return useMutation( {
		mutationFn: ( { uuid, token }: ManageCredentials ) => releaseHold( uuid, token ),
	} );
}

/**
 * `GET /bookings/{uuid}` for the manage view. Keyed on the uuid alone - the token authorizes the
 * read but does not vary within a page view - and suspended until both credentials are present,
 * so a manage mount with a stripped token renders its neutral refusal without a doomed request.
 */
export function useBooking( uuid: string, token: string ): UseQueryResult< Booking, Error > {
	return useQuery( {
		queryKey: [ 'booking', uuid ],
		queryFn: () => fetchBooking( uuid, token ),
		enabled: '' !== uuid && '' !== token,
	} );
}

/**
 * The shared aftermath of confirm, cancel and reschedule: `['booking', uuid]` is now stale by
 * definition, and `['availability']` (every cached window) because the transition just changed
 * what other visitors can book - a cancel or reschedule frees the old slots outright, and even a
 * confirm hardens a hold that expiry would otherwise have released.
 */
function useManageMutation< TVariables extends ManageCredentials >(
	mutationFn: ( variables: TVariables ) => Promise< Booking >
): UseMutationResult< Booking, Error, TVariables > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn,
		onSuccess: ( _data: Booking, variables: TVariables ) => {
			void queryClient.invalidateQueries( { queryKey: [ 'booking', variables.uuid ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'availability' ] } );
		},
	} );
}

export function useConfirm(): UseMutationResult< Booking, Error, ManageCredentials > {
	return useManageMutation( ( { uuid, token }: ManageCredentials ) => confirmBooking( uuid, token ) );
}

export function useCancel(): UseMutationResult< Booking, Error, ManageCredentials > {
	return useManageMutation( ( { uuid, token }: ManageCredentials ) => cancelBooking( uuid, token ) );
}

/** Credentials plus exactly one target field - the endpoint 400s both-or-neither. */
export type RescheduleVariables = ManageCredentials & RescheduleTarget;

export function useReschedule(): UseMutationResult< Booking, Error, RescheduleVariables > {
	return useManageMutation( ( { uuid, token, ...target }: RescheduleVariables ) =>
		rescheduleBooking( uuid, token, target )
	);
}
