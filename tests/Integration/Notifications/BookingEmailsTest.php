<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Notifications;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\ExpireHolds;
use Reservant\Application\HoldBooking;
use Reservant\Application\RescheduleBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `Notifications\BookingEmails`: the set a CUSTOMER receives.
 *
 * Before it existed, a guest who booked a service needing no approval received no email at all -
 * `reservant/booking/held` sent only the approver's request, and nothing listened on `confirmed`,
 * `cancelled` or `rescheduled`.
 *
 * `BookingEmails::register()` is never called here. Like `ApprovalEmailsTest`, these tests rely on
 * `Plugin::register()` having wired it at bootstrap, which makes every assertion below double as
 * the wiring assertion: unwire it and every one of them sees zero captured mail.
 *
 * Mail is captured through `pre_wp_mail`, which hands over the whole `$atts` array - including
 * `attachments`, read here while the temp files still exist, since `Mailer::send()` unlinks them in
 * a `finally` the moment `wp_mail()` returns.
 */
final class BookingEmailsTest extends ReservantTestCase {

	private int $serviceId;
	private int $approvalServiceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId         = $services->insert(
			array(
				'name'                => 'Cut',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'currency'            => 'EUR',
				'payment_mode'        => 'onsite',
				'cancel_window_hours' => 0,
			)
		);
		$this->approvalServiceId = $services->insert(
			array(
				'name'                => 'Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 5000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->staffId           = $resources->insert( array( 'name' => 'Alex', 'email' => 'alex@example.com' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		$resources->linkService( $this->approvalServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/**
	 * @return list<array{to: string, subject: string, message: string, attachments: array<string, string>}>
	 */
	private function captureMail( callable $trigger ): array {
		$captured = array();
		$listener = static function ( $preempt, array $atts ) use ( &$captured ) {
			$files = array();
			/** @var array<string, string> $given */
			$given = is_array( $atts['attachments'] ?? null ) ? $atts['attachments'] : array();
			foreach ( $given as $name => $path ) {
				// Read NOW: Mailer unlinks these as soon as wp_mail() returns.
				$files[ (string) $name ] = is_readable( (string) $path ) ? (string) file_get_contents( (string) $path ) : '';
			}
			$to         = $atts['to'] ?? '';
			$captured[] = array(
				'to'          => is_array( $to ) ? implode( ',', $to ) : (string) $to,
				'subject'     => (string) ( $atts['subject'] ?? '' ),
				'message'     => (string) ( $atts['message'] ?? '' ),
				'attachments' => $files,
			);
			return true; // Short-circuits wp_mail() - never touches a real transport.
		};
		add_filter( 'pre_wp_mail', $listener, 10, 2 );
		$trigger();
		remove_filter( 'pre_wp_mail', $listener, 10 );
		return $captured;
	}

	/**
	 * @param list<array{to: string, subject: string, message: string, attachments: array<string, string>}> $sent
	 * @return array{to: string, subject: string, message: string, attachments: array<string, string>}
	 */
	private function guestMail( array $sent ): array {
		$mine = array_values( array_filter( $sent, static fn ( array $m ): bool => 'maria@example.com' === $m['to'] ) );
		self::assertCount( 1, $mine, 'exactly one email to the guest' );
		return $mine[0];
	}

	/** @return array<string, mixed> */
	private function hold( int $serviceId ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $serviceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/**
	 * The rule that decides who gets acknowledged and who does not.
	 *
	 * A checkout hold is a guest still inside the widget, seconds from pressing confirm. Mailing
	 * here would mail every ABANDONED CHECKOUT, and would arrive before the confirmation it precedes.
	 */
	public function test_a_checkout_hold_sends_the_guest_nothing_at_all(): void {
		self::assertSame( array(), $this->captureMail( fn () => $this->hold( $this->serviceId ) ) );
	}

	public function test_an_approval_hold_acknowledges_the_guest_and_hands_them_their_manage_link(): void {
		$booking = null;
		$sent    = $this->captureMail(
			function () use ( &$booking ): void {
				$booking = $this->hold( $this->approvalServiceId );
			}
		);

		$mail = $this->guestMail( $sent );
		self::assertStringContainsString( 'Maria', $mail['message'] );
		self::assertStringContainsString( 'Consultation', $mail['message'] );
		// The plaintext credential exists only inside HoldBooking::execute(); this hook is the one
		// instant a listener can be handed it, and this guest's only chance to be sent it.
		self::assertStringContainsString( (string) $booking['manage_token'], $mail['message'] );
		self::assertStringContainsString( (string) $booking['uuid'], $mail['message'] );
		// Nothing is reserved yet, so there is nothing to put in a calendar.
		self::assertSame( array(), $mail['attachments'] );
	}

	public function test_confirming_sends_the_guest_the_confirmation_with_an_ics_and_their_link(): void {
		global $wpdb;
		$held = $this->hold( $this->serviceId );

		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ), (string) $held['manage_token'] );
			}
		);

