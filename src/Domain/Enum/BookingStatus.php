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

	/**
	 * Whether arriving at this status hands the booking's seat claims back.
	 *
	 * The rule is "the appointment stopped happening", not "the status is terminal" - which is why
	 * `Completed` and `NoShow` are false despite being just as terminal as `Cancelled`. A booking
	 * that ran (or that the customer failed to turn up for) consumed its seat; a booking that was
	 * cancelled, refused or left to lapse never did, and the seat goes back on sale.
	 *
	 * Stated here rather than at the three call sites because `Application\GuardedWrite` derives the
	 * release from the target status instead of taking it as a flag - a flag is a thing a fifth
	 * transition can set wrongly, and "which statuses free the seat" is a fact about the domain, not
	 * about any one use case. `MarkBookingOutcome` is the reason the distinction is not academic: it
	 * is the one transition to a terminal status that deliberately does NOT release, and it would be
	 * the one an `isTerminal()`-shaped predicate silently broke.
	 */
	public function releasesSeatClaims(): bool {
		return in_array( $this, array( self::Cancelled, self::Rejected, self::Expired ), true );
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
