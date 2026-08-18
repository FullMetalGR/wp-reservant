<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

/**
 * The `.ics` a booking email carries (AGENTS.md "Notifications": "Email + `.ics` + reminders").
 *
 * One `VEVENT` per booking item, because a chain is several appointments the guest attends in
 * sequence, not one long one - the processing gap between "colour applied" and "rinse" is time they
 * are free, and a calendar that blocks it out is lying to them.
 *
 * Four properties decide whether a guest's calendar UPDATES or DUPLICATES when a booking moves, and
 * all four are this class's job rather than the caller's:
 *
 * - **`UID` is stable for the life of the booking.** It is derived from the container `uuid` plus
 *   the item's `sort`, and from nothing else. Not the start time (a reschedule would mint a second
 *   event and leave the old one sitting in the guest's calendar), and NOT the `booking_items.id`,
 *   which looks stable and is not: `RescheduleBooking` deletes every item row and inserts fresh
 *   ones, so the ids change on exactly the operation whose whole point is that the event is the
 *   same event. `sort` survives that rebuild.
 * - **`SEQUENCE` never goes backwards.** A compliant client ignores an update whose sequence is not
 *   higher than the copy it already holds, so this is what makes a reschedule land at all. It is
 *   the booking's age in seconds at the moment the message is built - monotonic by construction,
 *   needing neither a stored counter nor a query, and starting at ~0 on the first message. The
 *   obvious alternative, `bookings.updated_at`, is wrong here: `RescheduleBooking` moves the item
 *   rows and never touches the container, so the one transition that most needs a new sequence
 *   would not get one. Two messages built within the same second would share a sequence; that
 *   needs two significant changes to one booking inside one second, and the changed `DTSTART`
 *   still reaches most clients.
 * - **`METHOD`** is `REQUEST` for a booking that stands and `CANCEL` for one that does not.
 * - **`DTSTART`/`DTEND` are the CUSTOMER-facing span** (`start_utc`..`end_utc`), never the
 *   buffer-widened `block_*` range. Buffers are the shop's contention model (AGENTS.md section
 *   2.1); a guest whose 30-minute haircut appeared as 50 minutes would be right to complain. UTC
 *   with a `Z` suffix needs no `VTIMEZONE`, and the database is already UTC (AGENTS.md section 1).
 *
 * WordPress-free on purpose - it takes the organizer's name and address as arguments rather than
 * calling `get_bloginfo()`, the same convention `Application\SignedAction` follows for the salt, so
 * the whole format is exercised by the unit suite with no bootstrap. It also carries no translated
 * text: `SUMMARY` is built from the service and staff names the row already holds, so there is no
 * English in here to translate and no `__()` call to make it WordPress-dependent.
 *
 * **The manage link is deliberately absent.** The email carries the guest's credential because the
 * email is addressed to them; a calendar entry syncs to whatever devices and third-party services
 * the guest has connected, and a URL that IS a credential does not belong in any of them.
 */
final class Calendar {

	public const REQUEST = 'REQUEST';
	public const CANCEL  = 'CANCEL';

	/** The file name the guest sees on the attachment. */
	public const FILENAME = 'booking.ics';

	/** RFC 5545 section 3.1: content lines are folded at 75 octets, continuations start with a space. */
	private const MAX_OCTETS = 75;

	private const CRLF = "\r\n";

	/**
	 * @param array<string, mixed> $booking        `BookingRepository::findDetailByUuid()` shape - the
	 *                                             JOINED read, so items carry `service_name` and
	 *                                             `resource_name`.
	 * @param string               $method         self::REQUEST or self::CANCEL.
	 * @param string               $organizerName  The site's name, as the guest would recognise it.
	 * @param string               $organizerEmail The address the booking appears to come from; its
	 *                                             domain also becomes the UID's, so the identifier
	 *                                             is globally unique without inventing a namespace.
	 */
	public static function forBooking(
		array $booking,
		string $method,
		\DateTimeImmutable $nowUtc,
		string $organizerName,
		string $organizerEmail
	): string {
		$utc      = new \DateTimeZone( 'UTC' );
		$uuid     = (string) ( $booking['uuid'] ?? '' );
		$sequence = self::sequence( $booking, $nowUtc, $utc );
		$domain   = self::domain( $organizerEmail );
		$status   = self::CANCEL === $method ? 'CANCELLED' : 'CONFIRMED';

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Reservant//Booking//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
		);

		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		foreach ( $items as $item ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:reservant-' . $uuid . '-' . (int) ( $item['sort'] ?? 0 ) . '@' . $domain;
			$lines[] = 'DTSTAMP:' . $nowUtc->format( 'Ymd\THis\Z' );
			$lines[] = 'DTSTART:' . self::stamp( (string) ( $item['start_utc'] ?? '' ), $utc );
			$lines[] = 'DTEND:' . self::stamp( (string) ( $item['end_utc'] ?? '' ), $utc );
			$lines[] = 'SEQUENCE:' . $sequence;
			$lines[] = 'STATUS:' . $status;
			$lines[] = 'SUMMARY:' . self::escape( self::summary( $item, $organizerName ) );
			if ( '' !== $organizerEmail ) {
				$lines[] = 'ORGANIZER;CN=' . self::escape( $organizerName ) . ':mailto:' . $organizerEmail;
			}
			$attendee = (string) ( $booking['customer_email'] ?? '' );
			if ( '' !== $attendee ) {
				// RSVP=FALSE and PARTSTAT=ACCEPTED because nothing in this plugin reads calendar
				// replies: offering the guest an "accept / decline" they can press and have ignored
				// would be worse than not offering it. The booking IS the acceptance.
				$lines[] = 'ATTENDEE;CN=' . self::escape( (string) ( $booking['customer_name'] ?? '' ) ) . ';RSVP=FALSE;PARTSTAT=ACCEPTED:mailto:' . $attendee;
			}
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return implode( self::CRLF, array_map( array( self::class, 'fold' ), $lines ) ) . self::CRLF;
	}

