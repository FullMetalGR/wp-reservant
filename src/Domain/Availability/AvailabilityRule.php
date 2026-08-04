<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

final class AvailabilityRule {
	public function __construct(
		public readonly int $weekday,        // ISO-8601: 1 = Monday .. 7 = Sunday.
		public readonly string $startTime,   // 'HH:MM' in the business timezone.
		public readonly string $endTime,
		public readonly ?string $validFrom = null, // 'Y-m-d' local date, inclusive.
		public readonly ?string $validTo = null,
	) {
		if ( $this->weekday < 1 || $this->weekday > 7 ) {
			throw new \InvalidArgumentException( 'Weekday must be ISO 1-7.' );
		}
	}
}
