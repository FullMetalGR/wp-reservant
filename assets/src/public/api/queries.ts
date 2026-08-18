import {
	useMutation,
	useQuery,
	useQueryClient,
	type QueryClient,
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
		// The catalog changes only when the owner edits a service - rarely, and never as part of
		// a visitor's own journey - so five minutes of freshness stops every remount, refocus
		// and reconnect from re-fetching a near-static list. The window bounds how stale a
		// rendered price or duration can get; the numbers that BIND are the server's anyway
		// (a hold re-prices and re-validates under lock), so a briefly stale label costs
		// nothing but pixels.
		staleTime: 5 * 60 * 1000,
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
		// Deliberately zero, spelled out: an offered start goes stale the MOMENT any other
		// visitor holds a slot in it, so data already on screen is the most staleness this
		// widget tolerates - every fresh subscriber, refocus and reconnect refetches.
		// Availability is advisory either way (the hold is the only authority), but a stale
		// offer costs the visitor a 409 round-trip, so freshness here is conflicts avoided.
		// Never let this inherit a blanket default - queryPolicy.test.tsx pins the refetch.
		staleTime: 0,
	} );
}

/**
 * On a `409` - and only a `409` - every cached availability read is invalidated: a conflict is
 * the server saying the offer this widget rendered is already stale, so the slot list refreshes
 * for the retry (the design's "a vanished slot is normal, not exceptional"). A 429 or a
 * validation 400 says nothing about availability, and refetching on those would hammer a server
 * that just said stop. ONE policy with exactly two writers - `useCreateHold` (the booking
 * journey's hold) and `useReschedule` (the manage journey's re-hold) - because those are the two
 * mutations whose 409 means a rendered offer lost a race; a cancel or confirm 409 is about the
 * BOOKING's state and says nothing a refetched slot list would fix.
 */
function invalidateAvailabilityOnConflict( queryClient: QueryClient, error: Error ): void {
	if ( error instanceof ApiError && 409 === error.status ) {
		void queryClient.invalidateQueries( { queryKey: [ 'availability' ] } );
	}
}

/** `POST /holds` - the conflict aftermath is `invalidateAvailabilityOnConflict`'s. */
export function useCreateHold(): UseMutationResult< HeldBooking, Error, HoldInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: createHold,
		onError: ( error: Error ) => invalidateAvailabilityOnConflict( queryClient, error ),
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
		// Deliberately zero, spelled out: a booking's status changes WITHOUT the guest acting -
		// the owner approves or rejects, a hold expires, payment lands - and a manage tab left
		// open on "waiting for approval" must tell the truth when the guest returns to it (a
		// refocus refetches only stale queries). The guest's own mutations never ride on this
		// number: they invalidate `['booking', uuid]` explicitly (`useManageMutation`).
		staleTime: 0,
	} );
}

/**
 * The shared aftermath of confirm, cancel and reschedule: `['booking', uuid]` is now stale by
 * definition, and `['availability']` (every cached window) because the transition just changed
 * what other visitors can book - a cancel or reschedule frees the old slots outright, and even a
 * confirm hardens a hold that expiry would otherwise have released.
 */
function useManageMutation< TVariables extends ManageCredentials >(
	mutationFn: ( variables: TVariables ) => Promise< Booking >,
	onError?: ( queryClient: QueryClient, error: Error ) => void
): UseMutationResult< Booking, Error, TVariables > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn,
		onSuccess: ( _data: Booking, variables: TVariables ) => {
			void queryClient.invalidateQueries( { queryKey: [ 'booking', variables.uuid ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'availability' ] } );
		},
		onError:
			undefined === onError ? undefined : ( error: Error ) => onError( queryClient, error ),
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

/**
 * A move is an atomic release-and-re-hold, so its 409 is the same event as a hold's: the offer
 * this widget rendered lost a race. `invalidateAvailabilityOnConflict` refreshes the dialog's
 * still-subscribed slot list for the retry; the SUCCESS aftermath stays `useManageMutation`'s.
 */
export function useReschedule(): UseMutationResult< Booking, Error, RescheduleVariables > {
	return useManageMutation(
		( { uuid, token, ...target }: RescheduleVariables ) =>
			rescheduleBooking( uuid, token, target ),
		invalidateAvailabilityOnConflict
	);
}
