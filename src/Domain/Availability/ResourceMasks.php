<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

/**
 * The pair of masks one resource-day needs, because opening hours and buffers answer different
 * questions (AGENTS.md section 2.4).
 *
 * - `openMask` - bit set = the staff member is at work. Knows nothing about bookings.
 * - `busyFreeMask` - bit set = the staff member is not already committed to a blocking booking
 *   (whose own buffers are already baked into its block range). Knows nothing about the roster,
 *   so it is set outside working hours and at the edges of the window: a buffer is allowed to
 *   spill there.
 *
 * They are deliberately not folded into one "free" mask. Doing that - which is what this engine
 * used to do - makes a before-buffer contend with opening time, so a 30-minute service with a
 * 15-minute before-buffer is first offered at 09:15 in a shop that opens at 09:00, while
 * `HoldBooking` (the actual authority) accepts 09:00 all along.
 */
final class ResourceMasks {

	public function __construct(
		public readonly FreeBusyMask $openMask,
		public readonly FreeBusyMask $busyFreeMask,
	) {
		if ( $openMask->slots !== $busyFreeMask->slots ) {
			throw new \InvalidArgumentException( 'Both masks must cover the same window.' );
		}
	}
}
