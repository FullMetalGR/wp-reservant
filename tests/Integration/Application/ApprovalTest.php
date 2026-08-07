<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\ApproveBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\MarkBookingOutcome;
use Reservant\Application\RejectBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * ApproveBooking / RejectBooking / MarkBookingOutcome (AGENTS.md "Approval queue").
 */
final class ApprovalTest extends ReservantTestCase {

	private int $approvalServiceId;
	private int $plainServiceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->approvalServiceId = $services->insert(
			array(
				'name'                => 'Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->plainServiceId    = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2000,
				'payment_mode' => 'onsite',
			)
		);
		$staff                   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->approvalServiceId, $staff );
		$resources->linkService( $this->plainServiceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/** Holds the approval-required appointment service; lands awaiting_approval. @return array<string, mixed> */
	private function holdAwaitingApproval(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->approvalServiceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/** Holds the plain (no-approval) appointment service; lands pending. @return array<string, mixed> */
	private function holdPending(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '11:00' ), array( new SegmentChoice( $this->plainServiceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	public function testApproveConfirmsAndAudits(): void {
		global $wpdb;
		$booking    = $this->holdAwaitingApproval();
		$adminUser  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$notified = array();
		$listener = static function ( BookingSnapshot $snapshot ) use ( &$notified ): void {
			$notified[] = $snapshot;
		};
		add_action( 'reservant/booking/approved', $listener );
		// `$actor` ('admin') is the free-form audit label; `$adminUser` is the WP user id that
		// lands in the BIGINT `approved_by` column - the two are deliberately different shapes.
		$approved = ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin', $adminUser );
		remove_action( 'reservant/booking/approved', $listener );

		self::assertSame( 'confirmed', $approved['status'] );
		self::assertNull( $approved['hold_class'] );
		self::assertNull( $approved['hold_expires_at'] );
		self::assertSame( $adminUser, (int) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['approved_by'] );

		self::assertCount( 1, $notified );
		self::assertSame( $booking['uuid'], $notified[0]->uuid );
		self::assertSame( 'confirmed', $notified[0]->status );

		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'admin' AND a.action = 'approve'", // phpcs:ignore WordPress.DB.PreparedSQL
					$booking['uuid']
				)
			)
		);
	}

	/**
	 * A system/signed-link approval has no WP user behind it: `$actorUserId` is omitted, so the
	 * BIGINT `approved_by` column is left a real SQL NULL rather than coerced from the free-form
	 * `$actor` label - `approved_by` is a FK, never the audit text.
	 */
	public function testApproveWithoutActorUserIdLeavesApprovedByNull(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();

		$approved = ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'signed-link' );

		self::assertSame( 'confirmed', $approved['status'] );
		self::assertNull( ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['approved_by'] );

		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'signed-link' AND a.action = 'approve'", // phpcs:ignore WordPress.DB.PreparedSQL
					$booking['uuid']
				)
			)
		);
	}

	public function testApproveExpiredWindowRefuses(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $booking['uuid'] ) );

		try {
			ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), '7' );
			self::fail( 'Expected not_approvable.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'not_approvable', $e->getMessage() );
		}
		self::assertSame( 'awaiting_approval', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );
	}

	public function testRejectStopsBlocking(): void {
		global $wpdb;
		$seatMaps    = new SeatMapRepository( $wpdb );
		$occurrences = new OccurrenceRepository( $wpdb );
		$services    = new ServiceRepository( $wpdb );

		$spec    = 'rows A-B, 4 per row';
		$mapId   = $seatMaps->insert( 'Hall', $spec );
		$seatIds = array();
		$cells   = SeatMapSpec::parse( $spec )->seats();
		foreach ( $seatMaps->insertSeats( $mapId, $cells ) as $index => $seatId ) {
			if ( 'seat' === $cells[ $index ]->kind ) {
				$seatIds[] = $seatId;
			}
		}
		$eventId = $services->insert(
			array(
				'name'                => 'GridShow',
				'type'                => 'event',
				'capacity'            => 8,
				'seat_map_id'         => $mapId,
				'price_minor'         => 1000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$occId   = $occurrences->insert(
			array(
				'service_id' => $eventId,
				'start_utc'  => $this->sql( 10, '18:00' ),
				'end_utc'    => $this->sql( 10, '20:00' ),
				'capacity'   => 8,
			)
		);

		global $wpdb;
		$hold    = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), null, new EventRequest( $occId, 1, array( $seatIds[0] ) ) ),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', $hold['status'] );

		// A rival hold on the same seat is blocked while the first booking is still pending
		// approval - that is what "blocking" means for an awaiting_approval row.
		try {
			HoldBooking::make( $wpdb )->execute(
				new HoldRequest( $this->customer(), null, new EventRequest( $occId, 1, array( $seatIds[0] ) ) ),
				$this->utc( 0 )
			);
			self::fail( 'Expected the seat to be taken before the reject.' );
		} catch ( \Reservant\Application\SlotConflict $e ) {
			self::assertSame( 'seat_taken', $e->reason );
		}

		$rejected = RejectBooking::make( $wpdb )->execute( $hold['uuid'], 'no_show_history', $this->utc( 0, '01:00' ), 'admin' );
		self::assertSame( 'rejected', $rejected['status'] );
		self::assertSame( 'no_show_history', $rejected['rejection_reason'] );

		$freed = ( new BookingRepository( $wpdb ) )->findByUuid( $hold['uuid'] );
		self::assertNull( $freed['items'][0]['seat_claim'] );

		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'admin' AND a.action = 'reject'", // phpcs:ignore WordPress.DB.PreparedSQL
					$hold['uuid']
				)
			)
		);

		// The rival hold on the same seat now succeeds - the claim was actually released.
		$rival = HoldBooking::make( $wpdb )->execute(
			new HoldRequest( $this->customer(), null, new EventRequest( $occId, 1, array( $seatIds[0] ) ) ),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', $rival['status'] );
	}

	public function testOutcomeOnlyFromConfirmed(): void {
		global $wpdb;
		$pending = $this->holdPending();
		try {
			MarkBookingOutcome::make( $wpdb )->execute( $pending['uuid'], 'completed', 'admin' );
			self::fail( 'Expected stale_state.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'stale_state', $e->getMessage() );
		}

		$confirmed = ConfirmBooking::make( $wpdb )->execute( $pending['uuid'], $this->utc( 0, '00:05' ) );
		self::assertSame( 'confirmed', $confirmed['status'] );

		$notified = array();
		$listener = static function ( BookingSnapshot $snapshot ) use ( &$notified ): void {
			$notified[] = $snapshot;
		};
		add_action( 'reservant/booking/completed', $listener );
		$outcome = MarkBookingOutcome::make( $wpdb )->execute( $pending['uuid'], 'completed', 'admin' );
		remove_action( 'reservant/booking/completed', $listener );

		self::assertSame( 'completed', $outcome['status'] );
		self::assertCount( 1, $notified );
		self::assertSame( $pending['uuid'], $notified[0]->uuid );
		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'admin' AND a.action = 'completed'", // phpcs:ignore WordPress.DB.PreparedSQL
					$pending['uuid']
				)
			)
		);
	}

	public function testMarkBookingOutcomeRejectsBadOutcome(): void {
		global $wpdb;
		$pending   = $this->holdPending();
		$confirmed = ConfirmBooking::make( $wpdb )->execute( $pending['uuid'], $this->utc( 0, '00:05' ) );
		self::assertSame( 'confirmed', $confirmed['status'] );

		try {
			MarkBookingOutcome::make( $wpdb )->execute( $pending['uuid'], 'bogus', 'admin' );
			self::fail( 'Expected bad_outcome.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'bad_outcome', $e->getMessage() );
		}
	}

	public function testMarkBookingOutcomeNoShow(): void {
		global $wpdb;
		$pending   = $this->holdPending();
		ConfirmBooking::make( $wpdb )->execute( $pending['uuid'], $this->utc( 0, '00:05' ) );

		$outcome = MarkBookingOutcome::make( $wpdb )->execute( $pending['uuid'], 'no_show', 'admin' );
		self::assertSame( 'no_show', $outcome['status'] );
	}
}
