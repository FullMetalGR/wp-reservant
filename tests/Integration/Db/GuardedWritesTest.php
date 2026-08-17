<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Application\CancelBooking;
use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\EventRequest;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\ExpireHolds;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * Every write a held lock is supposed to protect refuses when it fails, rather than being walked past.
 *
 * `LockManager::acquire()` was the first of these to be guarded, but it was never the only unchecked
 * statement on the locked call paths: wpdb reports a DB-level failure by return value alone - `false`
 * from `query()`/`insert()`/`update()`, an EMPTY ARRAY from `get_col()`, `null` from `get_row()`/
 * `get_var()` - and with `WP_DEBUG` off it neither throws nor prints. Every one of those is
 * indistinguishable from a legitimate "nothing matched" unless the caller looks.
 *
 * The two MariaDB endings are the ones `BookingRepository::deleteItems()` names: 1205 lock-wait
 * timeout, where `innodb_rollback_on_timeout` being OFF means only the STATEMENT rolls back and the
 * transaction carries on without that write; and 1213 deadlock, where the server has already rolled
 * the transaction back and everything after commits individually under restored autocommit.
 *
 * Each test here asserts the COMMITTED state after the refusal, never merely that something was
 * thrown - an exception-only assertion would pass against the broken path each guard is about. The
 * sabotage is the codebase's usual one: a `query` filter rewriting one statement to a table that does
 * not exist, which is the same `false`/empty shape a 1205 produces.
 */
