<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\AvailabilityQuery;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

final class AvailabilityQueryTest extends ReservantTestCase {

	private int $serviceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services        = new ServiceRepository( $wpdb );
		$resources       = new ResourceRepository( $wpdb );
		$avail           = new AvailabilityRepository( $wpdb );
		$this->serviceId = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$this->staffId   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/** @return list<string> */
	private function starts(): array {
		global $wpdb;
		return array_map(
			static fn ( \DateTimeImmutable $start ): string => $start->format( 'H:i' ),
			AvailabilityQuery::make( $wpdb )->appointmentStarts(
				array( new SegmentChoice( $this->serviceId ) ),
				$this->utc( 1 ),
				$this->utc( 2 ),
				$this->utc( 0 )
			)
		);
	}

	public function test_starts_are_clipped_to_the_working_window(): void {
		$starts = $this->starts();
		self::assertContains( '09:00', $starts );
		self::assertContains( '16:30', $starts ); // Last start whose 30 minutes fit before 17:00.
		self::assertNotContains( '08:55', $starts );
		self::assertNotContains( '16:35', $starts );
	}

	/**
	 * Availability is advisory, but it may not be *wrong about the rules*: what it offers first has
	 * to be something the only authority on capacity actually takes.
	 *
	 * A before-buffer contends with other BOOKINGS, never with opening hours (AGENTS.md section 2.4), so a
	 * 30-minute service with a 15-minute before-buffer is holdable at 09:00 in a shop that opens at
	 * 09:00 - HoldBooking checks the roster against the service span and the buffer against other
	 * bookings' block ranges. Availability used to offer 09:15 and quietly lose the salon its first
	 * appointment of every day.
	 */
	public function test_the_first_offered_start_is_one_the_hold_authority_accepts(): void {
		global $wpdb;
		$buffered = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Colour', 'type' => 'appointment', 'duration_min' => 30, 'buffer_before_min' => 15, 'payment_mode' => 'onsite' )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $buffered, $this->staffId );

		$starts = array_map(
			static fn ( \DateTimeImmutable $start ): string => $start->format( 'H:i' ),
			AvailabilityQuery::make( $wpdb )->appointmentStarts( array( new SegmentChoice( $buffered ) ), $this->utc( 1 ), $this->utc( 2 ), $this->utc( 0 ) )
		);
		self::assertSame( '09:00', $starts[0] );
		self::assertNotContains( '08:55', $starts );

		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $buffered ) ) ) ),
			$this->utc( 0 )
		);
		self::assertSame( 'pending', $held['status'] );
		// The buffer really is held - it just reaches back before opening, which blocks nobody.
		self::assertSame( $this->sql( 1, '08:45' ), $held['items'][0]['block_start_utc'] );

		// And the converse: a start availability withheld is refused by the authority too, so the
		// two agree at both ends of the working window rather than merely overlapping.
		try {
			HoldBooking::make( $wpdb )->execute(
				new HoldRequest( new Customer( 'N', 'n@example.com' ), new AppointmentRequest( $this->utc( 1, '08:55' ), array( new SegmentChoice( $buffered ) ) ) ),
				$this->utc( 0 )
			);
			self::fail( 'Expected outside_hours.' );
		} catch ( SlotConflict $conflict ) {
			self::assertSame( 'outside_hours', $conflict->reason );
		}
	}

	public function test_a_hold_removes_the_starts_it_overlaps(): void {
		global $wpdb;
		HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), new AppointmentRequest( $this->utc( 1, '10:00' ), array( new SegmentChoice( $this->serviceId ) ) ) ),
			$this->utc( 0 )
		);
		$starts = $this->starts();
		self::assertNotContains( '10:00', $starts );
		self::assertNotContains( '09:35', $starts ); // Would run to 10:05.
		self::assertContains( '09:30', $starts );    // Ends exactly at 10:00.
		self::assertContains( '10:30', $starts );
	}
}
