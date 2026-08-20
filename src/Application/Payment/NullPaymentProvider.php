<?php
declare( strict_types=1 );

namespace Reservant\Application\Payment;

/**
 * The provider a site gets when nothing can take money: WooCommerce inactive, or deactivated
 * yesterday with `online` services still on the books.
 *
 * Every method answers rather than throws, and the answers are what make `online` degrade to
 * `onsite` (AGENTS.md section 6) instead of breaking: `isAvailable()` is false, so `ConfirmBooking`
 * stops refusing `online_payment_required` and the guest can book, and `ApproveBooking` keeps
 * landing approvals on `confirmed` rather than stranding them in `awaiting_payment` with nothing to
 * pay - both ask that one question BEFORE acting, so `createOrder()` is never reached on the
 * degrade path; and `syncService()` returns null, so a stale `wc_product_id` gets cleared rather
 * than pointing at a product that no longer exists. `createOrder()` answering null (the
 * interface's "this provider cannot create orders at all") is therefore a backstop for a filtered
 * provider that claims availability and then cannot deliver, not a signal any use case steers by.
 *
 * The owner is told, once, by `Admin\PaymentNotice` - silence here would look like the money simply
 * stopped arriving.
 */
final class NullPaymentProvider implements PaymentProvider {

	public function isAvailable(): bool {
		return false;
	}

	/** @param array<string, mixed> $service */
	public function syncService( array $service ): ?int {
		return null;
	}

	/** @param array<string, mixed> $booking */
	public function createOrder( array $booking ): ?int {
		return null;
	}

	public function paymentUrl( int $orderId ): ?string {
		return null;
	}

	/**
	 * Nowhere - which IS the degrade. With nothing able to take money, `ConfirmBooking` stops
	 * refusing `online_payment_required`, so the booking confirms where it stands and there is no
	 * checkout to send anybody to. A URL here would be the one thing worse than no payment: a link
	 * to a door that opens onto nothing.
	 *
	 * @param array<string, mixed> $booking
	 */
	public function checkoutUrl( array $booking, string $manageToken ): ?string {
		return null;
	}

	public function flagOrder( int $orderId, string $note ): void {
		// Nothing to flag. Not an error: the cancellation itself has already been audited by the
		// use case that called this, and that record is the one an operator reads.
	}
}
