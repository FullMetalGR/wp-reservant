import { QueryClient } from '@tanstack/react-query';
import { ApiError } from '../../shared';

/**
 * The widget's one QueryClient factory, shared by the booking journey (`BookingFlow`) and the
 * manage journey (`ManageView`) - each of which carried its own byte-identical copy while Tasks
 * 14 and 15 were fenced off from each other's files. Either journey creates its client ONCE per
 * mount through a `useState` initializer - a render-body `new QueryClient()` would throw the
 * whole cache away on every render.
 *
 * `retry` is a PREDICATE, not a count: no 4xx is ever retried - a validation 400 fails
 * identically forever, a 409 is a real answer the journeys handle, a 403/404 read is the manage
 * view's neutral panel and must land immediately, and a 429 is the rate limiter saying stop, the
 * one response retrying is guaranteed to make worse. Network failures and 5xx keep the default
 * three attempts. Mutations keep TanStack's no-retry default: a blind second `POST /holds`,
 * cancel or move could act twice.
 *
 * No default `staleTime` here: the three reads age at genuinely different speeds, so each query
 * in `queries.ts` sets its own, with its own justification. A blanket number in this factory
 * would either refetch a near-static catalog on every remount or serve stale availability as
 * fresh - see the per-query comments.
 */
export function newQueryClient(): QueryClient {
	return new QueryClient( {
		defaultOptions: {
			queries: {
				retry: ( failureCount: number, error: Error ): boolean => {
					if ( error instanceof ApiError && error.status >= 400 && error.status < 500 ) {
						return false;
					}
					return failureCount < 3;
				},
			},
		},
	} );
}
