<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\HoldClass;
use Reservant\Domain\Money\Currency;
use Reservant\Frontend\ManageRoute;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * The customer's email set (AGENTS.md section 9 item 7), beside `ApprovalEmails` and bound by the
 * same contract: every hook here fires AFTER the transition it announces has committed, so nothing
 * in this class may throw out of a listener - doing so converts a committed booking into the
 * caller's failure report. `Mailer::send()` never throws (its own contract), and the one statement
 * that can fail on its own is the booking re-read in `deliver()`, which is guarded there.
 *
 * Before this class existed, **a guest who booked a service that needs no approval received no
 * email at all**: `reservant/booking/held` sent only the approver's `approval_request`, and nothing
 * listened on `confirmed`, `cancelled` or `rescheduled`.
 *
 * WHICH HOLDS GET AN ACKNOWLEDGEMENT, and why it is not "all of them". `reservant/booking/held`
 * fires for every hold, and the three hold classes are three different situations:
 *
 * - `checkout` (`pending`) - the guest is still inside the widget, seconds from pressing confirm.
 *   Mailing here would mail every ABANDONED CHECKOUT, which is most of them, and would arrive
 *   before the confirmation it precedes. Silence; `booking_confirmed` is their first email.
 * - `approval` (`awaiting_approval`) - the guest has submitted and is now waiting on a human who
 *   may take a day. `approval_request` goes to the APPROVER, not to them, so without
 *   `booking_received` they hear nothing at all until somebody decides. This is the case the
 *   acknowledgement exists for, and it is also the guest's one chance to be handed their manage
 *   link, since the plaintext credential exists only during `HoldBooking::execute()`.
 * - no hold at all (`confirmed`) - an admin-created manual booking. `HoldBooking::execute()` fires
 *   `booking/confirmed` immediately after `booking/held` for exactly this case, so the confirmation
 *   is the right and only email. It carries the manage link, because for that guest this is the
 *   first email too.
 *
 * The same reasoning runs the other way on expiry: an abandoned checkout hold expiring is not news,
 * but an approval request that timed out without a human answering it is the guest's answer, and
 * without `booking_expired` they would wait forever.
 */
final class BookingEmails {

	public static function register(): void {
		add_action( 'reservant/booking/held', array( self::class, 'onHeld' ) );
		add_action( 'reservant/booking/confirmed', array( self::class, 'onConfirmed' ) );
		add_action( 'reservant/booking/cancelled', array( self::class, 'onCancelled' ) );
		add_action( 'reservant/booking/rescheduled', array( self::class, 'onRescheduled' ) );
		add_action( 'reservant/hold/expired', array( self::class, 'onExpired' ) );
		add_action( 'reservant/booking/reminder', array( self::class, 'onReminder' ) );
	}

	/** `reservant/booking/held` - an acknowledgement only where silence would leave the guest waiting. */
	public static function onHeld( BookingSnapshot $snapshot ): void {
		if ( HoldClass::Approval->value !== $snapshot->holdClass ) {
			return; // See the class docblock: checkout holds and admin bookings are not this email.
		}
		self::deliver( 'booking_received', $snapshot, null );
	}

	/** `reservant/booking/confirmed` - the one a guest actually waits for, and the one that carries the .ics. */
	public static function onConfirmed( BookingSnapshot $snapshot ): void {
		self::deliver( 'booking_confirmed', $snapshot, Calendar::REQUEST );
	}

	/** `reservant/booking/cancelled` - `METHOD:CANCEL`, so the entry leaves their calendar too. */
	public static function onCancelled( BookingSnapshot $snapshot ): void {
		self::deliver( 'booking_cancelled', $snapshot, Calendar::CANCEL );
	}

	/** `reservant/booking/rescheduled` - same UID, higher SEQUENCE: the entry MOVES rather than duplicating. */
	public static function onRescheduled( BookingSnapshot $snapshot ): void {
		self::deliver( 'booking_rescheduled', $snapshot, Calendar::REQUEST );
	}

