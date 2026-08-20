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
 *
 * The catalog is `reservant_manage_settings` throughout with exactly two exceptions: GET
 * /admin/services and GET /admin/resources also accept `reservant_manage_bookings`
 * (`AdminGuard::readCatalog()`), because the Calendar and Bookings screens are gated on that cap
 * and cannot render their staff filter, service filter or manual-booking drawer without those two
 * lists. Both are reads of the collection only - every write, and every other catalog route,
 * including the single-item reads, stays on `reservant_manage_settings`.
 *
 * **Licensing is enforced by which of two callbacks a route carries, and by nothing else.** Every
 * configuration WRITE - `CREATABLE`, `EDITABLE`, `DELETABLE` on services, staff, exceptions,
 * occurrences, seat maps and settings - is on `AdminGuard::configureSite()`, which is the same
 * capability plus an active license. Every configuration READ stays on `manageSettings()`,
 * `readCatalog()` or `calendarAccess()`, and every booking-lifecycle route stays on
 * `manageBookings()` / `approveBookings()`. So an unlicensed site loses the ability to change its
 * setup and keeps everything else: the calendar, the bookings list, approve, reject, cancel,
 * reschedule, manual booking, and the whole public surface. AGENTS.md section 5 states the policy
 * and `AdminGuard::configureSite()` states why the lifecycle in particular is exempt.
 *
 * The rule is "write verb on a configuration route", not "route that looks like configuration":
 * `POST /admin/bookings` is a write on this namespace and is deliberately NOT gated - a manual
 * booking is the owner taking a booking, which is the thing that must never stop.
 */
final class AdminRoutes {

	private const UUID = '(?P<uuid>[0-9a-f-]{36})';
	private const ID   = '(?P<id>\d+)';

	public function __construct( private readonly \wpdb $db ) {}

	public function register(): void {
		$guard        = new AdminGuard();
		$bookings     = new BookingsAdminController( $this->db );
		$calendar     = new CalendarAdminController( $this->db );
		$availability = new AvailabilityAdminController( $this->db );
		$services     = new ServicesAdminController( $this->db );
		$resources    = new ResourcesAdminController( $this->db );
		$occurrences  = new OccurrencesAdminController( $this->db );
		$seatMaps     = new SeatMapsAdminController( $this->db );
		$settings     = new SettingsAdminController();
		$license      = new LicenseAdminController();

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

		register_rest_route(
			Routes::NS,
			'/admin/services',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $services, 'index' ),
					'permission_callback' => array( $guard, 'readCatalog' ),
					'args'                => self::includeInactiveArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $services, 'create' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/services/' . self::ID,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $services, 'show' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $services, 'update' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $services, 'destroy' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/resources',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $resources, 'index' ),
					'permission_callback' => array( $guard, 'readCatalog' ),
					'args'                => self::includeInactiveArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $resources, 'create' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/resources/' . self::ID,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $resources, 'show' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $resources, 'update' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $resources, 'destroy' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/resources/' . self::ID . '/exceptions',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $resources, 'addException' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $resources, 'removeException' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/exceptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $resources, 'listExceptions' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => array(
						'resource_id' => array(
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $resources, 'addBusinessException' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $resources, 'removeBusinessException' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/occurrences',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $occurrences, 'index' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => array(
						'service_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $occurrences, 'create' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/occurrences/' . self::ID,
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $occurrences, 'update' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $occurrences, 'destroy' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/seat-maps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $seatMaps, 'index' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $seatMaps, 'create' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/seat-maps/' . self::ID,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $seatMaps, 'show' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $seatMaps, 'update' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $seatMaps, 'destroy' ),
					'permission_callback' => array( $guard, 'configureSite' ),
					'args'                => self::idArgs(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/admin/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $settings, 'index' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $settings, 'update' ),
					'permission_callback' => array( $guard, 'configureSite' ),
				),
			)
		);

		// The one write on this namespace that is `manageSettings` and NOT `configureSite`, on
		// purpose: this is the route that makes a lapsed license active again, so gating it on an
		// active license would leave an unlicensed site with no way back. See
		// `LicenseAdminController`.
		register_rest_route(
			Routes::NS,
			'/admin/license',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $license, 'index' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $license, 'create' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
					'args'                => array(
						'key' => array(
							'default'  => '',
							// Deliberately NOT `sanitize_text_field`: it strips more than whitespace
							// (tags, octets, percent-encodings), and a key is an opaque credential
							// that must reach the validator exactly as the vendor issued it. A key
							// silently altered on the way in is a key that can only ever be refused,
							// with nothing on screen to say why. `Input::text()` in the controller
							// refuses a non-string; trimming is the manager's own documented job.
							'type'     => 'string',
							'required' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $license, 'destroy' ),
					'permission_callback' => array( $guard, 'manageSettings' ),
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

	/** @return array<string, array<string, mixed>> */
	private static function idArgs(): array {
		return array(
			'id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function includeInactiveArgs(): array {
		return array(
			'include_inactive' => array(
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
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
