<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Enum\PaymentMode;
use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;

/**
 * `reservant/v1/admin/services` CRUD (Task 11): the service catalog an appointment or event is
 * generated from. Handlers stay thin - sanitize the request into a column patch, validate the
 * *effective* record (current row merged with the patch, so a bare `{status:'inactive'}` PUT never
 * re-triggers a duration/capacity check against fields it did not touch - the guard rail "deactivate
 * is always allowed" falls out of that merge rather than needing a special case), then persist.
 *
 * DELETE is refused with 409 `referenced` (AGENTS.md Task 11, `Rest\Errors`) when any booking item -
 * of any status, past or present - names the service; the response steers the caller towards
 * `PUT {status:'inactive'}` instead, which is never blocked by that check.
 */
final class ServicesAdminController {

	/** @var list<string> every column this endpoint may write - schema columns minus wc_product_id, which the WC bridge owns exclusively (AGENTS.md section 6). */
	private const FIELDS = array(
		'name',
		'type',
		'duration_min',
		'processing_time_min',
		'buffer_before_min',
		'buffer_after_min',
		'capacity',
		'seat_map_id',
		'price_minor',
		'currency',
		'payment_mode',
		'requires_approval',
		'approval_hold_hours',
		'on_approval_timeout',
		'cancel_window_hours',
		'reschedule_window_hours',
		'lead_time_min',
		'horizon_days',
		'status',
	);