	/** `reservant/hold/expired` - only the approval hold, whose guest was told to expect an answer. */
	public static function onExpired( BookingSnapshot $snapshot ): void {
		if ( HoldClass::Approval->value !== $snapshot->holdClass ) {
			return;
		}
		self::deliver( 'booking_expired', $snapshot, null );
	}

	/**
	 * `reservant/booking/reminder` - fired by `Infrastructure\Scheduler\Jobs::reminder()`, which
	 * owns the re-read that decides whether a reminder is still warranted at all.
	 *
	 * No .ics: the guest received one with their confirmation, carrying this same UID, and a second
	 * copy at the same SEQUENCE tells their calendar nothing it does not already know.
	 */
	public static function onReminder( BookingSnapshot $snapshot ): void {
		self::deliver( 'booking_reminder', $snapshot, null );
	}

	/**
	 * Re-reads the booking through the JOINED query so the email can name the service and the staff
	 * member, then builds the message and hands it to the mailer.
	 *
	 * **The re-read is POST-COMMIT, and a DB fault on it is treated exactly like an absent row: skip
	 * the email.** `ApprovalEmails::sendApproverEmail()` states this split at length and the same
	 * reasoning binds here - a refusal travelling out of a listener would surface as the failure of a
	 * transition that already committed. Losing one email costs a notification; refusing costs the
	 * caller their booking.
	 *
	 * @param string|null $icsMethod `Calendar::REQUEST`/`CANCEL`, or null for a booking that is not
	 *                               yet something to put in a calendar.
	 */
	private static function deliver( string $key, BookingSnapshot $snapshot, ?string $icsMethod ): void {
		if ( '' === $snapshot->customerEmail ) {
			return; // An admin-created booking need not carry an address at all.
		}

		global $wpdb;
		try {
			$detail = ( new BookingRepository( $wpdb ) )->findDetailByUuid( $snapshot->uuid );
		} catch ( \RuntimeException $exception ) {
			do_action( 'reservant/error', $exception, $key );
			return;
		}
		if ( null === $detail ) {
			return; // Gone by the time the mailer runs - nothing left to notify about.
		}

		$attachments = array();
		if ( null !== $icsMethod ) {
			$attachments[ Calendar::FILENAME ] = Calendar::forBooking(
				$detail,
				$icsMethod,
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
				self::siteName(),
				(string) get_option( 'admin_email' )
			);
		}

		Mailer::send(
			$key,
			$snapshot->customerEmail,
			self::subject( $key ),
			self::body( $key, $snapshot, $detail ),
			array( 'booking' => $snapshot ),
			$attachments
		);
	}

	private static function subject( string $key ): string {
		switch ( $key ) {
			case 'booking_received':
				return __( 'We have your booking request', 'reservant' );
			case 'booking_cancelled':
				return __( 'Your booking has been cancelled', 'reservant' );
			case 'booking_rescheduled':
				return __( 'Your booking has moved to a new time', 'reservant' );
			case 'booking_expired':
				return __( 'Your booking request has expired', 'reservant' );
			case 'booking_reminder':
				return __( 'A reminder about your booking', 'reservant' );
			default:
				return __( 'Your booking is confirmed', 'reservant' );
		}
	}

	/**
	 * Opening sentence, the segments, the total, and the guest's link where there is one to give.
	 *
	 * @param array<string, mixed> $detail `BookingRepository::findDetailByUuid()` shape.
	 */
	private static function body( string $key, BookingSnapshot $snapshot, array $detail ): string {
		$lines = array( self::opening( $key, $snapshot->customerName ), '' );

		/** @var list<array<string, mixed>> $items */
		$items = is_array( $detail['items'] ?? null ) ? $detail['items'] : array();
		foreach ( $items as $item ) {
			$lines[] = self::itemLine( $item );
		}

		// Not on the two emails about a booking that is no longer happening: a price beside "nothing
		// has been reserved for you" reads as a bill.
		if ( ! in_array( $key, array( 'booking_cancelled', 'booking_expired' ), true ) && $snapshot->totalMinor > 0 ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: the amount, already formatted for the site's locale. 2: three-letter currency code, e.g. EUR. */
				__( 'Total: %1$s %2$s', 'reservant' ),
				number_format_i18n( Currency::toMajor( $snapshot->totalMinor, $snapshot->currency ), Currency::exponent( $snapshot->currency ) ),
				$snapshot->currency
			);
		}

