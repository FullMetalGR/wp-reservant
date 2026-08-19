<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Notifications;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\RescheduleBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Scheduler\Jobs;
use Reservant\Settings;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `Notifications\Reminders`: the timer's whole life, and the re-read that is its actual authority.
 *
 * The timer is scheduled optimistically and cancelled on the way out, but a cancel can lose a race
 * with the queue runner - so the load-bearing test here is not that cancelling removes the action,
 * it is that FIRING a stale action sends nothing. A reminder about an appointment that is not
 * happening is a worse failure than no reminder at all.
 *
 * `utc( 0 )` is a week ahead of the wall clock (`ReservantTestCase`), so a day-1 booking sits eight
 * days out and every lead time below lands comfortably in the future without any clock fixture.
 */
final class RemindersTest extends ReservantTestCase {

	private int $serviceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId = $services->insert(
			array(
				'name'                => 'Cut',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'payment_mode'        => 'onsite',
				'cancel_window_hours' => 0,
			)
		);
		$staff           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/** @return array<string, mixed> */
	private function hold( string $start = '09:00', int $dayOffset = 1 ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( $dayOffset, $start ), array( new SegmentChoice( $this->serviceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/** @return array<string, mixed> */
	private function confirmed( string $start = '09:00' ): array {
		global $wpdb;
		$held = $this->hold( $start );
		ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );
		return $held;
	}

	/**
	 * The instants a reminder is still due for this booking.
	 *
	 * PENDING only, and that is not incidental: `as_unschedule_action()` marks an action `canceled`
	 * rather than deleting its row, so an unfiltered query answers "still scheduled" for every timer
	 * this class cancels.
	 *
	 * @return list<int>
	 */
	private function remindersFor( string $uuid ): array {
		/** @var array<int, \ActionScheduler_Action> $actions */
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Jobs::REMINDER,
				'group'    => 'reservant',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1000,
			),
			OBJECT
		);
		$due = array();
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( ( $args[0] ?? null ) !== $uuid ) {
				continue;
			}
			$date  = $action->get_schedule()->get_date();
			$due[] = null === $date ? 0 : $date->getTimestamp();
		}
		return $due;
	}

	/** @return list<array{to: string, subject: string, message: string, attachments: array<string, string>}> */
	private function captureMail( callable $trigger ): array {
		$captured = array();
		$listener = static function ( $preempt, array $atts ) use ( &$captured ) {
			$files = array();
			/** @var array<string, string> $given */
			$given = is_array( $atts['attachments'] ?? null ) ? $atts['attachments'] : array();
			foreach ( $given as $name => $path ) {
				$files[ (string) $name ] = is_readable( (string) $path ) ? (string) file_get_contents( (string) $path ) : '';
			}
			$to         = $atts['to'] ?? '';
			$captured[] = array(
				'to'          => is_array( $to ) ? implode( ',', $to ) : (string) $to,
				'subject'     => (string) ( $atts['subject'] ?? '' ),
				'message'     => (string) ( $atts['message'] ?? '' ),
				'attachments' => $files,
			);
			return true;
		};
		add_filter( 'pre_wp_mail', $listener, 10, 2 );
		$trigger();
		remove_filter( 'pre_wp_mail', $listener, 10 );
		return $captured;
	}

	public function test_confirming_schedules_one_reminder_a_lead_time_before_the_first_segment(): void {
		$booking = $this->confirmed();

		self::assertSame(
			array( $this->utc( 1, '09:00' )->getTimestamp() - 24 * HOUR_IN_SECONDS ),
			$this->remindersFor( (string) $booking['uuid'] )
		);
	}

	/** A hold is not an appointment yet, so it has nothing to be reminded about. */
	public function test_a_hold_that_is_never_confirmed_schedules_nothing(): void {
		self::assertSame( array(), $this->remindersFor( (string) $this->hold()['uuid'] ) );
	}

	public function test_cancelling_takes_the_reminder_with_it(): void {
		global $wpdb;
		$booking = $this->confirmed();
		self::assertCount( 1, $this->remindersFor( (string) $booking['uuid'] ) );

		CancelBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:10' ) );

		self::assertSame( array(), $this->remindersFor( (string) $booking['uuid'] ) );
	}

	/** Moving the appointment two hours later moves its reminder two hours later; there is no edit. */
	public function test_rescheduling_moves_the_reminder_rather_than_adding_a_second_one(): void {
		global $wpdb;
		$booking = $this->confirmed( '09:00' );

		RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 1, '11:00' ), null, $this->utc( 0, '00:10' ) );

		self::assertSame(
			array( $this->utc( 1, '11:00' )->getTimestamp() - 24 * HOUR_IN_SECONDS ),
			$this->remindersFor( (string) $booking['uuid'] )
		);
	}

	/**
	 * A lead time longer than the notice the guest gave.
	 *
	 * Scheduling it anyway would have Action Scheduler fire it on the next queue run, turning
	 * "24 hours before" into "immediately after booking" - a second confirmation email, worded as a
	 * reminder.
	 */
	public function test_a_booking_made_inside_the_lead_time_schedules_nothing(): void {
		Settings::make()->update( array( 'reminder_lead_hours' => 24 * 30 ) );

		self::assertSame( array(), $this->remindersFor( (string) $this->confirmed()['uuid'] ) );
	}

	/** Zero is how "send no reminders" is stored - the one place a zero is meaningful in Settings. */
	public function test_a_lead_time_of_zero_switches_reminders_off(): void {
		Settings::make()->update( array( 'reminder_lead_hours' => 0 ) );

		self::assertSame( array(), $this->remindersFor( (string) $this->confirmed()['uuid'] ) );
	}

	/** The setting is the default and the filter is the last word, as with the hold TTL. */
	public function test_the_filter_overrides_the_stored_lead_time(): void {
		$twoHours = static fn (): int => 2;
		add_filter( 'reservant/reminder_lead_hours', $twoHours );
		$booking = $this->confirmed();
		remove_filter( 'reservant/reminder_lead_hours', $twoHours );

		self::assertSame(
			array( $this->utc( 1, '09:00' )->getTimestamp() - 2 * HOUR_IN_SECONDS ),
			$this->remindersFor( (string) $booking['uuid'] )
		);
	}

	public function test_a_firing_reminder_emails_the_guest_and_attaches_no_second_calendar_file(): void {
		$booking = $this->confirmed();

		$sent = $this->captureMail(
			static function () use ( $booking ): void {
				do_action( Jobs::REMINDER, (string) $booking['uuid'] );
			}
		);

		self::assertCount( 1, $sent );
		self::assertSame( 'maria@example.com', $sent[0]['to'] );
		self::assertStringContainsString( 'reminder', strtolower( $sent[0]['subject'] ) );
		// They already have the .ics from their confirmation, carrying this same UID at this same
		// sequence, so a second copy tells their calendar nothing.
		self::assertSame( array(), $sent[0]['attachments'] );
	}

	/**
	 * The property the whole design rests on.
	 *
	 * `Scheduler::cancel()` is best effort - it can lose a race with the queue runner, and a job
	 * whose args no longer match is never found at all. The re-read in `Jobs::reminder()` is what
	 * actually keeps a reminder off an appointment that is not happening.
	 */
	public function test_a_reminder_that_fires_for_a_cancelled_booking_sends_nothing(): void {
		global $wpdb;
		$booking = $this->confirmed();
		CancelBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:10' ) );

		$sent = $this->captureMail(
			static function () use ( $booking ): void {
				do_action( Jobs::REMINDER, (string) $booking['uuid'] );
			}
		);

		self::assertSame( array(), $sent );
	}

	public function test_a_reminder_for_a_booking_that_no_longer_exists_is_a_benign_no_op(): void {
		$sent = $this->captureMail(
			static function (): void {
				do_action( Jobs::REMINDER, '00000000-0000-4000-8000-000000000000' );
			}
		);

		self::assertSame( array(), $sent );
	}

	/** The guest has to turn up for the FIRST segment; the rest of the chain follows on its own. */
	public function test_a_chain_is_reminded_off_its_first_segment(): void {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest(
					$this->utc( 1, '10:00' ),
					array( new SegmentChoice( $this->serviceId ), new SegmentChoice( $this->serviceId ) )
				)
			),
			$this->utc( 0 )
		);
		ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );

		self::assertSame(
			array( $this->utc( 1, '10:00' )->getTimestamp() - 24 * HOUR_IN_SECONDS ),
			$this->remindersFor( (string) $held['uuid'] )
		);
	}

	/** The owner's switch is honoured at the one seam every message passes through. */
	public function test_an_owner_who_switched_the_reminder_off_stops_it_at_the_mailer(): void {
		Settings::make()->update( array( 'emails_off' => array( 'booking_reminder' ) ) );
		$booking = $this->confirmed();

		$sent = $this->captureMail(
			static function () use ( $booking ): void {
				do_action( Jobs::REMINDER, (string) $booking['uuid'] );
			}
		);

		self::assertSame( array(), $sent );
		// The timer still exists - the switch is about the message, not about the machinery, so
		// turning it back on does not require rebooking anything.
		self::assertCount( 1, $this->remindersFor( (string) $booking['uuid'] ) );
	}
}
