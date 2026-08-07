import { bootConfig } from '../boot';

/**
 * The shape `Rest\Errors` puts on the wire (`src/Rest/Errors.php`): every `reservant_*` WP_Error
 * serializes as `{code, message, data: {status, detail, segment?}}` - `message` carries the
 * machine reason (e.g. `overlap`), `data.detail` a translated sentence safe to show verbatim.
 */
interface ErrorEnvelope {
	code?: unknown;
	message?: unknown;
	data?: {
		status?: unknown;
		detail?: unknown;
		segment?: unknown;
	};
}

function isErrorEnvelope( value: unknown ): value is ErrorEnvelope {
	return null !== value && 'object' === typeof value;
}

/**
 * A parsed `reservant_*` error response. `segment` is only present on a `SlotConflict` (the
 * zero-based index of the chain segment that failed) - most reasons never carry one.
 */
export class ApiError extends Error {
	code: string;
	status: number;
	detail: string;
	segment?: number;

	constructor( code: string, message: string, status: number, detail: string, segment?: number ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.detail = detail;
		this.segment = segment;
	}

	static fromResponse( body: unknown, fallbackStatus: number ): ApiError {
		const envelope = isErrorEnvelope( body ) ? body : {};
		const code = 'string' === typeof envelope.code ? envelope.code : 'reservant_error';
		const message = 'string' === typeof envelope.message ? envelope.message : 'error';
		const data = envelope.data ?? {};
		const status = 'number' === typeof data.status ? data.status : fallbackStatus;
		const detail = 'string' === typeof data.detail ? data.detail : message;
		const segment = 'number' === typeof data.segment ? data.segment : undefined;
		return new ApiError( code, message, status, detail, segment );
	}
}

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
 * Joins `restRoot` (`esc_url_raw( rest_url() )`, `src/Admin/AdminPage.php`) with a namespace and
 * path into one request URL, correctly under BOTH WordPress permalink modes.
 *
 * Under PRETTY permalinks `restRoot` is an ordinary directory URL (`http://site/wp-json/`) and
 * plain string concatenation is fine. Under PLAIN permalinks - WordPress core's own default for a
 * fresh install - `rest_url()` instead returns `http://site/index.php?rest_route=/`: the root URL
 * has ALREADY opened its own query string, and the route is not a path segment but the *value* of
 * that `rest_route` parameter. Naively concatenating a query-bearing path then produces a second,
 * structurally meaningless `?` (`...index.php?rest_route=/reservant/v1/admin/calendar?from=X&to=Y`).
 * PHP's query-string parser only ever splits on `&`, never on an embedded `?`, so it reads that as
 * `rest_route=/reservant/v1/admin/calendar?from=X` (matching no route - 404 `rest_no_route`) plus a
 * stray top-level `to=Y`.
 *
 * The fix: when `restRoot` already owns a `?`, the route's own query string is the only OTHER `?` a
 * well-formed path can carry, so folding just that one into `&` merges both into the single query
 * string `restRoot` already started - the route text itself (still holding its `&`-joined args)
 * lands correctly inside the `rest_route` value, because `restRoot` ends in `rest_route=/` and the
 * route is appended directly onto that trailing slash.
 *
 * This mirrors, step for step, `@wordpress/api-fetch`'s own root-URL middleware
 * (`packages/api-fetch/src/middlewares/root-url.ts` in WordPress/gutenberg - the mechanism WP
 * core's own admin JS uses to reach itself under either permalink mode: replace the first `?` in
 * the path with `&` whenever the root URL already contains one, then concatenate). That package
 * is not a dependency of this project (`package.json` deliberately keeps only `@wordpress/components`
 * / `element` / `i18n`, and this file already owns a small, precisely-typed fetch wrapper matched to
 * Reservant's own error envelope - pulling in WP's differently-shaped, same-named `apiFetch` runtime
 * just to reach one ~10-line join algorithm would trade a verifiable local function for a second,
 * competing HTTP abstraction), so the algorithm is ported here rather than imported.
 */
function buildRequestUrl( restRoot: string, namespace: string, path: string ): string {
	let route = `${ namespace }${ path }`;

	if ( restRoot.includes( '?' ) ) {
		route = route.replace( '?', '&' );
	}

	// `restRoot` always ends in a trailing slash (`rest_url()` guarantees this in both permalink
	// modes); `route` must not start with one too, or the join doubles it up.
	return restRoot + route.replace( /^\//, '' );
}

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
