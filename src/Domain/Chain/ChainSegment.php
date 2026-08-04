<?php
declare( strict_types=1 );

namespace Reservant\Domain\Chain;

final class ChainSegment {
	/** @param list<int> $eligibleResourceIds */
	public function __construct(
		public readonly int $serviceId,
		public readonly int $durationMin,       // staff-occupied service time (no processing).
		public readonly int $processingMin,     // staff-FREE gap after the service.
		public readonly int $bufferBeforeMin,
		public readonly int $bufferAfterMin,
		public readonly array $eligibleResourceIds,
		public readonly ?int $requestedResourceId = null,
	) {
		if ( $this->durationMin < 1 ) {
			throw new \InvalidArgumentException( 'Duration must be positive.' );
		}
		if ( array() === $this->eligibleResourceIds && null === $this->requestedResourceId ) {
			throw new \InvalidArgumentException( 'Segment needs at least one eligible resource.' );
		}
	}

	/** @return list<int> */
	public function candidateResourceIds(): array {
		if ( null !== $this->requestedResourceId ) {
			return array( $this->requestedResourceId );
		}
		$ids = $this->eligibleResourceIds;
		sort( $ids );
		return array_values( $ids );
	}
}
