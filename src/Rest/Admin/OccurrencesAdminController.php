<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;

/**
 * `reservant/v1/admin/occurrences` CRUD (Task 12): the fixed dates an event service runs on.
 *
 * DELETE is a soft cancel (`OccurrenceRepository::cancel()`), never a physical row delete - unlike
 * the catalog's `referenced` guard on services/resources (Task 11), which refuses deletion outright.
 * An occurrence's row must survive for any booking that already names it, cancelled or not.
 *
 * Two independent guard rails, not one, and that independence is deliberate rather than an
 * oversight (the brief states both as `activeBookingCount>0 -> referenced` and
 * `capacity below booked -> capacity`, which would collapse into a single unreachable branch if the
 * first blocked every edit: `booked` is always <= the count `activeBookingCount()` reports, since
 * both read the same blocking predicate, so "booked > 0" already implies "activeBookingCount > 0"):
 *
 *  - `referenced` (409) blocks only a RESCHEDULE (`start_utc`/`end_utc` in the patch) while any
 *    booking actively holds the occurrence - moving the date out from under seated attendees is
 *    refused outright; the admin must cancel those bookings first.
 *  - `capacity` (409) blocks only shrinking `capacity` below the seats already taken. Growing
 *    capacity, or editing it with nothing booked, is always allowed - even on an occurrence with
 *    other active bookings, as long as the edit itself does not touch the schedule.
 *
 * A seat-mapped occurrence's capacity is derived from its service's seat map, never trusted from the
 * client: POST silently overrides whatever `capacity` was sent, and PUT rejects any attempt to touch
 * `capacity` at all with 400 `bad_request` rather than silently letting it diverge from the grid -
 * `HoldBooking::validateEvent()` enforces `capacity` as the hard seat ceiling for grid bookings too,
 * so a diverged value would either strand real seats or admit more claims than the grid has cells for.
 *
 * Guard checks run twice per write - once outside any transaction for a fast, ordinary refusal, and
 * again as the transaction's first statement immediately before the write - mirroring
 * `ServicesAdminController::destroy()` as fixed in the Task 11 review (fix round 1): the recheck
 * closes the same TOCTOU window a concurrent booking could otherwise slip through.
 */
final class OccurrencesAdminController {

