<?php
declare( strict_types=1 );

namespace Reservant\Integrations\WooCommerce;

use Reservant\Application\Payment\PaymentProvider;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Domain\Money\Currency;

/**
 * The WooCommerce half of the payment seam (AGENTS.md section 6). Every WooCommerce symbol this
 * plugin touches lives in this namespace and nowhere else; `Application\Payment\Providers` is the
 * only thing that names this class, and only after `class_exists( 'WooCommerce' )`.
 *
 * **The mirror runs one way.** A service's price, name and existence are Reservant's; the WC product
 * is a shadow of them, rewritten on every save, and nothing ever reads a price back out of it. That
 * is what keeps two sources of truth from disagreeing about what a haircut costs - and it is why
 * `syncService()` sets the price every time rather than only on creation.
 *
 * **Virtual products, deliberately.** A booking is not shipped, so the mirror is virtual: WooCommerce
 * then skips shipping calculation, address requirements and stock entirely. It is also hidden from
 * the catalog (`exclude-from-catalog`, `exclude-from-search`) because a customer must reach a slot
 * through the booking widget, which is what actually holds it; a product page "Add to cart" would
 * sell time nobody reserved.
 */
final class WooPaymentProvider implements PaymentProvider {

	/** Meta key carrying the booking uuid on the order (AGENTS.md section 6). */
	public const ORDER_UUID_META = '_reservant_booking_uuid';

	/** Meta key marking a product as one of ours, so a resync never adopts a shop's own product. */
	public const PRODUCT_SERVICE_META = '_reservant_service_id';

	public function isAvailable(): bool {
		return true;
	}

	/**
	 * @param array<string, mixed> $service
	 * @return int|null product id to store, or null to clear it
	 */
	public function syncService( array $service ): ?int {
		$existing = null === ( $service['wc_product_id'] ?? null ) ? 0 : (int) $service['wc_product_id'];

		// Not an online service (any more). Trash whatever we mirrored and tell the caller to clear
		// the id, so a later resync creates a fresh product rather than reviving a retired one.
		if ( PaymentMode::Online->value !== (string) ( $service['payment_mode'] ?? '' ) ) {
			if ( $existing > 0 ) {
				$this->trashMirror( $existing );
			}
			return null;
		}

		$product = $existing > 0 ? wc_get_product( $existing ) : false;
		if ( ! $product instanceof \WC_Product ) {
			$product = new \WC_Product_Simple();
		}

		$product->set_name( (string) ( $service['name'] ?? '' ) );
		$product->set_virtual( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_sold_individually( false );
		$product->set_regular_price( (string) Currency::toMajor( (int) ( $service['price_minor'] ?? 0 ), $this->currency() ) );
		$product->set_price( (string) Currency::toMajor( (int) ( $service['price_minor'] ?? 0 ), $this->currency() ) );
		$product->update_meta_data( self::PRODUCT_SERVICE_META, (string) (int) ( $service['id'] ?? 0 ) );

		$id = $product->save();
		return $id > 0 ? (int) $id : null;
	}

	/**
	 * One order per booking, one line item per booking item.
	 *
	 * The order is created `pending` and NOT paid: paying it is the customer's job, and the paid
	 * transition is what `OrderObserver` turns back into a `ConfirmBooking`. It carries the booking
	 * uuid in meta so that observer can find its way home from an order id alone.
	 *
	 * Prices come off the BOOKING's own items, not off the mirrored products, and that is not
	 * belt-and-braces: the item price was fixed when the hold was taken, and a service whose price
	 * changed in between must not silently re-price a booking the customer already saw a total for.
	 *
	 * @param array<string, mixed> $booking
	 * @throws \RuntimeException `order_create_failed` when WooCommerce refuses - a booking that
	 *                           believes it is awaiting payment with no order behind it is
	 *                           unpayable, so this is loud rather than null.
	 */
	public function createOrder( array $booking ): ?int {
		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => 0,
			)
		);
		if ( $order instanceof \WP_Error || ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( 'order_create_failed' );
		}

		$currency = (string) ( $booking['currency'] ?? $this->currency() );
		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		foreach ( $items as $item ) {
			$this->addLine( $order, $item, $currency );
		}

		$order->set_billing_email( (string) ( $booking['customer_email'] ?? '' ) );
		$order->set_billing_first_name( (string) ( $booking['customer_name'] ?? '' ) );
		$order->set_billing_phone( (string) ( $booking['customer_phone'] ?? '' ) );
		$order->update_meta_data( self::ORDER_UUID_META, (string) ( $booking['uuid'] ?? '' ) );
		$order->calculate_totals( false );

