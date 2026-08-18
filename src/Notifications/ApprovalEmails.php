<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

use Reservant\Admin\ApprovalActionEndpoint;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;

/**
 * The approval-flow email set (AGENTS.md "Approval holds": "Owner emails carry one-click signed
 * approve/reject links so the decision never requires a wp-admin login").
 *
 * Four keys, one per hook this class listens on: `approval_request` (`reservant/booking/held`,
 * only when the snapshot requires approval) and `approval_nag` (`reservant/approval/nag`, the
 * 25/50/75% reminder `Infrastructure\Scheduler\Jobs::nag()` fires) both go to the approver - the
 * staff member assigned to the booking, or the site admin when none is assigned - and both carry a
 * signed approve URL and a signed reject URL (`ApprovalActionEndpoint::url()`). `booking_approved`
 * and `booking_rejected` go to the customer, the latter carrying the owner's rejection reason
 * verbatim.
 *
 * `Mailer::send()` never throws (its own contract), so a broken mail transport degrades to a logged
 * `reservant/error`, never a failed booking transition. Every one of these four hooks fires AFTER the
 * transition it announces has committed, so that rule binds this whole class and not only the mailer:
 * nothing here may throw out of a listener, because doing so converts a committed booking into the
 * caller's failure report. The one statement that can fail on its own is the booking re-read in
 * `sendApproverEmail()` - see that method's docblock, and `Infrastructure\Db\AuditLog::record()` for
 * the pre-decision / post-commit split this belongs to. `approverEmail()`'s resource lookup is
 * unguarded at the repository and so cannot refuse; it falls back to the site admin.
 */
final class ApprovalEmails {

	public static function register(): void {
		add_action( 'reservant/booking/held', array( self::class, 'onHeld' ) );
		add_action( 'reservant/approval/nag', array( self::class, 'onNag' ), 10, 2 );
		add_action( 'reservant/booking/approved', array( self::class, 'onApproved' ) );
		add_action( 'reservant/booking/rejected', array( self::class, 'onRejected' ) );
	}

	/** `reservant/booking/held` - only an approval-gated booking gets the approver email. */
	public static function onHeld( BookingSnapshot $snapshot ): void {
		if ( ! $snapshot->requiresApproval ) {
			return;
		}
		self::sendApproverEmail( 'approval_request', $snapshot, array() );
	}

	/** `reservant/approval/nag` - the 25/50/75% reminder, same recipient and same links. */
	public static function onNag( BookingSnapshot $snapshot, int $percent ): void {
		self::sendApproverEmail( 'approval_nag', $snapshot, array( 'percent' => $percent ) );
	}

	/** `reservant/booking/approved` - confirmation to the customer. */
	public static function onApproved( BookingSnapshot $snapshot ): void {
		Mailer::send(
			'booking_approved',
			$snapshot->customerEmail,
			self::approvedSubject(),
			self::approvedBody( $snapshot ),
			array( 'booking' => $snapshot )
		);
	}

	/** `reservant/booking/rejected` - the reason rides along verbatim (AGENTS.md "Approval queue"). */
	public static function onRejected( BookingSnapshot $snapshot ): void {
		Mailer::send(
			'booking_rejected',
			$snapshot->customerEmail,
			self::rejectedSubject(),
			self::rejectedBody( $snapshot ),
			array( 'booking' => $snapshot )
		);
	}

