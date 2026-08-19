<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Reservant\Notifications\Calendar;

/**
 * The `.ics` a booking email carries.
 *
 * Everything here is about one question: does the guest's calendar UPDATE when the booking moves,
 * or does it end up holding two copies of the same appointment? That is decided by `UID` and
 * `SEQUENCE`, neither of which any integration test can contradict cheaply - a reschedule needs a
 * database, a service, a staff member and working hours to reach, and even then the assertion would
 * be about a string. `Calendar` takes the organizer as arguments rather than calling
 * `get_bloginfo()`, so the whole format is exercised here with no WordPress at all.
 */
final class CalendarTest extends TestCase {

	private const ORGANIZER_NAME  = 'Acme Salon';
	private const ORGANIZER_EMAIL = 'hello@acme.example';

	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-08-18 12:00:00', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A two-segment chain as `findDetailByUuid()` returns it: items joined to their names, with the
	 * buffer-widened block range sitting beside the customer-facing span.
	 *
	 * @return array<string, mixed>
	 */
	private function booking(): array {
		return array(
			'uuid'           => 'aaaabbbb-cccc-dddd-eeee-ffff00001111',
			'created_at'     => '2026-08-18 11:00:00',
			'customer_name'  => 'Maria',
			'customer_email' => 'maria@example.com',
			'items'          => array(
				array(
					'id'              => 41,
					'sort'            => 0,
					'service_name'    => 'Cut',
					'resource_name'   => 'Alex',
					'start_utc'       => '2026-08-20 09:00:00',
					'end_utc'         => '2026-08-20 09:30:00',
					'block_start_utc' => '2026-08-20 08:50:00',
					'block_end_utc'   => '2026-08-20 09:40:00',
				),
				array(
					'id'              => 42,
					'sort'            => 1,
					'service_name'    => 'Colour',
					'resource_name'   => 'Bella',
					'start_utc'       => '2026-08-20 09:30:00',
					'end_utc'         => '2026-08-20 10:15:00',
					'block_start_utc' => '2026-08-20 09:30:00',
					'block_end_utc'   => '2026-08-20 10:25:00',
				),
			),
		);
	}

	private function build( array $booking, string $method = Calendar::REQUEST, ?\DateTimeImmutable $now = null ): string {
		return Calendar::forBooking( $booking, $method, $now ?? $this->now(), self::ORGANIZER_NAME, self::ORGANIZER_EMAIL );
	}

	/** @return list<string> unfolded content lines */
	private function lines( string $ics ): array {
		// Undo the 75-octet folding first: a property that happens to be long is still one line,
		// and a test that greps the raw string would pass or fail on the name's length.
		return explode( "\r\n", str_replace( "\r\n ", '', trim( $ics ) ) );
	}

	private function valuesOf( string $ics, string $property ): array {
		$found = array();
		foreach ( $this->lines( $ics ) as $line ) {
			if ( str_starts_with( $line, $property . ':' ) ) {
				$found[] = substr( $line, strlen( $property ) + 1 );
			}
		}
		return $found;
	}

	public function test_a_chain_produces_one_vevent_per_segment_inside_one_vcalendar(): void {
		$lines = $this->lines( $this->build( $this->booking() ) );

		self::assertSame( 'BEGIN:VCALENDAR', $lines[0] );
		self::assertSame( 'END:VCALENDAR', $lines[ count( $lines ) - 1 ] );
		self::assertSame( 2, count( array_filter( $lines, static fn ( string $l ): bool => 'BEGIN:VEVENT' === $l ) ) );
		self::assertSame( 2, count( array_filter( $lines, static fn ( string $l ): bool => 'END:VEVENT' === $l ) ) );
	}

	public function test_every_line_ends_crlf_as_the_format_requires(): void {
		$ics = $this->build( $this->booking() );

		self::assertStringEndsWith( "\r\n", $ics );
		self::assertSame( 0, preg_match( '/(?<!\r)\n/', $ics ), 'a bare LF is not a content-line break' );
	}

	/**
	 * The property the whole class exists for.
	 *
	 * A reschedule deletes every `booking_items` row and inserts fresh ones, so the item ids change
	 * and the times change - and if either reached the UID, the guest would be left holding the old
	 * appointment forever beside the new one.
	 */
	public function test_the_uid_survives_a_reschedule_that_changes_every_item_id_and_time(): void {
		$before = $this->build( $this->booking() );

		$after                          = $this->booking();
		$after['items'][0]['id']        = 907; // Reinserted rows: new ids.
		$after['items'][1]['id']        = 908;
		$after['items'][0]['start_utc'] = '2026-08-21 14:00:00';
		$after['items'][0]['end_utc']   = '2026-08-21 14:30:00';
		$after['items'][1]['start_utc'] = '2026-08-21 14:30:00';
		$after['items'][1]['end_utc']   = '2026-08-21 15:15:00';

		self::assertSame( $this->valuesOf( $before, 'UID' ), $this->valuesOf( $this->build( $after ), 'UID' ) );
	}

