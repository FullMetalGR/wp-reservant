import { ApiError, buildRequestUrl } from '../../shared';
import type {
	AvailabilityResponse,
	Booking,
	ChainItem,
	HeldBooking,
	HoldInput,
	PublicService,
	RescheduleTarget,
	WidgetBootstrap,
} from './types';

declare global {
	interface Window {
		/** Printed as an inline before-script on the widget handle (`Assets::enqueue()`). */
		reservantWidget?: WidgetBootstrap;
	}
}

/**
 * The boot object, or a loud throw when the inline script is missing - which means the bundle is
 * running on a page `Assets` never enqueued it on, and no request it could make would be right.
 */
export function widgetBootstrap(): WidgetBootstrap {
	if ( ! window.reservantWidget ) {
		throw new Error( 'reservantWidget missing' );
	}
	return window.reservantWidget;
}

/** The engine's namespace - the public widget never calls anything else (`Rest\Routes::NS`). */
const RESERVANT_NAMESPACE = 'reservant/v1';

async function readBody( response: Response ): Promise< unknown > {
	const text = await response.text();
	if ( '' === text ) {
		return null;
	}
	return JSON.parse( text ) as unknown;
}

/**
 * The one fetch wrapper every widget REST call goes through. Shaped like the admin SPA's
 * `apiFetch` (`assets/src/admin/api/client.ts`) but DELIBERATELY divergent on the nonce, and that
 * divergence is the whole reason this is not one shared function:
 *
 * The admin client sets `X-WP-Nonce` unconditionally, which is right for wp-admin. Here the
 * bootstrap's `nonce` is the empty string for every visitor (`Assets::config()`'s docblock owns
 * the why), and core validates any nonce a request DOES send before every permission callback -
 * `WP_REST_Server::serve_request()` runs `check_authentication()` before `dispatch()`, and
 * `rest_cookie_check_errors()` reads the header via `isset()`, not truthiness. A present-but-empty
 * header is therefore a nonce that fails verification: a hard 403 on EVERY route, the fully public
 * `GET /services` included. So the guard below is a falsy branch that OMITS the header entirely;
 * sending nothing just leaves the request unauthenticated, which is all these routes need - they
 * are public, or they authorize on the manage token.
 */
async function request< T >( path: string, init: RequestInit = {} ): Promise< T > {
	const { restRoot, nonce } = widgetBootstrap();

	const headers = new Headers( init.headers );
	if ( nonce ) {
		headers.set( 'X-WP-Nonce', nonce );
	}
	headers.set( 'Content-Type', 'application/json' );
	headers.set( 'Accept', 'application/json' );

	const response = await fetch( buildRequestUrl( restRoot, RESERVANT_NAMESPACE, path ), { ...init, headers } );
	const body = await readBody( response );

	if ( ! response.ok ) {
		throw ApiError.fromResponse( body, response.status );
	}

	return body as T;
}

/** `GET /services` - the public catalog, a bare array (unlike the admin's `{services}` envelope). */
export function fetchServices(): Promise< PublicService[] > {
	return request< PublicService[] >( '/services' );
}

/**
 * `GET /availability` for the whole chain. `from`/`to` are `Y-m-d` business dates, `to` exclusive
 * and at most 60 days out. No `tz` is sent: the endpoint's fallback for an absent `tz` is the
 * business timezone (`AvailabilityController::displayZone()`), which is exactly the clock the
 * widget shows - a customer books the salon's 09:00, wherever they sit.
 */
export function fetchAvailability( items: ChainItem[], from: string, to: string ): Promise< AvailabilityResponse > {
	const query = new URLSearchParams( { items: JSON.stringify( items ), from, to } );
	return request< AvailabilityResponse >( `/availability?${ query.toString() }` );
}

/**
 * `POST /holds` - the only authority on capacity. A `409` here is a normal answer (the slot went
 * to somebody else between read and hold), surfaced as an `ApiError` whose `detail` is the
 * sentence to show and whose `segment` names the failing chain link.
 */
export function createHold( input: HoldInput ): Promise< HeldBooking > {
	return request< HeldBooking >( '/holds', { method: 'POST', body: JSON.stringify( input ) } );
}

/** `DELETE /holds/{uuid}` - walking away from checkout; gives the slot straight back. */
export function releaseHold( uuid: string, token: string ): Promise< Booking > {
	return request< Booking >( `/holds/${ uuid }?token=${ encodeURIComponent( token ) }`, { method: 'DELETE' } );
}

/** `GET /bookings/{uuid}` - guest self-service read, authorized by the manage token alone. */
export function fetchBooking( uuid: string, token: string ): Promise< Booking > {
	return request< Booking >( `/bookings/${ uuid }?token=${ encodeURIComponent( token ) }` );
}

/** `POST /bookings/{uuid}/confirm` - the free / pay-on-site path out of a held booking. */
export function confirmBooking( uuid: string, token: string ): Promise< Booking > {
	return request< Booking >( `/bookings/${ uuid }/confirm`, { method: 'POST', body: JSON.stringify( { token } ) } );
}

/** `POST /bookings/{uuid}/cancel` - policy-checked; a refusal outside the window is a 403 with a sentence. */
export function cancelBooking( uuid: string, token: string ): Promise< Booking > {
	return request< Booking >( `/bookings/${ uuid }/cancel`, { method: 'POST', body: JSON.stringify( { token } ) } );
}

/**
 * `POST /bookings/{uuid}/reschedule` - moves ALL segments as one atomic release + re-hold;
 * partial success is impossible, so on any failure the original time still stands.
 */
export function rescheduleBooking( uuid: string, token: string, target: RescheduleTarget ): Promise< Booking > {
	return request< Booking >( `/bookings/${ uuid }/reschedule`, {
		method: 'POST',
		body: JSON.stringify( { token, ...target } ),
	} );
}
