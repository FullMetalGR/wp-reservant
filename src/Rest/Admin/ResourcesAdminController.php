<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Availability\AvailabilityException;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;

/**
 * `reservant/v1/admin/resources` CRUD, plus availability exceptions (Task 11): staff, the services
 * each performs (`service_ids`), their weekly rules, and date-specific overrides.
 *
 * `service_ids` and `rules` are replace-all-per-save, but only when the key is present in the body -
 * a bare `{status:'inactive'}` PUT (the deactivate guard rail) omits both and so leaves links and
 * rules untouched. Rule replacement goes through `TransactionRunner` (AGENTS.md Task 11: "resource
 * save replaces rules atomically") - old row ids never survive a save, by deleting every existing row
 * before inserting the replacement set inside one transaction.
 *
 * Exceptions are managed on their own routes rather than embedded in the resource body:
 * `POST|DELETE /admin/resources/{id}/exceptions` for a resource's own, `POST|DELETE /admin/exceptions`
 * for business-wide (`resource_id` NULL - AGENTS.md section 4). DELETE matches by shape (`date` plus
 * whether `start_time`/`end_time` were given) rather than by row id, since the body is the same
 * `{date,start_time?,end_time?,reason?}` shape POST accepts; `reason` is accepted for forward
 * compatibility but not persisted - the schema carries no such column.
 */
final class ResourcesAdminController {

