<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\CancelBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * `POST /holds` - the only authority on capacity (AGENTS.md section 2.2), and `DELETE /holds/{uuid}`,
 * which gives the slot straight back.
 *
 * Everything the client believed is discarded here: this controller's whole job is to turn an
 * untrusted body into a `HoldRequest` and let the locked write protocol decide. `409` is a normal
 * answer, not a failure.
 */
final class HoldsController {

	use PresentsBookings;

	private const DEFAULT_RATE_LIMIT = 10;
	private const MAX_SEGMENTS       = 5;
	private const MAX_SEATS          = 20;
	/** `Y-m-d H:i:s`, or the ISO variant a JS client will send without thinking about it. */
	private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?Z?$/';

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** POST /holds */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$limit = (int) apply_filters( 'reservant/holds/rate_limit', self::DEFAULT_RATE_LIMIT );
		if ( ! RateLimiter::allow( 'holds:' . self::clientIp(), $limit ) ) {
			$error = new \WP_Error(
				'reservant_rate_limited',
				'rate_limited',
				array(
					'status' => 429,
					'detail' => __( 'Too many booking attempts. Please wait a minute and try again.', 'reservant' ),
				)
			);
			// Converted here rather than returned as a WP_Error so the answer can carry Retry-After:
			// the limiter's window is a minute anchored to the last allowed request, so a minute is
			// the honest upper bound on the wait. The body is byte-identical to the WP_Error form.
			$response = rest_convert_error_to_response( $error );
			$response->header( 'Retry-After', (string) MINUTE_IN_SECONDS );
			return $response;
		}

		try {
			$holdRequest = $this->parse( $request );
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

		$payload                 = $this->presentBooking( $booking );
		$payload['manage_token'] = (string) $booking['manage_token']; // Shown exactly once.

		$response = new \WP_REST_Response( $payload, 201 );
		// This body carries the manage token - the guest's only credential for the booking. It must
		// not sit in a shared proxy or a browser's disk cache waiting to be replayed.
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * DELETE /holds/{uuid} - the customer walking away from checkout.
	 *
	 * Only a *held* booking may be released this way. Giving up a slot nobody has paid for or been
	 * promised is not policy-bound, so it force-cancels; a confirmed booking is a different act with
	 * a cancellation window attached, and belongs on `POST /bookings/{uuid}/cancel`.
	 *
	 * The status check below is a fast 409 on a read taken outside the lock, so it cannot be what
	 * enforces "held only" - a confirm committing right after it would otherwise be force-cancelled
	 * with its policy window bypassed. The held statuses are passed into the use case, which
	 * re-checks them inside the transaction; this is only the cheap path to the same answer.
	 */
	public function release( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure (BookingRepository's
		// docblock) - caught so it reaches the caller as the clean 409 the write below would already
		// answer with, not as an exception escaping this pre-check uncaught.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findByUuid( $uuid );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return Errors::notFound();
		}
		if ( ! BookingStatus::from( (string) $booking['status'] )->isHeld() ) {
			// Through `Errors` rather than hand-built, so this fast path and the use case's own
			// in-transaction `not_held` refusal answer with one envelope. They did not: this route
			// sent `reservant_conflict` plus its own sentence while the locked re-check sent
			// `reservant_not_held` plus the generic one, for the same reason on the same request.
			return Errors::failure( new \RuntimeException( 'not_held' ) );
		}

		try {
			$cancelled = CancelBooking::make( $this->db )->execute( $uuid, $this->now(), true, BookingStatus::heldStatuses() );
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentBooking( $cancelled ) );
	}

	/** @throws \InvalidArgumentException When the body is not a booking request. */
	private function parse( \WP_REST_Request $request ): HoldRequest {
		$customer    = self::customer( $request->get_param( 'customer' ) );
		$appointment = $request->get_param( 'appointment' );
		$event       = $request->get_param( 'event' );

		if ( is_array( $appointment ) === is_array( $event ) ) {
			throw new \InvalidArgumentException( 'Send exactly one of "appointment" or "event".' );
		}
		return is_array( $appointment )
			? new HoldRequest( $customer, self::appointment( $appointment ) )
			: new HoldRequest( $customer, null, self::event( (array) $event ) );
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
			// An unusable `resource_id` is a 400, never a silent fall-back to "any staff": the
			// customer asked for a particular person and must not quietly get somebody else.
			$choices[] = new SegmentChoice( $serviceId, self::optionalId( $segment, 'resource_id' ) );
		}

		// Narrowed to a string first: "false" and "0" must read as false, and only WordPress's
		// own sanitizer knows that.
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

		// A grid pick states its own count; only open seating needs `seats`.
		$seats = self::optionalId( $raw, 'seats' ) ?? ( array() === $seatIds ? 1 : count( $seatIds ) );
		if ( $seats > self::MAX_SEATS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in create(); never echoed.
			throw new \InvalidArgumentException( 'At most ' . self::MAX_SEATS . ' seats per booking.' );
		}
		return new EventRequest( $occurrenceId, $seats, $seatIds );
	}

	/**
	 * An optional positive integer field: absent (or explicitly null) means "unset", and anything
	 * else that is not a positive integer is a 400 rather than a silent default.
	 *
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

	/**
	 * The throttling bucket. `REMOTE_ADDR` is the only address the server itself observes; proxy
	 * headers are forgeable, so a site behind one filters `reservant/holds/rate_limit` instead.
	 */
	private static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? 'unknown' : $ip;
	}

	/** The request's single "now" (AGENTS.md section 7). */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
