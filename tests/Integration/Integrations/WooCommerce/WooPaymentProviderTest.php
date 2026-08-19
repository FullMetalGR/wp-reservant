<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Integrations\WooCommerce;

use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Integrations\WooCommerce\WooPaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The slow half of P7's two-layer test strategy: the provider against REAL WooCommerce.
 *
 * Everything here is a claim about WooCommerce's own behaviour rather than about Reservant's logic -
 * that a virtual product skips shipping, that `add_product()` prices a line from the product unless
 * overridden, that `delete( false )` trashes rather than purges. A fake provider cannot be wrong
 * about any of those, which is exactly why it cannot verify them either. The trash case is not a
 * hypothetical: the first version of this file asserted a purge, and the real thing said otherwise.
 *
 * Skipped rather than failed when WooCommerce is absent: `.wp-env.json` installs it, but a container
 * built before that line existed should run the rest of the suite instead of collapsing.
 */
final class WooPaymentProviderTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::markTestSkipped( 'WooCommerce is not installed in this container.' );
		}
	}

	public function test_an_online_service_mirrors_to_a_hidden_virtual_product(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert(
			array(
				'name'         => 'Deluxe Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 4550,
				'payment_mode' => PaymentMode::Online->value,
			)
		);

		$productId = ( new WooPaymentProvider() )->syncService( (array) $services->find( $serviceId ) );
		self::assertIsInt( $productId );

		$product = wc_get_product( $productId );
		self::assertInstanceOf( \WC_Product::class, $product );
		self::assertSame( 'Deluxe Cut', $product->get_name() );
		self::assertTrue( $product->is_virtual(), 'a booking is not shipped' );
		self::assertSame( 'hidden', $product->get_catalog_visibility(), 'time is sold through the widget, not the shop' );
		// 4550 minor units in a 2-exponent currency is 45.50 major - the mirror must not ship the
		// integer through as 4550 euros.
		self::assertSame( '45.5', $product->get_regular_price() );
		self::assertSame( (string) $serviceId, (string) $product->get_meta( WooPaymentProvider::PRODUCT_SERVICE_META ) );
	}

	/** A resync updates the same product rather than accumulating a new one per save. */
	public function test_resyncing_reuses_the_same_product(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 1000,
				'payment_mode' => PaymentMode::Online->value,
			)
		);
		$provider = new WooPaymentProvider();

		$first = $provider->syncService( (array) $services->find( $serviceId ) );
		$services->update( $serviceId, array( 'wc_product_id' => $first, 'price_minor' => 2000, 'name' => 'Cut Deluxe' ) );
		$second = $provider->syncService( (array) $services->find( $serviceId ) );

		self::assertSame( $first, $second, 'a resync must not orphan the old product and make a new one' );
		$product = wc_get_product( (int) $second );
		self::assertInstanceOf( \WC_Product::class, $product );
		self::assertSame( 'Cut Deluxe', $product->get_name(), 'the mirror follows the service' );
		self::assertSame( '20', $product->get_regular_price() );
	}

	/**
	 * Switching away from `online` clears the id and retires the mirror.
	 *
	 * Retired means TRASHED, not purged, and this test says so explicitly because the distinction
	 * is invisible from the calling code and easy to "tidy" into a force delete: a past order that
	 * bought this service still points a line item at the product, and WooCommerce renders that line
	 * from the product when it can. So `wc_get_product()` still returns an object here - what
	 * changes is its status.
	 */
	public function test_leaving_online_mode_removes_the_mirror(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 1000,
				'payment_mode' => PaymentMode::Online->value,
			)
		);
		$provider  = new WooPaymentProvider();
		$productId = (int) $provider->syncService( (array) $services->find( $serviceId ) );

		$services->update( $serviceId, array( 'wc_product_id' => $productId, 'payment_mode' => PaymentMode::Onsite->value ) );
		self::assertNull( $provider->syncService( (array) $services->find( $serviceId ) ) );
		self::assertSame( 'trash', get_post_status( $productId ), 'the mirror should be retired, not purged' );
	}

	/**
	 * The guard that keeps a resync from vandalising the shop: a product this plugin did not create
	 * carries no `_reservant_service_id`, and must survive a service being switched to onsite even
	 * if the id happens to match.
	 */
	public function test_a_product_we_did_not_create_is_never_deleted(): void {
		$foreign = new \WC_Product_Simple();
		$foreign->set_name( 'A real thing the shop sells' );
		$foreignId = (int) $foreign->save();

		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert(
			array(
				'name'          => 'Cut',
				'type'          => 'appointment',
				'duration_min'  => 30,
				'payment_mode'  => PaymentMode::Onsite->value,
				'wc_product_id' => $foreignId,
			)
		);

		self::assertNull( ( new WooPaymentProvider() )->syncService( (array) $services->find( $serviceId ) ) );
		self::assertInstanceOf( \WC_Product::class, wc_get_product( $foreignId ), 'someone else\'s product must survive' );
	}

	/**
	 * An open-capacity event booking bills seats x price ONCE. `price_minor` on the booking item is
	 * already the line total (`HoldBooking::planEvent()` stores `price * seats`), and the first
	 * version of `addLine()` multiplied it by the quantity again - an order for three seats at
	 * 10.00 came out at 90.00. The chain total on the booking itself is the number the customer
	 * agreed to, so the order must equal it exactly.
	 */
	public function test_an_open_event_order_bills_the_seats_once(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert(
			array(
				'name'         => 'Seminar',
				'type'         => 'event',
				'price_minor'  => 1000,
				'payment_mode' => PaymentMode::Online->value,
			)
		);
		$occId     = ( new OccurrenceRepository( $wpdb ) )->insert(
			array(
				'service_id' => $serviceId,
				'start_utc'  => $this->sql( 1, '18:00' ),
				'end_utc'    => $this->sql( 1, '20:00' ),
				'capacity'   => 10,
			)
		);

		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				null,
				new EventRequest( $occId, 3 )
			),
			$this->utc( 0 )
		);
		self::assertSame( 3000, (int) $held['total_minor'] );

		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $held['uuid'] );
		self::assertNotNull( $booking );
		$orderId = ( new WooPaymentProvider() )->createOrder( $booking );
		self::assertIsInt( $orderId );

		$order = wc_get_order( $orderId );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( 30.0, (float) $order->get_total(), 'three seats at 10.00 is 30.00, not 90.00' );
	}
}
