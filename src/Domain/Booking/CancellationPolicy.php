<?php
declare( strict_types=1 );

namespace Reservant\Domain\Booking;

final class CancellationPolicy {

	public function __construct(
		public readonly int $cancelWindowHours,
		public readonly int $rescheduleWindowHours,
	) {
		if ( $this->cancelWindowHours < 0 || $this->rescheduleWindowHours < 0 ) {
			throw new \InvalidArgumentException( 'Windows cannot be negative.' );
		}
	}

	public function canCancel( \DateTimeImmutable $nowUtc, \DateTimeImmutable $firstItemStartUtc ): bool {
		return $nowUtc <= $firstItemStartUtc->sub( new \DateInterval( 'PT' . $this->cancelWindowHours . 'H' ) );
	}

	public function canReschedule( \DateTimeImmutable $nowUtc, \DateTimeImmutable $firstItemStartUtc ): bool {
		return $nowUtc <= $firstItemStartUtc->sub( new \DateInterval( 'PT' . $this->rescheduleWindowHours . 'H' ) );
	}
}
