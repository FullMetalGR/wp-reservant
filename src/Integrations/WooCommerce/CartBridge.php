<?php
declare( strict_types=1 );

namespace Reservant\Integrations\WooCommerce;

use Reservant\Application\CancelBooking;
use Reservant\Application\ManageToken;
use Reservant\Application\Payment;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Domain\Money\Currency;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Notifications\SiteTime;
use Reservant\Rest\Input;

/**
 * Hold -> cart, for the NON-APPROVAL flow only (AGENTS.md section 6: "hold -> cart -> order paid ->
 * ConfirmBooking"). One cart line per booking item, the booking uuid on every line, and the order
 * that checkout produces carries `WooPaymentProvider::ORDER_UUID_META` - which is how
 * `OrderObserver` finds its way back to the booking when the order is paid.
 *
 * **Only a `pending` checkout hold may board.** An `awaiting_approval` booking is refused outright,
 * because section 6 is explicit that on the approval flow NO order exists until approval - a cart
 * line would become an order at checkout, before any human said yes. An `awaiting_payment` booking
 * already HAS its one order (created by `ApproveBooking`) and pays through the emailed link;
 * boarding it would mint a second order for the same booking.
 *
 * **The cart charges the BOOKING's price, never the product's.** The mirrored product exists so
 * WooCommerce has something to hang a line on; the price was fixed when the hold was taken, and a
 * service whose price changed in between must not silently re-price a booking the customer already
 * saw a total for. `applyBookingPrices()` re-asserts the booking's unit price on every totals
 * calculation, which is the canonical WooCommerce pattern for a server-decided price.
 *
 * **Boarding empties the cart first, deliberately.** "One WC order per booking, one line item per
 * booking item" is only true of an order that contains nothing else: `OrderObserver` maps order
 * death (cancelled/failed/refunded) onto booking release, and an order that also sold a shampoo
 * would kill the booking for reasons that have nothing to do with it - or keep a dead booking's
 * money for reasons that have nothing to do with the shampoo. Evicted reservant lines belonging to
 * ANOTHER booking release that booking (the guest abandoned its checkout by starting a new one);
 * plain products are merely removed, and WooCommerce's own undo can restore them.
 *
 * **A removed cart line releases the whole booking** - the carve-out AGENTS.md section 6 lists
 * beside the order-death releases that live in `OrderObserver`. Whole booking, not one line:
 * cancellation granularity is the container (section 1), so removing one segment of a chain
 * releases the booking and sweeps its sibling lines - a cart holding half a chain must never reach
 * checkout. The release is `CancelBooking` with the HELD statuses as a guard, the exact shape
 * `DELETE /holds/{uuid}` uses: walking away from a checkout is releasing a reservation, and if a
 * rival confirm already landed (the guest paid in another tab) the removal must not cancel a
 * confirmed booking - the `not_held` refusal is absorbed as the benign race it is.
 *
 * **Nothing here may throw at WooCommerce** - these hooks fire mid-checkout and mid-cart-render,
 * outside any Reservant transaction, so every handler catches `\Throwable` and reports on
 * `reservant/error` (the `OrderObserver` discipline, for the same reason).
 *
 * The way in is a front-channel link, `?reservant_checkout={uuid}&token={manage_token}`, handled at
 * `wp_loaded` like WooCommerce's own add-to-cart links - a normal page load, so the guest's WC
 * session and cart exist and the session cookie can still be set. The manage token is the guest's
 * only credential (AGENTS.md section 5) and rides in the URL exactly as it does on the manage page.
 * A missing booking and a wrong token produce one identical answer (a silent redirect home), so the
 * entry adds no booking-existence oracle.
 *
 * The link is built by `entryUrl()` and reaches the guest through the `POST /holds` 201, as
 * `checkout_url` beside the manage token - the only moment the plaintext credential exists
 * (`Rest\HoldsController::checkoutUrl()` says why nowhere else can). The widget's review step sends
 * them here rather than to a confirm that would answer 402 with nowhere to go, which is what makes
 * the non-approval flow reachable by a real visitor at all.
 */
final class CartBridge {

	/** The key inside cart item data under which a line carries its booking facts. */
	public const CART_ITEM_KEY = 'reservant';

	/** Hidden line-item meta naming the `reservant_booking_items` row a paid line settles. */
	public const ITEM_ID_META = '_reservant_booking_item_id';

