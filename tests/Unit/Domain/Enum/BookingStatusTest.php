<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\HoldClass;

final class BookingStatusTest extends TestCase {

	/** Full transition matrix from AGENTS.md section 2.3. Everything not listed is forbidden. */
	private const ALLOWED = array(
		'pending'           => array( 'confirmed', 'cancelled', 'expired' ),
		'awaiting_approval' => array( 'awaiting_payment', 'confirmed', 'rejected', 'cancelled', 'expired' ),
		'awaiting_payment'  => array( 'confirmed', 'cancelled', 'expired' ),
		'confirmed'         => array( 'completed', 'no_show', 'cancelled' ),
		'completed'         => array(),
		'no_show'           => array(),
		'cancelled'         => array(),
		'rejected'          => array(),
		'expired'           => array(),
	);

	public function test_transition_matrix_is_exact(): void {
		foreach ( BookingStatus::cases() as $from ) {
			foreach ( BookingStatus::cases() as $to ) {
				$expected = in_array( $to->value, self::ALLOWED[ $from->value ], true );
				self::assertSame(
					$expected,
					$from->canTransitionTo( $to ),
					"{$from->value} -> {$to->value} should be " . ( $expected ? 'allowed' : 'forbidden' )
				);
			}
		}
	}

	public function test_held_statuses_are_the_three_hold_classes(): void {
		self::assertSame(
			array( BookingStatus::Pending, BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment ),
			BookingStatus::heldStatuses()
		);
		self::assertTrue( BookingStatus::Pending->isHeld() );
		self::assertFalse( BookingStatus::Confirmed->isHeld() );
	}

	public function test_hold_class_maps_from_status(): void {
		self::assertSame( HoldClass::Checkout, HoldClass::forStatus( BookingStatus::Pending ) );
		self::assertSame( HoldClass::Approval, HoldClass::forStatus( BookingStatus::AwaitingApproval ) );
		self::assertSame( HoldClass::Payment, HoldClass::forStatus( BookingStatus::AwaitingPayment ) );
		self::assertNull( HoldClass::forStatus( BookingStatus::Confirmed ) );
	}

	/**
	 * `Application\GuardedWrite` derives the seat release from the target status rather than taking
	 * a flag, so this predicate decides - for every transition in the codebase, including ones not
	 * yet written - whether the seat goes back on sale. Asserted over ALL nine cases rather than the
	 * three that are true, because the interesting half is what stays false.
	 */
	public function test_only_the_three_statuses_that_undo_a_booking_release_its_seat(): void {
		$releasing = array( BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Expired );
		foreach ( BookingStatus::cases() as $status ) {
			self::assertSame(
				in_array( $status, $releasing, true ),
				$status->releasesSeatClaims(),
				"releasesSeatClaims() disagrees for {$status->value}"
			);
		}
	}

	/**
	 * The distinction the predicate exists to protect, spelled out because an `isTerminal()`-shaped
	 * rewrite would pass every other assertion here: `completed` and `no_show` are exactly as
	 * terminal as `cancelled`, and both must KEEP the seat. `MarkBookingOutcome` is the transition
	 * that would silently start handing seats back on sale.
	 */
	public function test_a_booking_that_happened_keeps_its_seat_though_its_status_is_terminal(): void {
		self::assertFalse( BookingStatus::Completed->releasesSeatClaims() );
		self::assertFalse( BookingStatus::NoShow->releasesSeatClaims() );
		self::assertFalse( BookingStatus::Confirmed->releasesSeatClaims() );
		self::assertTrue( BookingStatus::Cancelled->releasesSeatClaims() );
	}
}
