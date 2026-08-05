<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Rest\Routes;

/**
 * Every `reservant/v1/admin/*` route (AGENTS.md Task 10), registered from `Rest\Routes::register()`
 * alongside the public surface. Authenticated as an ordinary WordPress user (`X-WP-Nonce`, core
 * REST cookie auth) - there is no guest credential on this namespace, unlike the public one.
 *
 * Cancel/no_show/complete require `reservant_manage_bookings` outright; approve/reject only need
 * `reservant_approve_bookings` (a staff member has this without the former, and is confined to
 * their own resource inside the controller - AGENTS.md section 10, "Approval decisions are made by
 * admins or by the staff member assigned to the booking"). The calendar and availability reads
 * accept either capability, since a staff member's own-schedule view has to be able to see what is
 * free as well as what is booked.
 */
final class AdminRoutes {

	private const UUID = '(?P<uuid>[0-9a-f-]{36})';

	public function __construct( private readonly \wpdb $db ) {}

	public function register(): void {
		$guard        = new AdminGuard();
		$bookings     = new BookingsAdminController( $this->db );
		$calendar     = new CalendarAdminController( $this->db );
		$availability = new AvailabilityAdminController( $this->db );

		register_rest_route(
			Routes::NS,
			'/admin/bookings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $bookings, 'index' ),
					'permission_callback' => array( $guard, 'manageBookings' ),
					'args'                => self::searchArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $bookings, 'create' ),
					'permission_callback' => array( $guard, 'manageBookings' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $bookings, 'show' ),
				'permission_callback' => array( $guard, 'manageBookings' ),
				'args'                => self::uuidArgs(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID . '/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'approve' ),
				'permission_callback' => array( $guard, 'approveBookings' ),
				'args'                => self::uuidArgs(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID . '/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'reject' ),
				'permission_callback' => array( $guard, 'approveBookings' ),
				'args'                => self::uuidArgs() + array(
					'reason' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID . '/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'cancel' ),
				'permission_callback' => array( $guard, 'manageBookings' ),
				'args'                => self::uuidArgs(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID . '/no_show',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'noShow' ),
				'permission_callback' => array( $guard, 'manageBookings' ),
				'args'                => self::uuidArgs(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/bookings/' . self::UUID . '/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $bookings, 'complete' ),
				'permission_callback' => array( $guard, 'manageBookings' ),
				'args'                => self::uuidArgs(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/calendar',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $calendar, 'index' ),
				'permission_callback' => array( $guard, 'calendarAccess' ),
				'args'                => array(
					'from'        => array(
						'required'          => true,
						'validate_callback' => array( Routes::class, 'isDate' ),
					),
					'to'          => array(
						'required'          => true,
						'validate_callback' => array( Routes::class, 'isDate' ),
					),
					'resource_id' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $availability, 'index' ),
				'permission_callback' => array( $guard, 'calendarAccess' ),
				'args'                => array(
					'items'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn ( $value ): string => is_string( $value ) ? $value : '',
					),
					'from'       => array(
						'required'          => true,
						'validate_callback' => array( Routes::class, 'isDate' ),
					),
					'to'         => array(
						'required'          => true,
						'validate_callback' => array( Routes::class, 'isDate' ),
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
	}

	/** @return array<string, array<string, mixed>> */
	private static function searchArgs(): array {
		return array(
			'from'        => array(
				'default'           => '',
				'validate_callback' => array( self::class, 'isDateOrEmpty' ),
			),
			'to'          => array(
				'default'           => '',
				'validate_callback' => array( self::class, 'isDateOrEmpty' ),
			),
			'status'      => array(
				'default'           => '',
				'validate_callback' => array( self::class, 'isStatusOrEmpty' ),
			),
			'resource_id' => array(
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'service_id'  => array(
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'search'      => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'        => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'    => array(
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function uuidArgs(): array {
		return array(
			'uuid' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/** @param mixed $value */
	public static function isDateOrEmpty( $value ): bool {
		return '' === $value || Routes::isDate( $value );
	}

	/** @param mixed $value */
	public static function isStatusOrEmpty( $value ): bool {
		if ( '' === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		return null !== BookingStatus::tryFrom( $value );
	}
}
