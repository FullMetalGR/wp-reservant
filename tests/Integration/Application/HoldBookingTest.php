<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Settings;
use Reservant\Tests\Integration\ReservantTestCase;

final class HoldBookingTest extends ReservantTestCase {

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
		return new Customer( 'Maria', 'maria@example.com', '+30123456789' );
	}

	public function test_holds_a_simple_appointment(): void {
		global $wpdb;
		$hold    = HoldBooking::make( $wpdb );
		$request = new HoldRequest( $this->customer(), new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->cutId ) ) ) );
		$booking = $hold->execute( $request, $this->utc( 0 ) );
		self::assertSame( 'pending', $booking['status'] );
		self::assertSame( 2000, $booking['total_minor'] );
		self::assertNotEmpty( $booking['manage_token'] );
		self::assertCount( 1, $booking['items'] );
		self::assertSame( $this->staffA, $booking['items'][0]['resource_id'] ); // lowest free id wins
	}

	public function test_second_hold_on_same_slot_conflicts(): void {
		global $wpdb;
		$hold    = HoldBooking::make( $wpdb );
		$start   = $this->utc( 1, '10:00' );
		$now     = $this->utc( 0 );
		// Pin both requests to staff A so the second cannot fall over to staff B.
		$request = fn () => new HoldRequest( $this->customer(), new AppointmentRequest( $start, array( new SegmentChoice( $this->cutId, $this->staffA ) ) ) );
		$hold->execute( $request(), $now );
		try {
			$hold->execute( $request(), $now );
			self::fail( 'Expected an overlap conflict.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'overlap', $e->reason );
			self::assertSame( 0, $e->segmentIndex );
		}
	}

	public function test_chain_books_across_staff_and_processing_gap_frees_colourist(): void {
		global $wpdb;
		$hold = HoldBooking::make( $wpdb );
		$now  = $this->utc( 0 );
		// Chain: colour (30 + 30 processing) then cut (30). Cut starts at +60 min.
		$chain   = new HoldRequest( $this->customer(), new AppointmentRequest(
			$this->utc( 2, '09:00' ),
			array( new SegmentChoice( $this->colourId, $this->staffA ), new SegmentChoice( $this->cutId, $this->staffB ) )
		) );
		$booking = $hold->execute( $chain, $now );
		self::assertSame( $this->sql( 2, '09:00' ), $booking['items'][0]['start_utc'] );
		self::assertSame( $this->sql( 2, '10:00' ), $booking['items'][1]['start_utc'] ); // offset = 30 + 30
		self::assertSame( 7000, $booking['total_minor'] );

		// The colourist's processing gap [09:30, 10:00) is bookable by another customer.
		$gap = new HoldRequest( $this->customer(), new AppointmentRequest(
			$this->utc( 2, '09:30' ),
			array( new SegmentChoice( $this->cutId, $this->staffA ) )
		) );
		self::assertSame( 'pending', $hold->execute( $gap, $now )['status'] );
	}

	public function test_same_staff_chain_assigns_one_resource_to_every_segment(): void {
		global $wpdb;
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest(
				$this->utc( 4, '09:00' ),
				array( new SegmentChoice( $this->colourId ), new SegmentChoice( $this->cutId ) ),
				true
			) ),
			$this->utc( 0 )
		);
		self::assertSame( $this->staffA, $booking['items'][0]['resource_id'] );
		self::assertSame( $this->staffA, $booking['items'][1]['resource_id'] );
	}

	public function test_same_staff_chain_conflicts_when_only_a_split_would_work(): void {
		global $wpdb;
		$hold = HoldBooking::make( $wpdb );
		$now  = $this->utc( 0 );
		$pin  = function ( int $staff, string $start ) use ( $hold, $now ): void {
			$hold->execute(
				new HoldRequest( $this->customer(), new AppointmentRequest( $this->utc( 5, $start ), array( new SegmentChoice( $this->cutId, $staff ) ) ) ),
				$now
			);
		};
		// Bella is busy when the chain starts; Alex is busy when its second segment runs.
		$pin( $this->staffB, '09:00' );
		$pin( $this->staffA, '10:00' );

		$chain = fn ( bool $sameStaff ): HoldRequest => new HoldRequest( $this->customer(), new AppointmentRequest(
			$this->utc( 5, '09:00' ),
			array( new SegmentChoice( $this->colourId ), new SegmentChoice( $this->cutId ) ),
			$sameStaff
		) );

		try {
			$hold->execute( $chain( true ), $now );
			self::fail( 'Expected a same-staff conflict.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'overlap', $e->reason );
		}

		// The identical chain succeeds once the segments may split, so sameStaff caused the 409.
		$split = $hold->execute( $chain( false ), $now );
		self::assertSame( $this->staffA, $split['items'][0]['resource_id'] );
		self::assertSame( $this->staffB, $split['items'][1]['resource_id'] );
	}

	public function test_event_capacity_is_the_authority_on_open_seating(): void {
		global $wpdb;
		$services    = new ServiceRepository( $wpdb );
		$occurrences = new OccurrenceRepository( $wpdb );
		$eventId     = $services->insert( array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' ) );
		$occId       = $occurrences->insert( array( 'service_id' => $eventId, 'start_utc' => $this->sql( 10, '18:00' ), 'end_utc' => $this->sql( 10, '20:00' ), 'capacity' => 3 ) );
		$hold        = HoldBooking::make( $wpdb );
		$now         = $this->utc( 0 );

		$booking = $hold->execute( new HoldRequest( $this->customer(), null, new EventRequest( $occId, 2 ) ), $now );
		self::assertSame( 2000, $booking['total_minor'] ); // 1000 x 2 seats
		try {
			$hold->execute( new HoldRequest( $this->customer(), null, new EventRequest( $occId, 2 ) ), $now ); // 2 + 2 > 3
			self::fail( 'Expected a capacity conflict.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'capacity', $e->reason );
		}
	}

	public function test_approval_service_holds_as_awaiting_approval(): void {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );
		$consult  = $services->insert( array( 'name' => 'Consult', 'type' => 'appointment', 'duration_min' => 30, 'requires_approval' => 1, 'payment_mode' => 'free' ) );
		( new ResourceRepository( $wpdb ) )->linkService( $consult, $this->staffA );
		$hold    = HoldBooking::make( $wpdb );
		$booking = $hold->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest( $this->utc( 3, '11:00' ), array( new SegmentChoice( $consult ) ) ) ),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', $booking['status'] );
		self::assertSame( 'approval', $booking['hold_class'] );
	}

	/**
	 * A service that stores no approval window of its own falls back to the site-wide
	 * `approval_ttl_hours` setting, not to a constant.
	 *
	 * `Settings::approvalTtlHours()` shipped with no caller at all: the settings screen offered the
	 * field, the controller validated it, the option stored it, and `holdExpiresAt()` then used a
	 * hardcoded 48 regardless. They are the same quantity (AGENTS.md section 2.3), so the setting is
	 * now the fallback - and this is the test that says so. The value chosen here is 3, which is
	 * neither the setting's default nor the schema column's, so nothing can pass by coincidence.
	 *
	 * The clock is the wall clock, not this suite's week-ahead fixture instant, because the TTL is
	 * anchored to `max(injected now, wall clock)`; anything else would make the expected expiry a
	 * week out and the assertion meaningless.
	 */
	public function test_a_service_with_no_approval_window_of_its_own_uses_the_site_setting(): void {
		global $wpdb;
		Settings::make()->update( array( 'approval_ttl_hours' => 3 ) );

		$services = new ServiceRepository( $wpdb );
		$consult  = $services->insert(
			array(
				'name'                => 'Consult',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'requires_approval'   => 1,
				'approval_hold_hours' => 0,
				'payment_mode'        => 'free',
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $consult, $this->staffA );

		$now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest( $this->utc( 3, '11:00' ), array( new SegmentChoice( $consult ) ) ) ),
			$now
		);

		self::assertSame( 'awaiting_approval', $booking['status'] );
		$expires = ( new \DateTimeImmutable( (string) $booking['hold_expires_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		self::assertEqualsWithDelta( $now->getTimestamp() + ( 3 * HOUR_IN_SECONDS ), $expires, 5 );
	}
}
