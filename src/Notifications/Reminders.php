<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Infrastructure\Scheduler\Jobs;
use Reservant\Infrastructure\Scheduler\Scheduler;
use Reservant\Settings;

/**
 * The timer behind "your appointment is tomorrow" (AGENTS.md section 9 item 7).
 *
 * This class owns the TIMER's whole life - scheduled when a booking becomes real, cancelled when it
 * stops being real, moved when it moves - and the email that timer eventually produces.
 * `Infrastructure\Scheduler\Jobs::reminder()` is the callback in between: it re-reads the booking
 * and fires `reservant/booking/reminder` only if the booking is still `confirmed`, which is what
 * actually keeps a reminder off a cancelled appointment. Scheduling is best-effort; the re-read is
 * the authority. The same split `Jobs::nag()` and `ApprovalEmails` already use.
 *
 * Cancelling matters as much as scheduling. Action Scheduler matches a pending action on hook, args
 * AND group, so `Scheduler::cancel()` here has to pass exactly the args `schedule()` passed - the
 * uuid alone, which is also why the job payload carries nothing else. A reschedule is a cancel
 * followed by a fresh schedule rather than an edit, because moving an appointment two days later
 * moves its reminder with it and there is no in-place form.
 *
 * WHEN NO TIMER IS SET, and none of these is an error:
 *
 * - the owner set `reminder_lead_hours` to zero, which is what "no reminders" is stored as;
 * - the appointment starts SOONER than the lead time, so the reminder's own instant is already in
 *   the past. Scheduling it anyway would have Action Scheduler fire it on the next queue run,
 *   turning "24 hours before" into "immediately after booking" - a second confirmation email,
 *   worded as a reminder;
 * - the booking has no items at all, which only a malformed row could produce.
 */
final class Reminders {

	public static function register(): void {
		add_action( 'reservant/booking/confirmed', array( self::class, 'onConfirmed' ) );
		add_action( 'reservant/booking/rescheduled', array( self::class, 'onRescheduled' ) );
		add_action( 'reservant/booking/cancelled', array( self::class, 'onCancelled' ) );
		add_action( 'reservant/hold/expired', array( self::class, 'onCancelled' ) );
	}

	public static function onConfirmed( BookingSnapshot $snapshot ): void {
		self::schedule( $snapshot );
	}

	/** Cancel then re-schedule: the appointment moved, so its reminder has to move with it. */
	public static function onRescheduled( BookingSnapshot $snapshot ): void {
		Scheduler::cancel( Jobs::REMINDER, array( $snapshot->uuid ) );
		self::schedule( $snapshot );
	}

	/**
	 * A booking that stopped being real. Also wired to `hold/expired`, which cannot have a reminder
	 * of its own - a hold was never confirmed - but a booking that was confirmed, rescheduled and
	 * then reaped by some future path would, and cancelling a timer that does not exist costs one
	 * no-op.
	 */
	public static function onCancelled( BookingSnapshot $snapshot ): void {
		Scheduler::cancel( Jobs::REMINDER, array( $snapshot->uuid ) );
	}

	/**
	 * The lead time, in hours, as a site may override it.
	 *
	 * The setting is the default and the filter is the last word, matching how
	 * `reservant/hold_ttl_minutes` sits over `checkout_ttl_min`. A negative answer from a filter is
	 * clamped to zero rather than treated as "the reminder goes out after the appointment".
	 */
	public static function leadHours(): int {
		/** @var mixed $filtered */
		$filtered = apply_filters( 'reservant/reminder_lead_hours', Settings::make()->reminderLeadHours() );
		return max( 0, is_int( $filtered ) ? $filtered : 0 );
	}

	private static function schedule( BookingSnapshot $snapshot ): void {
		$leadHours = self::leadHours();
		if ( 0 === $leadHours ) {
			return;
		}

		$firstStart = self::firstStart( $snapshot );
		if ( null === $firstStart ) {
			return;
		}

		$fireAt = $firstStart->getTimestamp() - $leadHours * HOUR_IN_SECONDS;
		if ( $fireAt <= time() ) {
			return; // See the class docblock: a lead time longer than the notice given.
		}
		Scheduler::at( $fireAt, Jobs::REMINDER, array( $snapshot->uuid ) );
	}

	/**
	 * The chain's first segment, which is when the guest has to turn up. Items arrive sorted
	 * ascending by `sort` (`BookingRepository::hydrate()`), and the customer-facing `start_utc` is
	 * the right instant rather than the buffer-widened block range - the buffers are the shop's
	 * contention model and would move the reminder earlier for no reason the guest could see.
	 */
	private static function firstStart( BookingSnapshot $snapshot ): ?\DateTimeImmutable {
		$first = $snapshot->items[0] ?? null;
		if ( null === $first || ! isset( $first['start_utc'] ) ) {
			return null;
		}
		return new \DateTimeImmutable( (string) $first['start_utc'], new \DateTimeZone( 'UTC' ) );
	}
}
