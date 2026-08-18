import { __ } from '@wordpress/i18n';

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

/**
 * The one sentence to SHOW a human for a failed request - `detail` first, always.
 *
 * `ApiError` carries two halves of the same failure (see the `ErrorEnvelope` docblock at the top of
 * this file): `message` is the machine reason the code branches on (`overlap`, `not_approvable`,
 * `referenced`, and - for every single 400 the admin API can answer, since `Rest\Errors::badRequest()`
 * hardcodes it - the useless literal `invalid_request`), while `data.detail` is the translated,
 * per-field sentence written expressly to be shown verbatim. Six screens each carried their own
 * `error instanceof Error ? error.message : ...` copy of this, which threw the translated half away
 * and showed the admin "Error: invalid_request" for any of ~18 possible bad fields, or an
 * untranslated "overlap"/"not_approvable" to a non-English admin.
 *
 * Falls back, in order: a non-empty `detail`, then the machine `message` (better than nothing when a
 * response somehow carries no detail - `ApiError.fromResponse()` already defaults `detail` to
 * `message`, so this only matters for a hand-constructed `ApiError`), then any other `Error`'s own
 * message (a network/parse failure from `fetch`, which is genuinely all there is to say), then a
 * generic sentence for a non-`Error` throw.
 */
export function errorMessage( error: unknown ): string {
	if ( error instanceof ApiError ) {
		return '' === error.detail.trim() ? error.message : error.detail;
	}
	if ( error instanceof Error && '' !== error.message.trim() ) {
		return error.message;
	}
	return __( 'Something went wrong.', 'reservant' );
}