		// Present on exactly the two emails that can carry it: the acknowledgement of an approval
		// hold, and the confirmation of a booking the guest confirmed themselves (or that an admin
		// created for them). Every other hook sees a snapshot with no token - see BookingSnapshot.
		if ( null !== $snapshot->manageToken && '' !== $snapshot->manageToken ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: the guest's private link for viewing, changing or cancelling their booking. */
				__( 'View, change or cancel your booking here: %s', 'reservant' ),
				ManageRoute::url( $snapshot->uuid, $snapshot->manageToken )
			);
		}

		return implode( "\n", $lines );
	}

	private static function opening( string $key, string $customerName ): string {
		switch ( $key ) {
			case 'booking_received':
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, we have your booking request. It is waiting to be approved, and we will email you as soon as there is an answer.', 'reservant' ),
					$customerName
				);
			case 'booking_cancelled':
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, your booking has been cancelled.', 'reservant' ),
					$customerName
				);
			case 'booking_rescheduled':
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, your booking has been moved. Here are the new details.', 'reservant' ),
					$customerName
				);
			case 'booking_expired':
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, your booking request expired before it could be approved, so nothing has been reserved for you. Please book again if you would still like to come.', 'reservant' ),
					$customerName
				);
			case 'booking_reminder':
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, this is a reminder about your booking. We are looking forward to seeing you.', 'reservant' ),
					$customerName
				);
			default:
				return sprintf(
					/* translators: %s: the customer's name. */
					__( 'Hi %s, your booking is confirmed. The details are below, and the attached calendar file will add it to your calendar.', 'reservant' ),
					$customerName
				);
		}
	}

	/**
	 * One segment, in the SITE's timezone - the database is UTC (AGENTS.md section 1) and a guest
	 * reading "14:00 UTC" would have to do arithmetic to find out when to turn up.
	 *
	 * Two whole sentences rather than one built from pieces: AGENTS.md section 7 forbids
	 * concatenating translated fragments, and a translator handed "%1$s with %2$s" separately from
	 * "%1$s on %2$s" cannot see which word order the pair needs.
	 *
	 * The times are the CUSTOMER-facing span, never the buffer-widened block range - the same rule
	 * `Calendar` follows, for the same reason.
	 *
	 * NOTE: `Admin\ApprovalActionEndpoint::summary()` builds the same site-local timestamp from the
	 * same two options inline. Two copies of a formatting rule is how the money formatter came to
	 * undercharge zero-decimal currencies by 100x; a third would want one home for it, which means a
	 * namespace both a Notifications class and an Admin one may depend on, and there is no such
	 * namespace today.
	 *
	 * @param array<string, mixed> $item a `findDetailByUuid()` item: joined to its service and staff names.
	 */
	private static function itemLine( array $item ): string {
		$service  = trim( (string) ( $item['service_name'] ?? '' ) );
		$resource = trim( (string) ( $item['resource_name'] ?? '' ) );
		$when     = self::siteLocal( (string) ( $item['start_utc'] ?? '' ) );

		if ( '' === $resource ) {
			return sprintf(
				/* translators: 1: service name. 2: date and time, in the site's timezone. */
				__( '%1$s on %2$s', 'reservant' ),
				$service,
				$when
			);
		}
		return sprintf(
			/* translators: 1: service name. 2: the staff member's name. 3: date and time, in the site's timezone. */
			__( '%1$s with %2$s on %3$s', 'reservant' ),
			$service,
			$resource,
			$when
		);
	}

	private static function siteLocal( string $sqlUtc ): string {
		if ( '' === $sqlUtc ) {
			return '';
		}
		$startUtc = new \DateTimeImmutable( $sqlUtc, new \DateTimeZone( 'UTC' ) );
		$format   = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
		return (string) wp_date( $format, $startUtc->getTimestamp(), wp_timezone() );
	}

	/** The site's name as a human wrote it - `get_bloginfo()` returns it HTML-encoded, and this is plain text. */
	private static function siteName(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
