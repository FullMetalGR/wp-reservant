<?php
declare( strict_types=1 );

namespace Reservant\Domain\Chain;

use Reservant\Domain\Availability\FreeBusyMask;
use Reservant\Domain\Availability\ResourceMasks;

/**
 * Chain feasibility as mask algebra (AGENTS.md section 2.4). Segments never overlap in time,
 * so staff choices are independent per segment: no search, no backtracking.
 * feasible(t) = INTERSECT over i of shiftDown( UNION over r of segmentStartMask(i, r), offset_i ).
 */
final class ChainResolver {

	public function __construct( private readonly int $granularityMin = 5 ) {}

	/**
	 * @param list<ChainSegment>              $segments
	 * @param list<array<int, ResourceMasks>> $masksBySegment segment index => resourceId => masks
	 */
	public function feasibleStarts( array $segments, array $masksBySegment, bool $sameStaff = false ): FreeBusyMask {
		if ( array() === $segments ) {
			throw new \InvalidArgumentException( 'A chain needs at least one segment.' );
		}
		$slots = $this->windowSlots( $masksBySegment );

		if ( ! $sameStaff ) {
			$result = null;
			foreach ( $segments as $i => $segment ) {
				$union = FreeBusyMask::empty( $slots );
				foreach ( $segment->candidateResourceIds() as $resourceId ) {
					$masks = $masksBySegment[ $i ][ $resourceId ] ?? null;
					if ( null !== $masks ) {
						$union = $union->or( $this->segmentStartMask( $segment, $masks ) );
					}
				}
				$shifted = $union->shiftDown( $this->offsetSlots( $segments, $i ) );
				$result  = null === $result ? $shifted : $result->and( $shifted );
			}
			return $result;
		}

		// Same staff for the whole chain: intersect per resource, then union the resources.
		$common = null;
		foreach ( $segments as $segment ) {
			$ids    = $segment->candidateResourceIds();
			$common = null === $common ? $ids : array_values( array_intersect( $common, $ids ) );
		}
		$result = FreeBusyMask::empty( $slots );
		foreach ( $common as $resourceId ) {
			$perResource = null;
			foreach ( $segments as $i => $segment ) {
				$masks = $masksBySegment[ $i ][ $resourceId ] ?? null;
				if ( null === $masks ) {
					$perResource = FreeBusyMask::empty( $slots );
					break;
				}
				$m           = $this->segmentStartMask( $segment, $masks )->shiftDown( $this->offsetSlots( $segments, $i ) );
				$perResource = null === $perResource ? $m : $perResource->and( $m );
			}
			$result = $result->or( $perResource );
		}
		return $result;
	}

	/**
	 * Bit s = this staff member can serve the segment with the CUSTOMER start at slot s.
	 *
	 * Two independent conditions, because buffers and opening hours constrain different spans:
	 *
	 * - **contention** - the buffer-widened block `[s - bb, s + dur + ba)` must be clear of other
	 *   bookings. Tested against `busyFreeMask`, which knows nothing about the roster, so a buffer
	 *   may legally sit outside working hours;
	 * - **roster** - the service span `[s, s + dur)` must be inside working hours. Tested against
	 *   `openMask`, which knows nothing about bookings.
	 *
	 * This is exactly what `HoldBooking` re-validates under the lock: `coversSpan()` on the service
	 * span, `overlapCount()` on the block range. Folding the two into a single free mask made a
	 * before-buffer contend with opening time and cost the shop the first appointment of every day.
	 */
	public function segmentStartMask( ChainSegment $segment, ResourceMasks $masks ): FreeBusyMask {
		$blockSlots = $this->slotCount( $segment->bufferBeforeMin + $segment->durationMin + $segment->bufferAfterMin );
		$unbooked   = $masks->busyFreeMask->runs( $blockSlots )->shiftUp( $this->slotCount( $segment->bufferBeforeMin ) );
		return $unbooked->and( $masks->openMask->runs( $this->slotCount( $segment->durationMin ) ) );
	}

	/**
	 * Customer-timeline offset of segment $index from the chain start, in slots.
	 * @param list<ChainSegment> $segments
	 */
	public function offsetSlots( array $segments, int $index ): int {
		$minutes = 0;
		for ( $i = 0; $i < $index; $i++ ) {
			$minutes += $segments[ $i ]->durationMin + $segments[ $i ]->processingMin;
		}
		return $this->slotCount( $minutes );
	}

	/**
	 * Deterministic staff assignment for a chosen chain start (advisory - re-validated under lock).
	 * @param list<ChainSegment> $segments
	 * @param list<array<int, ResourceMasks>> $masksBySegment
	 * @return array<int, int> segment index => resource id
	 */
	public function assign( array $segments, array $masksBySegment, int $startSlot ): array {
		$assignment = array();
		foreach ( $segments as $i => $segment ) {
			$slot = $startSlot + $this->offsetSlots( $segments, $i );
			foreach ( $segment->candidateResourceIds() as $resourceId ) {
				$masks = $masksBySegment[ $i ][ $resourceId ] ?? null;
				if ( null !== $masks && $this->segmentStartMask( $segment, $masks )->isSet( $slot ) ) {
					$assignment[ $i ] = $resourceId;
					continue 2;
				}
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new NoFeasibleAssignment( sprintf( 'Segment %d has no free staff at slot %d.', $i, $slot ) );
		}
		return $assignment;
	}

	private function slotCount( int $minutes ): int {
		if ( 0 !== $minutes % $this->granularityMin ) {
			throw new \InvalidArgumentException( 'Minutes must be a multiple of the granularity.' );
		}
		return intdiv( $minutes, $this->granularityMin );
	}

	/** @param list<array<int, ResourceMasks>> $masksBySegment */
	private function windowSlots( array $masksBySegment ): int {
		foreach ( $masksBySegment as $byResource ) {
			foreach ( $byResource as $masks ) {
				return $masks->openMask->slots;
			}
		}
		throw new \InvalidArgumentException( 'No masks supplied.' );
	}
}
