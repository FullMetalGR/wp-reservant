<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Application\ApproveBooking;
use Reservant\Application\CancelBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\MarkBookingOutcome;
use Reservant\Application\RejectBooking;
use Reservant\Application\SlotConflict;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;
use Reservant\Rest\PresentsBookings;
use Reservant\Rest\Routes;

/**
 * The admin bookings surface (AGENTS.md Task 10): search/detail, the owner's manual booking
 * (`POST /admin/bookings`), and the lifecycle transitions - approve/reject/cancel/no_show/complete.
 *
 * Handlers stay thin: sanitize the request, call the use case, present through `PresentsBookings`
 * (which already strips `manage_token`/`manage_token_hash` - AGENTS.md Task 10 explicitly forbids the
 * manual-booking response carrying a manage token, and this trait is how that is guaranteed rather
 * than merely remembered).
 */
final class BookingsAdminController {

	use PresentsBookings;

	private const MAX_SEGMENTS     = 5;
	private const MAX_SEATS        = 20;
	private const MAX_PER_PAGE     = 100;
	private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?Z?$/';

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/bookings */
	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$filters = array();
		$from    = (string) $request->get_param( 'from' );
		if ( '' !== $from ) {
			$filters['from'] = $from . ' 00:00:00';
		}
		$to = (string) $request->get_param( 'to' );
		if ( '' !== $to ) {
			$filters['to'] = $to . ' 00:00:00';
		}
		$status = (string) $request->get_param( 'status' );
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}
		$resourceId = (int) $request->get_param( 'resource_id' );
		if ( $resourceId > 0 ) {
			$filters['resource_id'] = $resourceId;
		}
		$serviceId = (int) $request->get_param( 'service_id' );
		if ( $serviceId > 0 ) {
			$filters['service_id'] = $serviceId;
		}
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		// search() reads each row through the now-guarded findById() (BookingRepository's docblock) -
		// caught so a DB-level failure on any one row answers the same clean 409 the detail/lifecycle
		// routes on this controller already do, instead of escaping this callback uncaught.
		try {
			list( $total, $rows ) = ( new BookingRepository( $this->db ) )->search( $filters, $perPage, ( $page - 1 ) * $perPage );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		return new \WP_REST_Response(
			array(
				'total'    => $total,
				'bookings' => array_map( fn ( array $row ): array => $this->presentForCaller( $row ), $rows ),
			)
		);
	}

	/**
	 * GET /admin/bookings/{uuid} - the same joined shape as the list, plus the audit trail.
	 *
	 * Manage-gated (AGENTS.md Task 10 fix round 1: this route is only reachable with
	 * `reservant_manage_bookings`, so `presentForCaller()`'s contact-stripping branch never fires
	 * here - kept for defense in depth, not because a staff-only caller can reach this handler.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// findDetailByUuid() reads through the now-guarded findByUuid() - see that method's docblock.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findDetailByUuid( (string) $request->get_param( 'uuid' ) );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return Errors::notFound();
		}
		$audit   = ( new AuditLog( $this->db ) )->forBooking( (int) $booking['id'] );
		$payload = $this->presentForCaller( $booking );

		$payload['audit'] = $audit;
		return new \WP_REST_Response( $payload );
	}

	/**
	 * POST /admin/bookings - the owner booking a slot by hand (AGENTS.md Task 6/10): `HoldRequest::$admin`
	 * lands it straight on `confirmed`, and the response is presented through the same trait every
	 * other booking response goes through, so `manage_token` never leaves this endpoint.
	 *
	 * `HoldBooking` itself always records its own system-tagged audit row (actor `admin`, action
	 * `admin_create`); this handler adds a SECOND row, `admin_create_by`, naming the real WP user
	 * who did it - a distinct action name on purpose (AGENTS.md Task 10 fix round 1: reusing
	 * `admin_create` for both rows made "who created this" ambiguous in the audit trail), so the
	 * detail view can show both "this was an admin-mode booking" and "created by X".
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$holdRequest = self::parse( $request );
		} catch ( \InvalidArgumentException $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		try {
			$booking = HoldBooking::make( $this->db )->execute( $holdRequest, $this->now() );
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		$actor = (string) wp_get_current_user()->user_login;
		if ( '' !== $actor ) {
			( new AuditLog( $this->db ) )->record( (int) $booking['id'], $actor, 'admin_create_by' );
		}

		return new \WP_REST_Response( $this->presentForCaller( $booking ), 201 );
	}

	/**
	 * POST /admin/bookings/{uuid}/approve.
	 *
	 * A staff member (`reservant_approve_bookings` without `reservant_manage_bookings`) may only
	 * approve a booking assigned to their own resource (AGENTS.md section 10: "Approval decisions are
	 * made by admins or by the staff member assigned to the booking"). Reachable with only
	 * `reservant_approve_bookings` - `presentForCaller()` strips the customer's contact details for
	 * exactly that caller (AGENTS.md Task 10 fix round 1: contact details require
	 * `reservant_manage_bookings`).
	 */
	public function approve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure - see its docblock.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findByUuid( $uuid );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return Errors::notFound();
		}
		$scoped = $this->assertOwnResourceOrManage( $booking );
		if ( true !== $scoped ) {
			return $scoped;
		}

		$user = wp_get_current_user();
		try {
			$approved = ApproveBooking::make( $this->db )->execute( $uuid, $this->now(), (string) $user->user_login, get_current_user_id() );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentForCaller( $approved ) );
	}

	/**
	 * POST /admin/bookings/{uuid}/reject - body `{reason?: string}`. Same own-resource scope, and
	 * the same contact-stripping for a staff-only caller, as approve.
	 */
	public function reject( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure - see its docblock.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findByUuid( $uuid );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return Errors::notFound();
		}
		$scoped = $this->assertOwnResourceOrManage( $booking );
		if ( true !== $scoped ) {
			return $scoped;
		}

		$reason = sanitize_textarea_field( Input::text( $request->get_param( 'reason' ) ) );
		$actor  = (string) wp_get_current_user()->user_login;
		try {
			$rejected = RejectBooking::make( $this->db )->execute( $uuid, $reason, $this->now(), $actor );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentForCaller( $rejected ) );
	}

	/**
	 * POST /admin/bookings/{uuid}/cancel - the manager override (`force: true`); no staff scoping,
	 * manage-gated.
	 *
	 * `CancelBooking` always records its own audit row with the hardcoded actor `'customer'` (it is
	 * shared with the guest-facing `DELETE /holds`/`POST /bookings/{uuid}/cancel` paths, where that
	 * is correct); an admin force-cancel through THIS route adds a second row, `admin_cancel`, naming
	 * the real WP user, so a manager's cancellation is never misattributed to the customer in the
	 * audit trail (AGENTS.md Task 10 fix round 1).
	 */
	public function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure - see its docblock.
		try {
			$exists = null !== ( new BookingRepository( $this->db ) )->findByUuid( $uuid );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( ! $exists ) {
			return Errors::notFound();
		}
		try {
			$cancelled = CancelBooking::make( $this->db )->execute( $uuid, $this->now(), true );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		$actor = (string) wp_get_current_user()->user_login;
		if ( '' !== $actor ) {
			( new AuditLog( $this->db ) )->record( (int) $cancelled['id'], $actor, 'admin_cancel' );
		}

		return new \WP_REST_Response( $this->presentForCaller( $cancelled ) );
	}

	/** POST /admin/bookings/{uuid}/no_show */
	public function noShow( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->outcome( $request, 'no_show' );
	}

	/** POST /admin/bookings/{uuid}/complete */
	public function complete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->outcome( $request, 'completed' );
	}

	private function outcome( \WP_REST_Request $request, string $outcome ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure - see its docblock.
		try {
			$exists = null !== ( new BookingRepository( $this->db ) )->findByUuid( $uuid );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( ! $exists ) {
			return Errors::notFound();
		}
		$actor = (string) wp_get_current_user()->user_login;
		try {
			$result = MarkBookingOutcome::make( $this->db )->execute( $uuid, $outcome, $actor );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentForCaller( $result ) );
	}

	/**
	 * `presentBooking()` plus one more rule (AGENTS.md Task 10 fix round 1, spec: "contact details
	 * require `reservant_manage_bookings`"): a caller who reached this controller on
	 * `reservant_approve_bookings`/`reservant_view_own_calendar` alone - never `reservant_manage_bookings`
	 * - must not receive the customer's email or phone, on ANY admin response, not only the calendar.
	 * Every handler in this class routes its response through this method rather than
	 * `presentBooking()` directly, so the rule cannot be forgotten route-by-route; on the
	 * manage-gated routes (list/detail/create/cancel/no_show/complete) the caller always holds
	 * `reservant_manage_bookings` already, so the branch below is a no-op there.
	 *
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>
	 */
	private function presentForCaller( array $booking ): array {
		$payload = $this->presentBooking( $booking );
		if ( ! current_user_can( Routes::CAP_MANAGE ) ) {
			unset( $payload['customer_email'], $payload['customer_phone'] );
		}
		return $payload;
	}

	/**
	 * A user with `reservant_manage_bookings` acts on any booking. Anyone else must be the staff
	 * member linked (`ResourceRepository::findByWpUser()`) to at least one item's `resource_id` -
	 * event items (no `resource_id`) never satisfy this for a non-manager.
	 *
	 * @param array<string, mixed> $booking
	 * @return true|\WP_Error
	 */
	private function assertOwnResourceOrManage( array $booking ): bool|\WP_Error {
		if ( current_user_can( Routes::CAP_MANAGE ) ) {
			return true;
		}
		$resource = ( new ResourceRepository( $this->db ) )->findByWpUser( get_current_user_id() );
		if ( null === $resource ) {
			return AdminGuard::forbiddenError();
		}
		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		foreach ( $items as $item ) {
			if ( (int) ( $item['resource_id'] ?? 0 ) === (int) $resource['id'] ) {
				return true;
			}
		}
		return AdminGuard::forbiddenError();
	}

	/** @throws \InvalidArgumentException When the body is not a booking request. */
	private static function parse( \WP_REST_Request $request ): HoldRequest {
		$customer    = self::customer( $request->get_param( 'customer' ) );
		$appointment = $request->get_param( 'appointment' );
		$event       = $request->get_param( 'event' );

		if ( is_array( $appointment ) === is_array( $event ) ) {
			throw new \InvalidArgumentException( 'Send exactly one of "appointment" or "event".' );
		}
		return is_array( $appointment )
			? new HoldRequest( $customer, self::appointment( $appointment ), null, true )
			: new HoldRequest( $customer, null, self::event( (array) $event ), true );
	}

	/** @param mixed $raw */
	private static function customer( mixed $raw ): Customer {
		$raw   = is_array( $raw ) ? $raw : array();
		$name  = sanitize_text_field( Input::text( $raw['name'] ?? null ) );
		$email = sanitize_email( Input::text( $raw['email'] ?? null ) );
		if ( '' === $name || ! is_email( $email ) ) {
			throw new \InvalidArgumentException( '"customer" needs a name and a valid email.' );
		}
		return new Customer( $name, $email, sanitize_text_field( Input::text( $raw['phone'] ?? null ) ) );
	}

	/** @param array<string, mixed> $raw */
	private static function appointment( array $raw ): AppointmentRequest {
		$segments = $raw['segments'] ?? null;
		if ( ! is_array( $segments ) || array() === $segments || count( $segments ) > self::MAX_SEGMENTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create(); never echoed.
			throw new \InvalidArgumentException( 'A chain needs between 1 and ' . self::MAX_SEGMENTS . ' segments.' );
		}

		$choices = array();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				throw new \InvalidArgumentException( 'Every segment must be an object.' );
			}
			$serviceId = Input::posInt( $segment['service_id'] ?? null );
			if ( null === $serviceId ) {
				throw new \InvalidArgumentException( 'Every segment needs a positive integer service_id.' );
			}
			$choices[] = new SegmentChoice( $serviceId, self::optionalId( $segment, 'resource_id' ) );
		}

		$sameStaff = $raw['same_staff'] ?? false;
		$sameStaff = is_scalar( $sameStaff ) ? (string) $sameStaff : '';

		return new AppointmentRequest(
			self::utcDateTime( Input::text( $raw['start_utc'] ?? null ) ),
			$choices,
			rest_sanitize_boolean( $sameStaff )
		);
	}

	/** @param array<string, mixed> $raw */
	private static function event( array $raw ): EventRequest {
		$occurrenceId = Input::posInt( $raw['occurrence_id'] ?? null );
		if ( null === $occurrenceId ) {
			throw new \InvalidArgumentException( '"event" needs a positive integer occurrence_id.' );
		}

		$rawSeatIds = $raw['seat_ids'] ?? array();
		if ( ! is_array( $rawSeatIds ) ) {
			throw new \InvalidArgumentException( '"seat_ids" must be a list of seat ids.' );
		}
		$seatIds = array();
		foreach ( $rawSeatIds as $seatId ) {
			$id = Input::posInt( $seatId );
			if ( null === $id ) {
				throw new \InvalidArgumentException( 'Seat ids must be positive integers.' );
			}
			$seatIds[] = $id;
		}
		$seatIds = array_values( array_unique( $seatIds ) );

		$seats = self::optionalId( $raw, 'seats' ) ?? ( array() === $seatIds ? 1 : count( $seatIds ) );
		if ( $seats > self::MAX_SEATS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create(); never echoed.
			throw new \InvalidArgumentException( 'At most ' . self::MAX_SEATS . ' seats per booking.' );
		}
		return new EventRequest( $occurrenceId, $seats, $seatIds );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @throws \InvalidArgumentException When the key is present but unusable.
	 */
	private static function optionalId( array $raw, string $key ): ?int {
		if ( ! array_key_exists( $key, $raw ) || null === $raw[ $key ] ) {
			return null;
		}
		$id = Input::posInt( $raw[ $key ] );
		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create(); never echoed. $key is a literal from this class.
			throw new \InvalidArgumentException( '"' . $key . '" must be a positive integer when given.' );
		}
		return $id;
	}

	/** @throws \InvalidArgumentException On anything but an explicit UTC wall-clock string. */
	private static function utcDateTime( string $value ): \DateTimeImmutable {
		if ( 1 !== preg_match( self::DATETIME_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( '"start_utc" must look like 2026-06-01 09:00:00 (UTC).' );
		}
		try {
			return new \DateTimeImmutable( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $value ), new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			throw new \InvalidArgumentException( '"start_utc" is not a real date and time.' );
		}
	}

	/** The request's single "now" (AGENTS.md section 7). */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
