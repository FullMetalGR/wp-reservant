<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/**
 * A booking container snapshot, as read back from `BookingRepository::findByUuid()` /
 * `findById()`. This is what every `reservant/booking/*` and `reservant/hold/expired` action
 * hands to listeners (AGENTS.md section 7: "Pass DTOs, not arrays.") - the row array itself
 * never leaves the Application layer.
 */
final class BookingSnapshot {

	/**
	 * @param list<array<string, mixed>> $items raw booking_items rows, as returned by the
	 *                                          repository - not remodelled here.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly string $status,
		public readonly ?string $holdClass,
		public readonly ?string $holdExpiresAt,
		public readonly string $customerName,
		public readonly string $customerEmail,
		public readonly string $customerPhone,
		public readonly int $totalMinor,
		public readonly string $currency,
		public readonly string $paymentMode,
		public readonly bool $requiresApproval,
		public readonly array $items,
		public readonly ?string $rejectionReason,
	) {}

	/**
	 * Tolerates missing keys with safe defaults, so a caller building a fixture never has to
	 * populate the whole row shape. `requires_approval` arrives from the DB as the string '0'/'1',
	 * not a bool - normalised via `(bool) (int)` rather than PHP's truthy-string cast, which would
	 * treat '0' as truthy.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromArray( array $row ): self {
		/** @var list<array<string, mixed>> $items */
		$items = isset( $row['items'] ) && is_array( $row['items'] ) ? $row['items'] : array();

		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : 0,
			isset( $row['uuid'] ) ? (string) $row['uuid'] : '',
			isset( $row['status'] ) ? (string) $row['status'] : '',
			isset( $row['hold_class'] ) ? (string) $row['hold_class'] : null,
			isset( $row['hold_expires_at'] ) ? (string) $row['hold_expires_at'] : null,
			isset( $row['customer_name'] ) ? (string) $row['customer_name'] : '',
			isset( $row['customer_email'] ) ? (string) $row['customer_email'] : '',
			isset( $row['customer_phone'] ) ? (string) $row['customer_phone'] : '',
			isset( $row['total_minor'] ) ? (int) $row['total_minor'] : 0,
			isset( $row['currency'] ) ? (string) $row['currency'] : '',
			isset( $row['payment_mode'] ) ? (string) $row['payment_mode'] : '',
			isset( $row['requires_approval'] ) ? (bool) (int) $row['requires_approval'] : false,
			$items,
			isset( $row['rejection_reason'] ) ? (string) $row['rejection_reason'] : null,
		);
	}

	/** @return array<string, mixed> BookingRepository::findByUuid() row naming. */
	public function toArray(): array {
		return array(
			'id'                => $this->id,
			'uuid'              => $this->uuid,
			'status'            => $this->status,
			'hold_class'        => $this->holdClass,
			'hold_expires_at'   => $this->holdExpiresAt,
			'customer_name'     => $this->customerName,
			'customer_email'    => $this->customerEmail,
			'customer_phone'    => $this->customerPhone,
			'total_minor'       => $this->totalMinor,
			'currency'          => $this->currency,
			'payment_mode'      => $this->paymentMode,
			'requires_approval' => $this->requiresApproval,
			'items'             => $this->items,
			'rejection_reason'  => $this->rejectionReason,
		);
	}
}
