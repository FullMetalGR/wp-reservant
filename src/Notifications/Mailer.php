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
	 * @param string                $key         One of the email keys this plugin sends:
	 *                                           `approval_request`, `approval_nag`,
	 *                                           `booking_approved`, `booking_rejected`,
	 *                                           `booking_received`, `booking_confirmed`,
	 *                                           `booking_cancelled`, `booking_rescheduled`,
	 *                                           `booking_reminder`.
	 * @param array<string, mixed>  $context     Extra data threaded through as the filter's second
	 *                                           argument, alongside the `to`/`subject`/`body`
	 *                                           array being filtered - e.g. the booking snapshot,
	 *                                           so a site can base a rewrite on more than the
	 *                                           three fields `wp_mail()` takes.
	 * @param array<string, string> $attachments Display filename => file CONTENTS, not paths. The
	 *                                           caller (`Notifications\Calendar`) produces a
	 *                                           string; materializing it is this class's problem,
	 *                                           and so is unlinking it afterwards.
	 */
	public static function send( string $key, string $to, string $subject, string $body, array $context = array(), array $attachments = array() ): bool {
		// The owner's switch is honoured HERE rather than in each listener, so it covers every
		// message including any a future phase adds, and so a switched-off key never reaches
		// `reservant/email/{$key}/args` - there is nothing to filter about a message not being sent.
		// Not an error and not reported: this is the configured answer, not a failure.
		if ( in_array( $key, \Reservant\Settings::make()->emailsOff(), true ) ) {
			return false;
		}

		$files = array();
		try {
			$filtered = apply_filters(
				"reservant/email/{$key}/args",
				array(
					'to'          => $to,
					'subject'     => $subject,
					'body'        => $body,
					'attachments' => $attachments,
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

			// Attachments are guarded SEPARATELY from the three required keys, and absence means
			// "unchanged" rather than "none". This filter predates attachments, so every filter
			// already written against it returns exactly `to`/`subject`/`body` - and under an
			// `isset()` guard covering all four, a site that rewrites only the subject would
			// silently strip the guest's .ics off every email. Only an explicit `attachments` key
			// replaces them; that is also how a site removes them.
			$wanted = array_key_exists( 'attachments', $args ) && is_array( $args['attachments'] )
				? $args['attachments']
				: $attachments;
			/** @var array<string, string> $wanted */
			$files = self::materialize( $wanted, $key );

			$sent = wp_mail( (string) $args['to'], (string) $args['subject'], (string) $args['body'], '', $files );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e, $key );
			return false;
		} finally {
			self::discard( $files );
		}

		if ( ! $sent ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			do_action( 'reservant/error', new \RuntimeException( "mail_send_failed:{$key}" ), $key );
		}
		return $sent;
	}

	/**
	 * Writes each attachment to a private temp file and returns the form `wp_mail()` wants:
	 * `array( 'booking.ics' => '/tmp/reservant-<uuid>.ics' )`. Core reads the KEY as the display
	 * filename (`wp_mail()`'s own `addAttachment( $attachment, $filename )` loop), so the random
	 * on-disk name never reaches the recipient, and PHPMailer derives the `text/calendar` content
	 * type from the `.ics` extension it carries.
	 *
	 * Why a real file rather than PHPMailer's `addStringAttachment()` on `phpmailer_init`: an
	 * API-based mail plugin (Mailgun, SendGrid, Postmark) short-circuits `pre_wp_mail` and never
	 * constructs a PHPMailer at all, so a string attachment added on that hook would vanish
	 * silently on exactly the sites most likely to deliver the mail. `$attachments` is part of the
	 * `$atts` array those plugins receive.
	 *
	 * A write that fails costs the email its .ics and nothing else - it is reported and skipped,
	 * because an invitation the guest cannot add to their calendar still beats no email at all.
	 *
	 * @param array<string, string> $attachments filename => contents
	 * @return array<string, string> filename => temp path
	 */
	private static function materialize( array $attachments, string $key ): array {
		$files = array();
		foreach ( $attachments as $filename => $contents ) {
			$extension = pathinfo( (string) $filename, PATHINFO_EXTENSION );
			$path      = get_temp_dir() . 'reservant-' . wp_generate_uuid4() . ( '' === $extension ? '' : '.' . $extension );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A private temp file for one wp_mail() call, unlinked in the same request; WP_Filesystem is for site assets and can demand FTP credentials on the front end.
			$written = file_put_contents( $path, (string) $contents );
			if ( false === $written ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				do_action( 'reservant/error', new \RuntimeException( "attachment_write_failed:{$key}" ), $key );
				continue;
			}
			$files[ (string) $filename ] = $path;
		}
		return $files;
	}

	/**
	 * Unlinked in a `finally`, so a throwing filter or a fatal inside `wp_mail()` cannot leave the
	 * temp directory accumulating booking details - which is what these files contain.
	 *
	 * @param array<string, string> $files filename => temp path
	 */
	private static function discard( array $files ): void {
		foreach ( $files as $path ) {
			wp_delete_file( $path );
		}
	}
}
