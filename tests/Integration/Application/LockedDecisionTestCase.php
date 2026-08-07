<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * Shared ground for `ApprovalLockingTest` and `RejectionLockingTest`: one approval-gated
 * appointment service on one staff member, one approval-gated event occurrence, and the statement
 * capture both use to observe the locks their use case takes.
 *
 * The capture hook is core wpdb's own `query` filter, so these tests read the statements the code
 * really issues rather than a re-description of them. What that can and cannot prove is spelled
 * out on each concrete test class.
 */
abstract class LockedDecisionTestCase extends ReservantTestCase {

	/** `LockManager`'s own two statements, verbatim in shape. */
	protected const RESOURCE_DAY_LOCK = '/SELECT\s+resource_id\s+FROM\s+\S*reservant_resource_days\s+WHERE\s+resource_id\s*=\s*\d+\s+AND\s+day_utc\s*=\s*\'[^\']+\'\s+FOR UPDATE/i';
	protected const OCCURRENCE_LOCK   = '/SELECT\s+id\s+FROM\s+\S*reservant_occurrences\s+WHERE\s+id\s*=\s*\d+\s+FOR UPDATE/i';
	/** `BookingRepository::findByUuidForUpdate()` - the row lock that must always come SECOND. */
	protected const BOOKING_LOCK      = '/FROM\s+\S*reservant_bookings\s+WHERE\s+uuid\s*=\s*\'[^\']+\'\s+FOR UPDATE/i';
	protected const REV_BUMP          = '/UPDATE\s+\S*reservant_resource_days\s+SET\s+rev\s*=\s*rev\s*\+\s*1/i';
	protected const TRANSACTION_START = '/^START TRANSACTION$/i';

	protected int $appointmentServiceId;
	protected int $eventServiceId;
	protected int $occurrenceId;
	protected int $staffId;

	/** @var list<string> */
	private array $captured = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->appointmentServiceId = $services->insert(
			array(
				'name'                => 'Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->staffId              = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->appointmentServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}

		$this->eventServiceId = $services->insert(
			array(
				'name'              => 'Seminar',
				'type'              => 'event',
				'capacity'          => 20,
				'price_minor'       => 1000,
				'payment_mode'      => 'onsite',
				'requires_approval' => 1,
			)
		);
		$this->occurrenceId   = ( new OccurrenceRepository( $wpdb ) )->insert(
			array(
				'service_id' => $this->eventServiceId,
				'start_utc'  => $this->sql( 3, '18:00' ),
				'end_utc'    => $this->sql( 3, '20:00' ),
				'capacity'   => 20,
			)
		);
	}

	/** An `awaiting_approval` appointment hold on the staff member's day 1, 09:00. @return array<string, mixed> */
	protected function holdAppointment(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->appointmentServiceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/** An `awaiting_approval` two-seat hold on the event occurrence. @return array<string, mixed> */
	protected function holdEvent(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				null,
				new EventRequest( $this->occurrenceId, 2 )
			),
			$this->utc( 0 )
		);
	}

	/** Record every statement wpdb issues while `$run` executes. */
	protected function capture( callable $run ): mixed {
		$this->captured = array();
		$recorder       = function ( $query ) {
			$this->captured[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			return $run();
		} finally {
			remove_filter( 'query', $recorder );
		}
	}

	/** Index of the first captured statement matching $pattern, or null. */
	protected function firstMatching( string $pattern ): ?int {
		foreach ( $this->captured as $index => $query ) {
			if ( 1 === preg_match( $pattern, $query ) ) {
				return $index;
			}
		}
		return null;
	}

	protected function assertOrdered( string $earlier, string $later, string $message ): void {
		$first  = $this->firstMatching( $earlier );
		$second = $this->firstMatching( $later );
		self::assertNotNull( $first, 'Expected a statement matching ' . $earlier );
		self::assertNotNull( $second, 'Expected a statement matching ' . $later );
		self::assertLessThan( $second, $first, $message );
	}

	/** The mask-cache revision on the staff member's booked day. */
	protected function rev(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rev FROM {$wpdb->prefix}reservant_resource_days WHERE resource_id = %d AND day_utc = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->staffId,
				$this->utc( 1 )->format( 'Y-m-d' )
			)
		);
	}
}