final class GuardedWritesTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->cutId  = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->staffA = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->cutId, $this->staffA );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffA, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Run `$body` with one statement rewritten to a table that does not exist, and answer the refusal
	 * message (or `null` if nothing was thrown).
	 *
	 * The message is RETURNED, never asserted in place: PHPUnit's `AssertionFailedError` extends
	 * `\RuntimeException`, so a `self::fail()` inside the `try` of a broad catch is swallowed and the
	 * test passes having proved nothing. This helper contains no assertions at all, so that trap
	 * cannot be reintroduced inside it - every assertion belongs to the caller, after the catch.
	 *
	 * @param callable(): void $body
	 */
	private function refusalUnderSabotage( string $pattern, string $replacement, callable $body ): ?string {
		global $wpdb;
		$sabotage = static function ( $query ) use ( $pattern, $replacement ) {
			return 1 === preg_match( $pattern, (string) $query ) ? $replacement : $query;
		};

		$refusal    = null;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			$body();
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}
		return $refusal;
	}

	/** @return array<string, mixed> */
	private function holdAppointment( string $start ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), new AppointmentRequest( $this->utc( 1, $start ), array( new SegmentChoice( $this->cutId ) ) ) ),
			$this->utc( 0 )
		);
	}

	private function lapse( string $uuid ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
	}

	private function bookingCount(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_bookings" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Rows A-B, two seats each. @return int seat_map_id */
	private function seatMap(): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'reservant_seat_maps', array( 'name' => 'Hall', 'spec' => 'rows A-B, 2 per row' ) );
		$mapId = (int) $wpdb->insert_id;
		$cells = array(
			array( 'A', 'A1', 0, 0 ),
			array( 'A', 'A2', 0, 1 ),
			array( 'B', 'B1', 1, 0 ),
			array( 'B', 'B2', 1, 1 ),
		);
		foreach ( $cells as $cell ) {
			$wpdb->insert(
				$wpdb->prefix . 'reservant_seats',
				array( 'seat_map_id' => $mapId, 'row_label' => $cell[0], 'seat_label' => $cell[1], 'sort_row' => $cell[2], 'sort_col' => $cell[3], 'kind' => 'seat' )
			);
		}
		return $mapId;
	}

	/** @return list<int> */
	private function seatIds( int $mapId ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}reservant_seats WHERE seat_map_id = %d ORDER BY id ASC", $mapId ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	private function gridOccurrence( int $mapId ): int {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite', 'seat_map_id' => $mapId, 'capacity' => 4 )
		);
		return ( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 3, '18:00' ), 'end_utc' => $this->sql( 3, '20:00' ), 'capacity' => 4 )
		);
	}

	/**
	 * @param list<int> $seats
	 * @return array<string, mixed>
	 */
	private function holdSeats( int $occurrenceId, array $seats ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest( new Customer( 'M', 'm@example.com' ), null, new EventRequest( $occurrenceId, count( $seats ), $seats ) ),
			$this->utc( 0 )
		);
	}

	private function seatClaimOf( string $uuid ): ?int {
		global $wpdb;
		$claim = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT i.seat_claim FROM {$wpdb->prefix}reservant_booking_items i
				 JOIN {$wpdb->prefix}reservant_bookings b ON b.id = i.booking_id
				 WHERE b.uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$uuid
			)
		);
		return null === $claim ? null : (int) $claim;
	}

	// ------------------------------------------- CRITICAL 1: the inline reap

	/**
	 * A hold whose inline reap silently did nothing must be refused, never committed.
	 *
	 * `BookingRepository::reapExpiredTouching()` is three statements deep inside `HoldBooking`'s
	 * transaction, immediately after the guarded `acquire()`. Its expiry UPDATE had its return
	 * discarded, so on a 1205 the reap did nothing at all, `acquire()` had already returned happily,
	 * and the new hold sailed on to COMMIT. The direct harm is bounded - `BLOCKING_SQL` treats the
	 * lapsed hold as free by time comparison regardless - but the hold is committed on the strength of
	 * bookkeeping that never happened, and on the 1213 ending the transaction is already dead and the
	 * insert autocommits alone, with no mutex held at all.
	 *
	 * `HoldBooking::reap()` also fires `reservant/hold/expired` and writes `expired` audit rows for
	 * every id the reap CLAIMS to have expired, so a silently failed UPDATE also announces expiries
	 * that never occurred.
	 */
	public function test_a_hold_whose_reap_silently_failed_is_refused_rather_than_committed(): void {
		global $wpdb;
		$lapsed = $this->holdAppointment( '10:00' );
		$this->lapse( (string) $lapsed['uuid'] );

		$refusal = $this->refusalUnderSabotage(
			'/UPDATE\s+\S*reservant_bookings\s+SET\s+status\s*=\s*\'expired\'/is',
			'UPDATE reservant_no_such_table SET status = 1 WHERE 1 = 1',
			function (): void {
				$this->holdAppointment( '10:00' );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal, 'a reap that failed must abort the hold it was clearing the way for' );

		// The committed state: no second booking, and the lapsed one untouched - not announced as
		// expired by a reap that never ran.
		self::assertSame( 1, $this->bookingCount(), 'a hold must never commit on the strength of a reap that silently did nothing' );
		self::assertSame( 'pending', ( new BookingRepository( $wpdb ) )->findByUuid( (string) $lapsed['uuid'] )['status'] );
	}

	/**
	 * The seat consequence of the same unguarded reap, which is the one a customer actually meets.
	 *
	 * A lapsed grid hold keeps its `seat_claim` until the reap NULLs it. If that release silently
	 * fails, the seat is free by `BLOCKING_SQL` but still claimed in the `(occurrence_id, seat_claim)`
	 * unique index, so the next customer to ask for it is refused `seat_taken` - a genuinely free seat,
	 * reported as taken, with nothing anywhere saying why. The guard turns that into an honest
	 * `lock_unavailable` retry.
	 */
	public function test_a_failed_reap_never_refuses_a_free_seat_as_taken(): void {
		global $wpdb;
		$mapId      = $this->seatMap();
		$seats      = $this->seatIds( $mapId );
		$occurrence = $this->gridOccurrence( $mapId );

		$lapsed = $this->holdSeats( $occurrence, array( $seats[0] ) );
		$this->lapse( (string) $lapsed['uuid'] );

		$refusal = $this->refusalUnderSabotage(
			'/UPDATE\s+\S*reservant_booking_items\s+SET\s+seat_claim\s*=\s*NULL\s+WHERE\s+booking_id\s+IN/is',
			'UPDATE reservant_no_such_table SET seat_claim = NULL WHERE 1 = 1',
			function () use ( $occurrence, $seats ): void {
				$this->holdSeats( $occurrence, array( $seats[0] ) );
			}
		);
		self::assertSame(
			'lock_unavailable',
			$refusal,
			'a free seat must never be reported as taken because the reap that frees it failed unnoticed'
		);

		self::assertSame( 1, $this->bookingCount() );
		self::assertSame( $seats[0], $this->seatClaimOf( (string) $lapsed['uuid'] ), 'the unreaped claim must survive intact for the next attempt' );
	}

	// ------------------------------------------- CRITICAL 2: the mutex row itself

	/**
	 * A mutex row that could not be created must refuse the request, not yield a lock over nothing.
	 *
	 * `ResourceDayRepository::ensure()` is the INSERT IGNORE that makes the resource-day mutex row
	 * exist at all, and it runs BEFORE the transaction. Its failure used to be justified as harmless
	 * on the grounds that `acquire()` "cannot lock a row that was never created" - but `acquire()`
	 * refused only on `false`, and a lock matching ZERO rows is not `false`. Nothing in this codebase
	 * ever reads a `reservant_resource_days` row, so unlike the occurrence case there is no later
	 * guard to answer `not_found`: the lock passed over an empty set and the capacity write ran with
	 * no mutex held whatsoever - the exact invariant this whole repair exists to restore.
	 */
	public function test_a_hold_whose_mutex_row_could_not_be_created_is_refused(): void {
		$refusal = $this->refusalUnderSabotage(
			'/^\s*INSERT\s+IGNORE\s+INTO\s+\S*reservant_resource_days/is',
			'INSERT IGNORE INTO reservant_no_such_table (id) VALUES (1)',
			function (): void {
				$this->holdAppointment( '11:00' );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal, 'a mutex row that could not be created must refuse the write it was guarding' );
		self::assertSame( 0, $this->bookingCount(), 'a capacity write must never commit with no mutex row in existence' );
	}

	/**
	 * The other half of the same hole, asserted directly: a resource-day lock that matched no row is a
	 * lock over nothing and must be refused.
	 *
	 * Refusing zero rows is safe HERE, and only here, because a resource-day mutex row is created by
	 * `ensure()` outside and before the transaction, and nothing in this codebase ever deletes one -
	 * the only statements touching that table are `ensure()`'s INSERT IGNORE, `bumpRev()`'s UPDATE and
	 * this lock. `SELECT ... FOR UPDATE` is a locking read, so it reads the latest committed row
	 * rather than the transaction's snapshot, and a rival `ensure()` still in flight blocks it rather
	 * than hiding the row. Zero rows therefore cannot mean "a legitimately concurrent ensure() has not
	 * landed yet"; it can only mean this request's own ensure() never took effect.
	 *
	 * Occurrence keys keep the opposite rule - see `LockManager::acquire()`'s docblock.
	 */
	public function test_a_resource_day_lock_matching_no_row_is_refused(): void {
		global $wpdb;
		// Deliberately NOT ensure()d: this is what a silently failed ensure() leaves behind.
		$key     = LockKey::resourceDay( 4242, '2026-03-01' );
		$refusal = null;
		try {
			( new TransactionRunner( $wpdb ) )->run(
				static function () use ( $wpdb, $key ): void {
					( new LockManager( $wpdb ) )->acquire( array( $key ) );
				}
			);
		} catch ( \RuntimeException $exception ) {
			$refusal = $exception->getMessage();
		}
		self::assertSame( 'lock_unavailable', $refusal, 'locking zero resource-day rows is holding no mutex at all' );
	}

	// ------------------------------------------- IMPORTANT 4: the seat release

	/**
	 * A cancellation whose seat release failed must refuse, not commit a permanently dead seat.
	 *
	 * `BookingRepository::releaseSeatClaims()` sits one and two lines before the `bumpRev()` that was
	 * guarded first, inside the same transaction. On a 1205 only that statement rolls back: the
	 * transition, the bump and the audit row all succeed and the transaction COMMITS a cancelled
	 * booking whose `seat_claim` is still set. `BLOCKING_SQL` then says that seat is free while the
	 * `(occurrence_id, seat_claim)` unique index refuses every attempt to rebook it - a seat nobody
	 * can ever sell again, committed silently, with no row anywhere recording that it happened.
	 */
	public function test_a_cancel_whose_seat_release_failed_is_refused_rather_than_committed(): void {
		global $wpdb;
		$mapId      = $this->seatMap();
		$seats      = $this->seatIds( $mapId );
		$occurrence = $this->gridOccurrence( $mapId );

		$booking = $this->holdSeats( $occurrence, array( $seats[0] ) );
		ConfirmBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:05' ) );

		// `releaseSeatClaims()` goes through `$wpdb->update()`, which quotes its identifiers and keys
		// on `booking_id = <id>`; the reap's own release is hand-written SQL keyed on `booking_id IN
		// (...)`. Matching the `=` form hits exactly the statement under test.
		$refusal = $this->refusalUnderSabotage(
			'/UPDATE\s+\S*reservant_booking_items\S*\s+SET\s+\S?seat_claim\S?\s*=\s*NULL\s+WHERE\s+\S?booking_id\S?\s*=/is',
			'UPDATE reservant_no_such_table SET seat_claim = NULL WHERE 1 = 1',
			function () use ( $booking, $wpdb ): void {
				CancelBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:06' ), true );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal, 'a seat release that failed must abort the cancellation' );

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertSame( 'confirmed', $after['status'], 'a cancellation must never commit while the seat it frees is still claimed' );
		self::assertSame( $seats[0], $this->seatClaimOf( (string) $booking['uuid'] ), 'the seat must stay coherently claimed by the booking that still holds it' );
	}

	// ------------------------------------------- IMPORTANT: the audit write

	/**
	 * A cancellation whose audit write failed must refuse, not commit silently.
	 *
	 * `AuditLog::record()` is the LAST statement inside every one of `HoldBooking`, `CancelBooking`,
	 * `ExpireHolds`, `RejectBooking`, `ApproveBooking`, `ConfirmBooking`, `MarkBookingOutcome` and
	 * `RescheduleBooking`'s transactions - immediately before the post-write re-read that becomes the
	 * response. Its return used to be discarded: on a 1213 deadlock the transaction is already dead
	 * server-side by the time this statement runs, and the following `findByUuid()` re-read sees the row
	 * exactly as it stood before the transaction started - the caller gets a 200 carrying a cancellation
	 * that never happened.
	 */
	public function test_a_cancel_whose_audit_write_failed_is_refused_rather_than_committed(): void {
		global $wpdb;
		$booking = $this->holdAppointment( '10:00' );
		ConfirmBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:05' ) );

		$refusal = $this->refusalUnderSabotage(
			'/^\s*INSERT\s+INTO\s+\S*reservant_audit_log\b/is',
			'INSERT INTO reservant_no_such_table (id) VALUES (1)',
			function () use ( $booking, $wpdb ): void {
				CancelBooking::make( $wpdb )->execute( (string) $booking['uuid'], $this->utc( 0, '00:06' ), true );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal, 'an audit write that failed must abort the cancellation it was recording' );

		$after = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
		self::assertSame(
			'confirmed',
			$after['status'],
			'a cancellation must never commit while the audit row recording it failed to write - the 200 would carry a change that never happened'
		);
	}

	// ------------------------------------------- the seat map's own insert

	/**
	 * A seat map creation whose own row insert failed must refuse before any seat is attached to it.
	 *
	 * `SeatMapRepository::insert()` used to discard `$wpdb->insert()`'s return and hand the caller
	 * `(int) $this->db->insert_id` regardless - on a failure that id is either `0` (no prior insert on
	 * this connection) or the id of whatever DIFFERENT row this connection last inserted, and
	 * `SeatMapsAdminController::create()` fed it straight into `insertSeats()`: seat rows attached to the
	 * wrong map, or to no map at all.
	 */
	public function test_a_seat_map_whose_own_insert_failed_is_refused_before_any_seat_is_attached(): void {
		global $wpdb;
		$refusal = $this->refusalUnderSabotage(
			'/^\s*INSERT\s+INTO\s+\S*reservant_seat_maps\b/is',
			'INSERT INTO reservant_no_such_table (id) VALUES (1)',
			static function () use ( $wpdb ): void {
				( new SeatMapRepository( $wpdb ) )->insert( 'Hall', 'rows A-B, 2 per row' );
			}
		);
		self::assertSame( 'lock_unavailable', $refusal, 'a seat map row that failed to insert must refuse before any seat is attached to it' );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reservant_seat_maps" ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::assertSame( 0, $count, 'no seat map row may exist when its own insert failed' );
	}

	// ------------------------------------------- Minor: findById()/findByUuid() uniformity

	/**
	 * `BookingRepository::findByUuid()` is the same `get_row()` statement shape as the guarded
	 * `findByUuidForUpdate()` - unlocked, but no less unable to tell "no such booking" from "the query
	 * failed" without `assertNoDbError()`. A masked failure here used to come back as an honest-looking
	 * `null`, which every caller reads as "row genuinely absent".
	 */
	public function test_find_by_uuid_refuses_rather_than_masking_a_db_failure_as_absence(): void {
		global $wpdb;
		$booking = $this->holdAppointment( '10:00' );

		// The plain read, never the locking `FOR UPDATE` one `findByUuidForUpdate()` already guards.
		$refusal = $this->refusalUnderSabotage(
			'/^\s*SELECT\s+\*\s+FROM\s+\S*reservant_bookings\s+WHERE\s+uuid\s*=(?!.*FOR UPDATE).*$/is',
			'SELECT * FROM reservant_no_such_table WHERE 1 = 1',
			function () use ( $booking, $wpdb ): void {
				( new BookingRepository( $wpdb ) )->findByUuid( (string) $booking['uuid'] );
			}
		);
		self::assertSame(
			'lock_unavailable',
			$refusal,
			'a DB-level failure on this read must never be reported as "no such booking"'
		);
	}

	/**
	 * The follow-up `findById()` needed inside `ExpireHolds::run()`'s own batch loop: guarding
	 * `findById()` means the pre-transaction batch read can now itself refuse `lock_unavailable`, and
	 * that must be caught by the exact same "skip this row, not the whole sweep" rule a busy mutex
	 * already gets - never let one bad row abort every other row in the batch.
	 */
	public function test_a_sweep_skips_a_row_whose_batch_read_failed_and_still_reaps_the_rest(): void {
		global $wpdb;
		// Both holds are created first, WHILE NEITHER IS LAPSED YET, deliberately: they share a
		// resource-day mutex (same resource, same day - `HoldBooking`'s lock keys are per resource-day,
		// not per time-of-day), and `HoldBooking::execute()` runs an inline reap of already-lapsed holds
		// touching that same mutex before inserting a new one (this file's own CRITICAL 1 tests, above).
		// Lapsing $lapsedA before creating $lapsedB would let THAT inline reap expire it right there,
		// leaving only one candidate for the sweep below and defeating the point of this test.
		$lapsedA = $this->holdAppointment( '09:00' );
		$lapsedB = $this->holdAppointment( '13:00' );
		$this->lapse( (string) $lapsedA['uuid'] );
		$this->lapse( (string) $lapsedB['uuid'] );

		// Sabotage only the FIRST plain `findById()`-shaped read against `reservant_bookings` - never
		// the row-locking `FOR UPDATE` ones. `expiredHeldIds()`'s own batch-candidate SELECT has no
		// `id = ` clause at all, and `findById()` is structurally the first such statement `run()` can
		// reach in either row's iteration (it runs before that row's own `transition()` UPDATE, which
		// is the only OTHER statement shaped like `id = <n>`), so this always lands on whichever
		// booking the batch happens to visit first - deliberately not pinned to a specific uuid, since
		// which of two rows sharing one `hold_expires_at` sorts first is not this test's concern.
		$hits     = 0;
		$sabotage = static function ( $query ) use ( &$hits ) {
			$q = (string) $query;
			if ( str_contains( $q, 'reservant_bookings' )
				&& 1 === preg_match( '/\bid\s*=\s*\d+\b/', $q )
				&& ! str_contains( $q, 'FOR UPDATE' )
			) {
				++$hits;
				if ( 1 === $hits ) {
					return 'SELECT * FROM reservant_no_such_table WHERE 1 = 1';
				}
			}
			return $query;
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		try {
			$processed = ExpireHolds::make( $wpdb )->run();
		} finally {
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
		}

		self::assertSame( 1, $processed, 'the one row whose batch read failed must be skipped, not counted' );

		$bookings = new BookingRepository( $wpdb );
		$statuses = array(
			$bookings->findByUuid( (string) $lapsedA['uuid'] )['status'],
			$bookings->findByUuid( (string) $lapsedB['uuid'] )['status'],
		);
		sort( $statuses );
		self::assertSame(
			array( 'expired', 'pending' ),
			$statuses,
			'the row whose batch read failed must stay untouched for the next sweep, and that failure must not abort the other row'
		);
	}
}
