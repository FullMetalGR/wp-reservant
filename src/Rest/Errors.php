<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Application\SlotConflict;

/**
 * The one place Application failures become HTTP.
 *
 * `SlotConflict` is a normal outcome, not a bug (AGENTS.md section 2.2): the availability endpoint is
 * advisory and the hold endpoint is the authority, so `409 Conflict` is an expected answer. The
 * machine-readable `reason` is the message - clients switch on it - and a translated sentence rides
 * along in `data.detail` for anything that renders the error directly.
 */
final class Errors {

	/**
	 * Every reason string this plugin may repeat back to a caller: the eleven `SlotConflict` codes
	 * (documented on that class) plus the lifecycle refusals the use cases throw. Anything outside
	 * this list is an internal detail - grep `src/` for `new \RuntimeException(` before adding to it.
	 */
	private const KNOWN_REASONS = array(
		// SlotConflict - see Reservant\Application\SlotConflict.
		'overlap',
		'seat_taken',
		'capacity',
		'no_staff',
		'not_found',
		'bad_time',
		'lead_time',
		'horizon',
		'outside_hours',
		'bad_seat',
		// SlotConflict - RescheduleBooking's own addition. Its closed-window refusal is `window_closed`
		// below, deliberately the same signal CancelBooking raises, not a second convention.
		'not_reschedulable',
		// Lifecycle - ConfirmBooking, CancelBooking, HoldBooking, HoldsController::release().
		'window_closed',
		'online_payment_required',
		'hold_expired',
		'not_confirmable',
		'approval_required',
		'not_cancellable',
		'stale_state',
		'not_held',
		'currency_mismatch',
		// Infrastructure contention - `LockManager::acquire()` and `ResourceDayRepository::bumpRev()`.
		// Deliberately NOT `stale_state`: that one means "a rival moved this booking between the plan
		// and the transaction", which is a benign no-op for a caller that only wanted the booking to
		// end up decided. A lock that could not be taken is the opposite - nothing happened at all and
		// the request is worth repeating verbatim. Folding the two together made
		// `Admin\ApprovalActionEndpoint` render a busy lock as "may already have been handled".
		'lock_unavailable',
		// Lifecycle - ApproveBooking, RejectBooking.
		'not_approvable',
		// Lifecycle - MarkBookingOutcome.
		'bad_outcome',
		// Lifecycle - the admin catalog's delete guard (Task 11), thrown when a service or resource is
		// still named by a booking item and deletion is refused in favour of deactivation.
		'referenced',
	);

	/**
	 * Every reason maps to 409 except `not_found`, which is a 404: the request named a service,
	 * occurrence or seat that does not exist, which is not contention.
	 */
	public static function conflict( SlotConflict $exception ): \WP_Error {
		$missing = 'not_found' === $exception->reason;
		return new \WP_Error(
			$missing ? 'reservant_not_found' : 'reservant_conflict',
			$exception->reason,
			array(
				'status'  => $missing ? 404 : 409,
				'segment' => $exception->segmentIndex,
				'detail'  => self::detail( $exception->reason ),
			)
		);
	}

	/**
	 * Lifecycle refusals from the use cases, which signal with the exception message.
	 *
	 * `window_closed` is 403 (the policy forbids it, not the state), `online_payment_required` is
	 * 402, an elapsed hold is 410 Gone, and everything else - `not_confirmable`, `approval_required`,
	 * `not_cancellable`, `stale_state` - is a 409 state conflict. `lock_unavailable` is a 409 too,
	 * though it is contention rather than state: the request was never decided, and repeating it
	 * verbatim is the correct response, which is exactly what 409 already asks a client to do.
	 *
	 * **The message is only echoed when it is a known reason.** `RuntimeException` is also how the
	 * repositories report a failed write (`booking_insert_failed: <$wpdb->last_error>`), so passing
	 * an arbitrary message through would hand an anonymous caller the database's own error text -
	 * table, column and index names - on a deadlock, a lock-wait timeout or a missing table. Only
	 * the allow-list below reaches the wire; anything else is an opaque 500, announced on
	 * `reservant/error` so a site can log it without the plugin choosing a sink.
	 */
	public static function failure( \RuntimeException $exception ): \WP_Error {
		$reason = $exception->getMessage();
		if ( ! in_array( $reason, self::KNOWN_REASONS, true ) ) {
			do_action( 'reservant/error', $exception );
			return new \WP_Error(
				'reservant_error',
				__( 'Something went wrong on our side. Please try again.', 'reservant' ),
				array(
					'status' => 500,
					'detail' => __( 'Something went wrong on our side. Please try again.', 'reservant' ),
				)
			);
		}
		$status = match ( $reason ) {
			'window_closed'           => 403,
			'online_payment_required' => 402,
			'hold_expired'            => 410,
			default                   => 409,
		};
		return new \WP_Error(
			'reservant_' . $reason,
			$reason,
			array(
				'status' => $status,
				'detail' => self::detail( $reason ),
			)
		);
	}

	public static function notFound(): \WP_Error {
		return new \WP_Error(
			'reservant_not_found',
			'not_found',
			array(
				'status' => 404,
				'detail' => self::detail( 'not_found' ),
			)
		);
	}

	public static function badRequest( string $detail ): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			'invalid_request',
			array(
				'status' => 400,
				'detail' => $detail,
			)
		);
	}

	/** A human sentence per machine reason - the widget may show it verbatim (AGENTS.md section 7). */
	private static function detail( string $reason ): string {
		return match ( $reason ) {
			'overlap'                 => __( 'That time was just taken. Please pick another.', 'reservant' ),
			'seat_taken'              => __( 'One of those seats was just claimed. Please pick another.', 'reservant' ),
			'capacity'                => __( 'There are not enough places left.', 'reservant' ),
			'no_staff'                => __( 'Nobody is available to perform that service.', 'reservant' ),
			'not_found'               => __( 'That booking is no longer available.', 'reservant' ),
			'bad_time'                => __( 'That start time is not on offer.', 'reservant' ),
			'lead_time'               => __( 'That time is too soon to book.', 'reservant' ),
			'horizon'                 => __( 'That date is too far ahead to book.', 'reservant' ),
			'outside_hours'           => __( 'That time is outside our working hours.', 'reservant' ),
			'bad_seat'                => __( 'Those seats are not selectable.', 'reservant' ),
			'not_reschedulable'       => __( 'This booking can no longer be moved.', 'reservant' ),
			'window_closed'           => __( 'It is too late to change this booking. Please contact us.', 'reservant' ),
			'online_payment_required' => __( 'This booking must be paid for online.', 'reservant' ),
			'hold_expired'            => __( 'Your reservation expired. Please start again.', 'reservant' ),
			'not_confirmable'         => __( 'This booking cannot be confirmed in its current state.', 'reservant' ),
			'approval_required'       => __( 'This booking is waiting for our approval.', 'reservant' ),
			'not_cancellable'         => __( 'This booking can no longer be cancelled.', 'reservant' ),
			'not_approvable'          => __( 'This booking can no longer be approved or rejected.', 'reservant' ),
			'bad_outcome'             => __( 'That outcome is not recognised.', 'reservant' ),
			'referenced'              => __( 'This item is still used by existing bookings. Deactivate it instead of deleting it.', 'reservant' ),
			'lock_unavailable'        => __( 'The system was busy. Please try again.', 'reservant' ),
			default                   => __( 'That request could not be completed.', 'reservant' ),
		};
	}
}
