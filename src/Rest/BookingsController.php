<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\RescheduleBooking;
use Reservant\Application\SlotConflict;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * Guest self-service on one booking (AGENTS.md section 5): read it, confirm it, cancel it, reschedule
 * it. The magic-link token is checked in the route's permission callback (`Routes::guard()`); by the
 * time a handler runs, the caller is either the booking's owner or a manager.
 */
final class BookingsController {

	use PresentsBookings;

	private ?\DateTimeImmutable $now = null;

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /bookings/{uuid} */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// findByUuid() now refuses `lock_unavailable` on a DB-level failure rather than returning a
		// misleading null (BookingRepository::findByUuid()'s docblock) - caught here so that refusal
		// reaches the caller as the same clean 409 every other guarded read on this codebase answers
		// with, not as an exception escaping this REST callback uncaught.
		try {
			$booking = ( new BookingRepository( $this->db ) )->findByUuid( (string) $request->get_param( 'uuid' ) );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		if ( null === $booking ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( $this->presentBooking( $booking ) );
	}

	/** POST /bookings/{uuid}/confirm - the free / pay-on-site path only (section 2.3). */
	public function confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			// The token is forwarded so `booking_confirmed` can carry the guest's manage link - it is
			// not what authorises the call (`Routes::guard()` did that), and the use case verifies it
			// against this booking's own hash before letting it reach a listener.
			$confirmed = ConfirmBooking::make( $this->db )->execute(
				(string) $request->get_param( 'uuid' ),
				$this->now(),
				null === $request->get_param( 'token' ) ? null : (string) $request->get_param( 'token' )
			);
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

	/**
	 * POST /bookings/{uuid}/reschedule.
	 *
	 * A guest is held to the service's reschedule window and never gets `force = true`; a manager's
	 * capability IS the override, exactly as `cancel()`'s - no `force` flag is read from the request,
	 * so a token can never buy a policy-window bypass by claiming to be one.
	 *
	 * Exactly one of "start_utc" (move the chain) or "occurrence_id" (move to another occurrence) must
	 * be present - both, or neither, is a 400, not a silent preference for one.
	 *
	 * The permission callback (`Routes::requireTokenOrCapNoOracle()`) already answers a wrong token
	 * and an unknown uuid identically, so this method never needs to tell them apart either; a `uuid`
	 * that reaches here with no matching booking can therefore only be the manager path, and
	 * `RescheduleBooking::execute()` answers that with its own `not_found`.
	 *
	 * This parity is local to THIS route, not a product-wide closing of the booking-existence oracle.
	 * `show()`, `confirm()` and `cancel()` still answer a wrong token (403) and an unknown uuid (404)
	 * differently - that asymmetry is deliberate, older, and pinned by
	 * `RestApiTest::test_bad_token_is_403_and_missing_uuid_404()` - and a plain, unauthenticated `GET`
	 * against one of them is a cheaper, side-effect-free way to learn the same thing an attacker might
	 * have wanted from probing this route. Do not read the sentence above as "this endpoint closes the
	 * oracle": it only declines to add a second, redundant way to observe it.
	 */
	public function reschedule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$startUtcRaw     = $request->get_param( 'start_utc' );
		$occurrenceIdRaw = $request->get_param( 'occurrence_id' );
		$hasStart        = null !== $startUtcRaw && '' !== $startUtcRaw;
		$hasOccurrence   = null !== $occurrenceIdRaw;
		if ( $hasStart === $hasOccurrence ) {
			return Errors::badRequest( __( 'Send exactly one of "start_utc" or "occurrence_id".', 'reservant' ) );
		}

		$newOccurrenceId = $hasOccurrence ? (int) $occurrenceIdRaw : null;
		$newStartUtc     = $hasStart
			? new \DateTimeImmutable( (string) $startUtcRaw, new \DateTimeZone( 'UTC' ) )
			// Ignored on the event path (the target occurrence's own start wins); "now" is a harmless
			// filler so the signature never needs a nullable start.
			: $this->now();

		try {
			$moved = RescheduleBooking::make( $this->db )->execute(
				(string) $request->get_param( 'uuid' ),
				$newStartUtc,
				$newOccurrenceId,
				$this->now(),
				current_user_can( Routes::CAP_MANAGE )
			);
		} catch ( SlotConflict $exception ) {
			return Errors::conflict( $exception );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}
		return new \WP_REST_Response( $this->presentBooking( $moved ) );
	}

	/** The request's single "now" (AGENTS.md section 7). */
	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