	/** Mirrors the schema's own column defaults - only the subset `validateBusinessRules()` reads. */
	private const VALIDATION_DEFAULTS = array(
		'type'         => 'appointment',
		'duration_min' => 30,
		'capacity'     => 1,
		'seat_map_id'  => null,
	);

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/services */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$includeInactive = (bool) $request->get_param( 'include_inactive' );
		$rows            = ( new ServiceRepository( $this->db ) )->all( $includeInactive );
		return new \WP_REST_Response( array( 'services' => array_map( array( self::class, 'present' ), $rows ) ) );
	}

	/** GET /admin/services/{id} */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$service = ( new ServiceRepository( $this->db ) )->find( (int) $request->get_param( 'id' ) );
		if ( null === $service ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( self::present( $service ) );
	}

	/** POST /admin/services */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$patch = self::sanitizeFields( $request );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		if ( '' === trim( (string) ( $patch['name'] ?? '' ) ) ) {
			return Errors::badRequest( __( '"name" is required.', 'reservant' ) );
		}

		$error = self::validateBusinessRules( array_merge( self::VALIDATION_DEFAULTS, $patch ) );
		if ( null !== $error ) {
			return Errors::badRequest( $error );
		}

		$repo = new ServiceRepository( $this->db );
		$id   = $repo->insert( $patch );
		return new \WP_REST_Response( self::present( (array) $repo->find( $id ) ), 201 );
	}

	/** PUT /admin/services/{id} - a partial patch; only the given fields change. */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo    = new ServiceRepository( $this->db );
		$id      = (int) $request->get_param( 'id' );
		$current = $repo->find( $id );
		if ( null === $current ) {
			return Errors::notFound();
		}

		try {
			$patch = self::sanitizeFields( $request );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		if ( array_key_exists( 'name', $patch ) && '' === trim( (string) $patch['name'] ) ) {
			return Errors::badRequest( __( '"name" cannot be blank.', 'reservant' ) );
		}

		// Validated against the effective record (current row + patch), never the patch alone - see
		// the class docblock for why that is what makes a bare status-only PUT always succeed.
		$error = self::validateBusinessRules( array_merge( $current, $patch ) );
		if ( null !== $error ) {
			return Errors::badRequest( $error );
		}

		if ( array() !== $patch ) {
			$repo->update( $id, $patch );
		}
		return new \WP_REST_Response( self::present( (array) $repo->find( $id ) ) );
	}

	/**
	 * DELETE /admin/services/{id}.
	 *
	 * The cheap check happens first, outside any transaction, purely to give an ordinary caller a
	 * fast 404/409 without ever opening one. The check that actually matters runs again as the FIRST
	 * statement inside `TransactionRunner::run()` (AGENTS.md Task 11 fix round 1, mirroring section
	 * 2.2's re-validate-under-lock pattern): under InnoDB REPEATABLE READ a fresh transaction's
	 * snapshot is established at its first read, so this recheck sees anything a concurrent
	 * `HoldBooking` committed in the gap between the outer check and here - a reference that appears
	 * in that window is caught before the physical delete runs, not after. The whole cascade (recheck,
	 * unlink, physical delete) is one transaction, so a mid-cascade failure rolls back rather than
	 * leaving link rows unlinked but the service still present, or vice versa.
	 *
	 * `ServiceRepository::delete()` reports whether a row was actually removed; a `false` here means
	 * the row vanished by some other path between the outer check and this point (or the DELETE
	 * itself failed) - either way it is surfaced as a failure, never folded into an unconditional 204.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new ServiceRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}
		if ( $repo->isReferenced( $id ) ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		try {
			$deleted = ( new TransactionRunner( $this->db ) )->run(
				function () use ( $id ): bool {
					// A fresh repository instance, deliberately not the `$repo` used for the outer
					// check above: this is a genuinely new read, not a reuse of the earlier result.
					$repo = new ServiceRepository( $this->db );
					if ( $repo->isReferenced( $id ) ) {
						throw new \RuntimeException( 'referenced' );
					}
					// No FK constraints on reservant_service_resource (AGENTS.md section 4) - clear
					// this service's links explicitly rather than leave them dangling on a dead id.
					$resources = new ResourceRepository( $this->db );
					foreach ( $resources->idsForService( $id ) as $resourceId ) {
						$resources->unlinkService( $id, $resourceId );
					}
					return $repo->delete( $id );
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		if ( ! $deleted ) {
			return Errors::failure( new \RuntimeException( 'delete_conflict' ) );
		}
		return new \WP_REST_Response( null, 204 );
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
			$patch[ $field ] = self::sanitizeField( $field, $request->get_param( $field ) );
		}
		return $patch;
	}

	/** @throws \InvalidArgumentException */
	private static function sanitizeField( string $field, mixed $value ): mixed {
		return match ( $field ) {
			'name' => sanitize_text_field( Input::text( $value ) ),
			'type' => self::enumOrThrow( $value, array( ServiceType::Appointment->value, ServiceType::Event->value ), $field ),
			'duration_min', 'processing_time_min', 'buffer_before_min', 'buffer_after_min', 'capacity',
			'price_minor', 'approval_hold_hours', 'cancel_window_hours', 'reschedule_window_hours',
			'lead_time_min', 'horizon_days' => self::nonNegIntOrThrow( $value, $field ),
			'seat_map_id' => ( null === $value || '' === $value ) ? null : self::posIntOrThrow( $value, $field ),
			'currency' => self::currencyOrThrow( $value ),
			'payment_mode' => self::enumOrThrow( $value, array( PaymentMode::Free->value, PaymentMode::Online->value, PaymentMode::Onsite->value ), $field ),
			'requires_approval' => rest_sanitize_boolean( is_scalar( $value ) ? (string) $value : '' ) ? 1 : 0,
			'on_approval_timeout' => self::enumOrThrow( $value, array( 'expire', 'auto_approve' ), $field ),
			'status' => self::enumOrThrow( $value, array( 'active', 'inactive' ), $field ),
			default => throw new \InvalidArgumentException( 'Unsupported field.' ),
		};
	}

	/**
	 * Type in {appointment,event}; an appointment needs `duration_min` a positive multiple of the
	 * slot granularity, an event needs `capacity >= 1` or a `seat_map_id` (AGENTS.md Task 11).
	 *
	 * @param array<string, mixed> $effective
	 */
	private static function validateBusinessRules( array $effective ): ?string {
		$type = (string) ( $effective['type'] ?? ServiceType::Appointment->value );

		if ( ServiceType::Appointment->value === $type ) {
			$duration    = (int) ( $effective['duration_min'] ?? 0 );
			$granularity = self::granularity();
			if ( $duration <= 0 || 0 !== $duration % $granularity ) {
				return sprintf(
					/* translators: %d: the slot granularity in minutes. */
					__( '"duration_min" must be a positive multiple of %d.', 'reservant' ),
					$granularity
				);
			}
			return null;
		}

		$capacity  = (int) ( $effective['capacity'] ?? 0 );
		$seatMapId = $effective['seat_map_id'] ?? null;
		if ( $capacity < 1 && null === $seatMapId ) {
			return __( 'An event needs a capacity of at least 1, or a seat_map_id.', 'reservant' );
		}
		return null;
	}

	private static function granularity(): int {
		return max( 1, (int) apply_filters( 'reservant/granularity_min', 5 ) );
	}

	/** @throws \InvalidArgumentException */
	private static function posIntOrThrow( mixed $value, string $field ): int {
		$id = Input::posInt( $value );
		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed.
			throw new \InvalidArgumentException( self::fieldError( $field ) );
		}
		return $id;
	}

	/** @throws \InvalidArgumentException */
	private static function nonNegIntOrThrow( mixed $value, string $field ): int {
		if ( is_int( $value ) ) {
			if ( $value < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed.
				throw new \InvalidArgumentException( self::fieldError( $field ) );
			}
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed.
		throw new \InvalidArgumentException( self::fieldError( $field ) );
	}

	/**
	 * @param list<string> $allowed
	 * @throws \InvalidArgumentException
	 */
	private static function enumOrThrow( mixed $value, array $allowed, string $field ): string {
		$str = sanitize_text_field( Input::text( $value ) );
		if ( ! in_array( $str, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed.
			throw new \InvalidArgumentException( self::fieldError( $field ) );
		}
		return $str;
	}

	/** @throws \InvalidArgumentException */
	private static function currencyOrThrow( mixed $value ): string {
		$str = strtoupper( sanitize_text_field( Input::text( $value ) ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $str ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed.
			throw new \InvalidArgumentException( self::fieldError( 'currency' ) );
		}
		return $str;
	}

	private static function fieldError( string $field ): string {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed. $field is always a literal from self::FIELDS.
		return '"' . $field . '" is not valid.';
	}

	/**
	 * @param array<string, mixed> $service
	 * @return array<string, mixed>
	 */
	private static function present( array $service ): array {
		$service['requires_approval'] = (bool) $service['requires_approval'];
		return $service;
	}
}
