<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

/** Converts UTC intervals into a FreeBusyMask over an N-day window starting at a UTC midnight. */
final class MaskBuilder {

	public function __construct( public readonly int $granularityMin = 5 ) {
		if ( 0 !== 1440 % $this->granularityMin ) {
			throw new \InvalidArgumentException( 'Granularity must divide 1440.' );
		}
	}

	public function slotsForDays( int $days ): int {
		return intdiv( 1440, $this->granularityMin ) * $days;
	}

	/**
	 * The two masks of one resource-day, kept apart on purpose (see ResourceMasks).
	 *
	 * Rounding is conservative in both directions: an open window shrinks to whole slots
	 * (a half-covered slot is not open) while a busy range grows to whole slots (a half-covered
	 * slot is not free). `busyFreeMask` starts from a FULL window, not from the open hours, so
	 * everything the roster excludes is still "not booked" - which is exactly what a buffer needs.
	 *
	 * @param list<array{\DateTimeImmutable,\DateTimeImmutable}> $openUtc working windows
	 * @param list<array{\DateTimeImmutable,\DateTimeImmutable}> $busyUtc block ranges incl. buffers
	 */
	public function masks( \DateTimeImmutable $windowStartUtc, int $days, array $openUtc, array $busyUtc ): ResourceMasks {
		$slots = $this->slotsForDays( $days );
		$open  = FreeBusyMask::fromIntervals(
			$slots,
			array_map(
				fn ( array $iv ): array => array( $this->ceilSlot( $windowStartUtc, $iv[0] ), $this->floorSlot( $windowStartUtc, $iv[1] ) ),
				$openUtc
			)
		);
		$busy  = FreeBusyMask::fromIntervals(
			$slots,
			array_map(
				fn ( array $iv ): array => array( $this->floorSlot( $windowStartUtc, $iv[0] ), $this->ceilSlot( $windowStartUtc, $iv[1] ) ),
				$busyUtc
			)
		);
		return new ResourceMasks( $open, FreeBusyMask::full( $slots )->andNot( $busy ) );
	}

	private function floorSlot( \DateTimeImmutable $origin, \DateTimeImmutable $t ): int {
		return (int) floor( $this->minutesFrom( $origin, $t ) / $this->granularityMin );
	}

	private function ceilSlot( \DateTimeImmutable $origin, \DateTimeImmutable $t ): int {
		return (int) ceil( $this->minutesFrom( $origin, $t ) / $this->granularityMin );
	}

	private function minutesFrom( \DateTimeImmutable $origin, \DateTimeImmutable $t ): float {
		return ( $t->getTimestamp() - $origin->getTimestamp() ) / 60;
	}
}
