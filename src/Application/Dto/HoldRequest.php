<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/**
 * One booking container request - exactly one of the two shapes the engine supports.
 *
 * `$admin` (AGENTS.md Task 6) is the owner booking a slot by hand: `HoldBooking` skips the
 * lead-time and horizon refusals for it and lands the container straight on `confirmed` with no
 * hold at all. Every other refusal - outside_hours, overlap, capacity, seat_taken, bad_seat,
 * bad_time, not_found, no_staff - still applies unchanged.
 *
 * Skipping the lead-time arm also skips the guard against a start already in the past: an admin
 * request may backdate `$start` to log a walk-in or a phone booking taken after the fact. This is
 * intentional (a deliberate ruling, not an oversight) - see `HoldBooking::assertWithinWindow()`.
 */
final class HoldRequest {

	public function __construct(
		public readonly Customer $customer,
		public readonly ?AppointmentRequest $appointment = null,
		public readonly ?EventRequest $event = null,
		public readonly bool $admin = false,
	) {
		if ( ( null === $this->appointment ) === ( null === $this->event ) ) {
			throw new \InvalidArgumentException( 'Exactly one of appointment or event.' );
		}
	}
}
