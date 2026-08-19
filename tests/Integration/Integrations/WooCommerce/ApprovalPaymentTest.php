<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Integrations\WooCommerce;

use Reservant\Application\ApproveBooking;
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
use Reservant\Integrations\WooCommerce\WooPaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The approve -> payment path against REAL WooCommerce (AGENTS.md section 6, "Approval flow: no
 * order exists until approval"). The use-case behaviour - which status the approval lands on, the
 * TTL, the failure handling - is pinned WC-free in `Application/ApprovePaymentTest`; what this file
 * claims is the live wiring and WooCommerce's half of it: that a real order comes out of the
 * approval carrying the booking's uuid meta and total, that its checkout-payment URL is what the
 * provider reports, and that PAYING it walks the whole loop back to `confirmed` through the order
 * observer that bootstrap wired.
 *
 * Skipped rather than failed when WooCommerce is absent, per `WooPaymentProviderTest`.
 */
final class ApprovalPaymentTest extends ReservantTestCase {

	private int $serviceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::markTestSkipped( 'WooCommerce is not installed in this container.' );
		}
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId = $services->insert(
			array(
				'name'                => 'Online Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 4550,
				'payment_mode'        => 'online',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->staffId   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
	}

	public function test_approving_creates_a_real_pending_order_that_carries_the_booking(): void {
		global $wpdb;
		$uuid = $this->heldUuid();

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'awaiting_payment', (string) $approved['status'] );
		$orderId = (int) $approved['wc_order_id'];
		self::assertGreaterThan( 0, $orderId );

		$order = wc_get_order( $orderId );
		self::assertInstanceOf( \WC_Order::class, $order );
		// Created UNPAID: paying it is the customer's job, and the paid transition is what the
		// observer turns back into a confirmation.
		self::assertSame( 'pending', $order->get_status() );
		self::assertSame( $uuid, (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META ) );
		// 4550 minor in a 2-exponent currency is 45.50 major - priced off the booking's items.
		self::assertSame( 45.5, (float) $order->get_total() );
		self::assertSame( 'maria@example.com', $order->get_billing_email() );
	}

	/** The emailed link is WooCommerce's own pay-for-order URL, reported through the provider seam. */
	public function test_the_payment_url_is_the_orders_checkout_payment_url(): void {
		global $wpdb;
		$uuid = $this->heldUuid();

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		$orderId  = (int) $approved['wc_order_id'];

		$order = wc_get_order( $orderId );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame(
			$order->get_checkout_payment_url(),
			( new WooPaymentProvider() )->paymentUrl( $orderId )
		);
	}

	/**
	 * The whole loop, on live wiring: approve mints the order, a gateway settles it
	 * (`payment_complete()`), and the observer bootstrap registered lands the booking `confirmed`
	 * with its payment hold released.
	 */
	public function test_paying_the_approval_order_confirms_the_booking(): void {
		global $wpdb;
		$uuid = $this->heldUuid();

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		$order    = wc_get_order( (int) $approved['wc_order_id'] );
		self::assertInstanceOf( \WC_Order::class, $order );

		$order->payment_complete( 'txn-approval-1' );

		$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $row );
		self::assertSame( 'confirmed', (string) $row['status'] );
		self::assertNull( $row['hold_expires_at'] );
	}

	/** Holds the approval-gated online appointment; lands awaiting_approval. */
	private function heldUuid(): string {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest(
					$this->utc( 1, '10:00' ),
					array( new SegmentChoice( $this->serviceId, $this->staffId ) )
				)
			),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', (string) $held['status'] );
		return (string) $held['uuid'];
	}
}
