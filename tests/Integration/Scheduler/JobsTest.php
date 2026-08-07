<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Scheduler;

use Reservant\Application\ApproveBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Scheduler\Jobs;
use Reservant\Infrastructure\Scheduler\Scheduler;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * Scheduler + Jobs (AGENTS.md "Approval holds"): holding an approval-gated service enqueues the
 * nag/timeout timers, and each job callback is idempotent against a booking that was already
 * decided by the time it fires.
 */
final class JobsTest extends ReservantTestCase {

	/** Deliberately not 48: see `testApprovalTimersLandOnTheServicesOwnWindow()`. */
	private const SHORT_WINDOW_HOURS = 6;

	private int $expireServiceId;
	private int $autoApproveServiceId;
	private int $shortWindowServiceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->expireServiceId      = $services->insert(
			array(
				'name'                => 'Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
				'on_approval_timeout' => 'expire',
			)
		);
		$this->autoApproveServiceId = $services->insert(
			array(
				'name'                => 'Fitting',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
				'on_approval_timeout' => 'auto_approve',
			)
		);
		$this->shortWindowServiceId = $services->insert(
			array(
				'name'                => 'Trial',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => self::SHORT_WINDOW_HOURS,
				'on_approval_timeout' => 'expire',
			)
		);
		$staff                       = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->expireServiceId, $staff );
		$resources->linkService( $this->autoApproveServiceId, $staff );
		$resources->linkService( $this->shortWindowServiceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/** @return array<string, mixed> */
	private function holdAwaitingApproval( int $serviceId ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $serviceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/**
	 * Every scheduled action for a hook (group 'reservant') whose first arg is this uuid, with the
	 * instant it is due - scoped to one booking rather than a bare count, since other tests in this
	 * class hold their own approval-gated bookings too.
	 *
	 * @return list<array{ts: int, args: array<int, mixed>}>
	 */
	private function scheduledForUuid( string $hook, string $uuid ): array {
		/** @var array<int, \ActionScheduler_Action> $actions */
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => 'reservant',
				'per_page' => 1000,
			),
			OBJECT
		);
		$matches = array();
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( ! isset( $args[0] ) || $uuid !== $args[0] ) {
				continue;
			}
			$date      = $action->get_schedule()->get_date();
			$matches[] = array(
				'ts'   => null === $date ? 0 : $date->getTimestamp(),
				'args' => $args,
			);
		}
		return $matches;
	}

	/** @return list<array<int, mixed>> */
	private function scheduledArgsForUuid( string $hook, string $uuid ): array {
		return array_map(
			static fn ( array $row ): array => $row['args'],
			$this->scheduledForUuid( $hook, $uuid )
		);
	}

	/** @return list<int> scheduled action ids for a hook, group 'reservant' */
	private function scheduledIds( string $hook ): array {
		/** @var list<int> $ids */
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => 'reservant',
				'per_page' => 1000,
			),
			'ids'
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	public function testHoldAwaitingApprovalSchedulesThreeNagsAndOneTimeout(): void {
		$booking = $this->holdAwaitingApproval( $this->expireServiceId );

		$nagArgs     = $this->scheduledArgsForUuid( Jobs::NAG, $booking['uuid'] );
		$timeoutArgs = $this->scheduledArgsForUuid( Jobs::TIMEOUT, $booking['uuid'] );
		self::assertCount( 3, $nagArgs );
		self::assertCount( 1, $timeoutArgs );

		$percents = array_map( static fn ( array $args ): int => $args[1], $nagArgs );
		sort( $percents );
		self::assertSame( array( 25, 50, 75 ), $percents );
		self::assertSame( array( $booking['uuid'] ), $timeoutArgs[0] );
	}

	/**
	 * The schedule TIMES, not just how many timers there are.
	 *
	 * `HoldBooking::scheduleApprovalTimers()` puts each nag at `created + round(window * pct / 100)`
	 * and the timeout at `hold_expires_at` exactly. Counting three nags and one timeout says nothing
	 * about any of that: a regression that scheduled all three nags at `created`, inverted the
	 * percentage, or fired the timeout an hour late would count identically. Every instant is
	 * asserted here, against offsets derived from the window rather than from the formula.
	 *
	 * The service's window is deliberately 6 hours, not the 48 every other approval fixture in this
	 * suite uses: 48 is also `Settings`' and the schema column's default and `HoldBooking`'s own
	 * fallback, so a window that only ever measured 48 hours could not tell "read from the service
	 * row" apart from "hardcoded". 6 hours can only have come from the service.
	 *
	 * The hold is minted against the wall clock rather than this suite's usual week-ahead fixture
	 * instant, because `holdExpiresAt()` anchors the TTL to `max(injected now, wall clock)` - with a
	 * week-ahead "now" the window would be a week plus the service's hours, and the derivation would
	 * be unreadable. The tolerance below absorbs the sub-second gap between that anchor and the
	 * `created_at` the database stamps a moment later.
	 */
	public function testApprovalTimersLandOnTheServicesOwnWindow(): void {
		global $wpdb;
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->shortWindowServiceId ) ) )
			),
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);

		$utc     = new \DateTimeZone( 'UTC' );
		$created = ( new \DateTimeImmutable( (string) $booking['created_at'], $utc ) )->getTimestamp();
		$expires = ( new \DateTimeImmutable( (string) $booking['hold_expires_at'], $utc ) )->getTimestamp();

		// The window itself came from the service row: 6 hours, not the 48-hour default.
		self::assertEqualsWithDelta(
			self::SHORT_WINDOW_HOURS * HOUR_IN_SECONDS,
			$expires - $created,
			5,
			'The approval window must be the service\'s own approval_hold_hours.'
		);

		$byPercent = array();
		foreach ( $this->scheduledForUuid( Jobs::NAG, $booking['uuid'] ) as $nag ) {
			$byPercent[ (int) $nag['args'][1] ] = $nag['ts'];
		}
		ksort( $byPercent );
		self::assertSame( array( 25, 50, 75 ), array_keys( $byPercent ) );

		// A quarter, a half and three quarters of six hours after the request was made.
		self::assertEqualsWithDelta( $created + ( 90 * MINUTE_IN_SECONDS ), $byPercent[25], 5, 'The 25% nag is not a quarter of the way through the window.' );
		self::assertEqualsWithDelta( $created + ( 3 * HOUR_IN_SECONDS ), $byPercent[50], 5, 'The 50% nag is not halfway through the window.' );
		self::assertEqualsWithDelta( $created + ( 270 * MINUTE_IN_SECONDS ), $byPercent[75], 5, 'The 75% nag is not three quarters of the way through the window.' );

		$timeouts = $this->scheduledForUuid( Jobs::TIMEOUT, $booking['uuid'] );
		self::assertCount( 1, $timeouts );
		// Exact, not approximate: the timeout fires at the deadline the row itself carries, so that
		// `ExpireHolds`' own `hold_expires_at <= UTC_NOW()` check is true the moment it runs.
		self::assertSame( $expires, $timeouts[0]['ts'], 'The timeout must be scheduled at hold_expires_at exactly.' );
	}

	public function testHoldWithoutApprovalSchedulesNothing(): void {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );
		$plainId  = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2000,
				'payment_mode' => 'onsite',
			)
		);
		$resources = new ResourceRepository( $wpdb );
		$staff     = $resources->insert( array( 'name' => 'Sam' ) );
		$resources->linkService( $plainId, $staff );
		$avail = new AvailabilityRepository( $wpdb );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}

		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '13:00' ), array( new SegmentChoice( $plainId ) ) )
			),
			$this->utc( 0 )
		);

		self::assertCount( 0, $this->scheduledArgsForUuid( Jobs::NAG, $booking['uuid'] ) );
		self::assertCount( 0, $this->scheduledArgsForUuid( Jobs::TIMEOUT, $booking['uuid'] ) );
	}

	public function testTimeoutExpireModeExpiresTheBooking(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval( $this->expireServiceId );
		// `ExpireHolds` (which the expire-mode path reuses) checks the real DB clock, never a
		// domain `$nowUtc` (AGENTS.md section 2.1) - so a synchronous test invocation, rather than
		// waiting for wall-clock time to actually reach the fixture's `hold_expires_at`, backdates
		// it instead. In production Action Scheduler only fires at or after the scheduled instant,
		// so this is never a real gap - see `testApproveExpiredWindowRefuses` for the same pattern.
		$wpdb->update(
			$wpdb->prefix . 'reservant_bookings',
			array( 'hold_expires_at' => '2020-01-01 00:00:00' ),
			array( 'uuid' => $booking['uuid'] )
		);

		do_action( Jobs::TIMEOUT, $booking['uuid'] );

		$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'expired', $fresh['status'] );
	}

	public function testTimeoutAutoApproveModeConfirmsAsSystem(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval( $this->autoApproveServiceId );

		do_action( Jobs::TIMEOUT, $booking['uuid'] );

		$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'confirmed', $fresh['status'] );
		self::assertNull( $fresh['approved_by'] );

		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
					 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
					 WHERE b.uuid = %s AND a.actor = 'system' AND a.action = 'approve'", // phpcs:ignore WordPress.DB.PreparedSQL
					$booking['uuid']
				)
			)
		);
	}

	public function testTimeoutAfterHumanApprovalIsNoOp(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval( $this->expireServiceId );
		$adminUser = self::factory()->user->create( array( 'role' => 'administrator' ) );
		ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin', $adminUser );

		$auditCountBefore = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
				 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
				 WHERE b.uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$booking['uuid']
			)
		);

		do_action( Jobs::TIMEOUT, $booking['uuid'] );

		$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'confirmed', $fresh['status'] );
		self::assertSame( $adminUser, (int) $fresh['approved_by'] );

		$auditCountAfter = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}reservant_audit_log a
				 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = a.booking_id
				 WHERE b.uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$booking['uuid']
			)
		);
		self::assertSame( $auditCountBefore, $auditCountAfter );
	}

	public function testNagWhileAwaitingApprovalFiresTheHook(): void {
		$booking = $this->holdAwaitingApproval( $this->expireServiceId );

		$fired    = array();
		$listener = static function ( BookingSnapshot $snapshot, int $percent ) use ( &$fired ): void {
			$fired[] = array( $snapshot, $percent );
		};
		add_action( 'reservant/approval/nag', $listener, 10, 2 );
		do_action( Jobs::NAG, $booking['uuid'], 50 );
		remove_action( 'reservant/approval/nag', $listener, 10 );

		self::assertCount( 1, $fired );
		self::assertSame( $booking['uuid'], $fired[0][0]->uuid );
		self::assertSame( 50, $fired[0][1] );
	}

	public function testNagAfterDecisionIsNoOp(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval( $this->expireServiceId );
		ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' );

		$fired    = array();
		$listener = static function () use ( &$fired ): void {
			$fired[] = true;
		};
		add_action( 'reservant/approval/nag', $listener );
		do_action( Jobs::NAG, $booking['uuid'], 25 );
		remove_action( 'reservant/approval/nag', $listener );

		self::assertCount( 0, $fired );
	}

	public function testSweepExpiresLapsedHold(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval( $this->expireServiceId );
		$wpdb->update(
			$wpdb->prefix . 'reservant_bookings',
			array( 'hold_expires_at' => '2020-01-01 00:00:00' ),
			array( 'uuid' => $booking['uuid'] )
		);

		do_action( Jobs::SWEEP );

		$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'expired', $fresh['status'] );
	}

	public function testEveryFiveMinutesIsIdempotent(): void {
		Scheduler::everyFiveMinutes( Jobs::SWEEP );
		Scheduler::everyFiveMinutes( Jobs::SWEEP );

		self::assertCount( 1, $this->scheduledIds( Jobs::SWEEP ) );
	}

	/**
	 * `Scheduler::everyFiveMinutes()`'s `as_has_scheduled_action()` check is only a fast path: it
	 * and the following `as_schedule_recurring_action()` call are two separate round trips to the
	 * store, so two concurrent requests can both see "not scheduled" before either has inserted.
	 * The actual correctness guarantee is `$unique = true` on `as_schedule_recurring_action()`
	 * itself - proven here directly against the real store this wp-env ships, bypassing
	 * `Scheduler` entirely and using a hook nothing else in this suite schedules, so it cannot be
	 * confused with `Jobs::SWEEP`'s own bootstrap-created recurring action.
	 */
	public function testUniqueFlagOnRecurringActionIsAtomicAgainstDuplicates(): void {
		$hook = 'reservant/test/unique_recurring_probe';

		$first  = as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS, $hook, array(), 'reservant', true );
		$second = as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS, $hook, array(), 'reservant', true );

		self::assertGreaterThan( 0, $first );
		self::assertSame( 0, $second );
		self::assertCount(
			1,
			as_get_scheduled_actions( array( 'hook' => $hook, 'group' => 'reservant', 'per_page' => 10 ), 'ids' )
		);
	}

	public function testSweepIsAlreadyScheduledByPluginRegistration(): void {
		// Plugin::register() runs on every `plugins_loaded`, including the bootstrap for this
		// test process (tests/Integration/bootstrap.php requires reservant.php on
		// `muplugins_loaded`) - so the recurring sweeper already exists without this test calling
		// Scheduler itself.
		self::assertTrue( as_has_scheduled_action( Jobs::SWEEP, array(), 'reservant' ) );
	}
}