	/** The entry link's query arg: `?reservant_checkout={uuid}&token={manage_token}`. */
	public const QUERY_UUID = 'reservant_checkout';

	/**
	 * The uuid whose lines `boardCart()` is adding RIGHT NOW, so a failed boarding's rollback
	 * removals do not release the hold they are rolling back (see `boardCart()`). Static because
	 * the removal hook runs on the instance `register()` wired at boot, which is not necessarily
	 * the instance boarding; cleared in a `finally`, so it can never outlive the call that set it.
	 */
	private static ?string $boardingUuid = null;

	/**
	 * @param \Closure|null $leave How the entry ends the request once it has decided where the
	 *                             guest goes - production uses the default (`wp_safe_redirect` +
	 *                             `exit`), tests inject a recorder because `exit` cannot be caught
	 *                             or stubbed any other way (the `Frontend\ManageRoute` idiom).
	 */
	public function __construct( private readonly ?\Closure $leave = null ) {}

	public static function register(): void {
		$bridge = new self();

		// The way in. Priority 20 is where WooCommerce handles its own add-to-cart links - by
		// then the session and cart of this request exist.
		add_action( 'wp_loaded', array( $bridge, 'maybeEnter' ), 20 );

		// The booking's price, re-asserted on every totals run. Priority 1000: after anything
		// else that fancies itself a price authority, because for a reserved slot there is none.
		add_action( 'woocommerce_before_calculate_totals', array( $bridge, 'applyBookingPrices' ), 1000 );

		// One hook covers BOTH checkouts: the classic `WC_Checkout::create_order()` and the Store
		// API's `update_line_items_from_cart()` funnel through `create_order_line_items()`, and
		// this action fires per line with the order in hand - so the uuid lands on the order and
		// the item id on the line no matter which checkout built them.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $bridge, 'onOrderLineItem' ), 10, 4 );

		// `bookings.wc_order_id`, from whichever checkout created the order - both hooks fire
		// after the order exists and before payment.
		add_action( 'woocommerce_checkout_update_order_meta', array( $bridge, 'onClassicOrderCreated' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $bridge, 'onStoreApiOrderProcessed' ), 10, 1 );

		// Cart line removed -> whole booking released (and its sibling lines swept). Restoring a
		// removed line is refused: the release already happened and cannot be undone safely - the
		// slot may already be someone else's.
		add_action( 'woocommerce_cart_item_removed', array( $bridge, 'onCartItemRemoved' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $bridge, 'onCartItemRestored' ), 10, 2 );

		// Quantity is a fact about the booking, not a shopper's choice: the classic cart renders
		// it as plain text, the cart block as read-only, and a forced update is refused. The
		// authoritative backstop for anything that slips past all three is `CheckoutGuard`'s
		// under-lock line check.
		add_filter( 'woocommerce_cart_item_quantity', array( $bridge, 'fixedQuantity' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $bridge, 'quantityEditable' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $bridge, 'refuseQuantityEdit' ), 10, 4 );

		// The line tells the guest WHEN, not just what: a cart row reading "Cut x 1" with no date
		// is a checkout the guest cannot sanity-check.
		add_filter( 'woocommerce_get_item_data', array( $bridge, 'lineDisplayData' ), 10, 2 );
	}

