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

	private int $expireServiceId;
	private int $autoApproveServiceId;

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
		$staff                       = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->expireServiceId, $staff );
		$resources->linkService( $this->autoApproveServiceId, $staff );
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
	 * Args of every scheduled action for a hook (group 'reservant') whose first arg is this uuid -
	 * scoped to one booking rather than a bare count, since Action Scheduler's tables are not part
	 * of `ReservantTestCase::set_up()`'s per-test truncation and other tests in this class hold
	 * their own approval-gated bookings too.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function scheduledArgsForUuid( string $hook, string $uuid ): array {
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
			if ( isset( $args[0] ) && $uuid === $args[0] ) {
				$matches[] = $args;
			}
		}
		return $matches;
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

	public function testSweepIsAlreadyScheduledByPluginRegistration(): void {
		// Plugin::register() runs on every `plugins_loaded`, including the bootstrap for this
		// test process (tests/Integration/bootstrap.php requires reservant.php on
		// `muplugins_loaded`) - so the recurring sweeper already exists without this test calling
		// Scheduler itself.
		self::assertTrue( as_has_scheduled_action( Jobs::SWEEP, array(), 'reservant' ) );
	}
}
