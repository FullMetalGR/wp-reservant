<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\RejectBooking;
use Reservant\Infrastructure\Db\OccurrenceRepository;

/**
 * `RejectBooking` runs under the section-2.2 locks, in the codebase-wide lock order, and bumps the
 * mask revision - the same protocol its siblings `CancelBooking` and `ExpireHolds` follow, and for
 * the same reason: `awaiting_approval` -> `rejected` leaves the blocking predicate and frees the
 * booking's seat claims, which is a write to the slot exactly as an acquisition is.
 *
 * WHAT THESE TESTS PROVE: that the mutex is taken inside the transaction and before the bookings
 * row, and that `reservant_resource_days.rev` - the free/busy mask cache key (AGENTS.md section 2.4
 * step 6) - is bumped inside that same transaction. The revision assertions are the important ones:
 * `rev` has no reader today, so the missing bump was invisible, and would have surfaced as "the
 * owner rejects a 10:00 request and 10:00 never comes back on the widget" only once the reader
 * landed, several releases after the cause. All of these fail against the unlocked version.
 *
 * WHAT THEY DO NOT PROVE: any statement about contention. Rejection cannot overbook - freeing
 * capacity only makes a concurrent hold more conservative, and the unique `(occurrence_id,
 * seat_claim)` index backstops the seats - so there is no race here to reproduce; the lock is taken
 * because a slot write belongs under the slot's mutex, and because a bump outside the lock could
 * otherwise be lost against a concurrent one.
 */
final class RejectionLockingTest extends LockedDecisionTestCase {

	public function testRejectLocksTheResourceDayBeforeTheBookingRow(): void {
		global $wpdb;
		$booking = $this->holdAppointment();

		$this->capture( fn () => RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'fully booked', $this->utc( 0, '01:00' ), 'admin' ) );

		$this->assertOrdered( self::TRANSACTION_START, self::RESOURCE_DAY_LOCK, 'The slot mutex must be taken inside the reject transaction.' );
		// The load-bearing ordering: resource_days/occurrences BEFORE bookings, everywhere.
		$this->assertOrdered( self::RESOURCE_DAY_LOCK, self::BOOKING_LOCK, 'Reject must lock the resource-day before the bookings row (deadlock order).' );
	}

	public function testRejectLocksTheOccurrenceForAnEventBooking(): void {
		global $wpdb;
		$booking = $this->holdEvent();

		$this->capture( fn () => RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'cancelled event', $this->utc( 0, '01:00' ), 'admin' ) );

		$this->assertOrdered( self::OCCURRENCE_LOCK, self::BOOKING_LOCK, 'Reject must lock the occurrence before the bookings row (deadlock order).' );
	}

	public function testRejectBumpsTheMaskRevisionSoTheFreedSlotLeavesTheCache(): void {
		global $wpdb;
		$booking = $this->holdAppointment();
		$before  = $this->rev();

		RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'fully booked', $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( $before + 1, $this->rev() );
	}

	public function testRejectBumpsTheRevisionUnderTheLockAndInsideTheTransaction(): void {
		global $wpdb;
		$booking = $this->holdAppointment();

		$this->capture( fn () => RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'fully booked', $this->utc( 0, '01:00' ), 'admin' ) );

		$this->assertOrdered( self::RESOURCE_DAY_LOCK, self::REV_BUMP, 'The revision bump belongs under the lock it is bumped for.' );
		$this->assertOrdered( self::REV_BUMP, '/^COMMIT$/i', 'The revision bump must be part of the same transaction as the rejection.' );
	}

	public function testRejectStillReleasesSeatClaimsAndFreesCapacity(): void {
		global $wpdb;
		$booking = $this->holdEvent();
		self::assertSame( 2, ( new OccurrenceRepository( $wpdb ) )->blockingSeatSum( $this->occurrenceId ) );

		RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'cancelled event', $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 0, ( new OccurrenceRepository( $wpdb ) )->blockingSeatSum( $this->occurrenceId ) );
	}
}
