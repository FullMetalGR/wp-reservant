<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

/**
 * The single seam every plugin-sent email passes through (AGENTS.md "Notifications": "Email + .ics
 * + reminders via Action Scheduler").
 *
 * Every message is filterable per key before it reaches `wp_mail()`
 * (`reservant/email/{$key}/args`, AGENTS.md section 7), and a delivery failure - `wp_mail()` itself
 * returning false, or the filter/mailer throwing - is reported on `reservant/error` and swallowed.
 * A broken mail transport must never fail the booking action that triggered the notification: the
 * approval, rejection or hold already committed by the time a listener here runs.
 */
final class Mailer {

	/**
	 * @param string               $key     One of the four email keys this plugin sends:
	 *                                      `approval_request`, `approval_nag`, `booking_approved`,
	 *                                      `booking_rejected`.
	 * @param array<string, mixed> $context Extra data threaded through as the filter's second
	 *                                      argument, alongside the `to`/`subject`/`body` array being
	 *                                      filtered - e.g. the booking snapshot, so a site can base a
	 *                                      rewrite on more than the three fields `wp_mail()` takes.
	 */
	public static function send( string $key, string $to, string $subject, string $body, array $context = array() ): bool {
		try {
			$filtered = apply_filters(
				"reservant/email/{$key}/args",
				array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				),
				$context
			);
			// A filter is arbitrary third-party code: guard the shape rather than trust it, so a
			// misbehaving filter degrades to the unfiltered message instead of a fatal type error.
			$args = is_array( $filtered ) && isset( $filtered['to'], $filtered['subject'], $filtered['body'] )
				? $filtered
				: array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				);

			$sent = wp_mail( (string) $args['to'], (string) $args['subject'], (string) $args['body'] );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e, $key );
			return false;
		}

		if ( ! $sent ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			do_action( 'reservant/error', new \RuntimeException( "mail_send_failed:{$key}" ), $key );
		}
		return $sent;
	}
}
