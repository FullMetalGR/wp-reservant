<?php
declare( strict_types=1 );

namespace Reservant\Domain\Seating;

final class Seat {
	/**
	 * @param string $kind 'seat' | 'aisle'
	 */
	public function __construct(
		public readonly string $rowLabel,
		public readonly string $seatLabel,
		public readonly int $sortRow,
		public readonly int $sortCol,
		public readonly string $kind,
	) {}
}
