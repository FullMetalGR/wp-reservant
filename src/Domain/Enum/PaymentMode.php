<?php
declare( strict_types=1 );

namespace Reservant\Domain\Enum;

enum PaymentMode: string {
	case Free   = 'free';
	case Online = 'online';
	case Onsite = 'onsite';
}
