<?php
declare( strict_types=1 );

namespace Reservant\Rest;

/**
 * Strict coercion at the REST boundary (AGENTS.md section 5: sanitize in, domain objects never see raw
 * input).
 *
 * PHP's own casts are too forgiving to be a boundary. `(int) array( 'a' => 1 )` is `1`, so
 * `{"service_id": {"a": 1}}` would silently book service 1; `(string) array()` is the literal
 * "Array" plus a warning, so `{"name": {"first": "M"}}` would book a customer called "Array".
 * Both read as valid input downstream, which is exactly the class of bug a boundary exists to stop.
 * Anything that is not plainly the type asked for is rejected here, and the controller answers 400.
 */
final class Input {

	/**
	 * A positive integer id, or null.
	 *
	 * Accepts a real integer or an all-digits string (form-encoded bodies carry numbers as
	 * strings). Rejects arrays, objects, booleans, floats, `"12abc"`, `0` and negatives - an id is
	 * either a whole positive number or it is not an id.
	 */
	public static function posInt( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			$id = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$id = (int) $value;
		} else {
			return null;
		}
		return $id > 0 ? $id : null;
	}

	/** A string, or nothing. Non-scalars are not text, whatever PHP thinks. */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : '';
	}
}
