<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Infrastructure\Db\ResourceRepository;

/**
 * Who may serve one chain segment - the single answer both the advisory read and the authoritative
 * write ask for.
 *
 * `AvailabilityQuery` and `HoldBooking` used to answer this question through separate code, and it
 * drifted in three ways at once. Availability drew its pool from `idsForService()` (every linked
 * row, deactivated staff included) while the hold drew from `activeIdsForService()`, so the widget
 * offered slots that only a deactivated staff member could serve and every hold on one came back
 * `no_staff`. The `reservant/chain/candidates` filter - documented in AGENTS.md section 7 as
 * narrowing which staff may serve a segment - was applied on the advisory side and nowhere else, so
 * a site using it to keep someone off a segment had that decision honoured in the offer and ignored
 * by the only path with authority, including auto-assignment landing on the excluded person. And
 * `idsForService()` had no join to the resources table, so a link row whose resource had been
 * deleted outright (there are no FK constraints - AGENTS.md section 4) counted as a live candidate.
 *
 * One rule, one place, both callers. A narrowing that exists on one side now cannot fail to exist on
 * the other, because there is only one side.
 *
 * The refusal lives here too rather than at the call sites: "nobody may serve this segment" and "the
 * staff member you asked for may not serve this segment" are the same decision, and both were
 * already spelled `no_staff` on both paths.
 */
final class SegmentEligibility {

	public function __construct( private readonly ResourceRepository $resources ) {}

	/**
	 * The resources that may serve this segment, ascending by id.
	 *
	 * Ascending order is load-bearing, not incidental: `HoldBooking::pickResource()` takes the first
	 * candidate that is open and unbooked, so this ordering is what makes auto-assignment
	 * deterministic and a failure reproducible in a test.
	 *
	 * The filter runs BEFORE the pinned-resource check, so a filter that removes the customer's
	 * chosen staff member refuses the segment rather than quietly serving someone else.
	 *
	 * @param int      $serviceId  The segment's service.
	 * @param int|null $pinned     The resource the customer asked for, or null for "any staff".
	 * @param int      $index      Segment position, used for the refusal's segment index and passed
	 *                             to the filter so a site can narrow one segment of a chain only.
	 * @return list<int> non-empty, ascending
	 * @throws SlotConflict `no_staff` when the pool is empty, or when `$pinned` is not in it.
	 */
	public function forSegment( int $serviceId, ?int $pinned, int $index ): array {
		$eligible = $this->resources->activeIdsForService( $serviceId );

		/**
		 * Narrow which staff may serve this segment (AGENTS.md section 7). Applied identically on
		 * the availability and hold paths - this is the one call site.
		 *
		 * @param list<int> $eligible
		 */
		$eligible = array_values(
			array_filter(
				(array) apply_filters( 'reservant/chain/candidates', $eligible, $serviceId, $index ),
				'is_int'
			)
		);

		if ( array() === $eligible || ( null !== $pinned && ! in_array( $pinned, $eligible, true ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new SlotConflict( 'no_staff', $index );
		}
		return $eligible;
	}
}
