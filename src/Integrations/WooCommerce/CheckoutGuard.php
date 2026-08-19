<?php
declare( strict_types=1 );

namespace Reservant\Integrations\WooCommerce;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Re-validation under lock at every door into payment (AGENTS.md section 6: "The hold TTL is the
 * authority, not the cart and not the payment link. A cart or link that outlives its hold loses the
 * slot; checkout must re-validate under lock and fail loudly rather than silently overbook").
 *
 * There are two doors and each exists twice, so four hooks:
 *
 *  - The CART checkout (the non-approval flow, `CartBridge`'s path): classic checkout validates at
 *    `woocommerce_after_checkout_validation`; the Store API checkout the Checkout block drives
 *    validates at `woocommerce_store_api_cart_errors`. Both fire before an order exists and long
 *    before a gateway sees a card number.
 *  - The PAY-FOR-ORDER page (the approval flow's emailed `get_checkout_payment_url()` link, and any
 *    retry of a pending order): the classic form posts through `WC_Form_Handler::pay_action()`,
 *    which fires `woocommerce_before_pay_action` and then runs the gateway ONLY while
 *    `wc_notice_count( 'error' )` is zero - an error notice added there is the documented brake.
 *    The Store API twin (`/wc/store/v1/checkout/{id}`, and the block checkout's own final
 *    validation) funnels through `OrderController::perform_custom_order_validation()`, whose
 *    `woocommerce_checkout_validate_order_before_payment` hook (WC 9.9+) takes a `WP_Error` to
 *    fill. `before_woocommerce_pay_form` additionally prints the refusal above the classic pay
 *    form, so the guest learns before typing card details, not after.
 *
 * The pay-for-order door is the one that carries real money risk: `ApproveBooking` emails that link
 * and the booking's payment TTL keeps running - without this guard a guest opening the link after
 * the TTL lapsed would be CHARGED (WooCommerce knows nothing of holds), while `ConfirmBooking`
 * correctly refuses `hold_expired` and the observer can only report money that already moved. The
 * guard stops the charge before the gateway is reached.
 *
 * **Why the check runs under the section-2.2 locks** rather than as a plain read: every question
 * this class answers is decided by the same rows the write protocol serialises - a rival hold's
 * reap, the sweeper expiring the booking, a cancel landing - and a plain read races all of them.
 * The check takes the booking's slot mutexes in the global order, re-reads the row `FOR UPDATE`,
 * and decides on what is actually committed. What no pre-payment check can close is the moments
 * BETWEEN this verdict and the gateway settling; `ConfirmBooking`'s own in-transition
 * `hold_expired` guard plus the observer's loud report remain the backstop for that residue, and
 * this guard's job is to make reaching it require a photo finish instead of an open door.
 *
 * A booking is payable when paying it buys exactly what it claims to: `confirmed` (the slot is
 * already theirs - a site using `reservant/allow_direct_confirm` collects after confirmation),
 * or `pending`/`awaiting_payment` with a LIVE hold. Everything else - lapsed holds, cancelled,
 * rejected, expired, and `awaiting_approval` (no money before a human says yes) - is refused with
 * a sentence the guest can act on.
 *
 * On the cart doors the guard also verifies the cart still SAYS what the booking says: every
 * booking item present as a line, every line at the quantity `CartBridge::lineShape()` gave it.
 * A quantity edited down would otherwise pay for one seat and confirm three - `ConfirmBooking`
 * only ever hears "a paid order arrived", never how much of it was paid.
 *
 * **Nothing here may throw at WooCommerce**, and for once that cuts the other way too: a
 * verification that FAILS (a lock refused, the DB away) must not let money move on an unverified
 * hold, so a cart or order that names one of our bookings fails CLOSED with "the system was busy" -
 * while carts and orders that carry no booking are never touched at all, so a Reservant fault can
 * never block a shop's ordinary sales.
 */
final class CheckoutGuard {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new TransactionRunner( $db ),
			new LockManager( $db ),
			new ResourceDayRepository( $db ),
			new BookingRepository( $db )
		);
	}

	public static function register(): void {
		global $wpdb;
		$guard = self::make( $wpdb );
		add_action( 'woocommerce_after_checkout_validation', array( $guard, 'onClassicCheckout' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( $guard, 'onStoreApiCart' ), 10, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $guard, 'onOrderValidation' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( $guard, 'onPayAction' ), 10, 1 );
		add_action( 'before_woocommerce_pay_form', array( $guard, 'onPayForm' ), 10, 1 );
	}

	/**
	 * Classic checkout door: refusals land on the `WP_Error` WooCommerce collects, which aborts
	 * `process_checkout()` before order creation.
	 *
	 * @param mixed $data   unused - posted checkout fields
	 * @param mixed $errors the validation errors collected so far
	 */
	public function onClassicCheckout( mixed $data, mixed $errors ): void {
		unset( $data );
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		foreach ( $this->cartBookings() as $uuid => $lines ) {
			$reason = $this->guardedRefusal( (string) $uuid, $lines );
			if ( null !== $reason ) {
				$errors->add( 'reservant_' . $reason, self::message( $reason ) );
			}
		}
	}

	/**
	 * Store API checkout door (the Checkout block): refusals on this `WP_Error` become the 409 the
	 * block renders, before a draft order is touched.
	 *
	 * @param mixed $errors the `WP_Error` the Store API collects
	 * @param mixed $cart   the cart being validated
	 */
	public function onStoreApiCart( mixed $errors, mixed $cart ): void {
		if ( ! $errors instanceof \WP_Error || ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $this->cartBookings( $cart ) as $uuid => $lines ) {
			$reason = $this->guardedRefusal( (string) $uuid, $lines );
			if ( null !== $reason ) {
				$errors->add( 'reservant_' . $reason, self::message( $reason ) );
			}
		}
	}

	/**
	 * The Store API's final pre-payment validation - for a checkout order this is a second look
	 * moments before the gateway, and for `/wc/store/v1/checkout/{id}` it is the ONLY look: that
	 * route pays an existing order (the approval flow's) and never passes the cart doors.
	 *
	 * @param mixed $order  the order about to be paid
	 * @param mixed $errors the `WP_Error` custom validations fill
	 */
	public function onOrderValidation( mixed $order, mixed $errors ): void {
		if ( ! $order instanceof \WC_Order || ! $errors instanceof \WP_Error ) {
			return;
		}
		$uuid = (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META );
		if ( '' === $uuid ) {
			return; // Not ours. A shop sells other things.
		}
		$reason = $this->guardedRefusal( $uuid, null );
		if ( null !== $reason ) {
			$errors->add( 'reservant_' . $reason, self::message( $reason ) );
		}
	}

	/**
	 * The classic pay-for-order door - the URL `ApproveBooking` emails. `pay_action()` runs the
	 * gateway only while there are no error notices, so the refusal notice IS the brake.
	 *
	 * @param mixed $order the order the guest is paying
	 */
	public function onPayAction( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$uuid = (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META );
		if ( '' === $uuid ) {
			return;
		}
		$reason = $this->guardedRefusal( $uuid, null );
		if ( null !== $reason && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message( $reason ), 'error' );
		}
	}

	/**
	 * Courtesy display on the pay-for-order PAGE: the guest opening a dead link reads the refusal
	 * above the form instead of typing card details into a payment that `onPayAction()` will
	 * refuse anyway. Display only - the brake is the POST-side hook.
	 *
	 * @param mixed $order the order the form would collect payment for
	 */
	public function onPayForm( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$uuid = (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META );
		if ( '' === $uuid ) {
			return;
		}
		$reason = $this->guardedRefusal( $uuid, null );
		if ( null !== $reason && function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( self::message( $reason ), 'error' );
		}
	}

	/**
	 * The verdict, under the section-2.2 locks: null when payment may proceed, otherwise a reason
	 * (`hold_expired`, `not_payable`, `cart_mismatch`) for `message()`.
	 *
	 * These reasons never travel through `Rest\Errors` - the doors speak WooCommerce's own error
	 * channels - so they are deliberately NOT added to its `KNOWN_REASONS`.
	 *
	 * @param array<int, int>|null $lines On the cart doors, what the cart claims: booking item id
	 *                                    => line quantity. Verified against the booking's own items
	 *                                    inside the same locked read, so a cart quantity edited
	 *                                    after boarding cannot underpay. Null on the order doors -
	 *                                    an order's lines were written by this plugin and no guest
	 *                                    can edit them.
	 */
	public function refusal( string $uuid, \DateTimeImmutable $nowUtc, ?array $lines = null ): ?string {
		// Unlocked read only to learn WHICH mutexes govern this booking; every decision below is
		// re-made on the locked re-read.
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			return 'not_payable';
		}
		/** @var list<array<string, mixed>> $plannedItems */
		$plannedItems = $booking['items'];
		$keys         = LockKey::forItems( $plannedItems );
		// Mutex rows must exist before the transaction opens (section 2.2). The hold created them;
		// ensure() is the protocol's own idempotent guarantee that they still do.
		$this->resourceDays->ensure( $keys );

		/** @var string|null $verdict */
		$verdict = $this->txn->run(
			function () use ( $keys, $uuid, $nowUtc, $lines ): ?string {
				$this->locks->acquire( $keys );
				$fresh = $this->bookings->findByUuidForUpdate( $uuid );
				if ( null === $fresh ) {
					return 'not_payable';
				}
				$status = BookingStatus::from( (string) $fresh['status'] );
				if ( BookingStatus::Confirmed === $status ) {
					// The slot is already theirs; whether money is still owed for it is the
					// site's business (`reservant/allow_direct_confirm` sites collect late).
					return null;
				}
				if ( ! in_array( $status, array( BookingStatus::Pending, BookingStatus::AwaitingPayment ), true ) ) {
					return 'not_payable';
				}
				if ( null === $fresh['hold_expires_at'] || (string) $fresh['hold_expires_at'] <= $nowUtc->format( 'Y-m-d H:i:s' ) ) {
					return 'hold_expired';
				}
				/** @var list<array<string, mixed>> $freshItems */
				$freshItems = $fresh['items'];
				if ( null !== $lines && ! self::linesMatch( $lines, $freshItems ) ) {
					return 'cart_mismatch';
				}
				return null;
			}
		);
		return $verdict;
	}

	/**
	 * `refusal()` wrapped in the fail-closed rule: a verification that cannot run
	 * (`lock_unavailable`, the DB away) must not let money move on an unverified hold. Reported on
	 * `reservant/error` - a blocked checkout with no operator trace would read as a ghost.
	 *
	 * @param array<int, int>|null $lines see refusal()
	 */
	private function guardedRefusal( string $uuid, ?array $lines ): ?string {
		try {
			return $this->refusal( $uuid, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $lines );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e, $uuid );
			return 'system_busy';
		}
	}

	/**
	 * What the cart claims, grouped by booking: uuid => (booking item id => line quantity). Reads
	 * only in-memory cart state; carts with no booking lines cost one loop and touch nothing.
	 *
	 * @return array<string, array<int, int>>
	 */
	private function cartBookings( ?\WC_Cart $cart = null ): array {
		$cart ??= WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return array();
		}
		$bookings = array();
		foreach ( $cart->get_cart() as $line ) {
			$facts = is_array( $line ) ? CartBridge::lineFacts( $line ) : null;
			if ( null === $facts ) {
				continue;
			}
			$uuid                = (string) $facts['booking_uuid'];
			$bookings[ $uuid ] ??= array();

			$bookings[ $uuid ][ (int) ( $facts['item_id'] ?? 0 ) ] = (int) ( $line['quantity'] ?? 0 );
		}
		return $bookings;
	}

	/**
	 * Does the cart still say what the booking says? Every item present, nothing extra, and every
	 * line at the quantity `CartBridge::lineShape()` derived - the one rule, re-read here rather
	 * than copied.
	 *
	 * @param array<int, int>            $lines booking item id => cart line quantity
	 * @param list<array<string, mixed>> $items the booking's own items, from the locked read
	 */
	private static function linesMatch( array $lines, array $items ): bool {
		if ( count( $lines ) !== count( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( ! isset( $lines[ $id ] ) || CartBridge::lineShape( $item )['quantity'] !== $lines[ $id ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A sentence per refusal, phrased for the guest who is about to NOT be charged. `hold_expired`
	 * reuses `Rest\Errors`'s exact wording so the widget and the checkout tell one story.
	 */
	private static function message( string $reason ): string {
		return match ( $reason ) {
			'hold_expired'  => __( 'Your reservation expired. Please start again.', 'reservant' ),
			'cart_mismatch' => __( 'Your cart no longer matches your reservation. Please start again.', 'reservant' ),
			'system_busy'   => __( 'The system was busy. Please try again.', 'reservant' ),
			default         => __( 'This booking can no longer be paid for.', 'reservant' ),
		};
	}
}
