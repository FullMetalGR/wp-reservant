<?php
declare( strict_types=1 );

namespace Reservant\Integrations\WooCommerce;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * Maps the WooCommerce order lifecycle onto Reservant bookings (AGENTS.md section 6): an order
 * reaching a paid status confirms its booking; an order reaching `cancelled`, `failed` or
 * `refunded` releases it. The order is found on `woocommerce_order_status_changed` and its booking
 * through `WooPaymentProvider::ORDER_UUID_META` - an order without that meta is not ours and is
 * ignored completely, because a shop sells other things than time.
 *
 * One hook, deliberately. Every path money takes ends in a status transition: a gateway calling
 * `WC_Order::payment_complete()` lands on `processing`/`completed`, an owner marking an order paid
 * in wp-admin lands on the same, a full refund lands on `refunded` - and
 * `woocommerce_order_status_changed` fires for each of them. Listening additionally on
 * `woocommerce_payment_complete` would only hand the same order over twice. What a single
 * status-shaped ear does NOT hear is a partial refund (no status change), and that is correct:
 * a partial refund is the owner compensating a customer whose booking still stands, not a
 * cancellation - AGENTS.md section 6 leaves refund decisions to the owner entirely.
 *
 * **Nothing here may throw at WooCommerce.** These hooks fire in the middle of checkout, of a
 * gateway's webhook handler, of an owner's order screen - all outside any Reservant transaction -
 * so an exception escaping this class is a WooCommerce-facing fatal that can break the very payment
 * it is reporting. Every failure is caught, reported on `reservant/error` with the booking uuid as
 * context, and swallowed: the same discipline `Notifications\Mailer` and
 * `AuditLog::recordAfterCommit()` follow, for the same reason - the money already moved, and the
 * customer must not pay for our bookkeeping.
 *
 * **Idempotent by re-reading, not by hoping.** WooCommerce delivers the same transition more than
 * once in real life - a gateway callback races an owner refreshing the order screen, `processing`
 * then `completed` are both paid statuses - so "the outcome already holds" is a normal input here,
 * not an error. The use cases refuse a repeat before firing their hook (`ConfirmBooking` never
 * reaches `reservant/booking/confirmed` twice, so the customer is emailed once), and this class
 * tells a benign refusal from a real failure by re-reading the booking: if the state the repeat
 * wanted is the state the row is in - or, for a release, a state that no longer holds capacity -
 * the refusal is silence, not a report.
 */
final class OrderObserver {

