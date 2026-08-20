<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Payment;

use Reservant\Application\ApproveBooking;
use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Integrations\WooCommerce\CheckoutGuard;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The RESERVANT half of the checkout guard: which bookings may pay, decided by
 * `CheckoutGuard::refusal()` under the section-2.2 locks, with no WooCommerce door in the loop -
 * the fast/slow split of `Payment/OrderObserverTest` vs the real-WC suite, for the same reason.
 * Whether WooCommerce actually asks this question at every door into payment is the other half's
 * claim (`Integrations/WooCommerce/CheckoutGuardTest`, gateway probe and all).
 *
 * The payable set is deliberately NOT `heldStatuses()`: `awaiting_approval` is held and blocking
 * but may not pay - no money moves before a human says yes (AGENTS.md section 1) - while
 * `confirmed` is not held at all and may: the slot is already the guest's, and a site running
 * `reservant/allow_direct_confirm` collects after confirmation. Both directions are pinned here.
 */
final class CheckoutGuardTest extends ReservantTestCase {

	private int $serviceId;
	private int $approvalServiceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId         = $services->insert(
			array(
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$this->approvalServiceId = $services->insert(
			array(
				'name'              => 'Online Consultation',
				'type'              => 'appointment',
				'duration_min'      => 30,
				'price_minor'       => 4000,
				'payment_mode'      => 'online',
				'requires_approval' => 1,
			)
		);
		$this->staffId           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		$resources->linkService( $this->approvalServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
	}

	public function test_a_live_checkout_hold_may_pay(): void {
		$uuid = $this->pendingUuid();

		self::assertNull( $this->guard()->refusal( $uuid, $this->utc( 0 ) ) );
	}

	public function test_a_lapsed_checkout_hold_is_hold_expired(): void {
		$uuid = $this->pendingUuid();
		$this->lapse( $uuid );

		self::assertSame( 'hold_expired', $this->guard()->refusal( $uuid, $this->utc( 0 ) ) );
	}

	public function test_a_live_awaiting_payment_hold_may_pay(): void {
		$uuid = $this->awaitingPaymentUuid();

		self::assertNull( $this->guard()->refusal( $uuid, $this->utc( 0, '02:00' ) ) );
	}

	public function test_a_lapsed_awaiting_payment_hold_is_hold_expired(): void {
		$uuid = $this->awaitingPaymentUuid();
		$this->lapse( $uuid );

		self::assertSame( 'hold_expired', $this->guard()->refusal( $uuid, $this->utc( 0, '02:00' ) ) );
	}

	/** Held and blocking, but no money moves before a human says yes. */
	public function test_awaiting_approval_may_not_pay(): void {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute(
			$this->request( $this->approvalServiceId, 2 ),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', (string) $held['status'] );

		self::assertSame( 'not_payable', $this->guard()->refusal( (string) $held['uuid'], $this->utc( 0 ) ) );
	}

	public function test_a_cancelled_booking_may_not_pay(): void {
		global $wpdb;
		$uuid = $this->pendingUuid();
		CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0 ), true );

		self::assertSame( 'not_payable', $this->guard()->refusal( $uuid, $this->utc( 0 ) ) );
	}

	/** The slot is already theirs; whether money is still owed for it is the site's business. */
	public function test_a_confirmed_booking_may_pay(): void {
		global $wpdb;
		$uuid = $this->pendingUuid();
		ConfirmBooking::make( $wpdb )->execute( $uuid, $this->utc( 0 ), null, true );

		self::assertNull( $this->guard()->refusal( $uuid, $this->utc( 0 ) ) );
	}

	public function test_an_unknown_booking_may_not_pay(): void {
		self::assertSame( 'not_payable', $this->guard()->refusal( '00000000-0000-0000-0000-000000000000', $this->utc( 0 ) ) );
	}

	/** The cart-door line check: the cart must still say what the booking says, item for item. */
	public function test_matching_cart_lines_pass_and_tampered_ones_do_not(): void {
		global $wpdb;
		$uuid    = $this->pendingUuid();
		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $booking );
		$itemId = (int) $booking['items'][0]['id'];

		self::assertNull( $this->guard()->refusal( $uuid, $this->utc( 0 ), array( $itemId => 1 ) ) );
		self::assertSame( 'cart_mismatch', $this->guard()->refusal( $uuid, $this->utc( 0 ), array( $itemId => 2 ) ), 'a quantity edited down or up must refuse' );
		self::assertSame( 'cart_mismatch', $this->guard()->refusal( $uuid, $this->utc( 0 ), array() ), 'a cart missing the booking item must refuse' );
		self::assertSame( 'cart_mismatch', $this->guard()->refusal( $uuid, $this->utc( 0 ), array( $itemId + 999 => 1 ) ), 'a cart naming the wrong item must refuse' );
	}

	private function guard(): CheckoutGuard {
		global $wpdb;
		return CheckoutGuard::make( $wpdb );
	}

	private function pendingUuid(): string {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute( $this->request( $this->serviceId, 1 ), $this->utc( 0 ) );
		self::assertSame( 'pending', (string) $held['status'] );
		return (string) $held['uuid'];
	}

	private function awaitingPaymentUuid(): string {
		global $wpdb;
		$held     = HoldBooking::make( $wpdb )->execute( $this->request( $this->approvalServiceId, 3 ), $this->utc( 0 ) );
		$approved = ApproveBooking::make( $wpdb )->execute( (string) $held['uuid'], $this->utc( 0, '01:00' ), 'admin' );
		self::assertSame( 'awaiting_payment', (string) $approved['status'] );
		return (string) $held['uuid'];
	}

	private function request( int $serviceId, int $day ): HoldRequest {
		return new HoldRequest(
			new Customer( 'Maria', 'maria@example.com' ),
			new AppointmentRequest( $this->utc( $day, '10:00' ), array( new SegmentChoice( $serviceId, $this->staffId ) ) )
		);
	}

	private function lapse( string $uuid ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
	}
}