	public function test_each_segment_of_a_chain_gets_its_own_uid(): void {
		$uids = $this->valuesOf( $this->build( $this->booking() ), 'UID' );

		self::assertCount( 2, $uids );
		self::assertNotSame( $uids[0], $uids[1] );
		foreach ( $uids as $uid ) {
			self::assertStringContainsString( 'aaaabbbb-cccc-dddd-eeee-ffff00001111', $uid );
			self::assertStringEndsWith( '@acme.example', $uid, 'the organizer address supplies a namespace that really exists' );
		}
	}

	public function test_a_cancellation_reuses_the_uid_it_cancels(): void {
		self::assertSame(
			$this->valuesOf( $this->build( $this->booking() ), 'UID' ),
			$this->valuesOf( $this->build( $this->booking(), Calendar::CANCEL ), 'UID' )
		);
	}

	public function test_the_method_and_status_agree_on_whether_the_booking_stands(): void {
		$request = $this->build( $this->booking() );
		self::assertContains( 'METHOD:REQUEST', $this->lines( $request ) );
		self::assertSame( array( 'CONFIRMED', 'CONFIRMED' ), $this->valuesOf( $request, 'STATUS' ) );

		$cancel = $this->build( $this->booking(), Calendar::CANCEL );
		self::assertContains( 'METHOD:CANCEL', $this->lines( $cancel ) );
		self::assertSame( array( 'CANCELLED', 'CANCELLED' ), $this->valuesOf( $cancel, 'STATUS' ) );
	}

	/** Without a rising SEQUENCE a compliant client discards the update and keeps the old time. */
	public function test_a_later_message_about_the_same_booking_carries_a_higher_sequence(): void {
		$first  = $this->valuesOf( $this->build( $this->booking() ), 'SEQUENCE' );
		$second = $this->valuesOf(
			$this->build( $this->booking(), Calendar::REQUEST, $this->now()->modify( '+2 hours' ) ),
			'SEQUENCE'
		);

		self::assertSame( array( '3600', '3600' ), $first ); // One hour old at the first message.
		self::assertSame( array( '10800', '10800' ), $second );
	}

	public function test_a_created_at_in_the_future_clamps_to_zero_rather_than_going_negative(): void {
		// PHP and MySQL disagreeing by a second is enough to reach this, and RFC 5545 forbids a
		// negative sequence outright.
		$skewed               = $this->booking();
		$skewed['created_at'] = '2026-08-18 12:00:05';

		self::assertSame( array( '0', '0' ), $this->valuesOf( $this->build( $skewed ), 'SEQUENCE' ) );
	}

	/**
	 * The guest's 30-minute haircut is 30 minutes in their calendar.
	 *
	 * `block_start_utc`/`block_end_utc` are 10 minutes wider here - the shop's buffers, which are a
	 * contention model and none of the guest's business.
	 */
	public function test_the_event_spans_the_customer_facing_times_not_the_buffered_block(): void {
		$ics = $this->build( $this->booking() );

		self::assertSame( array( '20260820T090000Z', '20260820T093000Z' ), $this->valuesOf( $ics, 'DTSTART' ) );
		self::assertSame( array( '20260820T093000Z', '20260820T101500Z' ), $this->valuesOf( $ics, 'DTEND' ) );
		self::assertStringNotContainsString( '20260820T085000Z', $ics );
		self::assertStringNotContainsString( '20260820T104000Z', $ics );
	}

	public function test_the_summary_names_the_service_and_the_staff_member(): void {
		self::assertSame( array( 'Cut - Alex', 'Colour - Bella' ), $this->valuesOf( $this->build( $this->booking() ), 'SUMMARY' ) );
	}

	public function test_an_event_booking_with_no_staff_is_titled_by_its_service_alone(): void {
		$booking                           = $this->booking();
		$booking['items']                  = array( $booking['items'][0] );
		$booking['items'][0]['resource_name'] = null; // An occurrence books a seat, not a person.

		self::assertSame( array( 'Cut' ), $this->valuesOf( $this->build( $booking ), 'SUMMARY' ) );
	}

