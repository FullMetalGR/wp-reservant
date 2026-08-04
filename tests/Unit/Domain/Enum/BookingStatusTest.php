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
}
