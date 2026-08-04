<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Tests\Integration\ReservantTestCase;

final class BookingRepositoryTest extends ReservantTestCase {

	private function repo(): BookingRepository {
		global $wpdb;
		return new BookingRepository( $wpdb );
	}

	/** @return int booking id */
	private function insertAppointment( string $uuid, string $status, ?string $holdExpires, string $blockStart, string $blockEnd, int $resourceId = 1 ): int {
		global $wpdb;
		$runner = new TransactionRunner( $wpdb );
		return $runner->run( fn (): int => $this->repo()->insertWithItems(
			array( 'uuid' => $uuid, 'status' => $status, 'hold_expires_at' => $holdExpires, 'customer_name' => 'T', 'customer_email' => 't@example.com' ),
			array( array(
				'service_id'      => 1,
				'resource_id'     => $resourceId,
				'start_utc'       => $blockStart,
				'end_utc'         => $blockEnd,
				'block_start_utc' => $blockStart,
				'block_end_utc'   => $blockEnd,
			) )
		) );
	}

	public function test_insert_and_find_roundtrip_with_items(): void {
		$this->insertAppointment( 'uu-1', 'pending', '2030-01-01 00:00:00', '2026-03-01 09:00:00', '2026-03-01 09:30:00' );
		$found = $this->repo()->findByUuid( 'uu-1' );
		self::assertNotNull( $found );
		self::assertSame( 'pending', $found['status'] );
		self::assertCount( 1, $found['items'] );
		self::assertSame( 1, $found['items'][0]['resource_id'] );
		self::assertNull( $this->repo()->findByUuid( 'missing' ) );
	}

	public function test_overlap_count_uses_ranges_not_equality(): void {
		$this->insertAppointment( 'uu-2', 'confirmed', null, '2026-03-01 10:00:00', '2026-03-01 10:45:00' );
		// 10:30 start overlaps the 10:00-10:45 block.
		self::assertSame( 1, $this->repo()->overlapCount( 1, '2026-03-01 10:30:00', '2026-03-01 11:00:00' ) );
		// Adjacent range does not overlap (half-open).
		self::assertSame( 0, $this->repo()->overlapCount( 1, '2026-03-01 10:45:00', '2026-03-01 11:15:00' ) );
		// Other resource unaffected.
		self::assertSame( 0, $this->repo()->overlapCount( 2, '2026-03-01 10:30:00', '2026-03-01 11:00:00' ) );
	}

	public function test_expired_holds_do_not_block(): void {
		$this->insertAppointment( 'uu-3', 'pending', '2020-01-01 00:00:00', '2026-03-01 12:00:00', '2026-03-01 12:30:00' );
		self::assertSame( 0, $this->repo()->overlapCount( 1, '2026-03-01 12:00:00', '2026-03-01 12:30:00' ) );
	}

	public function test_reap_expired_touching_flips_status_and_frees_claims(): void {
		global $wpdb;
		$id     = $this->insertAppointment( 'uu-4', 'pending', '2020-01-01 00:00:00', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 7 );
		$runner = new TransactionRunner( $wpdb );
		$reaped = $runner->run( fn (): array => $this->repo()->reapExpiredTouching( array( LockKey::resourceDay( 7, '2026-03-02' ) ) ) );
		// The ids come back so callers can audit and notify - the bulk UPDATE leaves no trace.
		self::assertSame( array( $id ), $reaped );
		self::assertSame( 'expired', $this->repo()->findByUuid( 'uu-4' )['status'] );
	}

	public function test_reap_expired_touching_reaps_hold_in_last_hour_of_the_day(): void {
		global $wpdb;
		// Block range sits in the LAST hour of the key's day (23:30-23:55). A timezone-shifted
		// next-day boundary (e.g. computed via strtotime() under a non-UTC date.timezone) would
		// push "day+1 00:00:00" earlier or later and exclude this item from the touch window.
		$id     = $this->insertAppointment( 'uu-7', 'pending', '2020-01-01 00:00:00', '2026-03-02 23:30:00', '2026-03-02 23:55:00', 9 );
		$runner = new TransactionRunner( $wpdb );
		$reaped = $runner->run( fn (): array => $this->repo()->reapExpiredTouching( array( LockKey::resourceDay( 9, '2026-03-02' ) ) ) );
		self::assertSame( array( $id ), $reaped );
		self::assertSame( 'expired', $this->repo()->findByUuid( 'uu-7' )['status'] );
	}

