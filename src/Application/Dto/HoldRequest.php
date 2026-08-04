<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/** One booking container request - exactly one of the two shapes the engine supports. */
final class HoldRequest {

	public function __construct(
		public readonly Customer $customer,
		public readonly ?AppointmentRequest $appointment = null,
		public readonly ?EventRequest $event = null,
	) {
		if ( ( null === $this->appointment ) === ( null === $this->event ) ) {
			throw new \InvalidArgumentException( 'Exactly one of appointment or event.' );
		}
	}
}
