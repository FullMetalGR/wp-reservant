<?php
declare( strict_types=1 );

namespace Reservant\Domain\Enum;

enum ServiceType: string {
	case Appointment = 'appointment';
	case Event       = 'event';
}
