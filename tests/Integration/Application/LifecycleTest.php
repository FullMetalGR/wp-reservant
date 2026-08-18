<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\ExpireHolds;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

final class LifecycleTest extends ReservantTestCase {

	private int $serviceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services        = new ServiceRepository( $wpdb );
		$resources       = new ResourceRepository( $wpdb );
		$avail           = new AvailabilityRepository( $wpdb );
		$this->serviceId = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'cancel_window_hours' => 24 ) );
		$staff           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/** Books day 1 at $start; "now" is always day 0. @return array<string, mixed> */
	private function holdOne( string $start ): array {
		return $this->holdOn( 1, $start );
	}

	/**
	 * The same hold on an arbitrary day - the sweeper tests need two bookings whose resource-day
	 * mutex rows differ, and one staff member on two days is the smallest way to get that.
	 *
	 * @return array<string, mixed>
	 */
	private function holdOn( int $dayOffset, string $start ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), new AppointmentRequest( $this->utc( $dayOffset, $start ), array( new SegmentChoice( $this->serviceId ) ) ) ),
			$this->utc( 0 )
		);
	}

	public function test_confirm_flow(): void {
		global $wpdb;
		$booking   = $this->holdOne( '09:00' );
		$confirmed = ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );
		self::assertSame( 'confirmed', $confirmed['status'] );
		self::assertNull( $confirmed['hold_expires_at'] );
	}

	public function test_cancel_respects_window_and_force_overrides(): void {
		global $wpdb;
		$booking = $this->holdOne( '09:00' );
		ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );
		$cancel = CancelBooking::make( $wpdb );
		// Captured into a variable rather than asserted inside the `catch`: PHPUnit's own
		// `AssertionFailedError` is a `\RuntimeException`, so a `self::fail()` in the `try` of a broad
		// catch would be swallowed by it and the test would pass having proved nothing.
		$refusal = null;
		try {
			$cancel->execute( $booking['uuid'], $this->utc( 1, '08:00' ) ); // inside 24h window
		} catch ( \RuntimeException $e ) {
			$refusal = $e->getMessage();
		}
		self::assertSame( 'window_closed', $refusal );
		$cancelled = $cancel->execute( $booking['uuid'], $this->utc( 1, '08:00' ), true ); // admin force
		self::assertSame( 'cancelled', $cancelled['status'] );
	}

	/**
	 * `DELETE /holds` force-cancels - giving up a reservation nobody has paid for is not
	 * policy-bound - so "held only" cannot be enforced by the controller's read, which happens
	 * outside the lock. A confirm winning that race would have its cancellation window bypassed by
	 * a route that was never allowed to touch a confirmed booking.
	 *
	 * The REST race itself is not deterministically reproducible; the guard it depends on is, and
	 * this is that guard: with `$onlyFromStatuses` set, the status read inside the transaction
	 * decides, and a booking that is no longer held is refused as `not_held`.
	 */
	public function test_a_held_only_release_refuses_a_booking_that_was_confirmed_first(): void {
		global $wpdb;
		$booking = $this->holdOne( '09:00' );
		ConfirmBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:05' ) );

		// See the note in `test_cancel_respects_window_and_force_overrides`: a `self::fail()` here
		// would be caught by the broad `\RuntimeException` arm, so the refusal is asserted afterwards.
		$refusal = null;
		try {
			CancelBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:06' ), true, BookingStatus::heldStatuses() );
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		}
		self::assertSame( 'not_held', $refusal );
		self::assertSame( 'confirmed', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );

		// The unrestricted call is the manager's force-cancel and still goes through, so the refusal
		// above is about the route's authority and not a broken transition.
		self::assertSame( 'cancelled', CancelBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '00:06' ), true )['status'] );
	}

	public function test_expired_hold_is_swept_and_slot_reusable(): void {
		global $wpdb;
		$booking = $this->holdOne( '10:00' );
		// Force the hold into the past, then sweep.
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $booking['uuid'] ) );
		self::assertSame( 1, ExpireHolds::make( $wpdb )->run() );
		// Slot is takeable again.
		self::assertSame( 'pending', $this->holdOne( '10:00' )['status'] );
	}

	public function test_inline_reap_audits_and_notifies_the_hold_it_expires(): void {
		global $wpdb;
		$stale = $this->holdOne( '10:00' );
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $stale['uuid'] ) );

		$notified = array();
		$listener = static function ( BookingSnapshot $booking ) use ( &$notified ): void {
			$notified[] = $booking->uuid;
		};
		add_action( 'reservant/hold/expired', $listener );
		// Taking the slot reaps the stale hold inline; ExpireHolds can never see it afterwards,
		// so this transaction is the only chance to record and announce it.
		$this->holdOne( '10:00' );
		remove_action( 'reservant/hold/expired', $listener );

		self::assertSame( array( $stale['uuid'] ), $notified );
		self::assertSame( 'expired', ( new BookingRepository( $wpdb ) )->findByUuid( $stale['uuid'] )['status'] );
		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'system' AND a.action = 'expired'", // phpcs:ignore WordPress.DB.PreparedSQL
					$stale['uuid']
				)
			)
		);
	}

	/**
	 * One contended row must not stop the sweep.
	 *
	 * A lock the sweeper could not take this minute is one it will very likely take next minute, so
	 * the batch skips that booking and carries on. AGENTS.md section 2.1 permits this in both
	 * directions - "Correctness must never depend on the sweeper having run", and expired holds are
	 * already free by time comparison in every query - so aborting and skipping are equally safe, and
	 * skipping does strictly more work.
	 *
	 * ONLY `lock_unavailable` is skipped. Any other `\RuntimeException` is a genuine bug and must
	 * still abort loudly rather than be swallowed by a sweeper nobody watches.
	 *
	 * Two holds on two different UTC days, so the sabotage can single out one of the two mutex rows
	 * by its `day_utc` literal; the contended one is given the earlier `hold_expires_at` so
	 * `expiredHeldIds()` (ordered ASC) hands it over FIRST.
	 *
	 * That ordering is load-bearing, though not for the reason it might look like: an aborting sweeper
	 * fails this test under EITHER ordering, because `run()` is called without a `catch` and the
	 * exception errors out of the test method. What the ordering actually buys is that
	 * `assertSame( 'expired', $other )` cannot be satisfied incidentally - if the contended booking
	 * were processed last, the other one would already have been swept before the abort, and the
	 * assertion would be describing work an aborting sweeper had done rather than work the skip
	 * allowed it to reach.
	 */
	public function test_sweeper_skips_a_booking_it_cannot_lock_and_finishes_the_batch(): void {
		global $wpdb;
		$contended = $this->holdOn( 1, '10:00' );
		$other     = $this->holdOn( 2, '10:00' );
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $contended['uuid'] ) );
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-02 00:00:00' ), array( 'uuid' => $other['uuid'] ) );

		// Only the contended day's mutex fails; every other statement, including the other booking's
		// own lock, runs untouched.
		$day      = $this->utc( 1 )->format( 'Y-m-d' );
		$pattern  = '/^\s*SELECT\s+resource_id\s+FROM\s+\S*reservant_resource_days\b[^;]*day_utc\s*=\s*\'' . preg_quote( $day, '/' ) . '\'.*FOR UPDATE/is';
		$sabotage = static function ( $query ) use ( $pattern ) {
			return 1 === preg_match( $pattern, (string) $query )
				? 'SELECT resource_id FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			$processed = ExpireHolds::make( $wpdb )->run();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}

		$bookings = new BookingRepository( $wpdb );
		self::assertSame( 1, $processed, 'the batch must carry on past the row it could not lock' );
		self::assertSame(
			'pending',
			$bookings->findByUuid( $contended['uuid'] )['status'],
			'a hold the sweeper could not lock must be left exactly as it was, for the next sweep'
		);
		self::assertSame(
			'expired',
			$bookings->findByUuid( $other['uuid'] )['status'],
			'the rest of the batch must still be swept'
		);
	}

	/**
	 * The other half of the rule above: a sweeper that swallowed everything would hide real bugs, so
	 * anything that is not `lock_unavailable` still aborts the run.
	 */
	public function test_sweeper_still_aborts_on_an_unexpected_failure(): void {
		global $wpdb;
		$booking = $this->holdOn( 1, '10:00' );
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $booking['uuid'] ) );

		$blowUp = static function (): void {
			throw new \RuntimeException( 'unexpected_test_failure' );
		};
		add_action( 'reservant/hold/expired', $blowUp );

		// Captured, not asserted in place: PHPUnit's AssertionFailedError extends \RuntimeException,
		// so a self::fail() inside the try would be swallowed by the catch arm below.
		$failure = null;
		try {
			ExpireHolds::make( $wpdb )->run();
		} catch ( \RuntimeException $e ) {
			$failure = $e->getMessage();
		} finally {
			remove_action( 'reservant/hold/expired', $blowUp );
		}
		self::assertSame( 'unexpected_test_failure', $failure, 'only a busy lock is skipped; a real bug must still abort' );
	}
}