	/**
	 * The `$toStatus` values (unprefixed, as the hook passes them) that mean the booking's slot
	 * must be released. `cancelled` and `failed` are the checkout dying; `refunded` is WooCommerce's
	 * status for a FULL refund, which only an owner can perform - and an owner refunding the whole
	 * order is saying the appointment is off.
	 */
	private const RELEASE_STATUSES = array( 'cancelled', 'failed', 'refunded' );

	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( new self(), 'onStatusChanged' ), 10, 4 );
	}

	/**
	 * The WooCommerce-facing skin: pull the booking uuid off the order and route the transition.
	 * All parameters are `mixed` on purpose - a scalar type here would make PHP itself throw a
	 * `TypeError` at whatever fired the hook, BEFORE the try/catch below could catch it, and
	 * third-party code does fire this hook with arguments of its own devising.
	 *
	 * @param mixed $orderId    Unused: the order object carries everything needed, and re-fetching
	 *                          by id would race the very save that fired the hook.
	 * @param mixed $fromStatus Unused: which transitions matter is a fact about where the order
	 *                          ARRIVED, and keying on the origin would miss transitions WooCommerce
	 *                          composes differently across versions and gateways.
	 * @param mixed $toStatus   The status the order arrived at, unprefixed (`processing`, not
	 *                          `wc-processing`).
	 * @param mixed $order      The `WC_Order`, per the hook's contract since WC 3.0.
	 */
	public function onStatusChanged( mixed $orderId, mixed $fromStatus, mixed $toStatus, mixed $order = null ): void {
		unset( $orderId, $fromStatus );
		try {
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$uuid = (string) $order->get_meta( WooPaymentProvider::ORDER_UUID_META );
			if ( '' === $uuid ) {
				return; // Not ours. Silently and completely: a shop sells other things.
			}
			// `wc_get_is_paid_statuses()` rather than a literal list, because the shop's definition
			// of "paid" is filterable (`woocommerce_order_is_paid_statuses`) and a site that widened
			// it expects everything downstream of payment - including us - to honour the widening.
			if ( in_array( (string) $toStatus, wc_get_is_paid_statuses(), true ) ) {
				$this->confirm( $uuid );
				return;
			}
			if ( in_array( (string) $toStatus, self::RELEASE_STATUSES, true ) ) {
				$this->release( $uuid );
			}
		} catch ( \Throwable $e ) {
			// Backstop for the extraction above; confirm() and release() each contain their own.
			do_action( 'reservant/error', $e );
		}
	}

	/**
	 * The booking-side consequence of a paid order. Public, and taking a uuid rather than an order,
	 * so the Reservant half of the mapping is testable without WooCommerce in the loop - the
	 * fast/slow test split of `tests/Integration/Payment/` vs
	 * `tests/Integration/Integrations/WooCommerce/`.
	 */
	public function confirm( string $uuid ): void {
		global $wpdb;
		try {
			// `paymentSettled: true` is THIS call site's assertion and nobody else's - see the
			// parameter's docblock in `ConfirmBooking` for why it is an argument rather than a
			// loosened guard or a toggled filter. This class qualifies to make it because it is
			// only ever reached downstream of a real order reporting a paid status.
			ConfirmBooking::make( $wpdb )->execute( $uuid, $this->now(), null, true );
		} catch ( \Throwable $e ) {
			// A refusal whose complaint is "already confirmed" is the repeat delivery this class
			// promises to absorb (`not_confirmable` on a confirmed row, or `stale_state` from
			// losing the compare-and-set to a rival confirm - both leave the row Confirmed). Every
			// other refusal is money arriving for a booking that cannot go ahead - the hold lapsed
			// (`hold_expired`), the booking was cancelled, the row is gone - and the owner must
			// hear about THAT: the customer has paid for a slot they do not hold.
			if ( BookingStatus::Confirmed === $this->statusOf( $uuid ) ) {
				return;
			}
			do_action( 'reservant/error', $e, $uuid );
		}
	}

	/**
	 * The booking-side consequence of a dead order: cancelled, failed or refunded, the slot goes
	 * back on sale. Public and uuid-shaped for the same fast/slow test split as `confirm()`.
	 *
	 * `force: true` because the customer's cancellation window is a policy about the CUSTOMER
	 * changing their mind, not about their payment dying: a failed payment inside the window would
	 * otherwise leave a hold nobody will ever pay for pinned to the slot until its TTL reaps it.
	 * Force also spans both shapes this arrives in - a still-held booking (checkout died) and an
	 * already-confirmed one (owner refunded) - which is exactly the pair `CancelBooking` handles.
	 */
	public function release( string $uuid ): void {
		global $wpdb;
		try {
			CancelBooking::make( $wpdb )->execute( $uuid, $this->now(), true );
		} catch ( \Throwable $e ) {
			// Benign iff the booking already holds no capacity to release: a repeat delivery
			// (already cancelled), a hold the sweeper already reaped (expired), a rejection - and
			// the two terminal outcomes, completed and no-show, where the appointment already
			// happened and a refund afterwards is the owner's business, decided in the very screen
			// that fired this hook. The enum knows that set as "cannot transition to Cancelled";
			// a row still IN a cancellable state after a refusal means the release genuinely
			// failed while the order side already believes it happened - report it.
			$status = $this->statusOf( $uuid );
			if ( null !== $status && ! $status->canTransitionTo( BookingStatus::Cancelled ) ) {
				return;
			}
			do_action( 'reservant/error', $e, $uuid );
		}
	}

	/**
	 * The booking's current status, or null when it cannot be known - post-failure forensics for
	 * the benign/real split above, so it must never throw INTO a catch block: the original failure
	 * is the one worth reporting, and a read that also fails simply fails to prove benignity.
	 */
	private function statusOf( string $uuid ): ?BookingStatus {
		global $wpdb;
		try {
			$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
			return null === $row ? null : BookingStatus::from( (string) $row['status'] );
		} catch ( \Throwable $ignored ) {
			return null;
		}
	}

	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
