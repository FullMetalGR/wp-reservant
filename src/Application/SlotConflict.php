<?php
declare( strict_types=1 );

namespace Reservant\Application;

/**
 * A slot that the client believed was free is not (AGENTS.md section 2.2): availability reads are
 * advisory, the locked re-validation is the authority, and this is a normal outcome (HTTP 409).
 */
final class SlotConflict extends \RuntimeException {

	/**
	 * Every reason the hold protocol can refuse a request. The REST layer maps these to the 409
	 * body, so the list is the contract - extend it here first.
	 *
	 * - `overlap`       a blocking item already covers the range on that resource
	 * - `seat_taken`    the seat is claimed on that occurrence
	 * - `capacity`      the open event has no room left
	 * - `no_staff`      no active staff member performs the segment (or the pinned one cannot)
	 * - `not_found`     the service, occurrence, or their status says it is not bookable
	 * - `bad_time`      the chain start is off the granularity grid
	 * - `lead_time`     the start is inside the service's notice period (or already past)
	 * - `horizon`       the start is beyond the service's booking horizon
	 * - `outside_hours` no candidate's working hours cover the segment
	 * - `bad_seat`      the seat ids do not name real seats of the service's map, or the
	 *                   grid/capacity-only mode was mismatched
	 *
	 * @param string $reason one of the codes above
	 * @param int    $segmentIndex chain position of the failing segment, -1 when not per-segment
	 */
	public function __construct(
		public readonly string $reason,
		public readonly int $segmentIndex = -1,
	) {
		parent::__construct( $reason );
	}
}
