<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Scheduler;

use Reservant\Application\ApproveBooking;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Application\ExpireHolds;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Licensing\Providers as LicenseProviders;

/**
 * Every Action Scheduler callback this plugin owns: the approval flow's `NAG` and `TIMEOUT`
 * (AGENTS.md "Approval holds"), the recurring hold `SWEEP`, the booking `REMINDER`, and the daily
 * `LICENSE` re-check.
 *
 * Each booking callback re-reads the booking and no-ops unless it is still in the status the timer
 * was set for - the scheduled timestamp and the moment the queue runner actually fires can be
 * minutes apart, and a human (or another job) may have decided the booking in between. That race is
 * not an error: it is the expected, benign outcome the brief calls out explicitly for `TIMEOUT` and
 * `NAG` alike.
 *
 * Those callbacks are also allowed to THROW, and `ExpireHolds`'s docblock documents why that is the
 * accepted outcome for them: nothing has committed when they run, and Action Scheduler marking the
 * action failed is a true report that the work did not happen. `licenseRecheck()` is the one
 * exception, and says at its own docblock why.
 */
final class Jobs {

	public const NAG      = 'reservant/job/approval_nag';
	public const TIMEOUT  = 'reservant/job/approval_timeout';
	public const SWEEP    = 'reservant/job/expire_holds';
	public const REMINDER = 'reservant/job/booking_reminder';
	public const LICENSE  = 'reservant/job/license_recheck';

	public static function register(): void {
		add_action( self::NAG, array( self::class, 'nag' ), 10, 2 );
		add_action( self::TIMEOUT, array( self::class, 'timeout' ), 10, 1 );
		add_action( self::SWEEP, array( self::class, 'sweep' ), 10, 0 );
		add_action( self::REMINDER, array( self::class, 'reminder' ), 10, 1 );
		add_action( self::LICENSE, array( self::class, 'licenseRecheck' ), 10, 0 );
	}

	/**
	 * Fires `reservant/approval/nag` with the current snapshot - Task 9 attaches the mailer there.
	 * No state changes here at all; a nag either reaches a listener or it does not.
	 */
	public static function nag( string $uuid, int $percent ): void {
		global $wpdb;
		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		if ( null === $booking || BookingStatus::AwaitingApproval->value !== $booking['status'] ) {
			return;
		}
		do_action( 'reservant/approval/nag', BookingSnapshot::fromArray( $booking ), $percent );
	}

	/**
	 * The owner's decision window closed without a human answering it. `on_approval_timeout`
	 * decides what happens next, and it is read from the *first approval-requiring segment* of the
	 * chain, not blindly from item 0: a chain is approval-gated the moment any one segment demands
	 * it (AGENTS.md section 2.3), so that segment's service is the one whose policy governs the
	 * container as a whole. The job payload carries only `uuid` (brief-mandated shape), so this is
	 * re-derived here rather than threaded through as a scheduling-time argument.
	 *
	 * `expire`: reuse `ExpireHolds`'s own terminal transition for exactly this one booking, rather
	 * than duplicate the reap/lock/transition sequence.
	 *
	 * `auto_approve`: proceed through `ApproveBooking` as `actor = 'system'`, `actorUserId = null`
	 * (no WP user behind an automatic decision). `ApproveBooking::approvable()` requires
	 * `hold_expires_at` to be strictly in the future of the instant it is asked about - true for a
	 * human approving before the deadline, but this job runs *at* the deadline, where the queue
	 * runner's actual firing time is minutes-to-never predictable versus the scheduled instant. The
	 * `$nowUtc` handed to `ApproveBooking::execute()` is therefore synthesized as one second before
	 * the booking's own `hold_expires_at` - "approved at the moment its window closed" - rather than
	 * read off the wall clock, so the outcome does not depend on how promptly Action Scheduler
	 * happened to run this callback.
	 */
	public static function timeout( string $uuid ): void {
		global $wpdb;
		$bookings = new BookingRepository( $wpdb );
		$booking  = $bookings->findByUuid( $uuid );
		if ( null === $booking || BookingStatus::AwaitingApproval->value !== $booking['status'] ) {
			return; // Someone already decided between schedule and fire - a benign no-op.
		}

		/** @var list<array<string, mixed>> $items */
		$items     = $booking['items'];
		$onTimeout = self::onApprovalTimeout( new ServiceRepository( $wpdb ), $items );

		if ( 'auto_approve' === $onTimeout ) {
			$expiresAt = new \DateTimeImmutable( (string) $booking['hold_expires_at'], new \DateTimeZone( 'UTC' ) );
			try {
				ApproveBooking::make( $wpdb )->execute( $uuid, $expiresAt->modify( '-1 second' ), 'system', null );
			} catch ( \RuntimeException $e ) {
				if ( 'not_approvable' !== $e->getMessage() ) {
					throw $e;
				}
				// Rejected, cancelled, or already approved by someone else in the meantime.
			}
			return;
		}

		ExpireHolds::make( $wpdb )->expireByUuid( $uuid );
	}