	private const FIELDS = array( 'name', 'email', 'wp_user_id', 'status' );

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/resources */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$includeInactive = (bool) $request->get_param( 'include_inactive' );
		$rows            = ( new ResourceRepository( $this->db ) )->all( $includeInactive );
		return new \WP_REST_Response( array( 'resources' => $rows ) );
	}

	/** GET /admin/resources/{id} */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );
		if ( null === ( new ResourceRepository( $this->db ) )->find( $id ) ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( self::present( $this->db, $id ) );
	}

	/** POST /admin/resources */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$patch      = self::sanitizeFields( $request );
			$serviceIds = $request->has_param( 'service_ids' ) ? self::sanitizeServiceIds( $this->db, $request->get_param( 'service_ids' ) ) : null;
			$rules      = $request->has_param( 'rules' ) ? self::sanitizeRules( $request->get_param( 'rules' ) ) : null;
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		if ( '' === trim( (string) ( $patch['name'] ?? '' ) ) ) {
			return Errors::badRequest( __( '"name" is required.', 'reservant' ) );
		}

		$repo = new ResourceRepository( $this->db );
		$id   = $repo->insert( $patch );

		if ( null !== $serviceIds ) {
			foreach ( $serviceIds as $serviceId ) {
				$repo->linkService( $serviceId, $id );
			}
		}
		if ( null !== $rules ) {
			self::replaceRules( $this->db, $id, $rules );
		}

		return new \WP_REST_Response( self::present( $this->db, $id ), 201 );
	}

	/** PUT /admin/resources/{id} - a partial patch; service_ids/rules only replace when the key is sent. */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new ResourceRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}

		try {
			$patch      = self::sanitizeFields( $request );
			$serviceIds = $request->has_param( 'service_ids' ) ? self::sanitizeServiceIds( $this->db, $request->get_param( 'service_ids' ) ) : null;
			$rules      = $request->has_param( 'rules' ) ? self::sanitizeRules( $request->get_param( 'rules' ) ) : null;
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		if ( array_key_exists( 'name', $patch ) && '' === trim( (string) $patch['name'] ) ) {
			return Errors::badRequest( __( '"name" cannot be blank.', 'reservant' ) );
		}

		if ( array() !== $patch ) {
			$repo->update( $id, $patch );
		}
		if ( null !== $serviceIds ) {
			self::replaceServiceLinks( $repo, $id, $serviceIds );
		}
		if ( null !== $rules ) {
			self::replaceRules( $this->db, $id, $rules );
		}

		return new \WP_REST_Response( self::present( $this->db, $id ) );
	}

	/** DELETE /admin/resources/{id} */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new ResourceRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}
		if ( $repo->isReferenced( $id ) ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		foreach ( $repo->serviceIdsForResource( $id ) as $serviceId ) {
			$repo->unlinkService( $serviceId, $id );
		}
		$availability = new AvailabilityRepository( $this->db );
		foreach ( $availability->rulesForResource( $id ) as $rule ) {
			$availability->deleteRule( (int) $rule['id'] );
		}
		foreach ( $availability->exceptionsForResource( $id ) as $exception ) {
			$availability->deleteException( (int) $exception['id'] );
		}

		$repo->delete( $id );
		return new \WP_REST_Response( null, 204 );
	}

	/** POST /admin/resources/{id}/exceptions */
	public function addException( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->createException( (int) $request->get_param( 'id' ), $request );
	}

	/** DELETE /admin/resources/{id}/exceptions */
	public function removeException( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->deleteExceptionByShape( (int) $request->get_param( 'id' ), $request );
	}

	/** POST /admin/exceptions - business-wide (resource_id NULL). */
	public function addBusinessException( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->createException( null, $request );
	}

	/** DELETE /admin/exceptions - business-wide. */
	public function removeBusinessException( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->deleteExceptionByShape( null, $request );
	}

	private function createException( ?int $resourceId, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( null !== $resourceId && null === ( new ResourceRepository( $this->db ) )->find( $resourceId ) ) {
			return Errors::notFound();
		}
		try {
			$exception = self::sanitizeException( $request );
		} catch ( \InvalidArgumentException $caught ) {
			return Errors::badRequest( $caught->getMessage() );
		}

		$id = ( new AvailabilityRepository( $this->db ) )->insertException( $resourceId, $exception );
		return new \WP_REST_Response(
			array(
				'id'          => $id,
				'resource_id' => $resourceId,
				'date_local'  => $exception->localDate,
				'closed'      => $exception->closed,
				'start_time'  => $exception->startTime,
				'end_time'    => $exception->endTime,
			),
			201
		);
	}

	private function deleteExceptionByShape( ?int $resourceId, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( null !== $resourceId && null === ( new ResourceRepository( $this->db ) )->find( $resourceId ) ) {
			return Errors::notFound();
		}

		$date = Input::text( $request->get_param( 'date' ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return Errors::badRequest( __( '"date" must look like YYYY-MM-DD.', 'reservant' ) );
		}
		$hasStart = self::nonEmptyParam( $request, 'start_time' );
		$hasEnd   = self::nonEmptyParam( $request, 'end_time' );
		$closed   = ! ( $hasStart && $hasEnd );
		$start    = $hasStart ? Input::text( $request->get_param( 'start_time' ) ) : null;
		$end      = $hasEnd ? Input::text( $request->get_param( 'end_time' ) ) : null;

		$availability = new AvailabilityRepository( $this->db );
		$deleted      = 0;
		foreach ( $availability->exceptionsForResource( $resourceId ) as $row ) {
			if ( $row['date_local'] !== $date || $row['closed'] !== $closed ) {
				continue;
			}
			if ( ! $closed && ( $row['start_time'] !== $start || $row['end_time'] !== $end ) ) {
				continue;
			}
			$availability->deleteException( (int) $row['id'] );
			++$deleted;
		}
		if ( 0 === $deleted ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( array( 'deleted' => $deleted ) );
	}

	private static function nonEmptyParam( \WP_REST_Request $request, string $key ): bool {
		return $request->has_param( $key ) && '' !== Input::text( $request->get_param( $key ) );
	}

	/** @throws \InvalidArgumentException */
	private static function sanitizeException( \WP_REST_Request $request ): AvailabilityException {
		$date = Input::text( $request->get_param( 'date' ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new \InvalidArgumentException( '"date" must look like YYYY-MM-DD.' );
		}
		$hasStart = self::nonEmptyParam( $request, 'start_time' );
		$hasEnd   = self::nonEmptyParam( $request, 'end_time' );
		if ( $hasStart !== $hasEnd ) {
			throw new \InvalidArgumentException( '"start_time" and "end_time" must be given together, or not at all.' );
		}
		if ( ! $hasStart ) {
			return new AvailabilityException( $date, true );
		}
		$start = self::timeOrThrow( $request->get_param( 'start_time' ) );
		$end   = self::timeOrThrow( $request->get_param( 'end_time' ) );
		if ( $start >= $end ) {
			throw new \InvalidArgumentException( '"start_time" must be before "end_time".' );
		}
		return new AvailabilityException( $date, false, $start, $end );
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
			'email' => self::emailOrThrow( $value ),
			'wp_user_id' => ( null === $value || '' === $value ) ? null : self::posIntOrThrow( $value, $field ),
			'status' => self::enumOrThrow( $value, array( 'active', 'inactive' ), $field ),
			default => throw new \InvalidArgumentException( 'Unsupported field.' ),
		};
	}

	/** @throws \InvalidArgumentException */
	private static function emailOrThrow( mixed $value ): ?string {
		$raw = trim( Input::text( $value ) );
		if ( '' === $raw ) {
			return null;
		}
		$email = sanitize_email( $raw );
		if ( ! is_email( $email ) ) {
			throw new \InvalidArgumentException( '"email" is not a valid address.' );
		}
		return $email;
	}

	/**
	 * @return list<int>
	 * @throws \InvalidArgumentException
	 */
	private static function sanitizeServiceIds( \wpdb $db, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( '"service_ids" must be a list of positive integers.' );
		}
		$services = new ServiceRepository( $db );
		$ids      = array();
		foreach ( $raw as $entry ) {
			$id = Input::posInt( $entry );
			if ( null === $id || null === $services->find( $id ) ) {
				throw new \InvalidArgumentException( '"service_ids" must reference existing services.' );
			}
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return list<AvailabilityRule>
	 * @throws \InvalidArgumentException
	 */
	private static function sanitizeRules( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( '"rules" must be a list of {weekday, start_time, end_time}.' );
		}
		$rules = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new \InvalidArgumentException( 'Every rule must be an object.' );
			}
			$weekday = Input::posInt( $entry['weekday'] ?? null );
			if ( null === $weekday ) {
				throw new \InvalidArgumentException( 'Every rule needs an integer weekday 1-7.' );
			}
			$start = self::timeOrThrow( $entry['start_time'] ?? null );
			$end   = self::timeOrThrow( $entry['end_time'] ?? null );
			if ( $start >= $end ) {
				throw new \InvalidArgumentException( "A rule's start_time must be before its end_time." );
			}
			// AvailabilityRule's own constructor enforces the 1-7 range.
			$rules[] = new AvailabilityRule( $weekday, $start, $end );
		}
		return $rules;
	}

	/** @throws \InvalidArgumentException */
	private static function timeOrThrow( mixed $value ): string {
		$str = Input::text( $value );
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $str ) ) {
			throw new \InvalidArgumentException( 'Times must look like HH:MM (24h).' );
		}
		return $str;
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

	private static function fieldError( string $field ): string {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed. $field is always a literal from self::FIELDS.
		return '"' . $field . '" is not valid.';
	}

	/** @param list<int> $serviceIds */
	private static function replaceServiceLinks( ResourceRepository $repo, int $resourceId, array $serviceIds ): void {
		$current = $repo->serviceIdsForResource( $resourceId );
		foreach ( array_diff( $current, $serviceIds ) as $remove ) {
			$repo->unlinkService( $remove, $resourceId );
		}
		foreach ( $serviceIds as $add ) {
			$repo->linkService( $add, $resourceId );
		}
	}

	/**
	 * Delete every existing rule row by id, then insert the replacement set, inside one transaction -
	 * "resource save replaces rules atomically" (AGENTS.md Task 11): old row ids never survive, and a
	 * failure partway through leaves the previous rules intact rather than half-replaced.
	 *
	 * @param list<AvailabilityRule> $rules
	 */
	private static function replaceRules( \wpdb $db, int $resourceId, array $rules ): void {
		$availability = new AvailabilityRepository( $db );
		( new TransactionRunner( $db ) )->run(
			static function () use ( $availability, $resourceId, $rules ): void {
				foreach ( $availability->rulesForResource( $resourceId ) as $existing ) {
					$availability->deleteRule( (int) $existing['id'] );
				}
				foreach ( $rules as $rule ) {
					$availability->insertRule( $resourceId, $rule );
				}
			}
		);
	}

	/** @return array<string, mixed> */
	private static function present( \wpdb $db, int $id ): array {
		$repo         = new ResourceRepository( $db );
		$availability = new AvailabilityRepository( $db );

		$resource                = (array) $repo->find( $id );
		$resource['service_ids'] = $repo->serviceIdsForResource( $id );
		$resource['rules']       = $availability->rulesForResource( $id );
		$resource['exceptions']  = $availability->exceptionsForResource( $id );
		return $resource;
	}
}
