<?php
declare( strict_types=1 );

namespace Reservant\Application\Payment;

/**
 * The one seam between this plugin and whatever takes the money (AGENTS.md section 6).
 *
 * **No WooCommerce class, function or constant may be referenced outside
 * `src/Integrations/WooCommerce/`.** That rule is what this interface exists to make keepable:
 * everything else - use cases, REST controllers, the admin - talks to these six methods and never
 * learns whether an order is a `WC_Order`, a Stripe session, or nothing at all.
 *
 * **Absence is a supported configuration, not an error.** With WooCommerce inactive, `Payment\Providers`
 * hands out `NullPaymentProvider` and `online` services degrade to `onsite` (the owner takes the
 * money in person) with an admin notice saying so. That is why every method here has a meaningful
 * "there is no payment system" answer rather than throwing: a site that deactivates WooCommerce on a
 * Tuesday must keep taking bookings on the Wednesday.
 */
interface PaymentProvider {

	/**
	 * Whether online payment can actually happen right now.
	 *
	 * The single question `ConfirmBooking` asks before refusing `online_payment_required`: refusing
	 * a payment that no provider could ever collect would strand every booking for an `online`
	 * service on a site with no payment plugin, which is precisely the degrade-to-onsite case.
	 */
	public function isAvailable(): bool;

	/**
	 * Make the payment system's mirror of this service match the service, and report what to store
	 * in `services.wc_product_id`.
	 *
	 * One method rather than create/update/delete because the caller's intent is always the same
	 * ("the service was saved; make the mirror true") and the branching is the provider's business.
	 * A service whose `payment_mode` is no longer `online` returns `null`, and the provider is
	 * expected to have cleaned up whatever it had - so the caller's stored id is cleared by the same
	 * call that made it stale.
	 *
	 * Reservant's own price is the source of truth; the mirror never feeds a price back.
	 *
	 * @param array<string, mixed> $service Service row, including its current `wc_product_id`.
	 * @return int|null Product id to store, or null to clear it.
	 */
	public function syncService( array $service ): ?int;

	/**
	 * Create the one order that belongs to this booking - one order per booking, one line item per
	 * booking item (AGENTS.md section 6) - and report its id for `bookings.wc_order_id`.
	 *
	 * Returns null when this provider cannot create orders at all. It does NOT return null for an
	 * ordinary failure: that is an exception, because a booking that believes it is awaiting payment
	 * with no order behind it is a booking nobody can ever pay for.
	 *
	 * @param array<string, mixed> $booking Booking row plus its `items`.
	 */
	public function createOrder( array $booking ): ?int;

	/** The URL a customer pays that order at, or null when there is nothing to pay. */
	public function paymentUrl( int $orderId ): ?string;

	/**
	 * Where a guest takes THIS booking to pay, before any order for it exists - the entry to the
	 * non-approval flow of AGENTS.md section 6 ("hold -> cart -> order paid -> `ConfirmBooking`").
	 *
	 * Null means "not here, not now", and it is the ordinary answer rather than a failure: a free or
	 * onsite booking, a booking waiting on a human, one that already has its order, or a site whose
	 * provider takes money some other way. A caller uses the answer to ROUTE a guest, so a provider
	 * must only return a URL that will still admit this booking when the guest arrives.
	 *
	 * Deliberately separate from `paymentUrl()`, which pays an order that ALREADY exists (the
	 * approval flow). Here there is no order yet and there must not be one: on this flow the order is
	 * what checkout produces.
	 *
	 * The manage token is the guest's own plaintext credential (AGENTS.md section 5), passed because
	 * it exists only at the moment a hold is created and the door the URL leads to has to verify the
	 * arriving guest exactly as every other guest surface does. A provider must not store it.
	 *
	 * @param array<string, mixed> $booking Booking row plus its `items`.
	 * @param string               $manageToken The guest's plaintext manage token.
	 */
	public function checkoutUrl( array $booking, string $manageToken ): ?string;

	/**
	 * Leave a note on the order for the owner - the cancellation path.
	 *
	 * Deliberately not "refund". AGENTS.md section 6 is explicit that taxes, invoicing and refunds
	 * are WooCommerce's job and that this plugin never issues a refund by itself in v1; a
	 * cancellation flags the order and a human decides what the customer is owed.
	 */
	public function flagOrder( int $orderId, string $note ): void;
}
