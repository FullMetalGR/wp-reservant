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
use Reservant\Integrations\WooCommerce\CartBridge;
use Reservant\Integrations\WooCommerce\CheckoutGuard;
use Reservant\Integrations\WooCommerce\WooPaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The checkout guard against REAL WooCommerce - the "fail loudly rather than silently overbook"
 * half of AGENTS.md section 6. The headline claim is the money one: the pay-for-order link
 * `ApproveBooking` emails goes through `WC_Form_Handler::pay_action()`, and a lapsed hold must stop
 * the GATEWAY from ever being reached - `RecordingGateway` below counts its `process_payment()`
 * calls, so "the guest cannot be charged for a slot they no longer hold" is asserted as a zero, on
 * WooCommerce's own code path, not on a hook fired by hand. The live-hold twin proves the guard
 * does not block money the site is owed.
 *
 * The cart doors (classic checkout and Store API) are driven through their handlers with the real
 * cart `CartBridge` boarded, including the quantity-tamper case: a cart line edited down to a
 * smaller quantity would pay for one seat and confirm three, because `ConfirmBooking` only ever
 * hears "a paid order arrived", never how much of it was paid.
 *
 * The pure payable/not-payable matrix - which statuses may pay, under the section-2.2 locks - is
 * the Reservant half, pinned WC-free in `tests/Integration/Payment/CheckoutGuardTest`.
 *
 * Skipped rather than failed when WooCommerce is absent, per `WooPaymentProviderTest`.
 */
final class CheckoutGuardTest extends ReservantTestCase {

	private int $approvalServiceId;
	private int $plainServiceId;
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

		$this->approvalServiceId = $services->insert(
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
		$this->plainServiceId    = $services->insert(
			array(
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$this->staffId           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->approvalServiceId, $this->staffId );
		$resources->linkService( $this->plainServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
		$productId = ( new WooPaymentProvider() )->syncService( (array) $services->find( $this->plainServiceId ) );
		$services->update( $this->plainServiceId, array( 'wc_product_id' => $productId ) );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		wc_clear_notices();
		RecordingGateway::$charges = 0;
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = RecordingGateway::class;
				return $gateways;
			}
		);
		WC()->payment_gateways()->init();
	}

	public function tear_down(): void {
		unset( $_GET['key'], $_POST['woocommerce_pay'], $_POST['payment_method'], $_REQUEST['woocommerce-pay-nonce'] );
		if ( isset( WC()->session ) ) {
			wc_clear_notices();
		}
		parent::tear_down();
	}

	/**
	 * THE money test. `ApproveBooking` emailed a pay-for-order link; the payment TTL then lapsed.
	 * WooCommerce itself would happily take the money - the order is `pending` and knows nothing of
	 * holds - so the guard must stop `pay_action()` before the gateway: zero charges, a loud error
	 * notice, and a booking that is still not confirmed.
	 */
	public function test_the_emailed_payment_link_cannot_take_money_after_the_hold_lapses(): void {
		global $wpdb;
		list( $uuid, $order ) = $this->approvedAwaitingPayment();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );

		$this->drivePayAction( $order );

