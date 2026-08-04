<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

/** A date-specific override: closed all day, or one window replacing the rules. */
final class AvailabilityException {
	public function __construct(
		public readonly string $localDate,   // 'Y-m-d' in the business timezone.
		public readonly bool $closed,
		public readonly ?string $startTime = null,
		public readonly ?string $endTime = null,
	) {
		if ( ! $this->closed && ( null === $this->startTime || null === $this->endTime ) ) {
			throw new \InvalidArgumentException( 'An open exception needs a start and end time.' );
		}
	}
}