	public static function sweep(): void {
		global $wpdb;
		ExpireHolds::make( $wpdb )->run();
	}

	/**
	 * The daily license re-check (`Licensing\LicenseManager::revalidate()`), scheduled by
	 * `Plugin::register()`.
	 *
	 * **The only callback here that swallows.** The others may fail their action, because a failed
	 * booking job is a true report of work that did not happen and an operator should see it. This
	 * one is different in both directions. A failing re-check is already a handled, expected
	 * condition - it opens the grace window precisely so that an unreachable validator changes
	 * nothing for a fortnight - so failing the action would fill an operator's list with alarms
	 * about the design working. And there is nothing behind it to retry on a site's behalf the way
	 * the five-minute sweep backstops `TIMEOUT`; the next run is simply tomorrow.
	 *
	 * `\Throwable`, not `\RuntimeException`: a validator implementation filtered in by a site is
	 * third-party code on a scheduled path, and whatever it throws must not become this plugin's
	 * failed action. The exception goes to `reservant/error`, the documented channel for swallowed
	 * failures (AGENTS.md section 7), so it is visible rather than silent.
	 */
	public static function licenseRecheck(): void {
		try {
			LicenseProviders::get()->revalidate( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		} catch ( \Throwable $exception ) {
			do_action( 'reservant/error', $exception, 'license_recheck' );
		}
	}

	/**
	 * The "your appointment is tomorrow" timer, fired with the current snapshot -
	 * `Notifications\Reminders` attaches the mailer. No state changes here at all, exactly like
	 * `nag()`.
	 *
	 * **The re-read is the authority, not the timer.** `Scheduler::cancel()` is called when a
	 * booking is cancelled or moved, but a cancel that lost the race with the queue runner, or a
	 * job whose args no longer match, would otherwise send a guest a reminder about an appointment
	 * that is not happening - a worse failure than no reminder at all. A booking that is not still
	 * `confirmed` when this runs gets nothing. That is the same benign, expected race
	 * `timeout()` and `nag()` document, and the reason this reads instead of trusting its payload.
	 */
	public static function reminder( string $uuid ): void {
		global $wpdb;
		$booking = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		if ( null === $booking || BookingStatus::Confirmed->value !== $booking['status'] ) {
			return;
		}
		do_action( 'reservant/booking/reminder', BookingSnapshot::fromArray( $booking ) );
	}

	/**
	 * @param list<array<string, mixed>> $items sorted ascending by `sort`
	 *                                          (`BookingRepository::hydrate()`).
	 */
	private static function onApprovalTimeout( ServiceRepository $services, array $items ): string {
		foreach ( $items as $item ) {
			$service = $services->find( (int) $item['service_id'] );
			if ( null !== $service && 1 === (int) $service['requires_approval'] ) {
				return (string) $service['on_approval_timeout'];
			}
		}
		// Defensive only: a booking cannot be `awaiting_approval` without at least one
		// approval-requiring segment. Fall back to the first item's service rather than crash if
		// that invariant is ever violated elsewhere.
		$service = array() !== $items ? $services->find( (int) $items[0]['service_id'] ) : null;
		return null !== $service ? (string) $service['on_approval_timeout'] : 'expire';
	}
}
