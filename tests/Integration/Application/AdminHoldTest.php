<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\BookingSnapshot;
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
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * Admin-mode manual booking (Task 6): `HoldRequest::$admin` skips the lead-time and horizon
 * refusal arms only. Every other refusal the locked write protocol enforces - outside_hours,
 * overlap, capacity, seat_taken, bad_seat, bad_time, not_found, no_staff (AGENTS.md section 2.2) -
 * still applies unchanged, and the container lands straight on `confirmed` with no hold at all.
 */
final class AdminHoldTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		// 48h notice, so day 1 (33h out) is inside the window for an ordinary customer hold.
		$this->cutId  = $services->insert(
			array(
				'name'          => 'Cut',
				'type'          => 'appointment',
				'duration_min'  => 30,
				'price_minor'   => 2000,
				'payment_mode'  => 'onsite',
				'lead_time_min' => 2880,
			)
		);
		$this->staffA = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->cutId, $this->staffA );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffA, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/** @return array<string, mixed> */
	private function hold( \DateTimeImmutable $start, int $serviceId, bool $admin = false ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), new AppointmentRequest( $start, array( new SegmentChoice( $serviceId, $this->staffA ) ) ), null, $admin ),
			$this->utc( 0 )
		);
	}

	public function testAdminHoldInsideLeadTimeSucceedsWhereCustomerHoldRefuses(): void {
		$start = $this->utc( 1, '09:00' ); // 33h out; the fixture service needs 48h notice.

		try {
			$this->hold( $start, $this->cutId, false );
			self::fail( 'Expected the ordinary customer hold to be refused inside the lead window.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'lead_time', $e->reason );
		}

		$admin = $this->hold( $start, $this->cutId, true );
		self::assertSame( 'confirmed', $admin['status'] );
		self::assertNull( $admin['hold_class'] );
		self::assertNull( $admin['hold_expires_at'] );
	}

	public function testAdminHoldOnApprovalRequiringServiceLandsConfirmed(): void {
		global $wpdb;
		$consult = ( new ServiceRepository( $wpdb ) )->insert(
			array(
				'name'                => 'Consult',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
				'payment_mode'        => 'free',
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $consult, $this->staffA );

		$booking = $this->hold( $this->utc( 3, '11:00' ), $consult, true );

		self::assertSame( 'confirmed', $booking['status'] );
		self::assertSame( 0, (int) $booking['requires_approval'] );
		self::assertNull( $booking['hold_class'] );
		self::assertNull( $booking['hold_expires_at'] );
	}

	public function testAdminHoldStillRefusedWithOverlapAgainstExistingConfirmedBooking(): void {
		$start = $this->utc( 2, '10:00' );
		$this->hold( $start, $this->cutId, true );

		try {
			$this->hold( $start, $this->cutId, true );
			self::fail( 'Expected an overlap conflict even in admin mode.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'overlap', $e->reason );
		}
	}

	public function testAdminHoldStillRefusedOutsideOpeningHours(): void {
		try {
			$this->hold( $this->utc( 1, '03:00' ), $this->cutId, true );
			self::fail( 'Expected an outside_hours conflict even in admin mode.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'outside_hours', $e->reason );
		}
	}

	public function testAdminHoldAuditsAdminCreateAndFiresHeldThenConfirmed(): void {
		global $wpdb;
		$fired    = array();
		$onHeld   = static function ( BookingSnapshot $snapshot ) use ( &$fired ): void {
			$fired[] = array( 'held', $snapshot->status );
		};
		$onConfd  = static function ( BookingSnapshot $snapshot ) use ( &$fired ): void {
			$fired[] = array( 'confirmed', $snapshot->status );
		};
		add_action( 'reservant/booking/held', $onHeld );
		add_action( 'reservant/booking/confirmed', $onConfd );

		$booking = $this->hold( $this->utc( 1, '09:00' ), $this->cutId, true );

		remove_action( 'reservant/booking/held', $onHeld );
		remove_action( 'reservant/booking/confirmed', $onConfd );

		self::assertSame( array( array( 'held', 'confirmed' ), array( 'confirmed', 'confirmed' ) ), $fired );

		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'admin' AND a.action = 'admin_create'", // phpcs:ignore WordPress.DB.PreparedSQL
					$booking['uuid']
				)
			)
		);
	}

	/**
	 * Event holds skip lead time / horizon the same way (AGENTS.md Task 6): `validateChainWindow`
	 * is appointment-only, so the event path's own window check (inside `validateEvent`, under the
	 * lock) needs the identical admin escape hatch.
	 */
	public function testAdminEventHoldInsideLeadTimeSucceedsWhereCustomerHoldRefuses(): void {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert(
			array(
				'name'          => 'Seminar',
				'type'          => 'event',
				'price_minor'   => 1000,
				'payment_mode'  => 'onsite',
				'lead_time_min' => 2880,
			)
		);
		$occId   = ( new OccurrenceRepository( $wpdb ) )->insert(
			array(
				'service_id' => $eventId,
				'start_utc'  => $this->sql( 1, '18:00' ),
				'end_utc'    => $this->sql( 1, '20:00' ),
				'capacity'   => 10,
			)
		);

		try {
			HoldBooking::make( $wpdb )->execute(
				new HoldRequest( $this->customer(), null, new EventRequest( $occId, 1 ) ),
				$this->utc( 0 )
			);
			self::fail( 'Expected the ordinary customer hold to be refused inside the lead window.' );
		} catch ( SlotConflict $e ) {
			self::assertSame( 'lead_time', $e->reason );
		}

		$admin = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), null, new EventRequest( $occId, 1 ), true ),
			$this->utc( 0 )
		);
		self::assertSame( 'confirmed', $admin['status'] );
		self::assertNull( $admin['hold_class'] );
		self::assertNull( $admin['hold_expires_at'] );
	}
}
