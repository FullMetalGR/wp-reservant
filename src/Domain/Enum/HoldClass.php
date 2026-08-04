<?php
declare( strict_types=1 );

namespace Reservant\Domain\Enum;

enum HoldClass: string {
	case Checkout = 'checkout';
	case Approval = 'approval';
	case Payment  = 'payment';

	public static function forStatus( BookingStatus $status ): ?self {
		return match ( $status ) {
			BookingStatus::Pending          => self::Checkout,
			BookingStatus::AwaitingApproval => self::Approval,
			BookingStatus::AwaitingPayment  => self::Payment,
			default                         => null,
		};
	}
}
