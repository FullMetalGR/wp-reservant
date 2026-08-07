<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\ApproveBooking;

/**
 * `ApproveBooking` runs under the section-2.2 locks, in the codebase-wide lock order, and bumps the
 * mask revision - the protocol `CancelBooking` and `ExpireHolds` already follow.
 *
 * WHAT THESE TESTS PROVE: that the use case acquires the slot mutex (the resource-day rows for
 * appointment items, the occurrence row for event items) INSIDE its transaction and BEFORE the
 * bookings row it then locks, that it bumps `reservant_resource_days.rev`, and that an ordinary
 * human approval still succeeds. The lock-order assertion is the load-bearing one: mutex rows
 * before bookings rows is what keeps this out of a deadlock with `HoldBooking`, which locks in
 * exactly that order. Every one of these assertions fails against the unlocked version.
 *
 * WHAT THEY DO NOT PROVE: that the serialisation prevents the double booking it exists to prevent.
 * That interleaving needs two connections racing - a hold's reap blocking on the booking row while
 * an auto-approve commits underneath the hold's snapshot - and PHPUnit's integration suite is a
 * single connection inside a single transaction, which cannot contend with itself. The lock is the
 * mechanism; this pins the mechanism. Why the mechanism is sufficient on its own, and why
 * re-validating capacity here instead would be both more expensive and unnecessary, is argued on
 * the `ApproveBooking` class docblock.
 *
 * The `rev` assertion IS end to end: it observes the committed column, not a query shape.
 */
final class ApprovalLockingTest extends LockedDecisionTestCase {

	public function testApproveLocksTheResourceDayBeforeTheBookingRow(): void {
		global $wpdb;
		$booking = $this->holdAppointment();

		$this->capture( fn () => ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' ) );

		$this->assertOrdered( self::TRANSACTION_START, self::RESOURCE_DAY_LOCK, 'The slot mutex must be taken inside the approve transaction.' );
		// The load-bearing ordering: resource_days/occurrences BEFORE bookings, everywhere.
		$this->assertOrdered( self::RESOURCE_DAY_LOCK, self::BOOKING_LOCK, 'Approve must lock the resource-day before the bookings row (deadlock order).' );
	}

	public function testApproveLocksTheOccurrenceForAnEventBooking(): void {
		global $wpdb;
		$booking = $this->holdEvent();

		$this->capture( fn () => ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' ) );

		$this->assertOrdered( self::TRANSACTION_START, self::OCCURRENCE_LOCK, 'The occurrence mutex must be taken inside the approve transaction.' );
		$this->assertOrdered( self::OCCURRENCE_LOCK, self::BOOKING_LOCK, 'Approve must lock the occurrence before the bookings row (deadlock order).' );
	}

	public function testApproveBumpsTheMaskRevision(): void {
		global $wpdb;
		$booking = $this->holdAppointment();
		$before  = $this->rev();

		ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' );

		// On the `Jobs::timeout()` auto-approve path the hold has already lapsed - it is NOT in the
		// free/busy mask - and confirming puts it back in permanently, so the cache key has to move.
		self::assertSame( $before + 1, $this->rev() );
	}

	public function testApproveStillSucceedsForAnOrdinaryHumanDecision(): void {
		global $wpdb;
		$booking  = $this->holdAppointment();
		$approved = ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' );

		// The locks must not have turned a perfectly ordinary approval into a refusal: a live
		// approval hold is blocking, so it blocks against ITSELF, and anything that re-validated
		// capacity naively here would fail exactly this case.
		self::assertSame( 'confirmed', $approved['status'] );
		self::assertNull( $approved['hold_expires_at'] );
	}
}
