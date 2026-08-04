<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/** Seats on a fixed occurrence: capacity-only when $seatIds is empty, otherwise a grid pick. */
final class EventRequest {

	/** @param list<int> $seatIds */
	public function __construct(
		public readonly int $occurrenceId,
		public readonly int $seats = 1,
		public readonly array $seatIds = array(),
	) {
		if ( $this->seats < 1 ) {
			throw new \InvalidArgumentException( 'At least one seat is required.' );
		}
		if ( array() !== $this->seatIds && count( $this->seatIds ) !== $this->seats ) {
			throw new \InvalidArgumentException( 'Seat ids must match the seat count.' );
		}
	}
}