		self::assertSame( 0, RecordingGateway::$charges, 'money must not move for a lapsed hold' );
		self::assertGreaterThan( 0, wc_notice_count( 'error' ), 'the refusal must be loud, not a silent non-charge' );
		self::assertNotSame( 'confirmed', $this->status( $uuid ) );
	}

	/** The twin: while the hold lives, the same door takes the money - the guard is a guard, not a wall. */
	public function test_the_payment_link_still_takes_money_while_the_hold_lives(): void {
		list( $uuid, $order ) = $this->approvedAwaitingPayment();

		$this->drivePayAction( $order );

		self::assertSame( 1, RecordingGateway::$charges, 'a live hold must reach the gateway' );
		self::assertSame( 'awaiting_payment', $this->status( $uuid ), 'the fake gateway reported failure, so nothing moved' );
	}

	/** The classic cart-checkout door: a cart that outlived its hold is refused before an order exists. */
	public function test_classic_checkout_refuses_a_cart_that_outlived_its_hold(): void {
		global $wpdb;
		$uuid = $this->boardedPendingUuid();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onClassicCheckout( array(), $errors );

		self::assertTrue( $errors->has_errors() );
		self::assertStringContainsString( 'expired', (string) $errors->get_error_message() );
	}

	public function test_classic_checkout_passes_a_live_hold(): void {
		global $wpdb;
		$this->boardedPendingUuid();
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onClassicCheckout( array(), $errors );

		self::assertFalse( $errors->has_errors() );
	}

	/** The same refusal on the Store API door the Checkout block drives. */
	public function test_store_api_checkout_refuses_a_cart_that_outlived_its_hold(): void {
		global $wpdb;
		$uuid = $this->boardedPendingUuid();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onStoreApiCart( $errors, WC()->cart );

		self::assertTrue( $errors->has_errors() );
	}

	/**
	 * The underpayment hole: a cart quantity edited down after boarding pays for less than the
	 * booking holds, and a paid order confirms the WHOLE booking. The guard compares the cart's
	 * lines against the booking's items under lock and refuses the mismatch.
	 */
	public function test_a_tampered_cart_quantity_is_refused_at_checkout(): void {
		global $wpdb;
		$this->boardedPendingUuid();
		$keys = array_keys( WC()->cart->get_cart() );
		WC()->cart->set_quantity( (string) $keys[0], 2 );
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onClassicCheckout( array(), $errors );

		self::assertTrue( $errors->has_errors(), 'a cart that no longer says what the booking says must not check out' );
	}

	/**
	 * The Store API's pay-for-existing-order route (`/wc/store/v1/checkout/{id}`) never passes the
	 * cart doors; its `woocommerce_checkout_validate_order_before_payment` validation is the only
	 * look the guard gets, so it must refuse there too.
	 */
	public function test_the_store_api_pay_for_order_door_is_guarded(): void {
		global $wpdb;
		list( $uuid, $order ) = $this->approvedAwaitingPayment();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onOrderValidation( $order, $errors );

		self::assertTrue( $errors->has_errors() );

		$live = new \WP_Error();
		list( , $liveOrder ) = $this->approvedAwaitingPayment( 2 );
		CheckoutGuard::make( $wpdb )->onOrderValidation( $liveOrder, $live );
		self::assertFalse( $live->has_errors() );
	}

	/** Courtesy display: the pay page tells the guest the link is dead before they type card details. */
	public function test_the_pay_page_prints_the_refusal_above_the_form(): void {
		global $wpdb;
		list( $uuid, $order ) = $this->approvedAwaitingPayment();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );

		ob_start();
		CheckoutGuard::make( $wpdb )->onPayForm( $order );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'expired', $output );
	}

	/** An order that never came from a booking is none of the guard's business, on any door. */
	public function test_a_foreign_order_is_never_touched(): void {
		global $wpdb;
		$foreign = wc_create_order( array( 'status' => 'pending' ) );
		self::assertInstanceOf( \WC_Order::class, $foreign );
		$errors = new \WP_Error();

		CheckoutGuard::make( $wpdb )->onOrderValidation( $foreign, $errors );
		CheckoutGuard::make( $wpdb )->onPayAction( $foreign );

		self::assertFalse( $errors->has_errors() );
		self::assertSame( 0, wc_notice_count( 'error' ) );
	}

	/**
	 * An approval-flow booking sitting on `awaiting_payment` with its real order, TTL live -
	 * exactly what the emailed link points at.
	 *
	 * @return array{0: string, 1: \WC_Order}
	 */
	private function approvedAwaitingPayment( int $day = 1 ): array {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest(
					$this->utc( $day, '10:00' ),
					array( new SegmentChoice( $this->approvalServiceId, $this->staffId ) )
				)
			),
			$this->utc( 0 )
		);
		$uuid = (string) $held['uuid'];

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		self::assertSame( 'awaiting_payment', (string) $approved['status'] );
		$order = wc_get_order( (int) $approved['wc_order_id'] );
		self::assertInstanceOf( \WC_Order::class, $order );
		return array( $uuid, $order );
	}

	/** A pending non-approval hold, boarded into the real cart. */
	private function boardedPendingUuid(): string {
		global $wpdb;
		$held = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest(
					$this->utc( 3, '10:00' ),
					array( new SegmentChoice( $this->plainServiceId, $this->staffId ) )
				)
			),
			$this->utc( 0 )
		);
		$uuid    = (string) $held['uuid'];
		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $booking );
		( new CartBridge() )->boardCart( $booking, $this->utc( 0 ) );
		return $uuid;
	}

	/**
	 * The real pay-for-order POST, exactly as the emailed link's form submits it. `pay_action()`
	 * opens an output buffer it does not always close, so the buffer depth is restored afterwards.
	 */
	private function drivePayAction( \WC_Order $order ): void {
		global $wp;
		$wp->query_vars['order-pay']       = $order->get_id();
		$_GET['key']                       = $order->get_order_key();
		$_POST['woocommerce_pay']          = '1';
		$_POST['payment_method']           = RecordingGateway::GATEWAY_ID;
		$_REQUEST['woocommerce-pay-nonce'] = wp_create_nonce( 'woocommerce-pay' );

		$level = ob_get_level();
		\WC_Form_Handler::pay_action();
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		unset( $wp->query_vars['order-pay'] );
	}

	private function status( string $uuid ): string {
		global $wpdb;
		$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $row );
		return (string) $row['status'];
	}
}

// phpcs:disable
if ( class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * A gateway that counts how often WooCommerce asked it to take money - the probe that turns
	 * "the guest cannot be charged" into an assertable zero. It reports failure so `pay_action()`
	 * never reaches its success redirect-and-exit, which would kill the PHPUnit process.
	 */
	final class RecordingGateway extends \WC_Payment_Gateway {

		public const GATEWAY_ID = 'reservant_recording';

		public static int $charges = 0;

		public function __construct() {
			$this->id           = self::GATEWAY_ID;
			$this->enabled      = 'yes';
			$this->title        = 'Recording gateway';
			$this->method_title = 'Recording gateway';
		}

		/**
		 * @param int $order_id
		 * @return array{result: string}
		 */
		public function process_payment( $order_id ): array {
			unset( $order_id );
			++self::$charges;
			return array( 'result' => 'failure' );
		}
	}
}
// phpcs:enable
