<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\ExpireHolds;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

final class LifecycleTest extends ReservantTestCase {

	private int $serviceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services        = new ServiceRepository( $wpdb );
		$resources       = new ResourceRepository( $wpdb );
		$avail           = new AvailabilityRepository( $wpdb );
		$this->serviceId = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'cancel_window_hours' => 24 ) );
		$staff           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/** Books day 1 at $start; "now" is always day 0. @return array<string, mixed> */
	private function holdOne( string $start ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), new AppointmentRequest( $this->utc( 1, $start ), array( new SegmentChoice( $this->serviceId ) ) ) ),
			$this->utc( 0 )
		);
	}

	public function test_confirm_flow(): void {
		global $wpdb;
		$booking   = $this->holdOne( '09:00' );
		$confirmed = ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );
		self::assertSame( 'confirmed', $confirmed['status'] );
		self::assertNull( $confirmed['hold_expires_at'] );
	}

	public function test_cancel_respects_window_and_force_overrides(): void {
		global $wpdb;
		$booking = $this->holdOne( '09:00' );
		ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );
		$cancel = CancelBooking::make( $wpdb );
		try {
			$cancel->execute( $booking['uuid'], $this->utc( 1, '08:00' ) ); // inside 24h window
			self::fail( 'Expected window_closed.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'window_closed', $e->getMessage() );
		}
		$cancelled = $cancel->execute( $booking['uuid'], $this->utc( 1, '08:00' ), true ); // admin force
		self::assertSame( 'cancelled', $cancelled['status'] );
	}

	/**
	 * `DELETE /holds` force-cancels - giving up a reservation nobody has paid for is not
	 * policy-bound - so "held only" cannot be enforced by the controller's read, which happens
	 * outside the lock. A confirm winning that race would have its cancellation window bypassed by
	 * a route that was never allowed to touch a confirmed booking.
	 *
	 * The REST race itself is not deterministically reproducible; the guard it depends on is, and
	 * this is that guard: with `$onlyFromStatuses` set, the status read inside the transaction
	 * decides, and a booking that is no longer held is refused as `not_held`.
	 */
	public function test_a_held_only_release_refuses_a_booking_that_was_confirmed_first(): void {
		global $wpdb;
		$booking = $this->holdOne( '09:00' );
		ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );

		try {
			CancelBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:06' ), true, BookingStatus::heldStatuses() );
			self::fail( 'Expected not_held.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'not_held', $exception->getMessage() );
		}
		self::assertSame( 'confirmed', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );

		// The unrestricted call is the manager's force-cancel and still goes through, so the refusal
		// above is about the route's authority and not a broken transition.
		self::assertSame( 'cancelled', CancelBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:06' ), true )['status'] );
	}

	public function test_expired_hold_is_swept_and_slot_reusable(): void {
		global $wpdb;
		$booking = $this->holdOne( '10:00' );
		// Force the hold into the past, then sweep.
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $booking['uuid'] ) );
		self::assertSame( 1, ExpireHolds::make( $wpdb )->run() );
		// Slot is takeable again.
		self::assertSame( 'pending', $this->holdOne( '10:00' )['status'] );
	}

	public function test_inline_reap_audits_and_notifies_the_hold_it_expires(): void {
		global $wpdb;
		$stale = $this->holdOne( '10:00' );
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $stale['uuid'] ) );

		$notified = array();
		$listener = static function ( array $booking ) use ( &$notified ): void {
			$notified[] = $booking['uuid'];
		};
		add_action( 'reservant/hold/expired', $listener );
		// Taking the slot reaps the stale hold inline; ExpireHolds can never see it afterwards,
		// so this transaction is the only chance to record and announce it.
		$this->holdOne( '10:00' );
		remove_action( 'reservant/hold/expired', $listener );

		self::assertSame( array( $stale['uuid'] ), $notified );
		self::assertSame( 'expired', ( new BookingRepository( $wpdb ) )->findByUuid( $stale['uuid'] )['status'] );
		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'system' AND a.action = 'expired'", // phpcs:ignore WordPress.DB.PreparedSQL
					$stale['uuid']
				)
			)
		);
	}
}
