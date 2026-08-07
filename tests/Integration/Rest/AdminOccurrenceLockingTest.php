<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\HoldBooking;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Rest\Admin\OccurrencesAdminController;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `OccurrencesAdminController` PUT/DELETE take the occurrence mutex (AGENTS.md section 2.2 - "event
 * items use the occurrence row itself as the mutex") before their guards read.
 *
 * WHAT THESE TESTS PROVE: that the lock is acquired, that it is acquired INSIDE the write
 * transaction, that it precedes the guard reads it exists to make meaningful, and that it precedes
 * the write. That is the whole content of the fix, and every one of these assertions fails against
 * the unlocked version.
 *
 * WHAT THEY DO NOT PROVE: that the resulting serialisation actually prevents the overbooking. A
 * single connection cannot contend with itself, and PHPUnit's integration suite is one connection
 * inside one transaction. The overbooking itself is reproduced end to end, on two connections
 * against the live REST API, by bin/concurrency-occurrence.php - which fails on the unlocked
 * version with capacity 10 against 28 seats sold, and passes on this one.
 *
 * The capture hook is core wpdb's own `query` filter, so it observes the statements the controller
 * really issues rather than a re-description of them.
 */
final class AdminOccurrenceLockingTest extends ReservantTestCase {

	/** `SELECT id FROM <prefix>reservant_occurrences WHERE id = N FOR UPDATE` - LockManager's own shape. */
	private const OCCURRENCE_LOCK  = '/SELECT\s+id\s+FROM\s+\S*reservant_occurrences\s+WHERE\s+id\s*=\s*\d+\s+FOR UPDATE/i';
	/** Either guard: both read booking_items joined to bookings, and neither locks anything. */
	private const GUARD_READ       = '/FROM\s+\S*reservant_booking_items/i';
	private const OCCURRENCE_WRITE = '/^\s*UPDATE\s+\S*reservant_occurrences/i';
	private const TRANSACTION_START = '/^START TRANSACTION$/i';

	private int $serviceId;
	private int $occurrenceId;

	/** @var list<string> */
	private array $captured = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->serviceId    = ( new ServiceRepository( $wpdb ) )->insert(
			array(
				'name'         => 'Seminar',
				'type'         => 'event',
				'capacity'     => 50,
				'price_minor'  => 1000,
				'payment_mode' => 'onsite',
			)
		);
		$this->occurrenceId = ( new OccurrenceRepository( $wpdb ) )->insert(
			array(
				'service_id' => $this->serviceId,
				'start_utc'  => $this->sql( 3, '18:00' ),
				'end_utc'    => $this->sql( 3, '20:00' ),
				'capacity'   => 50,
			)
		);
	}

	// ---------------------------------------------------------------- capture helpers

	/** Record every statement wpdb issues while `$run` executes. */
	private function capture( callable $run ): mixed {
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
	private function firstMatching( string $pattern ): ?int {
		foreach ( $this->captured as $index => $query ) {
			if ( 1 === preg_match( $pattern, $query ) ) {
				return $index;
			}
		}
		return null;
	}

	private function assertOrdered( string $earlier, string $later, string $message ): void {
		$first  = $this->firstMatching( $earlier );
		$second = $this->firstMatching( $later );
		self::assertNotNull( $first, 'Expected a statement matching ' . $earlier );
		self::assertNotNull( $second, 'Expected a statement matching ' . $later );
		self::assertLessThan( $second, $first, $message );
	}

	private function request( string $method ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/reservant/v1/admin/occurrences/' . $this->occurrenceId );
		$request->set_param( 'id', $this->occurrenceId );
		return $request;
	}

	// ---------------------------------------------------------------- PUT

	public function testUpdateLocksTheOccurrenceInsideTheTransactionBeforeGuardsAndWrite(): void {
		global $wpdb;
		$request = $this->request( 'PUT' );
		$request->set_param( 'capacity', 30 );

		$response = $this->capture( fn () => ( new OccurrencesAdminController( $wpdb ) )->update( $request ) );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );

		$lockIndex = $this->firstMatching( self::OCCURRENCE_LOCK );
		self::assertNotNull( $lockIndex, 'PUT must lock the occurrence row.' );

		// Inside the transaction, not before it: a lock taken outside would be released immediately.
		$this->assertOrdered( self::TRANSACTION_START, self::OCCURRENCE_LOCK, 'The occurrence lock must be taken inside the write transaction.' );

		// The point of the lock: the in-transaction guard re-read must happen under it. This is also
		// what pins the transaction's REPEATABLE READ snapshot to an instant after any rival hold
		// committed, since InnoDB assigns the snapshot at the first CONSISTENT read.
		$guardIndex = null;
		foreach ( $this->captured as $index => $query ) {
			if ( $index > $lockIndex && 1 === preg_match( self::GUARD_READ, $query ) ) {
				$guardIndex = $index;
				break;
			}
		}
		self::assertNotNull( $guardIndex, 'PUT must re-read its guards after taking the lock.' );
		$this->assertOrdered( self::OCCURRENCE_LOCK, self::OCCURRENCE_WRITE, 'The occurrence lock must precede the write it protects.' );
	}

	public function testUpdateStillRefusesShrinkingCapacityBelowBookedSeats(): void {
		global $wpdb;
		$this->holdSeats( 6 );

		$request = $this->request( 'PUT' );
		$request->set_param( 'capacity', 4 );
		$response = ( new OccurrencesAdminController( $wpdb ) )->update( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'capacity', $response->get_error_message() );
		// Refused under the lock, not written and then regretted.
		self::assertSame( 50, ( new OccurrenceRepository( $wpdb ) )->find( $this->occurrenceId )['capacity'] );
	}

	// ---------------------------------------------------------------- DELETE

	public function testDestroyLocksTheOccurrenceInsideTheTransactionBeforeItsGuard(): void {
		global $wpdb;

		$response = $this->capture( fn () => ( new OccurrencesAdminController( $wpdb ) )->destroy( $this->request( 'DELETE' ) ) );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 204, $response->get_status() );

		$this->assertOrdered( self::TRANSACTION_START, self::OCCURRENCE_LOCK, 'The occurrence lock must be taken inside the cancel transaction.' );

		$lockIndex = (int) $this->firstMatching( self::OCCURRENCE_LOCK );
		$guarded   = false;
		foreach ( $this->captured as $index => $query ) {
			if ( $index > $lockIndex && 1 === preg_match( self::GUARD_READ, $query ) ) {
				$guarded = true;
				break;
			}
		}
		self::assertTrue( $guarded, 'DELETE must re-read `activeBookingCount` after taking the lock.' );
		self::assertSame( 'cancelled', ( new OccurrenceRepository( $wpdb ) )->find( $this->occurrenceId )['status'] );
	}

	public function testDestroyStillRefusesWhileABookingBlocksTheOccurrence(): void {
		global $wpdb;
		$this->holdSeats( 2 );

		$response = ( new OccurrencesAdminController( $wpdb ) )->destroy( $this->request( 'DELETE' ) );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'referenced', $response->get_error_message() );
		self::assertSame( 'active', ( new OccurrenceRepository( $wpdb ) )->find( $this->occurrenceId )['status'] );
	}

	/** A permanently blocking booking on the occurrence - admin mode lands `confirmed`, no TTL to reason about. */
	private function holdSeats( int $seats ): void {
		global $wpdb;
		HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				null,
				new EventRequest( $this->occurrenceId, $seats ),
				true
			),
			$this->utc( 0 )
		);
	}
}
