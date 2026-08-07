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
	 * An unknown uuid passes the guard on purpose: the handler answers `404`, so a caller with a
	 * wrong token and a caller with a wrong uuid get different, accurate answers instead of one
	 * misleading `403`.
	 *
	 * @return true|\WP_Error
	 */
	private function guard( \WP_REST_Request $request, bool $allowCapability ): bool|\WP_Error {
		if ( $allowCapability && current_user_can( self::CAP_MANAGE ) ) {
			return true;
		}
		$booking = ( new BookingRepository( $this->db ) )->findByUuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $booking ) {
			return true;
		}
		$storedHash = null === $booking['manage_token_hash'] ? null : (string) $booking['manage_token_hash'];
		if ( ManageToken::verify( (string) $request->get_param( 'token' ), $storedHash ) ) {
			return true;
		}
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
