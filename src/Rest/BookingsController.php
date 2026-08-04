<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\SlotConflict;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * Guest self-service on one booking (AGENTS.md section 5): read it, confirm it, cancel it. The magic-link
 * token is checked in the route's permission callback (`Routes::guard()`); by the time a handler
 * runs, the caller is either the booking's owner or a manager.
 */
final class BookingsController {

	use PresentsBookings;

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /bookings/{uuid} */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$booking = ( new BookingRepository( $this->db ) )->findByUuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $booking ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( $this->presentBooking( $booking ) );
	}

	/** POST /bookings/{uuid}/confirm - the free / pay-on-site path only (section 2.3). */
	public function confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$confirmed = ConfirmBooking::make( $this->db )->execute( (string) $request->get_param( 'uuid' ), $this->now() );
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentBooking( $confirmed ) );
	}

	/**
	 * POST /bookings/{uuid}/cancel.
	 *
	 * A guest is held to the service's cancellation window; a manager is not - the capability *is*
	 * the override, so no `force` flag is read from the request.
	 */
	public function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$cancelled = CancelBooking::make( $this->db )->execute(
				(string) $request->get_param( 'uuid' ),
				$this->now(),
				current_user_can( Routes::CAP_MANAGE )
			);
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentBooking( $cancelled ) );
	}

	/** The request's single "now" (AGENTS.md section 7). */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
