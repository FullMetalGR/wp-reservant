<?php
declare( strict_types=1 );

namespace Reservant\Rest;

/**
 * The booking presenter for the guest surface, shared by every controller that returns one.
 *
 * The field list and the contact-details rule both live in `BookingPayload`; this trait is the
 * guest-side entry point to it. A caller here holds a signed manage token (or
 * `reservant_manage_bookings`, which `Routes::guard()` accepts in its place), so the booking is
 * theirs to read in full - the capability branch belongs on the admin surface, not this one.
 */
trait PresentsBookings {

	/**
	 * @param array<string, mixed> $booking `BookingRepository::findByUuid()` shape
	 * @return array<string, mixed>
	 */
	private function presentBooking( array $booking ): array {
		return BookingPayload::present( $booking, true );
	}
}
