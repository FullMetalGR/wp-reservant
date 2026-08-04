<?php
declare( strict_types=1 );

namespace Reservant\Rest;

/**
 * The single booking presenter, shared by every controller that returns one.
 *
 * `BookingRepository::findByUuid()` hands back the raw row, which carries two things the wire must
 * never see: the surrogate `id`, and `manage_token_hash` - the stored half of the guest credential
 * (AGENTS.md section 5). Presenting through one function is what keeps a future column from leaking by
 * default.
 */
trait PresentsBookings {

	/**
	 * @param array<string, mixed> $booking `BookingRepository::findByUuid()` shape
	 * @return array<string, mixed>
	 */
	private function presentBooking( array $booking ): array {
		unset( $booking['id'], $booking['manage_token_hash'], $booking['manage_token'] );

		// Nullable admin columns are omitted rather than sent as nulls the client must ignore.
		// (A local, not a class constant: traits may not declare constants before PHP 8.2.)
		foreach ( array( 'approved_at', 'approved_by', 'rejection_reason', 'wc_order_id' ) as $column ) {
			if ( null === ( $booking[ $column ] ?? null ) ) {
				unset( $booking[ $column ] );
			}
		}
		$booking['requires_approval'] = (bool) ( $booking['requires_approval'] ?? false );

		/** @var list<array<string, mixed>> $rows */
		$rows  = $booking['items'] ?? array();
		$items = array();
		foreach ( $rows as $item ) {
			// The container's id under another name - stripping one and keeping the other is theatre.
			unset( $item['booking_id'] );
			$items[] = $item;
		}
		$booking['items'] = $items;

		return $booking;
	}
}
