<?php
declare( strict_types=1 );

namespace Reservant\Domain\Enum;

enum BookingStatus: string {
	case Pending          = 'pending';
	case AwaitingApproval = 'awaiting_approval';
	case AwaitingPayment  = 'awaiting_payment';
	case Confirmed        = 'confirmed';
	case Completed        = 'completed';
	case NoShow           = 'no_show';
	case Cancelled        = 'cancelled';
	case Rejected         = 'rejected';
	case Expired          = 'expired';

	/** @return list<self> Statuses that block capacity while hold_expires_at is in the future. */
	public static function heldStatuses(): array {
		return array( self::Pending, self::AwaitingApproval, self::AwaitingPayment );
	}

	public function isHeld(): bool {
		return in_array( $this, self::heldStatuses(), true );
	}

	public function canTransitionTo( self $to ): bool {
		$allowed = match ( $this ) {
			self::Pending          => array( self::Confirmed, self::Cancelled, self::Expired ),
			self::AwaitingApproval => array( self::AwaitingPayment, self::Confirmed, self::Rejected, self::Cancelled, self::Expired ),
			self::AwaitingPayment  => array( self::Confirmed, self::Cancelled, self::Expired ),
			self::Confirmed        => array( self::Completed, self::NoShow, self::Cancelled ),
			default                => array(),
		};
		return in_array( $to, $allowed, true );
	}
}
