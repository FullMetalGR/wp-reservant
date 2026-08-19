<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\ManageToken;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * Every `reservant/v1` route, its schema and its permission callback in one place (AGENTS.md section 5).
 *
 * Two credentials exist. Managers authenticate as WordPress users and are checked against the
 * `reservant_manage_bookings` capability - never `manage_options`. Guests carry a signed manage
 * token: the plaintext secret lives only in their email link, the row stores its SHA-256 hash, and
 * `ManageToken::verify()` compares them in constant time.
 */
final class Routes {

	public const NS         = 'reservant/v1';
	public const CAP_MANAGE = 'reservant_manage_bookings';

	private const UUID = '(?P<uuid>[0-9a-f-]{36})';

	private readonly \wpdb $db;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	public function register(): void {
		$availability = new AvailabilityController( $this->db );
		$holds        = new HoldsController( $this->db );
		$bookings     = new BookingsController( $this->db );
		$services     = new ServicesController( $this->db );

		register_rest_route(
			self::NS,
			'/services',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $services, 'index' ),
				'permission_callback' => array( $this, 'allowPublic' ),
			)
		);

		register_rest_route(
			self::NS,
			'/services/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $availability, 'service' ),
				'permission_callback' => array( $this, 'allowPublic' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $availability, 'availability' ),
				'permission_callback' => array( $this, 'allowPublic' ),
				'args'                => array(
					'items'      => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'JSON list of chain segments: [{"service_id":1,"resource_id":2}].', 'reservant' ),
						'sanitize_callback' => static fn ( $value ): string => is_string( $value ) ? $value : '',
					),
					'from'       => array(
						'required'          => true,
						'validate_callback' => array( self::class, 'isDate' ),
					),
					'to'         => array(
						'required'          => true,
						'validate_callback' => array( self::class, 'isDate' ),
					),
					'same_staff' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'tz'         => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/occurrences/(?P<id>\d+)/seats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $availability, 'seats' ),
				'permission_callback' => array( $this, 'allowPublic' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/holds',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $holds, 'create' ),
				'permission_callback' => array( $this, 'allowPublic' ),
			)
		);

		register_rest_route(
			self::NS,
			'/holds/' . self::UUID,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $holds, 'release' ),
				'permission_callback' => array( $this, 'requireToken' ),
				'args'                => self::tokenArgs(),
			)
		);

		register_rest_route(
			self::NS,
			'/bookings/' . self::UUID,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $bookings, 'show' ),
				'permission_callback' => array( $this, 'requireTokenOrCap' ),
				'args'                => self::tokenArgs(),
			)
		);

		register_rest_route(
			self::NS,
			'/bookings/' . self::UUID . '/confirm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'confirm' ),
				'permission_callback' => array( $this, 'requireTokenOrCap' ),
				'args'                => self::tokenArgs(),
			)
		);

		register_rest_route(
			self::NS,
			'/bookings/' . self::UUID . '/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'cancel' ),
				'permission_callback' => array( $this, 'requireTokenOrCap' ),
				'args'                => self::tokenArgs(),
			)
		);

		register_rest_route(
			self::NS,
			'/bookings/' . self::UUID . '/reschedule',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'reschedule' ),
				// Not `requireTokenOrCap`: a wrong-token 403 must be indistinguishable from an
				// unknown-uuid answer here, or the guard becomes a booking-existence oracle for an
				// anonymous caller trying tokens against ids. See `guard()`'s `$hideNotFound` arm.
				'permission_callback' => array( $this, 'requireTokenOrCapNoOracle' ),
				'args'                => array_merge(
					self::tokenArgs(),
					array(
						// Exactly one of these two is required; the cross-field check (both, or
						// neither) lives in `BookingsController::reschedule()`, not here - REST's
						// per-argument schema has no "one of" primitive.
						'start_utc'     => array(
							'validate_callback' => array( self::class, 'isDateTime' ),
						),
						'occurrence_id' => array(
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		( new Admin\AdminRoutes( $this->db ) )->register();
	}

	/**
	 * The service catalogue, availability and seat maps are the shop window - readable by anyone,
	 * exactly as they are on the site's own pages. Written out rather than `__return_true` so the
	 * decision is visible at the call site (AGENTS.md section 5).
	 */
	public function allowPublic(): bool {
		return true;
	}

	/** @return true|\WP_Error */
	public function requireToken( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->guard( $request, false );
	}

	/** @return true|\WP_Error */
	public function requireTokenOrCap( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->guard( $request, true );
	}

	/**
	 * Same as `requireTokenOrCap()`, except an unknown uuid is refused in the SAME shape as a wrong
	 * token, rather than passed through to the handler's own `404`.
	 *
	 * Reserved for routes where telling the two apart would let an anonymous caller enumerate real
	 * booking ids by trying tokens against them - currently just `/reschedule`. `show`/`confirm`/
	 * `cancel` keep the accurate, distinguishing answer (`requireTokenOrCap()`), which is already
	 * pinned by `RestApiTest::test_bad_token_is_403_and_missing_uuid_404()`.
	 *
	 * @return true|\WP_Error
	 */
	public function requireTokenOrCapNoOracle( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->guard( $request, true, true );
	}

	/**
	 * An unknown uuid passes the guard on purpose by default: the handler answers `404`, so a caller
	 * with a wrong token and a caller with a wrong uuid get different, accurate answers instead of one
	 * misleading `403`. `$hideNotFound` inverts that for the one route that must not make the
	 * distinction - see `requireTokenOrCapNoOracle()`.
	 *
	 * `$hideNotFound` is opt-in, defaults `false`, and only `/reschedule` passes `true`. Both
	 * behaviours are intentional, not one fixed and one drifted: the default (distinguishing) answer
	 * on `show`/`confirm`/`cancel` is pinned by
	 * `RestApiTest::test_bad_token_is_403_and_missing_uuid_404()`; the non-distinguishing answer on
	 * `/reschedule` is pinned by
	 * `RescheduleRouteTest::test_rejects_a_wrong_token_without_revealing_whether_the_uuid_exists()`.
	 * The default is left alone on purpose - a plain, unauthenticated, side-effect-free `GET
	 * /bookings/{uuid}` is already a cheaper way to learn whether a uuid is real than anything
	 * `/reschedule` could be made to hide, so the booking-existence oracle is an accepted,
	 * product-wide property of this API today, not something any one route can close by itself.
	 * Whether to revisit that product-wide is a separate decision (tracked outside this file), not one
	 * to make by quietly flipping this default.
	 *
	 * @return true|\WP_Error
	 */
	private function guard( \WP_REST_Request $request, bool $allowCapability, bool $hideNotFound = false ): bool|\WP_Error {
		if ( $allowCapability && current_user_can( self::CAP_MANAGE ) ) {
			return true;
		}
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure rather than returning a
		// misleading null (BookingRepository::findByUuid()'s docblock). Caught here for the same reason
		// every handler that calls it directly does: a `WP_Error` is already this method's own return
		// type, so the fix is answering with the clean 409 instead of letting a permission callback
		// throw uncaught - which, before this guard, would have been worse than today's behaviour of
		// silently granting `true` on a failed lookup.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findByUuid( (string) $request->get_param( 'uuid' ) );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return $hideNotFound ? self::forbidden() : true;
		}
		$storedHash = null === $booking['manage_token_hash'] ? null : (string) $booking['manage_token_hash'];
		if ( ManageToken::verify( (string) $request->get_param( 'token' ), $storedHash ) && self::withinTokenLifetime( $booking ) ) {
			return true;
		}
		return self::forbidden();
	}

	/**
	 * Whether the guest's credential is still live, on top of whether it is correct.
	 *
	 * The secret has no stored expiry of its own - only the hash is kept - so its lifetime is
	 * derived from the booking it belongs to: valid until `reservant/manage_token_days_after` days
	 * past the end of the last segment. Nothing here is a second authorisation decision; a wrong
	 * token and an expired one get the same `forbidden()`, so this adds no way to tell them apart.
	 *
	 * WHY IT EXPIRES AT ALL. The link lives in the guest's inbox forever, and it is the whole
	 * credential: whoever holds it can read the booking's contact details on `GET /bookings/{uuid}`.
	 * The lifecycle routes are already self-limiting (a finished booking cannot be cancelled or
	 * rescheduled), so what a stale link really leaks is the customer's own email and phone number,
	 * years after the appointment, to whoever has the mailbox by then. Thirty days past the
	 * appointment is long enough to cover a receipt-hunting guest and short enough that a link is
	 * not a permanent disclosure.
	 *
	 * Zero switches expiry off, for a site that would rather keep the old behaviour; a negative
	 * filter answer is treated as zero rather than as a link that expired before it was sent.
	 *
	 * The fallback when a booking has no items is `created_at`, not "valid forever": a container
	 * with nothing in it is a malformed row, and the safe direction to fail on a credential is
	 * closed.
	 *
	 * @param array<string, mixed> $booking `BookingRepository::findByUuid()` shape.
	 */
	private static function withinTokenLifetime( array $booking ): bool {
		/** @var mixed $filtered */
		$filtered = apply_filters( 'reservant/manage_token_days_after', 30 );
		$days     = is_int( $filtered ) ? $filtered : 30;
		if ( $days <= 0 ) {
			return true;
		}

		$utc  = new \DateTimeZone( 'UTC' );
		$last = (string) ( $booking['created_at'] ?? '' );
		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		foreach ( $items as $item ) {
			$end = (string) ( $item['end_utc'] ?? '' );
			if ( $end > $last ) {
				$last = $end; // `Y-m-d H:i:s` sorts lexically, and both sides are always UTC.
			}
		}
		if ( '' === $last ) {
			return false;
		}

		$expiresAt = ( new \DateTimeImmutable( $last, $utc ) )->modify( '+' . $days . ' days' );
		return new \DateTimeImmutable( 'now', $utc ) <= $expiresAt;
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error(
			'reservant_forbidden',
			'forbidden',
			array(
				'status' => 403,
				'detail' => __( 'That link is not valid for this booking.', 'reservant' ),
			)
		);
	}

	/** @param mixed $value */
	public static function isDate( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	/**
	 * `Y-m-d H:i:s`, the wire shape `RescheduleBooking::execute()` and every fixture already use.
	 *
	 * @param mixed $value
	 */
	public static function isDateTime( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );
	}

	/** @return array<string, array<string, mixed>> */
	private static function tokenArgs(): array {
		return array(
			'uuid'  => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'token' => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
