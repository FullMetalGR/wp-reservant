<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Scheduler;

/**
 * Thin wrapper over Action Scheduler's procedural API (`vendor/woocommerce/action-scheduler`,
 * self-initialized by `reservant.php` right after the Composer autoloader). Every call goes
 * through the `reservant` group, so `as_get_scheduled_actions( array( 'group' => 'reservant' ) )`
 * is a complete inventory of this plugin's timers.
 */
final class Scheduler {

	private const GROUP = 'reservant';

	/**
	 * One-off job at a UTC unix timestamp - the nag/timeout timers HoldBooking schedules for an
	 * `awaiting_approval` hold.
	 *
	 * @param array<int, mixed> $args Positional - the job's `add_action` callback receives these
	 *                                in order (Action Scheduler calls
	 *                                `do_action_ref_array( $hook, array_values( $args ) )`).
	 */
	public static function at( int $ts, string $hook, array $args = array() ): void {
		as_schedule_single_action( $ts, $hook, $args, self::GROUP );
	}

	/**
	 * Ensures a recurring job exists, without ever scheduling a second copy of it - callers (only
	 * `Plugin::register()`, on every request) do not need to know whether this is the first call
	 * or the thousandth.
	 *
	 * The first run is anchored 5 minutes out, not `time()`: an interval job scheduled to fire
	 * immediately is, for the rest of that request's lifetime, an *already-due* action - which is
	 * exactly the condition Action Scheduler's own `shutdown`-hooked async runner watches for
	 * before firing a loopback HTTP request to process the queue. Nothing in this plugin's own
	 * code ever wants that: production execution is WP-Cron's job, and integration tests are
	 * explicitly told never to invoke the real queue runner. Starting one interval later sidesteps
	 * the condition entirely rather than relying on a filter to suppress its effect.
	 *
	 * The `as_has_scheduled_action()` check below is a fast-path only, not the correctness
	 * guarantee: it and the `as_schedule_recurring_action()` call are two separate round trips to
	 * the store, and this method runs on every request's `init` hook, so two concurrent requests
	 * can both see "not scheduled" before either has inserted, and both schedule a copy. The real
	 * guarantee is `$unique = true` on `as_schedule_recurring_action()` itself - the store's own
	 * insert is atomic against "a pending/running action with the same hook + group already
	 * exists", per Action Scheduler's own documented contract (see the `$unique` param docs on
	 * both scheduling functions in `tests/stubs/action-scheduler.php`).
	 */
	public static function everyFiveMinutes( string $hook ): void {
		if ( as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, $hook, array(), self::GROUP, true );
	}

	/**
	 * Cancels the next pending occurrence of a job matching this hook + args exactly - the
	 * counterpart to `at()`.
	 *
	 * @param array<int, mixed> $args
	 */
	public static function cancel( string $hook, array $args = array() ): void {
		as_unschedule_action( $hook, $args, self::GROUP );
	}
}
