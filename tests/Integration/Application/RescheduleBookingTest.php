<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\RescheduleBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * The reschedule contract (AGENTS.md section 5): all segments move as one atomic release + re-hold,
 * and partial success is impossible. `test_leaves_the_booking_untouched_when_the_target_is_taken` is
 * the one that matters - a refused move must leave the customer with the booking they already had,
 * so it asserts the ORIGINAL row's status and start time rather than merely that an exception was
 * thrown.
 *
 * Extends `LockedDecisionTestCase` for the same reason `ApprovalLockingTest` and
 * `RejectionLockingTest` do: the section-2.2 lock order and the mask-revision bump are properties of
 * the statement stream and the committed columns, not of the return value, so nothing else in this
 * file would notice if either were broken. The harness captures wpdb's real `query` filter, so those
 * tests read the statements the code actually issues.
 */
final class RescheduleBookingTest extends LockedDecisionTestCase {

	private int $cutId;
	private int $colourId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->cutId    = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->colourId = $services->insert( array( 'name' => 'Colour', 'type' => 'appointment', 'duration_min' => 30, 'processing_time_min' => 30, 'price_minor' => 5000, 'payment_mode' => 'onsite' ) );
		$this->staffA   = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB   = $resources->insert( array( 'name' => 'Bella' ) );
		foreach ( array( $this->cutId, $this->colourId ) as $service ) {
			foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
				$resources->linkService( $service, $staff );
			}
		}
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/**
	 * The mask-cache revision of one (resource, UTC day).
	 *
	 * `LockedDecisionTestCase::rev()` is pinned to its own fixture staff member and day; a reschedule
	 * is about two days at once, so it needs to ask about either.
	 */
	private function revOn( int $resourceId, \DateTimeImmutable $day ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rev FROM {$wpdb->prefix}reservant_resource_days WHERE resource_id = %d AND day_utc = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$resourceId,
				$day->format( 'Y-m-d' )
			)
		);
	}

	/**
	 * @param list<SegmentChoice> $segments
	 * @return array<string, mixed>
	 */
	private function hold( \DateTimeImmutable $start, array $segments ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest( $start, $segments ) ),
			$this->utc( 0 )
		);
	}

	/**
	 * A service wanting two hours' notice, with its reschedule window opened right up so the lead-time
	 * refusal is what the test actually reaches (the window is checked first, and it defaults to 24 h).
	 */
	private function noticeService(): int {
		global $wpdb;
		$id = ( new ServiceRepository( $wpdb ) )->insert(
			array(
				'name'                    => 'Shave',
				'type'                    => 'appointment',
				'duration_min'            => 30,
				'payment_mode'            => 'onsite',
				'lead_time_min'           => 120,
				'reschedule_window_hours' => 0,
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $id, $this->staffA );
		return $id;
	}

	/**
	 * @param list<SegmentChoice> $segments
	 * @return array<string, mixed>
	 */
	private function confirmedChain( \DateTimeImmutable $start, array $segments ): array {
		global $wpdb;
		return ConfirmBooking::make( $wpdb )->execute( (string) $this->hold( $start, $segments )['uuid'], $this->utc( 0, '00:05' ) );
	}

	/**
	 * Both segments move, the processing gap between them is preserved, and each keeps the staff
	 * member it was sold with.
	 *
	 * The second move is the self-collision case: +15 minutes is less than the 30-minute segment, so
	 * every new block range overlaps the old one it replaces. It can only succeed if the release
	 * happens before the claim, inside the same transaction.
	 */
	public function test_moves_every_segment_of_a_chain(): void {
		global $wpdb;
		$booking = $this->confirmedChain(
			$this->utc( 1, '09:00' ),
			array( new SegmentChoice( $this->colourId, $this->staffA ), new SegmentChoice( $this->cutId, $this->staffB ) )
		);
		self::assertSame( $this->sql( 1, '09:00' ), $booking['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 1, '10:00' ), $booking['items'][1]['start_utc'] );

		$moved = RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 1, '11:00' ), null, $this->utc( 0 ) );

		self::assertSame( 'confirmed', $moved['status'] );
		self::assertCount( 2, $moved['items'] );
		self::assertSame( $this->sql( 1, '11:00' ), $moved['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 1, '11:30' ), $moved['items'][0]['end_utc'] );
		self::assertSame( $this->sql( 1, '12:00' ), $moved['items'][1]['start_utc'] ); // offset = 30 + 30, unchanged
		self::assertSame( $this->staffA, $moved['items'][0]['resource_id'] );
		self::assertSame( $this->staffB, $moved['items'][1]['resource_id'] );
		self::assertSame( 7000, $moved['total_minor'] );

		$nudged = RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 1, '11:15' ), null, $this->utc( 0 ) );
		self::assertSame( $this->sql( 1, '11:15' ), $nudged['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 1, '12:15' ), $nudged['items'][1]['start_utc'] );
	}

	/**
	 * The contract: a refused move is not a cancellation. The rival keeps the target, and the
	 * original booking is still confirmed, still one item, still at its original time on its
	 * original staff member.
	 *
	 * These assertions are load-bearing, not belt and braces. `RescheduleBooking` deletes the old
	 * items BEFORE it tests the target, so by the time the overlap is noticed the booking has
	 * already been stripped inside the transaction - only the ROLLBACK puts it back. A test that
	 * merely caught the exception would pass with the customer's booking destroyed.
	 */
	public function test_leaves_the_booking_untouched_when_the_target_is_taken(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 2, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$this->hold( $this->utc( 2, '11:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );

		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 2, '11:00' ), null, $this->utc( 0 ) );
			self::fail( 'Expected an overlap conflict.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'overlap', $exception->reason );
		}

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $after );
		self::assertSame( 'confirmed', $after['status'] );
		self::assertCount( 1, $after['items'] );
		self::assertSame( $this->sql( 2, '09:00' ), $after['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 2, '09:30' ), $after['items'][0]['end_utc'] );
		self::assertSame( $this->staffA, $after['items'][0]['resource_id'] );
	}

	/**
	 * The service's reschedule window binds a customer; `$force` is the owner's escape from it.
	 *
	 * The refusal is `\RuntimeException('window_closed')`, not a `SlotConflict` - the same signal
	 * `CancelBooking` raises for the same class of refusal, so both answer 403 through
	 * `Errors::failure()` rather than inventing a second convention. `SlotConflict` extends
	 * `\RuntimeException`, so the message is what this asserts on (`LifecycleTest`'s idiom).
	 */
	public function test_refuses_outside_the_policy_window_unless_forced(): void {
		global $wpdb;
		$trim = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Trim', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'reschedule_window_hours' => 24 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $trim, $this->staffA );

		$booking = $this->confirmedChain( $this->utc( 3, '09:00' ), array( new SegmentChoice( $trim, $this->staffA ) ) );
		$now     = $this->utc( 2, '23:00' ); // 10 hours before the start - inside the 24 hour window.

		// Caught into a variable rather than asserted inside the `catch`: PHPUnit's own
		// `AssertionFailedError` is a `\RuntimeException`, so a `self::fail()` in the `try` of a broad
		// catch would be swallowed by it and the test would pass having proved nothing.
		$refusal = null;
		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 3, '14:00' ), null, $now );
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		}
		self::assertSame( 'window_closed', $refusal );
		self::assertSame(
			$this->sql( 3, '09:00' ),
			( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] )['items'][0]['start_utc']
		);

		$forced = RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 3, '14:00' ), null, $now, true );
		self::assertSame( $this->sql( 3, '14:00' ), $forced['items'][0]['start_utc'] );
	}

	/**
	 * A start off the granularity grid is not a slot the shop ever offered.
	 *
	 * `$force` does NOT excuse it, exactly as `HoldBooking`'s admin flag does not: the grid is what a
	 * start time IS, not a sales window an owner may waive.
	 */
	public function test_refuses_a_start_off_the_granularity_grid(): void {
		global $wpdb;
		$booking    = $this->confirmedChain( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$reschedule = RescheduleBooking::make( $wpdb );

		foreach ( array( false, true ) as $force ) {
			try {
				$reschedule->execute( (string) $booking['uuid'], $this->utc( 1, '09:07' ), null, $this->utc( 0 ), $force );
				self::fail( 'Expected a bad_time refusal.' );
			} catch ( SlotConflict $exception ) {
				self::assertSame( 'bad_time', $exception->reason );
			}
		}
		self::assertSame(
			$this->sql( 1, '09:00' ),
			( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] )['items'][0]['start_utc']
		);
	}

	/**
	 * Staff work 09:00-17:00, so 20:00 is nobody's slot to give.
	 *
	 * `$force` does NOT excuse this either: opening hours are the staff member's availability, not a
	 * sales window, and admin-mode holds have never been able to book outside them
	 * (`HoldBooking::assignResources()` applies `coversSpan()` regardless of the admin flag).
	 */
	public function test_refuses_a_target_outside_working_hours(): void {
		global $wpdb;
		$booking    = $this->confirmedChain( $this->utc( 1, '10:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$reschedule = RescheduleBooking::make( $wpdb );

		foreach ( array( false, true ) as $force ) {
			try {
				$reschedule->execute( (string) $booking['uuid'], $this->utc( 1, '20:00' ), null, $this->utc( 0 ), $force );
				self::fail( 'Expected an outside_hours refusal.' );
			} catch ( SlotConflict $exception ) {
				self::assertSame( 'outside_hours', $exception->reason );
			}
		}
		self::assertSame(
			$this->sql( 1, '10:00' ),
			( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] )['items'][0]['start_utc']
		);
	}

	/** The service's notice period applies to where a booking MOVES to, not only to where it began. */
	public function test_refuses_a_target_inside_the_lead_time(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 5, '12:00' ), array( new SegmentChoice( $this->noticeService(), $this->staffA ) ) );

		try {
			// "Now" is 11:00 and the service wants two hours' notice, so 11:30 is too soon.
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 5, '11:30' ), null, $this->utc( 5, '11:00' ) );
			self::fail( 'Expected a lead_time refusal.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'lead_time', $exception->reason );
		}
		self::assertSame(
			$this->sql( 5, '12:00' ),
			( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] )['items'][0]['start_utc']
		);
	}

	/** A service on sale ten days out cannot be moved twelve days out. */
	public function test_refuses_a_target_beyond_the_horizon(): void {
		global $wpdb;
		$shortRun = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Pop-up', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'horizon_days' => 10 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $shortRun, $this->staffA );
		$booking = $this->confirmedChain( $this->utc( 1, '09:00' ), array( new SegmentChoice( $shortRun, $this->staffA ) ) );

		try {
			// Day 0 is "now" and the horizon is ten days, so day 12 is past the end of the sale.
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 12, '09:00' ), null, $this->utc( 0 ) );
			self::fail( 'Expected a horizon refusal.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'horizon', $exception->reason );
		}
		self::assertSame(
			$this->sql( 1, '09:00' ),
			( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] )['items'][0]['start_utc']
		);
	}

	/**
	 * `$force` draws the line exactly where `HoldBooking`'s admin flag draws it: the sales windows are
	 * waived - lead time, horizon, and with them the guard against a start already in the past, which
	 * is how an owner records a walk-in after the fact - and integrity is not.
	 */
	public function test_force_skips_the_window_clamps_but_never_the_overlap_check(): void {
		global $wpdb;
		$notice     = $this->noticeService();
		$booking    = $this->confirmedChain( $this->utc( 5, '12:00' ), array( new SegmentChoice( $notice, $this->staffA ) ) );
		$reschedule = RescheduleBooking::make( $wpdb );
		$now        = $this->utc( 5, '11:00' );

		// 10:00 is both inside the two-hour notice period and already an hour in the past.
		$backdated = $reschedule->execute( (string) $booking['uuid'], $this->utc( 5, '10:00' ), null, $now, true );
		self::assertSame( $this->sql( 5, '10:00' ), $backdated['items'][0]['start_utc'] );

		// A rival holds 15:00, and forcing does not take it away from them.
		$this->hold( $this->utc( 5, '15:00' ), array( new SegmentChoice( $notice, $this->staffA ) ) );
		try {
			$reschedule->execute( (string) $booking['uuid'], $this->utc( 5, '15:00' ), null, $now, true );
			self::fail( 'Expected an overlap conflict even under force.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'overlap', $exception->reason );
		}

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $after );
		self::assertSame( 'confirmed', $after['status'] );
		self::assertCount( 1, $after['items'] );
		self::assertSame( $this->sql( 5, '10:00' ), $after['items'][0]['start_utc'] );
	}

	/** A booking that is not blocking anything has no slot to move. */
	public function test_refuses_a_cancelled_booking(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 4, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		CancelBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0 ), true );

		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 4, '14:00' ), null, $this->utc( 0 ) );
			self::fail( 'Expected a not_reschedulable refusal.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'not_reschedulable', $exception->reason );
		}
	}

	/**
	 * Moving seats between occurrences is a capacity acquisition on the target, so the target's
	 * remaining room decides - re-read under `LockKey::occurrence()`, never from the plan built
	 * before the transaction opened.
	 */
	public function test_event_reschedule_checks_target_capacity(): void {
		global $wpdb;
		$occurrences = new OccurrenceRepository( $wpdb );
		$eventId     = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' )
		);
		$occA = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 6, '18:00' ), 'end_utc' => $this->sql( 6, '20:00' ), 'capacity' => 5 ) );
		$occB = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 7, '18:00' ), 'end_utc' => $this->sql( 7, '20:00' ), 'capacity' => 3 ) );
		$occC = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 8, '18:00' ), 'end_utc' => $this->sql( 8, '20:00' ), 'capacity' => 4 ) );

		$seat    = fn ( int $occurrenceId, int $seats ): array => HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), null, new EventRequest( $occurrenceId, $seats ) ),
			$this->utc( 0 )
		);
		$booking = ConfirmBooking::make( $wpdb )->execute( (string) $seat( $occA, 2 )['uuid'], $this->utc( 0, '00:05' ) );
		$seat( $occB, 2 ); // Two of B's three seats are gone.

		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 7, '18:00' ), $occB, $this->utc( 0 ) );
			self::fail( 'Expected a capacity conflict.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'capacity', $exception->reason );
		}

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $after );
		self::assertSame( 'confirmed', $after['status'] );
		self::assertSame( $occA, $after['items'][0]['occurrence_id'] );
		self::assertSame( $this->sql( 6, '18:00' ), $after['items'][0]['start_utc'] );

		$moved = RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 8, '18:00' ), $occC, $this->utc( 0 ) );
		self::assertSame( $occC, $moved['items'][0]['occurrence_id'] );
		self::assertSame( $this->sql( 8, '18:00' ), $moved['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 8, '20:00' ), $moved['items'][0]['end_utc'] );
		self::assertSame( 2, $moved['items'][0]['seats'] );
	}

	/**
	 * Lock order, AGENTS.md section 2.2: the slot mutexes are taken INSIDE the transaction and BEFORE
	 * the bookings row. `HoldBooking` locks in exactly that order, so a reschedule that took the
	 * booking row first would deadlock against an ordinary hold.
	 *
	 * This fails if anyone swaps `$this->locks->acquire()` with `findByUuidForUpdate()` - which is
	 * also the swap that would silently move the consistent-read snapshot back before the mutexes,
	 * since a locking read does not open one and a plain read does.
	 */
	public function test_locks_the_resource_day_before_the_booking_row(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );

		$this->capture( fn () => RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 1, '13:00' ), null, $this->utc( 0 ) ) );

		$this->assertOrdered( self::TRANSACTION_START, self::RESOURCE_DAY_LOCK, 'The slot mutex must be taken inside the reschedule transaction.' );
		$this->assertOrdered( self::RESOURCE_DAY_LOCK, self::BOOKING_LOCK, 'Reschedule must lock the resource-day before the bookings row (deadlock order).' );
	}

	/** The same ordering on the event path, where the occurrence row is the mutex. */
	public function test_locks_the_occurrence_before_the_booking_row(): void {
		global $wpdb;
		$occurrences = new OccurrenceRepository( $wpdb );
		$eventId     = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Workshop', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' )
		);
		$occA    = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 6, '18:00' ), 'end_utc' => $this->sql( 6, '20:00' ), 'capacity' => 5 ) );
		$occB    = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 7, '18:00' ), 'end_utc' => $this->sql( 7, '20:00' ), 'capacity' => 5 ) );
		$held    = HoldBooking::make( $wpdb )->execute( new HoldRequest( $this->customer(), null, new EventRequest( $occA, 1 ) ), $this->utc( 0 ) );
		$booking = ConfirmBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '00:05' ) );

		$this->capture( fn () => RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 7, '18:00' ), $occB, $this->utc( 0 ) ) );

		$this->assertOrdered( self::TRANSACTION_START, self::OCCURRENCE_LOCK, 'The occurrence mutex must be taken inside the reschedule transaction.' );
		$this->assertOrdered( self::OCCURRENCE_LOCK, self::BOOKING_LOCK, 'Reschedule must lock the occurrence before the bookings row (deadlock order).' );
	}

	/**
	 * A move across a UTC-day boundary - the only shape that exercises the union at its full width.
	 *
	 * Every other successful move in this file starts and lands on one day, where the old key and the
	 * new key collapse to one under `LockKey::sorted()`'s deduplication. Here they do not: two
	 * resource-day rows are locked and BOTH revisions must move, because after the move day 1 has a
	 * minute free that was not and day 2 has one taken that was not. A one-sided bump would leave one
	 * of the two masks selling or hiding the wrong slot, which is a silent overbooking either way.
	 *
	 * This fails if `bumpRev()` is deleted, and it fails if the bump is narrowed to the new day only.
	 */
	public function test_a_cross_day_move_bumps_the_revision_on_both_days(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$before1 = $this->revOn( $this->staffA, $this->utc( 1 ) );
		$before2 = $this->revOn( $this->staffA, $this->utc( 2 ) );

		$moved = RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 2, '09:00' ), null, $this->utc( 0 ) );

		self::assertSame( $this->sql( 2, '09:00' ), $moved['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 2, '09:30' ), $moved['items'][0]['end_utc'] );
		self::assertSame( $this->staffA, $moved['items'][0]['resource_id'] );
		self::assertSame( $before1 + 1, $this->revOn( $this->staffA, $this->utc( 1 ) ), 'The day being vacated must have its mask revision bumped.' );
		self::assertSame( $before2 + 1, $this->revOn( $this->staffA, $this->utc( 2 ) ), 'The day being taken must have its mask revision bumped.' );

		// The slot really is free again on day 1, and really is taken on day 2.
		self::assertSame( 'pending', $this->hold( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) )['status'] );
		try {
			$this->hold( $this->utc( 2, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
			self::fail( 'Expected the moved-to slot to be taken.' );
		} catch ( SlotConflict $exception ) {
			self::assertSame( 'overlap', $exception->reason );
		}
	}

	/**
	 * A failed release must abort the move, not be walked past.
	 *
	 * `$wpdb->delete()` answers `false` on a DB-level failure - a deadlock (1213) or a lock-wait
	 * timeout (1205). Swallowing that is the one way this use case's all-or-nothing contract can be
	 * broken and still COMMIT: the old rows survive, `assertTargetIsFree()` passes because they do not
	 * overlap the target, the new rows are inserted on top of them, and the booking ends up holding
	 * BOTH placements - capacity consumed twice, with an audit trail that looks perfectly normal.
	 *
	 * The sabotage is a `query` filter that rewrites the item DELETE to hit a table that does not
	 * exist, which is the same class of failure a deadlock produces: `false` back from wpdb with
	 * `last_error` set. So this asserts more than PHP control flow - it asserts the committed row set.
	 */
	public function test_a_failed_release_aborts_the_move_instead_of_doubling_the_booking(): void {
		global $wpdb;
		$booking  = $this->confirmedChain( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$sabotage = static function ( $query ) {
			return 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*reservant_booking_items/i', (string) $query )
				? 'DELETE FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};

		// See the note in `test_refuses_outside_the_policy_window_unless_forced`: a `self::fail()` here
		// would be caught by the broad `\RuntimeException` arm, so the refusal is asserted afterwards.
		$refusal    = null;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 1, '15:00' ), null, $this->utc( 0 ) );
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}
		self::assertStringStartsWith( 'booking_item_delete_failed', (string) $refusal, 'A failed release must abort the move.' );

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $after );
		self::assertSame( 'confirmed', $after['status'] );
		self::assertCount( 1, $after['items'], 'A booking must never hold both its old and its new placement.' );
		self::assertSame( $this->sql( 1, '09:00' ), $after['items'][0]['start_utc'] );
	}

	/**
	 * A lock that could not actually be taken must refuse the request, not be written past.
	 *
	 * `LockManager::acquire()` issues `SELECT ... FOR UPDATE`, and wpdb answers `false` on a DB-level
	 * failure without throwing or surfacing anything while `WP_DEBUG` is off. The two failures that
	 * matter are the two `deleteItems()` already names:
	 *
	 *  - **1205, lock-wait timeout.** `innodb_rollback_on_timeout` is OFF by default, so only the
	 *    STATEMENT rolls back. The transaction stays open WITHOUT the lock and, unguarded, walks
	 *    straight through the re-read, the release, the capacity checks and COMMIT - a committed
	 *    capacity write outside the mutex, which is precisely what AGENTS.md section 2.2 exists to
	 *    prevent.
	 *  - **1213, deadlock.** The server rolls the whole transaction back, so every later statement runs
	 *    under restored autocommit and commits INDIVIDUALLY - the item DELETE alone, then the INSERT
	 *    alone - and `TransactionRunner`'s eventual ROLLBACK is a no-op. "Atomic release + re-hold;
	 *    partial success is impossible" would be a promise this class could not keep.
	 *
	 * The sabotage is the one `test_a_failed_release_aborts_the_move_instead_of_doubling_the_booking`
	 * uses, aimed at the lock statement instead of the release: a `query` filter rewriting it to a table
	 * that does not exist, which produces exactly the `false`-with-`last_error` shape a 1205 produces.
	 * The committed row set is what is asserted, not merely the exception - unguarded, this move
	 * COMMITS, and an exception-only test would pass against the very code path this guards.
	 */
	public function test_a_lock_that_cannot_be_taken_refuses_the_move_rather_than_writing_without_it(): void {
		global $wpdb;
		$booking  = $this->confirmedChain( $this->utc( 2, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		$sabotage = static function ( $query ) {
			return 1 === preg_match( '/^\s*SELECT\s+resource_id\s+FROM\s+\S*reservant_resource_days\b.*FOR UPDATE/is', (string) $query )
				? 'SELECT resource_id FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};

		// Captured rather than asserted inside the `catch`: PHPUnit's `AssertionFailedError` extends
		// `\RuntimeException`, so a `self::fail()` in the `try` would be swallowed by the broad arm.
		$refusal    = null;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 2, '15:00' ), null, $this->utc( 0 ) );
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}
		self::assertSame( 'stale_state', $refusal, 'A lock that could not be taken must refuse the request.' );

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $after );
		self::assertSame( 'confirmed', $after['status'] );
		self::assertCount( 1, $after['items'] );
		self::assertSame( $this->sql( 2, '09:00' ), $after['items'][0]['start_utc'], 'A move made without the mutex must never commit.' );
	}

	public function test_writes_one_audit_entry_describing_the_move(): void {
		global $wpdb;
		$booking = $this->confirmedChain( $this->utc( 9, '09:00' ), array( new SegmentChoice( $this->cutId, $this->staffA ) ) );
		RescheduleBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 9, '15:00' ), null, $this->utc( 0 ) );

		$moves = array_values(
			array_filter(
				( new AuditLog( $wpdb ) )->forBooking( (int) $booking['id'] ),
				static fn ( array $row ): bool => 'rescheduled' === $row['action']
			)
		);
		self::assertCount( 1, $moves );
		self::assertSame( $this->sql( 9, '09:00' ), $moves[0]['payload']['from'] );
		self::assertSame( $this->sql( 9, '15:00' ), $moves[0]['payload']['to'] );
	}
}
