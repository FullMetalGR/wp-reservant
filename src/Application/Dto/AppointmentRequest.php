<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/** An ordered chain of appointment segments starting at one customer-facing time. */
final class AppointmentRequest {

	/** @param list<SegmentChoice> $segments */
	public function __construct(
		public readonly \DateTimeImmutable $startUtc,
		public readonly array $segments,
		public readonly bool $sameStaff = false,
	) {
		if ( array() === $this->segments ) {
			throw new \InvalidArgumentException( 'A chain needs at least one segment.' );
		}
	}
}
