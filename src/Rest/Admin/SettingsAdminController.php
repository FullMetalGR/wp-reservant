<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Rest\Errors;
use Reservant\Rest\Input;
use Reservant\Settings;

/**
 * `reservant/v1/admin/settings` (Task 13b, plan gap-filler): GET|PUT was named in the interface
 * table but never assigned to a task 1-17 - Task 13's implementer flagged the gap (P4 ledger) rather
 * than inventing this controller out of scope.
 *
 * GET returns `Settings::make()->toArray()` verbatim. PUT is a partial patch: only the keys actually
 * present in the request body are sanitized and forwarded to `Settings::update()`, which validates
 * the merged result and persists it; unknown keys are silently ignored by that same "only forward
 * what is present" filter.
 *
 * `Settings::update()` merges its partial with `??` (Task 3 ledger obligation), so a key that is
 * present but explicitly `null` would silently keep the old value instead of erroring - this
 * controller rejects that case itself, naming the offending key in the 400, before the value ever
 * reaches `update()`.
 */
final class SettingsAdminController {

	/** @var list<string> every key this endpoint reads or writes - Settings::toArray()'s own shape. */
	private const FIELDS = array(
		'currency',
		'checkout_ttl_min',
		'approval_ttl_hours',
		'payment_ttl_hours',
		'purge_on_uninstall',
	);

	/** GET /admin/settings */
	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( Settings::make()->toArray() );
	}

	/** PUT /admin/settings - a partial patch; only the given fields change. */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$patch = self::sanitizeFields( $request );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		try {
			$settings = Settings::make()->update( $patch );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		return new \WP_REST_Response( $settings->toArray() );
	}

	/**
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private static function sanitizeFields( \WP_REST_Request $request ): array {
		$patch = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! $request->has_param( $field ) ) {
				continue;
			}
			$value = $request->get_param( $field );
			if ( null === $value ) {
				// Settings::update() merges a partial with `??`, so a forwarded null would silently
				// keep the old value rather than error (AGENTS.md Task 3 ledger obligation) - refuse
				// it here instead, naming the key, before it ever reaches that merge.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in update(); never echoed. $field is always a literal from self::FIELDS.
				throw new \InvalidArgumentException( '"' . $field . '" must not be null.' );
			}
			$patch[ $field ] = self::sanitizeField( $field, $value );
		}
		return $patch;
	}

	/** @throws \InvalidArgumentException */
	private static function sanitizeField( string $field, mixed $value ): mixed {
		return match ( $field ) {
			// Pre-trimmed only; format (three uppercase letters) is Settings::validate()'s call.
			'currency' => sanitize_text_field( Input::text( $value ) ),
			'checkout_ttl_min', 'approval_ttl_hours', 'payment_ttl_hours' => self::posIntOrThrow( $value, $field ),
			'purge_on_uninstall' => rest_sanitize_boolean( is_scalar( $value ) ? (string) $value : '' ),
			default => throw new \InvalidArgumentException( 'Unsupported field.' ),
		};
	}

	/** @throws \InvalidArgumentException */
	private static function posIntOrThrow( mixed $value, string $field ): int {
		$int = Input::posInt( $value );
		if ( null === $int ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in update(); never echoed. $field is always a literal from self::FIELDS.
			throw new \InvalidArgumentException( '"' . $field . '" is not valid.' );
		}
		return $int;
	}
}