	/**
	 * The booking's age in seconds - see the class docblock for why this and not `updated_at`.
	 *
	 * Clamped at zero: a `created_at` in the future (a clock skew between PHP and MySQL) would
	 * otherwise produce a negative sequence, which RFC 5545 forbids outright.
	 *
	 * @param array<string, mixed> $booking
	 */
	private static function sequence( array $booking, \DateTimeImmutable $nowUtc, \DateTimeZone $utc ): int {
		$createdAt = (string) ( $booking['created_at'] ?? '' );
		if ( '' === $createdAt ) {
			return 0;
		}
		return max( 0, $nowUtc->getTimestamp() - ( new \DateTimeImmutable( $createdAt, $utc ) )->getTimestamp() );
	}

	/**
	 * `service_name` and `resource_name` are proper nouns the site owner chose, so a summary built
	 * from them alone carries no English for a translator to reach - which is what keeps this class
	 * free of WordPress. The organizer's name stands in when the join found no service, rather than
	 * an event titled with a blank.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function summary( array $item, string $organizerName ): string {
		$service  = trim( (string) ( $item['service_name'] ?? '' ) );
		$resource = trim( (string) ( $item['resource_name'] ?? '' ) );
		if ( '' === $service ) {
			$service = $organizerName;
		}
		return '' === $resource ? $service : $service . ' - ' . $resource;
	}

	private static function stamp( string $sqlUtc, \DateTimeZone $utc ): string {
		return ( new \DateTimeImmutable( '' === $sqlUtc ? 'now' : $sqlUtc, $utc ) )->format( 'Ymd\THis\Z' );
	}

	/** The UID's namespace. Derived from the organizer address so it is a domain that really exists. */
	private static function domain( string $organizerEmail ): string {
		$at     = strrpos( $organizerEmail, '@' );
		$domain = false === $at ? '' : substr( $organizerEmail, $at + 1 );
		return '' === $domain ? 'reservant.invalid' : $domain;
	}

	/** RFC 5545 section 3.3.11: backslash, semicolon, comma and newline are the four TEXT escapes. */
	private static function escape( string $text ): string {
		return str_replace(
			array( '\\', ';', ',', "\r\n", "\n", "\r" ),
			array( '\\\\', '\;', '\\,', '\\n', '\\n', '\\n' ),
			$text
		);
	}

	/**
	 * Folds one content line to 75 octets, continuations prefixed with a single space.
	 *
	 * Split on CHARACTER boundaries while counting OCTETS: RFC 5545 section 3.1 forbids folding in
	 * the middle of a multi-octet UTF-8 sequence, and a service named in Greek would hit that on any
	 * naive `substr()`. `preg_split` with `/u` rather than `mb_str_split` because PCRE's UTF-8
	 * support is always there and the mbstring extension is not.
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= self::MAX_OCTETS ) {
			return $line;
		}
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return $line; // Invalid UTF-8: an over-long line beats a mangled one.
		}

		$folded  = array();
		$current = '';
		foreach ( $chars as $char ) {
			if ( '' !== $current && strlen( $current ) + strlen( $char ) > self::MAX_OCTETS ) {
				$folded[] = $current;
				$current  = ' '; // The continuation's own leading space counts toward its 75.
			}
			$current .= $char;
		}
		$folded[] = $current;
		return implode( self::CRLF, $folded );
	}
}
