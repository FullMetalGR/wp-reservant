<?php
/**
 * Minimal Action Scheduler procedural API surface, for static analysis only.
 *
 * `woocommerce/action-scheduler` is a Composer dependency (unlike WP-CLI, see `wp-cli.php` in
 * this directory) and ships no PHPStan-friendly stub set of its own - its real `functions.php`
 * defines these the same way, so scanning it directly would work too, but only once the package
 * is installed; scanning a minimal stub here keeps `composer stan` reproducible for a checkout
 * that has not yet run `composer install`. This file is listed under `scanFiles` in
 * phpstan.neon.dist and is never autoloaded or executed - `reservant.php` loads the real
 * `vendor/woocommerce/action-scheduler/action-scheduler.php` at runtime, which defines the actual
 * functions these signatures describe.
 *
 * @package Reservant
 */

declare( strict_types=1 );

/**
 * @param int                $timestamp Unix timestamp (UTC) to run at.
 * @param string             $hook      Hook to trigger.
 * @param array<int, mixed>  $args      Positional args passed to the hook.
 * @param string             $group     Group to file the action under.
 * @param bool               $unique    Skip if a pending/running action with the same hook+group exists.
 * @param int                $priority  0-255, lower runs first.
 * @return int The action id, or 0 on error.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) { // phpcs:ignore
	return 0;
}

/**
 * @param int                $timestamp           First run's unix timestamp (UTC).
 * @param int                $interval_in_seconds Gap between runs.
 * @param string             $hook                Hook to trigger.
 * @param array<int, mixed>  $args                Positional args passed to the hook.
 * @param string             $group               Group to file the action under.
 * @param bool               $unique              Skip if a pending/running action with the same hook+group exists.
 * @param int                $priority            0-255, lower runs first.
 * @return int The action id, or 0 on error.
 */
function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) { // phpcs:ignore
	return 0;
}

/**
 * @param string                  $hook  Hook to look for.
 * @param array<int, mixed>|null $args  Exact args to match; null matches any.
 * @param string                  $group Group to look in.
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool { // phpcs:ignore
	return false;
}

/**
 * @param string             $hook  Hook that would have triggered.
 * @param array<int, mixed>  $args  Exact args to match.
 * @param string             $group Group to look in.
 * @return int|null The cancelled action id, or null if none matched.
 */
function as_unschedule_action( $hook, $args = array(), $group = '' ) { // phpcs:ignore
	return null;
}