	/**
	 * Shared by `approval_request` and `approval_nag`: both go to the same approver with the same
	 * pair of signed links, built off the fresh row's `updated_at` (`ApprovalActionEndpoint::url()`
	 * binds the signature to it, so a stale in-hook snapshot would mint a link the endpoint itself
	 * would reject on the next write) and the snapshot's own `hold_expires_at`, which is the
	 * approval window's authoritative deadline.
	 *
	 * **This read is POST-COMMIT, and a DB fault on it is treated exactly like an absent row: skip the
	 * email.** `AuditLog::record()`'s docblock states the pre-decision / post-commit split in full.
	 * Concretely here: `reservant/booking/held` fires from `HoldBooking::execute()` AFTER
	 * `$this->txn->run()` has returned, so the hold is committed and its listeners are side work. Once
	 * `findByUuid()` began refusing a failed read, that refusal travelled out of this hook, out of
	 * `HoldBooking::execute()`, and into `Rest\HoldsController::create()`'s own `catch` - which answered
	 * 409 `lock_unavailable` for a booking that EXISTS and, because it returned from that catch, never
	 * reached the two lines below it that hand the guest their `manage_token`. The result was a
	 * committed booking the customer could neither manage nor cancel, a 409 inviting them to book it a
	 * second time, and (the hook fires before `scheduleApprovalTimers()`) a hold with no nags and no
	 * timeout either. The same read is reached from `Infrastructure\Scheduler\Jobs::nag()`, where the
	 * refusal instead failed a scheduled action outright.
	 *
	 * Losing an approver email costs the owner one notification, and the nag timers will try again.
	 * Losing the booking's own credential costs the customer the booking. The pre-guard behaviour -
	 * `null` and skip - is therefore the right answer, and it is restored here rather than at the
	 * repository, whose refusal is correct for the pre-decision callers that decide on it.
	 *
	 * `Jobs::nag()`'s and `Jobs::timeout()`'s OWN reads keep the refusal on purpose: those decide
	 * whether the job does anything at all, nothing has committed when they run, and Action Scheduler
	 * marking a throwing action failed is the accepted outcome for a job (`ExpireHolds`'s docblock
	 * documents that precedent).
	 *
	 * @param array<string, mixed> $extra additional filter context (e.g. the nag percent).
	 */
	private static function sendApproverEmail( string $key, BookingSnapshot $snapshot, array $extra ): void {
		if ( null === $snapshot->holdExpiresAt ) {
			return; // Defensive only: an approval-gated hold always carries an expiry.
		}

		global $wpdb;
		try {
			$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( $snapshot->uuid );
		} catch ( \RuntimeException $exception ) {
			// Reported so the lost email is visible, then treated as absence - see above.
			do_action( 'reservant/error', $exception, $key );
			return;
		}
		if ( null === $fresh ) {
			return; // Gone by the time the mailer runs - nothing left to notify about.
		}

		$expiresTs = ( new \DateTimeImmutable( $snapshot->holdExpiresAt, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$updatedAt = (string) $fresh['updated_at'];

		$approveUrl = ApprovalActionEndpoint::url( $snapshot->uuid, 'approve', $updatedAt, $expiresTs );
		$rejectUrl  = ApprovalActionEndpoint::url( $snapshot->uuid, 'reject', $updatedAt, $expiresTs );

		Mailer::send(
			$key,
			self::approverEmail( $snapshot ),
			self::approverSubject( $key ),
			self::approverBody( $snapshot, $approveUrl, $rejectUrl ),
			$extra + array(
				'booking'     => $snapshot,
				'approve_url' => $approveUrl,
				'reject_url'  => $rejectUrl,
			)
		);
	}

	/**
	 * The assigned staff member's email, falling back to the site admin - AGENTS.md "Approval
	 * decisions are made by admins or by the staff member assigned to the booking." An event
	 * booking's item carries no `resource_id` (it books an occurrence, not a staff member), which
	 * falls back here exactly like a deactivated/unassigned appointment resource would.
	 */
	private static function approverEmail( BookingSnapshot $snapshot ): string {
		$resourceId = $snapshot->items[0]['resource_id'] ?? null;
		if ( null !== $resourceId ) {
			global $wpdb;
			$resource = ( new ResourceRepository( $wpdb ) )->find( (int) $resourceId );
			$email    = null !== $resource ? (string) ( $resource['email'] ?? '' ) : '';
			if ( '' !== $email ) {
				return $email;
			}
		}
		return (string) get_option( 'admin_email' );
	}

	private static function approverSubject( string $key ): string {
		return 'approval_nag' === $key
			? __( 'Reminder: a booking is still awaiting your approval', 'reservant' )
			: __( 'New booking awaiting your approval', 'reservant' );
	}

	private static function approverBody( BookingSnapshot $snapshot, string $approveUrl, string $rejectUrl ): string {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: customer name */
			__( '%s has requested a booking that needs your approval.', 'reservant' ),
			$snapshot->customerName
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: signed one-click approve link */
			__( 'Approve: %s', 'reservant' ),
			$approveUrl
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: signed one-click reject link */
			__( 'Reject: %s', 'reservant' ),
			$rejectUrl
		);
		return implode( "\n", $lines );
	}

	private static function approvedSubject(): string {
		return __( 'Your booking has been approved', 'reservant' );
	}

	private static function approvedBody( BookingSnapshot $snapshot ): string {
		return sprintf(
			/* translators: %s: customer name */
			__( 'Hi %s, your booking has been approved.', 'reservant' ),
			$snapshot->customerName
		);
	}

	private static function rejectedSubject(): string {
		return __( 'Your booking could not be approved', 'reservant' );
	}

	private static function rejectedBody( BookingSnapshot $snapshot ): string {
		$reason = null !== $snapshot->rejectionReason && '' !== $snapshot->rejectionReason
			? $snapshot->rejectionReason
			: __( 'No reason was given.', 'reservant' );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: customer name */
			__( 'Hi %s, unfortunately your booking could not be approved.', 'reservant' ),
			$snapshot->customerName
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the reason given for rejecting the booking */
			__( 'Reason: %s', 'reservant' ),
			$reason
		);
		return implode( "\n", $lines );
	}
}