		$id = $order->save();
		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new \RuntimeException( 'order_create_failed' );
		}
		return $id;
	}

	public function paymentUrl( int $orderId ): ?string {
		$order = wc_get_order( $orderId );
		return $order instanceof \WC_Order ? $order->get_checkout_payment_url() : null;
	}

	/**
	 * The non-approval flow's door: `CartBridge`'s front-channel entry link, but only for a booking
	 * that link would actually admit.
	 *
	 * The condition is `CartBridge::boardable()` itself rather than a second reading of section 6's
	 * "belongs in a cart" rule - one rule, two readers, so a link can never be offered for a booking
	 * the cart then refuses as `not_boardable`. No order exists at this point and none should: on
	 * this flow the order is what checkout produces, and `OrderObserver` is what turns paying it
	 * back into a confirmed booking. (`paymentUrl()` above is the OTHER flow - an order that already
	 * exists because a human approved the booking.)
	 *
	 * @param array<string, mixed> $booking
	 */
	public function checkoutUrl( array $booking, string $manageToken ): ?string {
		if ( ! CartBridge::boardable( $booking ) ) {
			return null;
		}
		return CartBridge::entryUrl( (string) ( $booking['uuid'] ?? '' ), $manageToken );
	}

	public function flagOrder( int $orderId, string $note ): void {
		$order = wc_get_order( $orderId );
		if ( $order instanceof \WC_Order ) {
			// `0` = not a customer note. The owner decides what, if anything, to refund
			// (AGENTS.md section 6); telling the customer their money is on its way would be a
			// promise this plugin has no authority to make.
			$order->add_order_note( $note, 0 );
		}
	}

	/**
	 * One line item, priced from the booking item.
	 *
	 * `add_product()` would take the price off the product; the explicit subtotal/total override is
	 * what makes the BOOKING's price win. `seats` is the quantity, so an event booking for three
	 * reads as three on the invoice rather than one line the owner has to interpret.
	 *
	 * `price_minor` on a booking item is already the LINE total, for every shape: appointments and
	 * grid seats carry seats=1, and `HoldBooking::planEvent()` stores `price * seats` on an
	 * open-capacity event item. Multiplying by the quantity here again - as the first version of
	 * this method did - billed an open event for three seats at NINE times the seat price, so the
	 * quantity is display only and the amount is the item's own number, untouched.
	 *
	 * @param array<string, mixed> $item
	 */
	private function addLine( \WC_Order $order, array $item, string $currency ): void {
		$service   = ( new \Reservant\Infrastructure\Db\ServiceRepository( $GLOBALS['wpdb'] ) )->find( (int) ( $item['service_id'] ?? 0 ) );
		$productId = null === $service ? 0 : (int) ( $service['wc_product_id'] ?? 0 );
		$product   = $productId > 0 ? wc_get_product( $productId ) : false;

		$quantity = max( 1, (int) ( $item['seats'] ?? 1 ) );
		$line     = Currency::toMajor( (int) ( $item['price_minor'] ?? 0 ), $currency );

		if ( $product instanceof \WC_Product ) {
			$order->add_product(
				$product,
				$quantity,
				array(
					'subtotal' => $line,
					'total'    => $line,
				)
			);
			return;
		}

		// No mirror to hang the line on - the service predates the bridge, or its product was
		// deleted in wp-admin. A free-text line still bills the right amount and still names what
		// was booked, which beats an order that silently omits a segment the customer must pay for.
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( null === $service ? __( 'Booking', 'reservant' ) : (string) $service['name'] );
		$fee->set_amount( (string) $line );
		$fee->set_total( (string) $line );
		$order->add_item( $fee );
	}

	/**
	 * Trashed, never force-deleted, and the difference is the shop's order history: a past order
	 * that bought this service still holds a line item pointing at the product, and WooCommerce
	 * renders that line from the product when it can. A trashed product keeps rendering; a purged
	 * one leaves the owner reading an order with a blank line on it.
	 *
	 * Note what this means for the caller: `wc_get_product()` still returns an object for a trashed
	 * product (with `post_status` `trash`), so "did we retire it" is a status question, not an
	 * existence one.
	 */
	private function trashMirror( int $productId ): void {
		$product = wc_get_product( $productId );
		// Only ever our own mirror: a shop that reused the id for a real product must not have it
		// trashed by a booking service being switched to onsite.
		if ( $product instanceof \WC_Product && '' !== (string) $product->get_meta( self::PRODUCT_SERVICE_META ) ) {
			$product->delete( false );
		}
	}

	private function currency(): string {
		return \Reservant\Settings::make()->currency();
	}
}