	/** @return int booking id */
	private function insertEventSeat( string $uuid, string $status, ?string $holdExpires, int $occurrenceId, int $seatClaim ): int {
		global $wpdb;
		$runner = new TransactionRunner( $wpdb );
		return $runner->run( fn (): int => $this->repo()->insertWithItems(
			array( 'uuid' => $uuid, 'status' => $status, 'hold_expires_at' => $holdExpires, 'customer_name' => 'T', 'customer_email' => 't@example.com' ),
			array( array(
				'service_id'      => 1,
				'occurrence_id'   => $occurrenceId,
				'start_utc'       => '2026-03-06 18:00:00',
				'end_utc'         => '2026-03-06 20:00:00',
				'block_start_utc' => '2026-03-06 18:00:00',
				'block_end_utc'   => '2026-03-06 20:00:00',
				'seat_claim'      => $seatClaim,
			) )
		) );
	}

	/**
	 * The race the reap must lose: a booking whose hold lapsed a moment ago but which a concurrent
	 * ConfirmBooking already moved to `confirmed`.
	 *
	 * ConfirmBooking holds no lock - it is a single guarded UPDATE - so it can commit between the
	 * reaper's candidate SELECT and its UPDATE. With the write guarded only by `id IN (...)` the
	 * confirmed booking would be flipped to `expired`, its seat claim NULLed and the seat resold,
	 * with the customer holding a confirmation. `confirmed` blocks unconditionally (AGENTS.md
	 * section 2.1); expiry is a property of HELD rows only, and `hold_expires_at` is simply stale here.
	 */
	public function test_reap_never_touches_a_booking_a_racing_confirm_already_won(): void {
		global $wpdb;
		$confirmed = $this->insertEventSeat( 'uu-8', 'confirmed', '2020-01-01 00:00:00', 5, 42 );
		$stale     = $this->insertEventSeat( 'uu-9', 'pending', '2020-01-01 00:00:00', 5, 43 );

		$runner = new TransactionRunner( $wpdb );
		$reaped = $runner->run( fn (): array => $this->repo()->reapExpiredTouching( array( LockKey::occurrence( 5 ) ) ) );

		// Only the genuinely held row is reported, so only it is audited and announced.
		self::assertSame( array( $stale ), $reaped );
		self::assertNotContains( $confirmed, $reaped );

		$survivor = $this->repo()->findByUuid( 'uu-8' );
		self::assertSame( 'confirmed', $survivor['status'] );
		self::assertSame( 42, $survivor['items'][0]['seat_claim'] ); // Seat still claimed.

		// The neighbouring hold on the same occurrence is reaped normally - the guard narrows the
		// write, it does not disable it.
		$expired = $this->repo()->findByUuid( 'uu-9' );
		self::assertSame( 'expired', $expired['status'] );
		self::assertNull( $expired['items'][0]['seat_claim'] );
	}

	public function test_transition_is_guarded(): void {
		$id = $this->insertAppointment( 'uu-5', 'pending', '2030-01-01 00:00:00', '2026-03-03 09:00:00', '2026-03-03 09:30:00' );
		self::assertTrue( $this->repo()->transition( $id, BookingStatus::Pending, BookingStatus::Confirmed, array( 'hold_expires_at' => null, 'hold_class' => null ) ) );
		self::assertFalse( $this->repo()->transition( $id, BookingStatus::Pending, BookingStatus::Confirmed ) ); // already moved
		self::assertSame( 'confirmed', $this->repo()->findByUuid( 'uu-5' )['status'] );
	}

	public function test_blocking_intervals_for_resources(): void {
		$this->insertAppointment( 'uu-6', 'confirmed', null, '2026-03-04 09:00:00', '2026-03-04 09:30:00', 3 );
		$intervals = $this->repo()->blockingIntervalsForResources( array( 3 ), '2026-03-04 00:00:00', '2026-03-05 00:00:00' );
		self::assertSame( array( array( '2026-03-04 09:00:00', '2026-03-04 09:30:00' ) ), $intervals[3] );
	}
}
