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
use Reservant\Integrations\WooCommerce\CartBridge;
use Reservant\Integrations\WooCommerce\WooPaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The cart half of the non-approval flow (AGENTS.md section 6: "hold -> cart -> order paid ->
 * `ConfirmBooking`"), against real WooCommerce. Everything here is a claim about the live wiring or
 * about WooCommerce's own behaviour: that a boarded hold reads as one cart line per booking item
 * with the booking's price winning over the mirror's, that the order classic checkout builds from
 * that cart carries `ORDER_UUID_META` and pays the booking's total, that paying it walks the loop
 * back to `confirmed` through the observer, and that removing a line - the release carve-out this
 * class owns - gives the slot back while WooCommerce's undo cannot resurrect it.
 *
 * Skipped rather than failed when WooCommerce is absent, per `WooPaymentProviderTest`.
 */
final class CartBridgeTest extends ReservantTestCase {

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
		// Mirror the service so boarding has a product to hang lines on, and store the id the way
		// the admin save path would.
		$productId = ( new WooPaymentProvider() )->syncService( (array) $services->find( $this->serviceId ) );
		$services->update( $this->serviceId, array( 'wc_product_id' => $productId ) );

		$this->freshCart();
	}

	public function tear_down(): void {
		unset( $_GET[ CartBridge::QUERY_UUID ], $_GET['token'] );
		if ( function_exists( 'wc_clear_notices' ) && isset( WC()->session ) ) {
			wc_clear_notices();
		}
		parent::tear_down();
	}

	public function test_boarding_puts_one_cart_line_per_booking_item_with_the_booking_uuid(): void {
		$booking = $this->heldBooking( 2 );

		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );

		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 2, $cart->get_cart() );

		$itemIds = array();
		foreach ( $cart->get_cart() as $line ) {
			$facts = CartBridge::lineFacts( $line );
			self::assertNotNull( $facts, 'every boarded line must carry its booking facts' );
			self::assertSame( (string) $booking['uuid'], (string) $facts['booking_uuid'] );
			$itemIds[] = (int) $facts['item_id'];
		}
		$expected = array_map( static fn ( array $item ): int => (int) $item['id'], $booking['items'] );
		sort( $itemIds );
		sort( $expected );
		self::assertSame( $expected, $itemIds, 'one cart line per booking item, each naming its own item' );
	}

	/**
	 * The mirror is a mirror: a price change between hold and cart must not re-price a booking the
	 * customer already saw a total for. The product is deliberately made expensive AFTER the hold,
	 * and the cart must keep charging what was held.
	 */
	public function test_the_cart_charges_the_bookings_price_not_the_products(): void {
		global $wpdb;
		$booking = $this->heldBooking();

		$services = new ServiceRepository( $wpdb );
		$services->update( $this->serviceId, array( 'price_minor' => 9900 ) );
		( new WooPaymentProvider() )->syncService( (array) $services->find( $this->serviceId ) );

		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		$cart->calculate_totals();

		self::assertSame( 25.0, (float) $cart->get_total( 'edit' ), 'the booking held 25.00; the mirror now saying 99.00 changes nothing' );
	}

	/**
	 * The whole non-approval loop on live wiring: board, let classic checkout build the order, pay
	 * it, and the observer lands the booking `confirmed`. The order must carry the uuid meta -
	 * without it the observer ignores the payment - and must bill the booking's own total.
	 */
	public function test_checkout_produces_the_bookings_one_order_and_paying_it_confirms(): void {
		global $wpdb;
		$booking = $this->heldBooking();
		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );

		$orderId = WC()->checkout()->create_order(
			array(
				'payment_method'     => '',
				'billing_email'      => 'maria@example.com',
				'billing_first_name' => 'Maria',
			)
		);
		self::assertIsInt( $orderId );
		$order = wc_get_order( $orderId );
		self::assertInstanceOf( \WC_Order::class, $order );

		self::assertSame( (string) $booking['uuid'], (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META ) );
		self::assertCount( 1, $order->get_items(), 'one line item per booking item' );
		self::assertSame( 25.0, (float) $order->get_total() );

		$stored = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertNotNull( $stored );
		self::assertSame( $orderId, (int) $stored['wc_order_id'], 'one WC order per booking, remembered on the booking' );

		$order->payment_complete( 'txn-cart-1' );
		self::assertSame( 'confirmed', $this->status( (string) $booking['uuid'] ) );
	}

	/** The release carve-out: a removed cart line is the guest walking away, and the slot goes back. */
	public function test_removing_a_cart_line_releases_the_booking(): void {
		$booking = $this->heldBooking();
		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );
		$cart = WC()->cart;
		self::assertNotNull( $cart );

		$keys = array_keys( $cart->get_cart() );
		$cart->remove_cart_item( (string) $keys[0] );

		self::assertSame( 'cancelled', $this->status( (string) $booking['uuid'] ) );
	}

	/**
	 * Cancellation granularity is the container (AGENTS.md section 1): removing ONE segment of a
	 * chain releases the whole booking and sweeps its sibling lines, so a cart holding half a chain
	 * can never reach checkout - and the sweep's repeat releases are absorbed, not reported.
	 */
	public function test_removing_one_line_of_a_chain_releases_the_whole_booking(): void {
		$booking = $this->heldBooking( 2 );
		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 2, $cart->get_cart() );
		$errors = $this->countErrors();

		$keys = array_keys( $cart->get_cart() );
		$cart->remove_cart_item( (string) $keys[0] );

		self::assertSame( 'cancelled', $this->status( (string) $booking['uuid'] ) );
		self::assertCount( 0, $cart->get_cart(), 'the sibling line must not survive its booking' );
		self::assertSame( 0, $errors() );
	}

	/**
	 * WooCommerce's "Undo?" cannot resurrect a booking line: the removal released the slot, and by
	 * the time of the undo it may be someone else's. The restored line is removed again and the
	 * booking stays released.
	 */
	public function test_a_removed_line_cannot_be_restored(): void {
		$booking = $this->heldBooking();
		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		$keys = array_keys( $cart->get_cart() );
		$cart->remove_cart_item( (string) $keys[0] );

		$cart->restore_cart_item( (string) $keys[0] );

		self::assertCount( 0, $cart->get_cart(), 'a restored booking line must be removed again' );
		self::assertSame( 'cancelled', $this->status( (string) $booking['uuid'] ) );
	}

	/**
	 * One order per booking means one BOOKING per cart: boarding a second booking evicts the first
	 * booking's lines, and the eviction releases it - starting a new checkout IS abandoning the old
	 * one.
	 */
	public function test_boarding_a_second_booking_evicts_and_releases_the_first(): void {
		$first  = $this->heldBooking( 1, '10:00' );
		$bridge = new CartBridge();
		$bridge->boardCart( $first, $this->utc( 0 ) );

		$second = $this->heldBooking( 1, '14:00' );
		$bridge->boardCart( $second, $this->utc( 0 ) );

		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 1, $cart->get_cart() );
		foreach ( $cart->get_cart() as $line ) {
			$facts = CartBridge::lineFacts( $line );
			self::assertNotNull( $facts );
			self::assertSame( (string) $second['uuid'], (string) $facts['booking_uuid'] );
		}
		self::assertSame( 'cancelled', $this->status( (string) $first['uuid'] ) );
		self::assertSame( 'pending', $this->status( (string) $second['uuid'] ) );
	}

	/** The front-channel entry: the link boards the cart and hands the guest to checkout. */
	public function test_the_checkout_link_boards_the_cart_and_redirects_to_checkout(): void {
		$booking = $this->heldBooking();
		$bridge  = $this->enteringBridge( $landed );

		$_GET[ CartBridge::QUERY_UUID ] = (string) $booking['uuid'];
		$_GET['token']                  = (string) $booking['manage_token'];
		$bridge->maybeEnter();

		self::assertSame( wc_get_checkout_url(), $landed );
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 1, $cart->get_cart() );
	}

	/**
	 * A wrong token and a missing booking answer identically - a silent redirect home, no cart, no
	 * notice - so the entry is not a booking-existence oracle (the `Frontend\ManageRoute` rule).
	 */
	public function test_a_wrong_token_neither_boards_nor_tells(): void {
		$booking = $this->heldBooking();
		$bridge  = $this->enteringBridge( $landedWrongToken );

		$_GET[ CartBridge::QUERY_UUID ] = (string) $booking['uuid'];
		$_GET['token']                  = 'not-the-token';
		$bridge->maybeEnter();

		$bridge2                        = $this->enteringBridge( $landedNoBooking );
		$_GET[ CartBridge::QUERY_UUID ] = '00000000-0000-0000-0000-000000000000';
		$_GET['token']                  = 'anything';
		$bridge2->maybeEnter();

		self::assertSame( home_url( '/' ), $landedWrongToken );
		self::assertSame( $landedWrongToken, $landedNoBooking, 'wrong token and no booking must be indistinguishable' );
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 0, $cart->get_cart() );
		self::assertSame( 'pending', $this->status( (string) $booking['uuid'] ), 'a failed entry must not touch the hold' );
	}

	/**
	 * Approval flow: NO order exists until approval (AGENTS.md section 6), and a cart line would
	 * become an order at checkout - so an `awaiting_approval` booking never boards, and no order
	 * carrying its uuid exists anywhere.
	 */
	public function test_an_approval_hold_never_boards_the_cart(): void {
		global $wpdb;
		$services   = new ServiceRepository( $wpdb );
		$approvalId = $services->insert(
			array(
				'name'              => 'Approval Consultation',
				'type'              => 'appointment',
				'duration_min'      => 30,
				'price_minor'       => 4000,
				'payment_mode'      => 'online',
				'requires_approval' => 1,
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $approvalId, $this->staffId );
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 2, '10:00' ), array( new SegmentChoice( $approvalId, $this->staffId ) ) )
			),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', (string) $held['status'] );

		$bridge                         = $this->enteringBridge( $landed );
		$_GET[ CartBridge::QUERY_UUID ] = (string) $held['uuid'];
		$_GET['token']                  = (string) $held['manage_token'];
		$bridge->maybeEnter();

		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 0, $cart->get_cart() );
		foreach ( wc_get_orders( array( 'limit' => -1 ) ) as $order ) {
			self::assertNotSame(
				(string) $held['uuid'],
				(string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META ),
				'no order may exist before approval'
			);
		}
		self::assertSame( 'awaiting_approval', $this->status( (string) $held['uuid'] ), 'the refusal must not disturb the approval hold' );
	}

	/** The TTL is the authority: a lapsed hold is refused at the cart door, with the expiry sentence. */
	public function test_a_lapsed_hold_is_refused_at_the_cart_door(): void {
		global $wpdb;
		$booking = $this->heldBooking();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => (string) $booking['uuid'] ) );

		$bridge                         = $this->enteringBridge( $landed );
		$_GET[ CartBridge::QUERY_UUID ] = (string) $booking['uuid'];
		$_GET['token']                  = (string) $booking['manage_token'];
		$bridge->maybeEnter();

		$cart = WC()->cart;
		self::assertNotNull( $cart );
		self::assertCount( 0, $cart->get_cart() );
		self::assertGreaterThan( 0, wc_notice_count( 'error' ), 'the guest must be told, not silently bounced' );
	}

	/** A free or onsite booking has nothing to pay online; the cart refuses it. */
	public function test_a_booking_that_needs_no_online_payment_never_boards(): void {
		global $wpdb;
		$onsiteId = ( new ServiceRepository( $wpdb ) )->insert(
			array(
				'name'         => 'Onsite Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'onsite',
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $onsiteId, $this->staffId );
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 3, '10:00' ), array( new SegmentChoice( $onsiteId, $this->staffId ) ) )
			),
			$this->utc( 0 )
		);

		$row = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $held['uuid'] );
		self::assertNotNull( $row );
		try {
			( new CartBridge() )->boardCart( $row, $this->utc( 0 ) );
			self::fail( 'An onsite booking must not board the cart.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'not_boardable', $e->getMessage() );
		}
	}

	/**
	 * A held non-approval online booking, its `manage_token` still on the array, plus the stored
	 * row + items shape `boardCart()` takes.
	 *
	 * @return array<string, mixed>
	 */
	private function heldBooking( int $segments = 1, string $time = '10:00' ): array {
		global $wpdb;
		$choices = array();
		foreach ( range( 1, $segments ) as $ignored ) {
			$choices[] = new SegmentChoice( $this->serviceId, $this->staffId );
		}
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, $time ), $choices )
			),
			$this->utc( 0 )
		);
		self::assertSame( 'pending', (string) $held['status'] );

		$stored = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $held['uuid'] );
		self::assertNotNull( $stored );
		$stored['manage_token'] = (string) $held['manage_token'];
		return $stored;
	}

	/** A bridge whose leave() records where the guest was sent instead of exiting the process. */
	private function enteringBridge( ?string &$landed ): CartBridge {
		$landed = null;
		return new CartBridge(
			static function ( string $url ) use ( &$landed ): void {
				$landed = $url;
			}
		);
	}

	/** A fresh, empty cart for this test - boarding claims the whole cart, so tests must not share one. */
	private function freshCart(): void {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		self::assertNotNull( $cart );
		$cart->empty_cart();
		$cart->removed_cart_contents = array();
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
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
}
