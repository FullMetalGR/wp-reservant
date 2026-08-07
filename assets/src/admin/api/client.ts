import { bootConfig } from '../boot';
import { ApiError, buildRequestUrl } from '../../shared';

async function readBody( response: Response ): Promise< unknown > {
	const text = await response.text();
	if ( '' === text ) {
		return null;
	}
	return JSON.parse( text ) as unknown;
}

/** The engine's own namespace - every admin call except a WP-core lookup (`Rest\Routes::NS`). */
const RESERVANT_NAMESPACE = 'reservant/v1';

/**
 * The one typed fetch wrapper every admin REST call goes through: joins `restRoot` (from the
 * inline boot config) with a namespace and the given path, authenticates with the REST nonce, and
 * narrows a non-`ok` response into a typed `ApiError` parsed from the engine's error envelope
 * (`Rest\Errors`) rather than a bare HTTP status.
 *
 * `namespace` defaults to the engine's own (`reservant/v1`) - every existing call site is
 * unaffected - but a caller may override it to reach a WP-core route on the SAME site instead, e.g.
 * `apiFetch<WpUser[]>('/users?search=...', {}, 'wp/v2')` (Task 16's staff-link user search): core
 * error responses do not carry `Rest\Errors`' envelope shape, so `ApiError.fromResponse()`'s own
 * fallbacks (a generic code/message, the raw HTTP status) apply rather than a parsed `detail` - fine
 * for a lookup this SPA never surfaces a bespoke error message for.
 */
export async function apiFetch< T >( path: string, init: RequestInit = {}, namespace: string = RESERVANT_NAMESPACE ): Promise< T > {
	const { restRoot, nonce } = bootConfig();

	const headers = new Headers( init.headers );
	headers.set( 'X-WP-Nonce', nonce );
	headers.set( 'Content-Type', 'application/json' );
	headers.set( 'Accept', 'application/json' );

	const response = await fetch( buildRequestUrl( restRoot, namespace, path ), { ...init, headers } );
	const body = await readBody( response );

	if ( ! response.ok ) {
		throw ApiError.fromResponse( body, response.status );
	}

	return body as T;
}

/**
 * True for the `referenced` 409 the catalog's guard rails answer with - `ServicesAdminController`/
 * `ResourcesAdminController`'s DELETE, `OccurrencesAdminController`'s destroy and schedule-touching
 * PUT, `SeatMapsAdminController`'s PUT/DELETE (`Rest\Errors::failure(new RuntimeException('referenced'))`
 * on every one of them) - the admin catalog screens' (Task 16) cue to offer "deactivate instead" or
 * lock further editing, rather than a bare error toast.
 */
export function isReferencedConflict( error: unknown ): boolean {
	return error instanceof ApiError && 'referenced' === error.message;
}
