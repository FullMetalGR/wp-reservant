<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/**
 * A booking container snapshot, as read back from `BookingRepository::findByUuid()` /
 * `findById()`. This is what every `reservant/booking/*` and `reservant/hold/expired` action
 * hands to listeners (AGENTS.md section 7: "Pass DTOs, not arrays.") - the row array itself
 * never leaves the Application layer.
 *
 * `$manageToken` is the one field that is not a column. It is the guest's plaintext credential
 * (`Application\ManageToken`), which exists for the length of one request and is never stored -
 * only its SHA-256 hash is. It rides here because the emailed manage link cannot be built without
 * it and no later listener can reconstruct it, so `HoldBooking::execute()` is the only place it can
 * be handed over. It is therefore populated ONLY on the two hooks that use case fires - `held`
 * always, and `confirmed` on the admin-created booking that skips the hold entirely - and null on
 * every other hook, including `ConfirmBooking`'s own `confirmed`. A listener wanting the link must
 * treat its absence as ordinary rather than as an error: see `Notifications\BookingEmails`, which
 * sends the link exactly once, from whichever of those two emails the guest actually receives.
 *
 * `toArray()` deliberately does NOT emit it - see that method.
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
		public readonly ?string $manageToken = null,
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
			isset( $row['manage_token'] ) ? (string) $row['manage_token'] : null,
		);
	}

	/**
	 * The persistable row shape - which is why `manage_token` is absent from it even when the
	 * snapshot carries one.
	 *
	 * This is the array form a listener reaches for when it wants to store, log or forward the
	 * booking, and the plaintext credential belongs in exactly one place: the link in the email
	 * this plugin sends. Emitting it here would put it wherever any third-party listener puts the
	 * array, permanently, while the hash beside it in the database exists precisely so the secret
	 * is never at rest. The asymmetry with `fromArray()` is the point, not an oversight: the
	 * credential travels IN to the DTO from the one use case that mints it, and does not travel out.
	 *
	 * @return array<string, mixed> BookingRepository::findByUuid() row naming.
	 */
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
