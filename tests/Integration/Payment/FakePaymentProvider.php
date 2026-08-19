<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Payment;

use Reservant\Application\Payment\PaymentProvider;

/**
 * The fast half of P7's two-layer test strategy: a provider that records what it was asked to do and
 * answers however the test needs, so use-case and REST behaviour can be pinned without WooCommerce
 * in the loop.
 *
 * It exists because "is a payment possible" and "did WooCommerce build the right order" are two
 * different questions with two different costs. `ConfirmBooking`'s degrade rule, the admin notice,
 * and the service mirror being called at all are decided by the ANSWER, not by the implementation -
 * and asserting them against real WooCommerce would mean building products and orders to test a
 * boolean. The WC-specific behaviour has its own suite
 * (`tests/Integration/Integrations/WooCommerce`), which is where the real thing belongs.
 *
 * Install it with `Providers::reset()` then a `reservant/payment_provider` filter; the base test case
 * resets between cases so one test's fake cannot leak into the next.
 */
final class FakePaymentProvider implements PaymentProvider {

	/** @var list<array<string, mixed>> every service handed to syncService(), in order */
	public array $synced = array();

	/** @var list<array<string, mixed>> every booking handed to createOrder(), in order */
	public array $ordered = array();

	/** @var list<array{id: int, note: string}> every flagOrder() call, in order */
	public array $flagged = array();

	public function __construct(
		private readonly bool $available = true,
		private readonly ?int $productId = 4242,
		private readonly ?int $orderId = 9001,
	) {}

	public function isAvailable(): bool {
		return $this->available;
	}

	/** @param array<string, mixed> $service */
	public function syncService( array $service ): ?int {
		$this->synced[] = $service;
		return $this->productId;
	}

	/** @param array<string, mixed> $booking */
	public function createOrder( array $booking ): ?int {
		$this->ordered[] = $booking;
		return $this->orderId;
	}

	public function paymentUrl( int $orderId ): ?string {
		return "https://example.test/pay/{$orderId}";
	}

	public function flagOrder( int $orderId, string $note ): void {
		$this->flagged[] = array(
			'id'   => $orderId,
			'note' => $note,
		);
	}
}