		$mail = $this->guestMail( $sent );
		self::assertStringContainsString( 'Cut', $mail['message'] );
		self::assertStringContainsString( 'Alex', $mail['message'] );
		self::assertStringContainsString( '30,00 EUR', str_replace( '.', ',', $mail['message'] ), 'the total, at the right scale' );
		self::assertStringContainsString( (string) $held['manage_token'], $mail['message'], 'the guest presented this token, so echoing it into their own email leaks nothing new' );

		$ics = $mail['attachments']['booking.ics'] ?? '';
		self::assertStringContainsString( 'METHOD:REQUEST', $ics );
		self::assertStringContainsString( 'BEGIN:VEVENT', $ics );
		self::assertStringContainsString( 'reservant-' . $held['uuid'] . '-0@', $ics );
		self::assertStringNotContainsString( (string) $held['manage_token'], $ics, 'the credential belongs in the email, not in an entry that syncs to the guest\'s phone' );
	}

	/**
	 * The token forwarded to `ConfirmBooking` is not an authorisation argument, so it is not
	 * necessarily this booking's: `Routes::guard()` short-circuits on `reservant_manage_bookings`
	 * before it ever reads the `token` parameter. A wrong one must not become a manage link that
	 * 403s, mailed to the guest as if it were theirs.
	 */
	public function test_a_token_belonging_to_another_booking_never_reaches_the_email(): void {
		global $wpdb;
		$mine      = $this->hold( $this->serviceId );
		$somebodys = 'a-token-that-is-not-this-bookings';

		$sent = $this->captureMail(
			function () use ( $mine, $somebodys, $wpdb ): void {
				ConfirmBooking::make( $wpdb )->execute( (string) $mine['uuid'], $this->utc( 0, '00:05' ), $somebodys );
			}
		);

		$mail = $this->guestMail( $sent );
		self::assertStringNotContainsString( $somebodys, $mail['message'] );
		self::assertStringNotContainsString( 'cancel your booking here', $mail['message'], 'no link at all beats a link that does not work' );
	}

	public function test_cancelling_sends_a_cancellation_whose_ics_withdraws_the_entry(): void {
		global $wpdb;
		$held = $this->hold( $this->serviceId );
		ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );

		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				CancelBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:10' ) );
			}
		);

		$mail = $this->guestMail( $sent );
		$ics  = $mail['attachments']['booking.ics'] ?? '';
		self::assertStringContainsString( 'METHOD:CANCEL', $ics );
		self::assertStringContainsString( 'STATUS:CANCELLED', $ics );
		self::assertStringContainsString( 'reservant-' . $held['uuid'] . '-0@', $ics, 'a cancellation must name the very event it withdraws' );
	}

	/**
	 * The property the whole `.ics` exists for: the guest's calendar MOVES the appointment instead
	 * of ending up with two of them.
	 *
	 * Both halves of the pair are captured, because "same UID" is only meaningful against the
	 * message it supersedes. The sequence is asserted as non-decreasing rather than strictly
	 * higher: it is the booking's age in seconds (`Calendar`'s docblock says why, and why
	 * `updated_at` cannot be used - `RescheduleBooking` never touches the container row), and a test
	 * confirms and reschedules inside the same second. `CalendarTest` pins the rise itself against a
	 * clock it controls; what belongs here is that the two real messages agree about the identity of
	 * the event.
	 */
	public function test_rescheduling_reuses_the_uid_of_the_confirmation_it_supersedes(): void {
		global $wpdb;
		$held = $this->hold( $this->serviceId );

		$confirmed = $this->guestMail(
			$this->captureMail(
				function () use ( $held, $wpdb ): void {
					ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );
				}
			)
		);
		$moved = $this->guestMail(
			$this->captureMail(
				function () use ( $held, $wpdb ): void {
					RescheduleBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 1, '11:00' ), null, $this->utc( 0, '00:10' ) );
				}
			)
		);

		$before = $confirmed['attachments']['booking.ics'] ?? '';
		$after  = $moved['attachments']['booking.ics'] ?? '';

		self::assertStringContainsString( 'METHOD:REQUEST', $after );
		self::assertSame( $this->linesStartingWith( $before, 'UID:' ), $this->linesStartingWith( $after, 'UID:' ) );
		self::assertNotSame( $this->linesStartingWith( $before, 'DTSTART:' ), $this->linesStartingWith( $after, 'DTSTART:' ) );
		self::assertSame(
			array( 'DTSTART:' . gmdate( 'Ymd\THis\Z', $this->utc( 1, '11:00' )->getTimestamp() ) ),
			$this->linesStartingWith( $after, 'DTSTART:' )
		);

		$sequences = array_map( static fn ( string $l ): int => (int) substr( $l, 9 ), $this->linesStartingWith( $after, 'SEQUENCE:' ) );
		self::assertNotSame( array(), $sequences );
		foreach ( $sequences as $index => $sequence ) {
			self::assertGreaterThanOrEqual( 0, $sequence, 'RFC 5545 forbids a negative sequence' );
			self::assertGreaterThanOrEqual(
				(int) substr( $this->linesStartingWith( $before, 'SEQUENCE:' )[ $index ], 9 ),
				$sequence,
				'a client that saw a LOWER sequence would discard the update and keep the old time'
			);
		}
	}

	/** @return list<string> */
	private function linesStartingWith( string $ics, string $prefix ): array {
		return array_values(
			array_filter(
				explode( "\r\n", trim( $ics ) ),
				static fn ( string $line ): bool => str_starts_with( $line, $prefix )
			)
		);
	}

	/**
	 * An admin-created manual booking lands `confirmed` with no hold in between, so
	 * `HoldBooking::execute()` fires `held` and then `confirmed` from the same call. The
	 * acknowledgement would be wrong ("waiting to be approved" - it is not), and the confirmation is
	 * this guest's first and only email, so it is the one that carries the link.
	 */
	public function test_an_admin_created_booking_sends_only_the_confirmation_and_it_carries_the_link(): void {
		global $wpdb;
		$booking = null;
		$sent    = $this->captureMail(
			function () use ( &$booking, $wpdb ): void {
				$booking = HoldBooking::make( $wpdb )->execute(
					new HoldRequest(
						new Customer( 'Maria', 'maria@example.com' ),
						new AppointmentRequest( $this->utc( 1, '13:00' ), array( new SegmentChoice( $this->serviceId, $this->staffId ) ) ),
						null,
						true
					),
					$this->utc( 0 )
				);
			}
		);

		$mail = $this->guestMail( $sent );
		self::assertStringContainsString( 'confirmed', strtolower( $mail['subject'] ) );
		self::assertStringContainsString( (string) $booking['manage_token'], $mail['message'] );
		self::assertArrayHasKey( 'booking.ics', $mail['attachments'] );
	}

	/** An approval request that timed out is the guest's answer; without it they wait forever. */
	public function test_an_expired_approval_hold_tells_the_guest_it_expired(): void {
		global $wpdb;
		$held = $this->hold( $this->approvalServiceId );

		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}reservant_bookings SET hold_expires_at = %s WHERE uuid = %s",
						gmdate( 'Y-m-d H:i:s', time() - 60 ),
						(string) $held['uuid']
					)
				);
				ExpireHolds::make( $wpdb )->expireByUuid( (string) $held['uuid'] );
			}
		);

		$mail = $this->guestMail( $sent );
		self::assertStringContainsString( 'expired', strtolower( $mail['subject'] ) );
		self::assertSame( array(), $mail['attachments'] );
	}

	/** The other half of that rule: an abandoned checkout expiring is not news for anyone. */
	public function test_an_expired_checkout_hold_tells_the_guest_nothing(): void {
		global $wpdb;
		$held = $this->hold( $this->serviceId );

		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}reservant_bookings SET hold_expires_at = %s WHERE uuid = %s",
						gmdate( 'Y-m-d H:i:s', time() - 60 ),
						(string) $held['uuid']
					)
				);
				ExpireHolds::make( $wpdb )->expireByUuid( (string) $held['uuid'] );
			}
		);

		self::assertSame( array(), $sent );
	}

	/**
	 * The filter predates attachments, so every filter already written against it returns exactly
	 * `to`/`subject`/`body`. Under a guard that required all four keys, a site rewriting only the
	 * subject would silently strip the guest's .ics off every email.
	 */
	public function test_a_filter_that_returns_only_the_original_three_keys_keeps_the_attachment(): void {
		global $wpdb;
		$held    = $this->hold( $this->serviceId );
		$rewrite = static function ( array $args ): array {
			return array(
				'to'      => $args['to'],
				'subject' => 'Rewritten',
				'body'    => $args['body'],
			);
		};

		add_filter( 'reservant/email/booking_confirmed/args', $rewrite );
		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );
			}
		);
		remove_filter( 'reservant/email/booking_confirmed/args', $rewrite );

		$mail = $this->guestMail( $sent );
		self::assertSame( 'Rewritten', $mail['subject'] );
		self::assertArrayHasKey( 'booking.ics', $mail['attachments'] );
	}

	/** And a site that does not want the calendar file has an explicit way to say so. */
	public function test_a_filter_can_drop_the_attachment_by_naming_it(): void {
		global $wpdb;
		$held    = $this->hold( $this->serviceId );
		$rewrite = static function ( array $args ): array {
			$args['attachments'] = array();
			return $args;
		};

		add_filter( 'reservant/email/booking_confirmed/args', $rewrite );
		$sent = $this->captureMail(
			function () use ( $held, $wpdb ): void {
				ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );
			}
		);
		remove_filter( 'reservant/email/booking_confirmed/args', $rewrite );

		self::assertSame( array(), $this->guestMail( $sent )['attachments'] );
	}

	/** Booking details on disk are exactly as sensitive as booking details in an email. */
	public function test_the_temp_file_behind_an_attachment_does_not_outlive_the_send(): void {
		global $wpdb;
		$held  = $this->hold( $this->serviceId );
		$paths = array();

		$spy = static function ( $preempt, array $atts ) use ( &$paths ) {
			/** @var array<string, string> $given */
			$given = is_array( $atts['attachments'] ?? null ) ? $atts['attachments'] : array();
			foreach ( $given as $path ) {
				$paths[] = (string) $path;
			}
			return true;
		};
		add_filter( 'pre_wp_mail', $spy, 10, 2 );
		ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );
		remove_filter( 'pre_wp_mail', $spy, 10 );

		self::assertCount( 1, $paths );
		self::assertFileDoesNotExist( $paths[0] );
	}
}
