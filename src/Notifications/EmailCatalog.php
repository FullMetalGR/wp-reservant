<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

/**
 * Every message this plugin can send, and what to call each one in front of the owner.
 *
 * Stated once because four unrelated things need the same list and must not drift: `Mailer::send()`
 * (which refuses a key the owner switched off), `Settings::validate()` (which refuses to store a
 * switch for a message that does not exist), the admin Settings screen (one checkbox each), and the
 * tests that walk it. A new email is added here or it is not switchable.
 *
 * The labels live here rather than in the React screen for the same reason: a hard-coded list in
 * TypeScript could not be compared against this one by any test, so the screen is handed
 * `choices()` from the server and renders whatever it is given. Adding an email in a later phase
 * makes its checkbox appear with no client-side change at all.
 */
final class EmailCatalog {

	/** In the order a booking meets them. */
	public const KEYS = array(
		'booking_received',
		'booking_confirmed',
		'booking_rescheduled',
		'booking_cancelled',
		'booking_expired',
		'booking_reminder',
		'approval_request',
		'approval_nag',
		'booking_approved',
		'booking_rejected',
	);

	/**
	 * Key plus a label naming WHO receives it, because that is the question an owner is actually
	 * answering when they reach for the switch - "stop emailing my customers" and "stop emailing
	 * me" are different intentions and four of these ten go to the approver, not the guest.
	 *
	 * @return list<array{key: string, label: string}>
	 */
	public static function choices(): array {
		$labels = array(
			'booking_received'    => __( 'Customer: we have your booking request', 'reservant' ),
			'booking_confirmed'   => __( 'Customer: your booking is confirmed', 'reservant' ),
			'booking_rescheduled' => __( 'Customer: your booking has moved', 'reservant' ),
			'booking_cancelled'   => __( 'Customer: your booking was cancelled', 'reservant' ),
			'booking_expired'     => __( 'Customer: your booking request expired', 'reservant' ),
			'booking_reminder'    => __( 'Customer: reminder before the appointment', 'reservant' ),
			'approval_request'    => __( 'Approver: a booking needs your decision', 'reservant' ),
			'approval_nag'        => __( 'Approver: reminder that a booking is still waiting', 'reservant' ),
			'booking_approved'    => __( 'Customer: your booking was approved', 'reservant' ),
			'booking_rejected'    => __( 'Customer: your booking was declined', 'reservant' ),
		);

		// No fallback for a key with no label, and that is the gate rather than an omission:
		// PHPStan proves this lookup total against `KEYS`, so a message added above without a
		// sentence here fails `composer stan` and names the key. The same trick
		// `Rest\ErrorsExhaustivenessTest` plays on refusal sentences, bought for free by the type.
		$choices = array();
		foreach ( self::KEYS as $key ) {
			$choices[] = array(
				'key'   => $key,
				'label' => $labels[ $key ],
			);
		}
		return $choices;
	}
}
