<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Integrations\WooCommerce;

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
 * The WOOCOMMERCE half of the order observer, against the real thing. Everything asserted here is a
 * claim about WooCommerce's own behaviour or about the live wiring: that `payment_complete()` lands
 * an order on a status `wc_get_is_paid_statuses()` contains, that `update_status()` fires
 * `woocommerce_order_status_changed` synchronously, that the uuid meta written by
 * `WooPaymentProvider::createOrder()` comes back off the order the hook hands over - and that
 * `Plugin::register()` actually wired the observer, since like `BookingEmailsTest` this file never
 * registers it itself. The booking-side consequences (idempotency, what is reported, what is
 * absorbed) are the Reservant half, pinned WC-free in `tests/Integration/Payment/OrderObserverTest`.
 *
 * Skipped rather than failed when WooCommerce is absent, per `WooPaymentProviderTest`.
 */
final class OrderObserverTest extends ReservantTestCase {

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
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$this->staffId   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
	}

	/** The gateway path: `payment_complete()` is what a real gateway calls when the money clears. */
	public function test_payment_complete_confirms_the_booking(): void {
		list( $uuid, $order ) = $this->heldBookingWithOrder();

		$order->payment_complete( 'txn-1' );

		// First the WC claim this whole design leans on: paying an order lands it on a status the
		// observer recognises as paid. If WooCommerce ever changed that, the assertion below this
		// one would fail with no explanation; this one names the actual break.
		self::assertContains( $order->get_status(), wc_get_is_paid_statuses() );
		self::assertSame( 'confirmed', $this->status( $uuid ) );
	}

	/**
	 * `processing` and `completed` are BOTH paid statuses, so one real order can deliver "paid"
	 * twice without anything being wrong - which is exactly why the observer must treat the second
	 * delivery as silence. One confirmation, one email, no incident report.
	 */
	public function test_a_second_paid_transition_confirms_once_and_emails_once(): void {
		list( $uuid, $order ) = $this->heldBookingWithOrder();
		$errors               = $this->countErrors();
		$emails               = $this->countEmails( 'booking_confirmed' );

		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		self::assertSame( 'confirmed', $this->status( $uuid ) );
		self::assertSame( 1, $emails() );
		self::assertSame( 0, $errors() );
	}

	public function test_cancelling_the_order_releases_the_hold(): void {
		list( $uuid, $order ) = $this->heldBookingWithOrder();

		$order->update_status( 'cancelled' );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
	}

	public function test_a_failed_payment_releases_the_hold(): void {
		list( $uuid, $order ) = $this->heldBookingWithOrder();

		$order->update_status( 'failed' );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
	}

	/**
	 * The refund shape: the booking is already `confirmed` when the owner refunds the order in
	 * wp-admin, and a FULL refund (the only one that moves the status) puts the slot back on sale.
	 */
	public function test_refunding_a_paid_order_releases_the_confirmed_booking(): void {
		list( $uuid, $order ) = $this->heldBookingWithOrder();
		$order->update_status( 'processing' );
		self::assertSame( 'confirmed', $this->status( $uuid ) );

		$order->update_status( 'refunded' );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
	}

	/**
	 * A shop sells other things. An order that never came from a booking carries no uuid meta, and
	 * the observer must not touch anything or say anything about it - on payment OR on death.
	 */
	public function test_an_order_with_no_booking_meta_is_ignored(): void {
		list( $uuid ) = $this->heldBookingWithOrder();
		$errors       = $this->countErrors();

		$foreign = wc_create_order( array( 'status' => 'pending' ) );
		self::assertInstanceOf( \WC_Order::class, $foreign );
		$foreign->update_status( 'processing' );
		$foreign->update_status( 'cancelled' );

		self::assertSame( 'pending', $this->status( $uuid ), 'someone else\'s order must not move our booking' );
		self::assertSame( 0, $errors() );
	}

	/**
	 * A pending checkout hold plus the real order `WooPaymentProvider::createOrder()` builds for it,
	 * uuid meta and all - the same pair the non-approval flow produces between "hold" and "pay".
	 *
	 * @return array{0: string, 1: \WC_Order}
	 */
	private function heldBookingWithOrder(): array {
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
		$uuid = (string) $held['uuid'];

		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $booking );
		$orderId = ( new WooPaymentProvider() )->createOrder( $booking );
		self::assertIsInt( $orderId );
		$order = wc_get_order( $orderId );
		self::assertInstanceOf( \WC_Order::class, $order );
		return array( $uuid, $order );
	}

	private function status( string $uuid ): string {
		global $wpdb;
		$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $row );
		return (string) $row['status'];
	}

	/** @return callable(): int the number of `reservant/error` firings since the call */
	private function countErrors(): callable {
		$count = 0;
		add_action(
			'reservant/error',
			static function () use ( &$count ): void {
				++$count;
			}
		);
		return static function () use ( &$count ): int {
			return $count;
		};
	}

	/**
	 * Counts sends of one Reservant email KEY via its args filter. Counting `pre_wp_mail` would be
	 * wrong in THIS suite specifically: WooCommerce mails the customer about the very same order
	 * transitions, on the same guest address, through the same `wp_mail()`.
	 *
	 * @return callable(): int the number of sends of `$key` since the call
	 */
	private function countEmails( string $key ): callable {
		$count = 0;
		add_filter(
			"reservant/email/{$key}/args",
			static function ( $args ) use ( &$count ) {
				++$count;
				return $args;
			}
		);
		return static function () use ( &$count ): int {
			return $count;
		};
	}
}