	public function test_a_service_that_the_join_did_not_find_falls_back_to_the_site_name(): void {
		$booking                              = $this->booking();
		$booking['items']                     = array( $booking['items'][0] );
		$booking['items'][0]['service_name']  = null;
		$booking['items'][0]['resource_name'] = null;

		self::assertSame( array( 'Acme Salon' ), $this->valuesOf( $this->build( $booking ), 'SUMMARY' ) );
	}

	public function test_the_four_text_escapes_are_applied_to_a_service_name_that_needs_them(): void {
		$booking                              = $this->booking();
		$booking['items']                     = array( $booking['items'][0] );
		$booking['items'][0]['service_name']  = "Cut, wash; back\\front\nsame day";
		$booking['items'][0]['resource_name'] = null;

		self::assertSame( array( 'Cut\\, wash\; back\\\\front\\nsame day' ), $this->valuesOf( $this->build( $booking ), 'SUMMARY' ) );
	}

	/** The credential belongs in the email, not in an entry that syncs to the guest's phone. */
	public function test_the_manage_token_never_reaches_the_calendar_entry(): void {
		$booking                 = $this->booking();
		$booking['manage_token'] = 'the-secret';

		self::assertStringNotContainsString( 'the-secret', $this->build( $booking ) );
	}

	public function test_the_attendee_is_the_guest_and_is_never_asked_to_rsvp(): void {
		$ics = $this->build( $this->booking() );

		foreach ( $this->lines( $ics ) as $line ) {
			if ( str_starts_with( $line, 'ATTENDEE' ) ) {
				self::assertStringContainsString( 'mailto:maria@example.com', $line );
				self::assertStringContainsString( 'RSVP=FALSE', $line );
				// Nothing in this plugin reads calendar replies, so an RSVP button would be a lie.
				self::assertStringContainsString( 'PARTSTAT=ACCEPTED', $line );
				return;
			}
		}
		self::fail( 'the guest should appear as the attendee' );
	}

	public function test_a_booking_with_no_customer_email_emits_no_attendee(): void {
		$booking                   = $this->booking();
		$booking['customer_email'] = '';

		self::assertStringNotContainsString( 'ATTENDEE', $this->build( $booking ) );
	}

	public function test_a_long_line_is_folded_at_seventy_five_octets(): void {
		$booking                              = $this->booking();
		$booking['items']                     = array( $booking['items'][0] );
		$booking['items'][0]['service_name']  = str_repeat( 'A', 200 );
		$booking['items'][0]['resource_name'] = null;

		$ics = $this->build( $booking );
		foreach ( explode( "\r\n", trim( $ics ) ) as $line ) {
			self::assertLessThanOrEqual( 75, strlen( $line ), 'RFC 5545 section 3.1 caps a content line at 75 octets' );
		}
		// Unfolding puts it back together byte for byte.
		self::assertSame( array( str_repeat( 'A', 200 ) ), $this->valuesOf( $ics, 'SUMMARY' ) );
	}

	/**
	 * A Greek service name is the ordinary case for this codebase's own author, and every one of its
	 * characters is two octets - so a fold that counted characters would overrun the limit, and one
	 * that counted octets with `substr()` would cut a character in half and produce mojibake.
	 */
	public function test_folding_never_splits_a_multibyte_character(): void {
		$booking                              = $this->booking();
		$booking['items']                     = array( $booking['items'][0] );
		$booking['items'][0]['service_name']  = str_repeat( 'Koureio ', 4 ) . str_repeat( "\u{03ba}\u{03bf}\u{03c5}\u{03c1}\u{03b5}\u{03af}\u{03bf}", 12 );
		$booking['items'][0]['resource_name'] = null;

		$ics = $this->build( $booking );
		foreach ( explode( "\r\n", trim( $ics ) ) as $line ) {
			self::assertLessThanOrEqual( 75, strlen( $line ) );
			// PCRE's /u flag fails to match against invalid UTF-8, which is exactly what half a
			// character is. Checked with PCRE rather than mbstring for the same reason Calendar
			// folds with it: the extension is not guaranteed to be installed.
			self::assertSame( 1, preg_match( '//u', $line ), 'a fold that cut a character in half would leave invalid UTF-8' );
		}
		self::assertSame( array( $booking['items'][0]['service_name'] ), $this->valuesOf( $ics, 'SUMMARY' ) );
	}

	public function test_a_booking_with_no_items_still_produces_a_well_formed_empty_calendar(): void {
		$booking          = $this->booking();
		$booking['items'] = array();

		$lines = $this->lines( $this->build( $booking ) );
		self::assertSame( 'BEGIN:VCALENDAR', $lines[0] );
		self::assertSame( 'END:VCALENDAR', $lines[ count( $lines ) - 1 ] );
		self::assertNotContains( 'BEGIN:VEVENT', $lines );
	}
}