	private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?Z?$/';

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/occurrences?service_id= */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$serviceId = (int) $request->get_param( 'service_id' );
		$rows      = ( new OccurrenceRepository( $this->db ) )->forService( $serviceId );
		return new \WP_REST_Response(
			array(
				'occurrences' => array_map(
					static fn ( array $row ): array => self::present( $row, (int) $row['booked'] ),
					$rows
				),
			)
		);
	}

	/** POST /admin/occurrences - event services only; capacity is derived, not trusted, on a seat-mapped one. */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$serviceId = Input::posInt( $request->get_param( 'service_id' ) );
		if ( null === $serviceId ) {
			return Errors::badRequest( __( '"service_id" must be a positive integer.', 'reservant' ) );
		}
		$service = ( new ServiceRepository( $this->db ) )->find( $serviceId );
		if ( null === $service ) {
			return Errors::badRequest( __( 'No such service.', 'reservant' ) );
		}
		if ( ServiceType::Event->value !== $service['type'] ) {
			return Errors::badRequest( __( 'Occurrences may only be created for event services.', 'reservant' ) );
		}

		try {
			$startUtc = self::utcDateTime( Input::text( $request->get_param( 'start_utc' ) ), 'start_utc' );
			$endUtc   = self::utcDateTime( Input::text( $request->get_param( 'end_utc' ) ), 'end_utc' );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}
		if ( $endUtc <= $startUtc ) {
			return Errors::badRequest( __( '"end_utc" must be after "start_utc".', 'reservant' ) );
		}

		$repo      = new OccurrenceRepository( $this->db );
		$seatMapId = null === $service['seat_map_id'] ? null : (int) $service['seat_map_id'];
		if ( null !== $seatMapId ) {
			// Derived from the grid, not the client - see the class docblock.
			$capacity = count( $repo->validSeatIds( $seatMapId ) );
		} else {
			$capacity = Input::posInt( $request->get_param( 'capacity' ) );
			if ( null === $capacity ) {
				return Errors::badRequest( __( '"capacity" must be a positive integer.', 'reservant' ) );
			}
		}

		$id = $repo->insert(
			array(
				'service_id' => $serviceId,
				'start_utc'  => $startUtc->format( 'Y-m-d H:i:s' ),
				'end_utc'    => $endUtc->format( 'Y-m-d H:i:s' ),
				'capacity'   => $capacity,
			)
		);
		// A freshly inserted occurrence has no booking items yet - 0 is exact, not a placeholder.
		return new \WP_REST_Response( self::present( (array) $repo->find( $id ), 0 ), 201 );
	}

	/** PUT /admin/occurrences/{id} - a partial patch of start_utc/end_utc/capacity; see the class docblock for the guard rails. */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo    = new OccurrenceRepository( $this->db );
		$id      = (int) $request->get_param( 'id' );
		$current = $repo->find( $id );
		if ( null === $current ) {
			return Errors::notFound();
		}

		try {
			$patch = self::sanitizePatch( $request );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}
		if ( array() === $patch ) {
			return new \WP_REST_Response( self::present( $current, $repo->blockingSeatSum( $id ) ) );
		}

		$service   = ( new ServiceRepository( $this->db ) )->find( (int) $current['service_id'] );
		$seatMapId = ( null !== $service && null !== $service['seat_map_id'] ) ? (int) $service['seat_map_id'] : null;
		if ( array_key_exists( 'capacity', $patch ) && null !== $seatMapId ) {
			return Errors::badRequest( __( 'Capacity is derived from the seat map on this occurrence; edit the map instead.', 'reservant' ) );
		}

		// Validated against the effective record (current row + patch), so a bare `{capacity:...}`
		// PUT never re-checks a start/end it did not touch (ServicesAdminController's own idiom).
		$effective = array_merge( $current, $patch );
		if ( strtotime( (string) $effective['end_utc'] ) <= strtotime( (string) $effective['start_utc'] ) ) {
			return Errors::badRequest( __( '"end_utc" must be after "start_utc".', 'reservant' ) );
		}

		$touchesSchedule = array_key_exists( 'start_utc', $patch ) || array_key_exists( 'end_utc', $patch );

		// The fast, non-transactional refusal - see the class docblock.
		$guardFailure = self::checkGuards( $repo, $id, $patch, $touchesSchedule );
		if ( null !== $guardFailure ) {
			return Errors::failure( $guardFailure );
		}

		try {
			$updated = ( new TransactionRunner( $this->db ) )->run(
				function () use ( $id, $patch, $touchesSchedule ): array {
					// A fresh repository instance, deliberately not the outer `$repo` - a genuinely
					// new read, not a reuse of the earlier result (Task 11 fix round 1 idiom).
					$repo         = new OccurrenceRepository( $this->db );
					$guardFailure = self::checkGuards( $repo, $id, $patch, $touchesSchedule );
					if ( null !== $guardFailure ) {
						throw $guardFailure;
					}
					$repo->update( $id, $patch );
					$fresh = $repo->find( $id );
					if ( null === $fresh ) {
						throw new \RuntimeException( 'update_conflict' );
					}
					return $fresh;
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		return new \WP_REST_Response( self::present( $updated, ( new OccurrenceRepository( $this->db ) )->blockingSeatSum( $id ) ) );
	}

	/**
	 * DELETE /admin/occurrences/{id} - soft cancel; refused with 409 `referenced` while any booking
	 * actively holds the occurrence. See the class docblock for why this is a full block (unlike
	 * PUT's schedule-only one): cancelling erases the occurrence from every future-facing listing,
	 * which a capacity-only PUT never does.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new OccurrenceRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}
		if ( $repo->activeBookingCount( $id ) > 0 ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		try {
			$cancelled = ( new TransactionRunner( $this->db ) )->run(
				function () use ( $id ): bool {
					$repo = new OccurrenceRepository( $this->db );
					if ( $repo->activeBookingCount( $id ) > 0 ) {
						throw new \RuntimeException( 'referenced' );
					}
					return $repo->cancel( $id );
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		if ( ! $cancelled ) {
			return Errors::failure( new \RuntimeException( 'cancel_conflict' ) );
		}
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Both PUT guards in one place so the outer fast check and the in-transaction recheck can never
	 * drift apart (AGENTS.md Task 11 fix round 1 idiom - the two calls must run the identical check).
	 *
	 * @param array<string, mixed> $patch
	 */
	private static function checkGuards( OccurrenceRepository $repo, int $id, array $patch, bool $touchesSchedule ): ?\RuntimeException {
		if ( $touchesSchedule && $repo->activeBookingCount( $id ) > 0 ) {
			return new \RuntimeException( 'referenced' );
		}
		if ( array_key_exists( 'capacity', $patch ) && (int) $patch['capacity'] < $repo->blockingSeatSum( $id ) ) {
			return new \RuntimeException( 'capacity' );
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private static function sanitizePatch( \WP_REST_Request $request ): array {
		$patch = array();
		foreach ( array( 'start_utc', 'end_utc', 'capacity' ) as $field ) {
			if ( ! $request->has_param( $field ) ) {
				continue;
			}
			$patch[ $field ] = 'capacity' === $field
				? self::posIntOrThrow( $request->get_param( $field ), $field )
				: self::utcDateTime( Input::text( $request->get_param( $field ) ), $field )->format( 'Y-m-d H:i:s' );
		}
		return $patch;
	}

	/** @throws \InvalidArgumentException */
	private static function posIntOrThrow( mixed $value, string $field ): int {
		$id = Input::posInt( $value );
		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in update(); never echoed. $field is always a literal.
			throw new \InvalidArgumentException( '"' . $field . '" must be a positive integer.' );
		}
		return $id;
	}

	/** @throws \InvalidArgumentException On anything but an explicit UTC wall-clock string. */
	private static function utcDateTime( string $value, string $field ): \DateTimeImmutable {
		if ( 1 !== preg_match( self::DATETIME_PATTERN, $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed. $field is always a literal.
			throw new \InvalidArgumentException( '"' . $field . '" must look like 2026-06-01 09:00:00 (UTC).' );
		}
		try {
			return new \DateTimeImmutable( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $value ), new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create()/update(); never echoed. $field is always a literal.
			throw new \InvalidArgumentException( '"' . $field . '" is not a real date and time.' );
		}
	}

	/**
	 * `booked` is always given explicitly rather than read off the row (AGENTS.md Task 12): a
	 * `find()` row never carries it, and a `forService()` row's copy can be stale by the time a
	 * write elsewhere in the same request has run, so every call site passes a freshly computed
	 * count rather than let this method guess whether one is already on the row.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function present( array $row, int $booked ): array {
		return array(
			'id'         => (int) $row['id'],
			'service_id' => (int) $row['service_id'],
			'start_utc'  => (string) $row['start_utc'],
			'end_utc'    => (string) $row['end_utc'],
			'capacity'   => (int) $row['capacity'],
			'booked'     => $booked,
			'status'     => (string) $row['status'],
		);
	}
}