	/**
	 * The `?reservant_checkout={uuid}&token=...` entry. Only ever acts when its own query arg is
	 * present; every other request pays one isset().
	 */
	public function maybeEnter(): void {
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a guest's emailed/widget link carries no nonce; the manage token below is the credential, verified against the booking's stored hash.
		$uuid = sanitize_text_field( Input::text( wp_unslash( $_GET[ self::QUERY_UUID ] ?? '' ) ) );
		if ( '' === $uuid ) {
			return;
		}
		try {
			$this->enter( $uuid, $this->token() );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e, $uuid );
			$this->notice( __( 'The system was busy. Please try again.', 'reservant' ) );
			$this->leave( $this->checkoutUrl() );
		}
	}

	/**
	 * Whether this booking has any business in a WooCommerce cart at all - the "belongs in a cart"
	 * rule of AGENTS.md section 6, stated once.
	 *
	 * Public and static for the `lineShape()` reason: two readers, one rule. `boardCart()` enforces
	 * it at the door (a false answer is `not_boardable`), and `WooPaymentProvider::checkoutUrl()`
	 * asks it before handing anybody a link, so the surface that ROUTES a guest here and the door
	 * that ADMITS them cannot drift into offering a checkout that is then refused.
	 *
	 * - `pending` only. An `awaiting_approval` booking must mint no order before a human says yes,
	 *   and an `awaiting_payment` one already has the single order `ApproveBooking` created; both
	 *   are spelled out in the class docblock above.
	 * - `online` only. A free or onsite booking is settled by `ConfirmBooking`, not by a checkout.
	 * - The RESOLVED provider, not `wooCommerceActive()`: a site that filtered in a non-WC provider
	 *   has said who takes NEW money, and boarding the WC cart would take it through WooCommerce
	 *   anyway. With no provider at all this is false, which is the section 6 degrade - the booking
	 *   simply confirms and nobody is sent anywhere.
	 *
	 * Deliberately says nothing about the hold deadline: whether a booking BELONGS in a cart is a
	 * fact about the booking, while whether its hold is still alive is a fact about the clock, and
	 * `boardCart()` asks the second question separately so it can answer `hold_expired` rather than
	 * telling an expired guest their booking was never payable.
	 *
	 * @param array<string, mixed> $booking booking row, as `BookingRepository` hydrates it
	 */
	public static function boardable( array $booking ): bool {
		return BookingStatus::Pending->value === (string) ( $booking['status'] ?? '' )
			&& PaymentMode::Online->value === (string) ( $booking['payment_mode'] ?? '' )
			&& Payment\Providers::get() instanceof WooPaymentProvider;
	}

	/**
	 * The front-channel entry link `maybeEnter()` answers: `?reservant_checkout={uuid}&token=...`
	 * on the site root.
	 *
	 * It lives beside the handler it inverts, for the reason `Frontend\ManageRoute::url()` lives
	 * beside its rewrite rule: a URL builder in another file is one more thing to remember when the
	 * entry changes. The shape is that same magic link's - `home_url()` plus `add_query_arg`, the
	 * manage token riding in the query string exactly as it does there - because it IS that
	 * credential (AGENTS.md section 5), and the token's alphabet is URL-safe by construction
	 * (`ManageToken::issue()`).
	 *
	 * No rewrite rule and no permalink branch: `maybeEnter()` runs on `wp_loaded` for any front-end
	 * request carrying the query arg, so the site root serves it under either permalink mode.
	 */
	public static function entryUrl( string $uuid, string $token ): string {
		return add_query_arg(
			array(
				self::QUERY_UUID => $uuid,
				'token'          => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Put a held booking into the cart: evict everything else, then one line per booking item.
	 *
	 * All lines or none - a cart holding part of a chain must never exist, so a line that cannot
	 * be added rolls the boarded ones back out. Those rollback removals fire the same
	 * `woocommerce_cart_item_removed` hook a guest's own removal does, and WITHOUT the
	 * `$boardingUuid` suppressor below they would release the very hold being boarded: a guest
	 * whose cart hiccupped (a third-party validation filter refusing one add) would lose their
	 * reservation over a failure that was never theirs. Suppressed, the hold stands, the entry
	 * says "cannot be taken to checkout", and the guest can simply try the link again. Eviction
	 * of OTHER bookings' lines is deliberately not suppressed - abandoning booking A's checkout
	 * by boarding booking B is a real walk-away, and A releases.
	 *
	 * @param array<string, mixed> $booking booking row + items, as `BookingRepository` hydrates it
	 * @throws \RuntimeException `not_boardable` when this booking has no business in a cart
	 *                           (wrong status, not an online service, or WooCommerce is not the
	 *                           provider taking money), `hold_expired` when the checkout hold has
	 *                           lapsed, `cart_board_failed` when WooCommerce refused a line.
	 */
	public function boardCart( array $booking, \DateTimeImmutable $nowUtc ): void {
		if ( ! self::boardable( $booking ) ) {
			throw new \RuntimeException( 'not_boardable' );
		}
		if ( null === $booking['hold_expires_at'] || (string) $booking['hold_expires_at'] <= $nowUtc->format( 'Y-m-d H:i:s' ) ) {
			throw new \RuntimeException( 'hold_expired' );
		}

		$cart = $this->cart();
		$this->evictEverything( $cart );

		$uuid     = (string) $booking['uuid'];
		$currency = (string) $booking['currency'];
		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		$added = array();
		try {
			self::$boardingUuid = $uuid;
			foreach ( $items as $item ) {
				$key = $this->addLine( $cart, $uuid, $item, $currency );
				if ( null === $key ) {
					foreach ( $added as $rollback ) {
						$cart->remove_cart_item( $rollback );
					}
					throw new \RuntimeException( 'cart_board_failed' );
				}
				$added[] = $key;
			}
		} finally {
			self::$boardingUuid = null;
		}
	}

	/**
	 * How a booking item reads as a cart/order line: the quantity shown and the unit price that
	 * multiplies back to the item's own `price_minor`.
	 *
	 * `price_minor` on a booking item is always the LINE total - `HoldBooking` stores
	 * `price * seats` for an open-capacity event item (appointments and grid seats are seats=1) -
	 * while a cart price is per UNIT. An event booking for three should read as three on the
	 * invoice, so the quantity is the seat count and the unit price is the exact division; if a
	 * filtered price ever made that division inexact, the line falls back to quantity one at the
	 * full amount, because a right total beats a pretty quantity.
	 *
	 * Public and static because `CheckoutGuard` re-derives the same expectation under lock when it
	 * verifies that a cart still matches its booking - one rule, two readers.
	 *
	 * @param array<string, mixed> $item booking item row
	 * @return array{quantity: int, unit_minor: int}
	 */
	public static function lineShape( array $item ): array {
		$seats     = max( 1, (int) ( $item['seats'] ?? 1 ) );
		$lineMinor = (int) ( $item['price_minor'] ?? 0 );
		if ( $seats > 1 && 0 === $lineMinor % $seats ) {
			return array(
				'quantity'   => $seats,
				'unit_minor' => intdiv( $lineMinor, $seats ),
			);
		}
		return array(
			'quantity'   => 1,
			'unit_minor' => $lineMinor,
		);
	}

	/**
	 * The booking's own prices, re-asserted every time WooCommerce calculates totals. Session
	 * reloads rebuild the product object from the catalog, so a one-off `set_price()` at boarding
	 * would silently revert to the mirror's price on the next page view - this hook is the only
	 * shape that survives.
	 *
	 * @param mixed $cart the cart being totalled, per the hook's contract
	 */
	public function applyBookingPrices( mixed $cart ): void {
		try {
			if ( ! $cart instanceof \WC_Cart ) {
				return;
			}
			foreach ( $cart->get_cart() as $line ) {
				$facts = self::lineFacts( $line );
				if ( null === $facts || ! isset( $line['data'] ) || ! $line['data'] instanceof \WC_Product ) {
					continue;
				}
				$line['data']->set_price( (string) Currency::toMajor( (int) $facts['unit_minor'], (string) $facts['currency'] ) );
			}
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/**
	 * Fires once per line in `create_order_line_items()` - the funnel BOTH checkouts use - with
	 * the order in hand. The uuid goes onto the ORDER (idempotently, once per line) because that
	 * is where `OrderObserver` looks; the item id goes onto the LINE so a paid line can be traced
	 * to the `reservant_booking_items` row it settles.
	 *
	 * @param mixed $item        the order line being built
	 * @param mixed $cartItemKey unused - the cart line's facts travel in `$values`
	 * @param mixed $values      the cart line's data
	 * @param mixed $order       the order being built
	 */
	public function onOrderLineItem( mixed $item, mixed $cartItemKey, mixed $values, mixed $order ): void {
		unset( $cartItemKey );
		try {
			$facts = is_array( $values ) ? self::lineFacts( $values ) : null;
			if ( null === $facts ) {
				return;
			}
			if ( $item instanceof \WC_Order_Item ) {
				$item->add_meta_data( self::ITEM_ID_META, (string) (int) $facts['item_id'], true );
			}
			if ( $order instanceof \WC_Order ) {
				$order->update_meta_data( WooPaymentProvider::ORDER_UUID_META, (string) $facts['booking_uuid'] );
			}
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/** @param mixed $orderId the saved order's id, per `woocommerce_checkout_update_order_meta` */
	public function onClassicOrderCreated( mixed $orderId ): void {
		try {
			$order = wc_get_order( (int) ( is_scalar( $orderId ) ? $orderId : 0 ) );
			if ( $order instanceof \WC_Order ) {
				$this->rememberOrder( $order );
			}
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/** @param mixed $order the processed order, per `woocommerce_store_api_checkout_order_processed` */
	public function onStoreApiOrderProcessed( mixed $order ): void {
		try {
			if ( $order instanceof \WC_Order ) {
				$this->rememberOrder( $order );
			}
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/**
	 * A removed line releases its whole booking and sweeps the booking's other lines out of the
	 * cart - half a chain must never reach checkout, and cancellation granularity is the container
	 * (AGENTS.md section 1). The sweep re-fires this handler per sibling; the repeat releases are
	 * absorbed by `release()` exactly like a repeat order-death delivery is by `OrderObserver`.
	 *
	 * @param mixed $cartItemKey the removed line's key
	 * @param mixed $cart        the cart it was removed from
	 */
	public function onCartItemRemoved( mixed $cartItemKey, mixed $cart ): void {
		try {
			if ( ! $cart instanceof \WC_Cart || ! is_string( $cartItemKey ) ) {
				return;
			}
			$removed = $cart->removed_cart_contents[ $cartItemKey ] ?? null;
			$facts   = is_array( $removed ) ? self::lineFacts( $removed ) : null;
			if ( null === $facts ) {
				return;
			}
			$uuid = (string) $facts['booking_uuid'];
			if ( self::$boardingUuid === $uuid ) {
				return; // A failed boarding rolling itself back - the hold stands (see boardCart()).
			}
			foreach ( $cart->get_cart() as $key => $line ) {
				$sibling = self::lineFacts( $line );
				if ( null !== $sibling && $uuid === (string) $sibling['booking_uuid'] ) {
					$cart->remove_cart_item( $key );
				}
			}
			$this->release( $uuid );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/**
	 * WooCommerce's "Undo?" link, refused for booking lines: the removal already released the
	 * slot, and a release cannot be undone - the slot may be someone else's by now. The line is
	 * removed again (its release attempt is the benign repeat `release()` absorbs) and the guest
	 * is told to book afresh rather than left holding a cart line for a booking that no longer
	 * exists.
	 *
	 * @param mixed $cartItemKey the restored line's key
	 * @param mixed $cart        the cart it came back into
	 */
	public function onCartItemRestored( mixed $cartItemKey, mixed $cart ): void {
		try {
			if ( ! $cart instanceof \WC_Cart || ! is_string( $cartItemKey ) ) {
				return;
			}
			$line = $cart->get_cart()[ $cartItemKey ] ?? null;
			if ( ! is_array( $line ) || null === self::lineFacts( $line ) ) {
				return;
			}
			$cart->remove_cart_item( $cartItemKey );
			$this->notice( __( 'That reservation was already released. Please book again.', 'reservant' ) );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	/**
	 * Classic cart page: a booking line's quantity renders as plain text, not an input.
	 *
	 * @param mixed $productQuantity the quantity cell WooCommerce was about to render
	 * @param mixed $cartItemKey     unused
	 * @param mixed $cartItem        the cart line
	 * @return mixed
	 */
	public function fixedQuantity( mixed $productQuantity, mixed $cartItemKey, mixed $cartItem ): mixed {
		unset( $cartItemKey );
		if ( is_array( $cartItem ) && null !== self::lineFacts( $cartItem ) ) {
			return esc_html( (string) (int) ( $cartItem['quantity'] ?? 1 ) );
		}
		return $productQuantity;
	}

	/**
	 * Cart block: a booking line's quantity is not editable.
	 *
	 * @param mixed $editable what WooCommerce decided
	 * @param mixed $product  unused
	 * @param mixed $cartItem the cart line
	 * @return mixed
	 */
	public function quantityEditable( mixed $editable, mixed $product, mixed $cartItem ): mixed {
		unset( $product );
		if ( is_array( $cartItem ) && null !== self::lineFacts( $cartItem ) ) {
			return false;
		}
		return $editable;
	}

	/**
	 * Classic cart update form: a forced quantity change on a booking line is refused with the
	 * honest remedy - remove the line (releasing the booking) and book the right count instead.
	 *
	 * @param mixed $passed      the validation verdict so far
	 * @param mixed $cartItemKey unused
	 * @param mixed $values      the cart line
	 * @param mixed $quantity    the requested quantity
	 * @return mixed
	 */
	public function refuseQuantityEdit( mixed $passed, mixed $cartItemKey, mixed $values, mixed $quantity ): mixed {
		unset( $cartItemKey );
		if ( ! is_array( $values ) ) {
			return $passed;
		}
		$facts = self::lineFacts( $values );
		if ( null === $facts || (int) ( is_scalar( $quantity ) ? $quantity : 0 ) === (int) $facts['quantity'] ) {
			return $passed;
		}
		$this->notice( __( 'A reservation cannot change quantity in the cart. Remove it and book again instead.', 'reservant' ) );
		return false;
	}

	/**
	 * The line's customer-visible facts on cart and checkout: when the booked item starts, in the
	 * site's timezone.
	 *
	 * @param mixed $data     the extra display rows so far
	 * @param mixed $cartItem the cart line
	 * @return mixed
	 */
	public function lineDisplayData( mixed $data, mixed $cartItem ): mixed {
		$facts = is_array( $cartItem ) ? self::lineFacts( $cartItem ) : null;
		if ( null === $facts || ! is_array( $data ) ) {
			return $data;
		}
		$when = SiteTime::local( (string) $facts['start_utc'] );
		if ( '' === $when ) {
			return $data;
		}
		$data[] = array(
			'key'   => __( 'Booked for', 'reservant' ),
			'value' => $when,
		);
		return $data;
	}

	/**
	 * The booking facts a cart line carries, or null when the line is not ours. The single reader
	 * of the `CART_ITEM_KEY` shape, so a malformed line (a corrupted session, another plugin's
	 * idea of cart data) reads as "not ours" everywhere at once.
	 *
	 * @param array<string, mixed> $line cart line (or removed-line) array
	 * @return array<string, mixed>|null
	 */
	public static function lineFacts( array $line ): ?array {
		$facts = $line[ self::CART_ITEM_KEY ] ?? null;
		if ( ! is_array( $facts ) || '' === (string) ( $facts['booking_uuid'] ?? '' ) ) {
			return null;
		}
		return $facts;
	}

	/** The token from the entry link, `Frontend\ManageRoute`'s reading of the same parameter. */
	private function token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token IS the credential; it is verified against the booking's stored hash in enter().
		return sanitize_text_field( Input::text( wp_unslash( $_GET['token'] ?? '' ) ) );
	}

	private function enter( string $uuid, string $token ): void {
		global $wpdb;
		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		$hash    = null === $booking || null === ( $booking['manage_token_hash'] ?? null ) ? null : (string) $booking['manage_token_hash'];
		if ( null === $booking || ! ManageToken::verify( $token, $hash ) ) {
			// One identical answer for "no such booking" and "wrong token": no existence oracle.
			$this->leave( home_url( '/' ) );
			return;
		}
		try {
			$this->boardCart( $booking, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		} catch ( \RuntimeException $refusal ) {
			$this->notice(
				'hold_expired' === $refusal->getMessage()
					? __( 'Your reservation expired. Please start again.', 'reservant' )
					: __( 'This booking cannot be taken to checkout.', 'reservant' )
			);
			$this->leave( $this->checkoutUrl() );
			return;
		}
		$this->leave( $this->checkoutUrl() );
	}

	/**
	 * One cart line for one booking item, its facts in the cart item data.
	 *
	 * @param array<string, mixed> $item booking item row
	 * @return string|null the cart line key, or null when WooCommerce refused
	 */
	private function addLine( \WC_Cart $cart, string $uuid, array $item, string $currency ): ?string {
		$productId = $this->productFor( (int) ( $item['service_id'] ?? 0 ) );
		if ( null === $productId ) {
			return null;
		}
		$shape = self::lineShape( $item );
		$key   = $cart->add_to_cart(
			$productId,
			$shape['quantity'],
			0,
			array(),
			array(
				self::CART_ITEM_KEY => array(
					'booking_uuid' => $uuid,
					'item_id'      => (int) ( $item['id'] ?? 0 ),
					'quantity'     => $shape['quantity'],
					'unit_minor'   => $shape['unit_minor'],
					'currency'     => $currency,
					'start_utc'    => (string) ( $item['start_utc'] ?? '' ),
				),
			)
		);
		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * The mirrored product a service's cart line hangs on, re-mirrored on demand when it is
	 * missing or trashed - a mirror deleted in wp-admin must not strand a live hold at the cart
	 * door when `syncService()` can simply rebuild it (`wc_get_product()` still returns trashed
	 * products, so "usable" is a status question - see `WooPaymentProvider::trashMirror()`).
	 */
	private function productFor( int $serviceId ): ?int {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );
		$service  = $services->find( $serviceId );
		if ( null === $service ) {
			return null;
		}
		$productId = null === ( $service['wc_product_id'] ?? null ) ? 0 : (int) $service['wc_product_id'];
		$product   = $productId > 0 ? wc_get_product( $productId ) : false;
		if ( $product instanceof \WC_Product && 'trash' !== $product->get_status() ) {
			return $productId;
		}
		$fresh = ( new WooPaymentProvider() )->syncService( $service );
		if ( null === $fresh ) {
			return null;
		}
		$services->update( $serviceId, array( 'wc_product_id' => $fresh ) );
		return $fresh;
	}

	/**
	 * Empty the cart line by line rather than `empty_cart()`: per-line removal fires
	 * `woocommerce_cart_item_removed`, which is what releases any OTHER booking being abandoned,
	 * and it leaves plain products in the removed list where WooCommerce's undo can reach them.
	 */
	private function evictEverything( \WC_Cart $cart ): void {
		foreach ( array_keys( $cart->get_cart() ) as $key ) {
			$cart->remove_cart_item( (string) $key );
		}
	}

	/**
	 * Release a booking whose cart line was removed - `DELETE /holds/{uuid}`'s exact shape: force
	 * (giving up a reservation nobody paid for is not policy-bound) but only FROM a held status,
	 * so a booking confirmed in a rival tab is never cancelled by a stale cart being tidied.
	 *
	 * Benign refusals are silence: the sweep and the undo path deliver repeats, and "already
	 * released" is the outcome this method wanted. A booking still HELD after a refusal means the
	 * release genuinely failed while the cart line is already gone - that is reported.
	 */
	private function release( string $uuid ): void {
		global $wpdb;
		try {
			CancelBooking::make( $wpdb )->execute(
				$uuid,
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
				true,
				BookingStatus::heldStatuses()
			);
		} catch ( \Throwable $e ) {
			$status = $this->statusOf( $uuid );
			if ( null !== $status && ! $status->isHeld() ) {
				return;
			}
			do_action( 'reservant/error', $e, $uuid );
		}
	}

	/** Post-failure forensics for release(); never throws INTO the catch block that calls it. */
	private function statusOf( string $uuid ): ?BookingStatus {
		global $wpdb;
		try {
			$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
			return null === $row ? null : BookingStatus::from( (string) $row['status'] );
		} catch ( \Throwable $ignored ) {
			return null;
		}
	}

	/** `bookings.wc_order_id` for the order checkout just created - idempotent on repeats. */
	private function rememberOrder( \WC_Order $order ): void {
		$uuid = (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META );
		if ( '' === $uuid ) {
			return;
		}
		global $wpdb;
		$bookings = new BookingRepository( $wpdb );
		$booking  = $bookings->findByUuid( $uuid );
		if ( null === $booking || (int) ( $booking['wc_order_id'] ?? 0 ) === $order->get_id() ) {
			return;
		}
		$bookings->storeOrderId( (int) $booking['id'], $order->get_id() );
	}

	/** This request's cart, loaded explicitly where WooCommerce did not (REST, CLI). */
	private function cart(): \WC_Cart {
		if ( ! WC()->cart instanceof \WC_Cart ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			throw new \RuntimeException( 'cart_board_failed' );
		}
		return $cart;
	}

	/**
	 * A message the guest sees on the page they land on. The session cookie is forced first
	 * because a first-contact guest has none yet, and a notice stored in a session the browser
	 * cannot name again is a message nobody reads.
	 */
	private function notice( string $message ): void {
		try {
			$session = WC()->session;
			if ( $session instanceof \WC_Session_Handler && ! headers_sent() ) {
				$session->set_customer_session_cookie( true );
			}
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e );
		}
	}

	private function checkoutUrl(): string {
		$url = function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '';
		return '' === $url ? home_url( '/' ) : $url;
	}

	private function leave( string $url ): void {
		$leave = $this->leave ?? static function ( string $target ): void {
			wp_safe_redirect( $target );
			exit;
		};
		$leave( $url );
	}
}
