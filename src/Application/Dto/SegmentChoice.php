<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/** One link of a chain: the service, plus an optional pinned staff member ("any" when null). */
final class SegmentChoice {

	public function __construct(
		public readonly int $serviceId,
		public readonly ?int $resourceId = null,
	) {}
}
