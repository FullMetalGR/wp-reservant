<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Reservant\Infrastructure\Db\LockKey;

/**
 * Which mutexes a set of items needs, and in what order they are taken.
 *
 * AGENTS.md section 2.2 calls lock ordering "deadlock protection, not optional" and says to test it
 * with parallel chains - which `bin/run-concurrency.sh` does, against a live server, in about two
 * minutes. That proves the property end to end and is not going anywhere, but it is a poor place to
 * learn that a range ending at midnight claims a day it does not touch.
 *
 * The derivation used to be a public static on `HoldBooking`, so the only way to reach it was to
 * take out a hold: a database, a service, a staff member, working hours. It is a pure function of
 * item rows and it now lives on the value object that owns the ordering, so this file exercises it
 * with no WordPress, no database and no fixtures.
 */
final class LockKeyTest extends TestCase {

	/** @return array<string, mixed> */
	private static function appointment( int $resourceId, string $blockStart, string $blockEnd ): array {
		return array(
			'resource_id'     => $resourceId,
			'occurrence_id'   => null,
			'block_start_utc' => $blockStart,
			'block_end_utc'   => $blockEnd,
		);
	}

	/** @param list<LockKey> $keys @return list<string> */
	private static function names( array $keys ): array {
		return array_map( static fn ( LockKey $k ): string => $k->type . ':' . $k->id . ( '' === $k->day ? '' : '@' . $k->day ), $keys );
	}

	public function test_an_appointment_item_claims_the_day_its_block_range_sits_in(): void {
		self::assertSame(
			array( 'resource_day:4@2026-08-20' ),
			self::names( LockKey::forItems( array( self::appointment( 4, '2026-08-20 09:00:00', '2026-08-20 09:30:00' ) ) ) )
		);
	}

	public function test_a_range_ending_at_midnight_does_not_claim_the_day_it_stops_at(): void {
		// The end is exclusive. Claiming 08-21 here would take a mutex nothing contends on and, on a
		// chain, widen the lock set enough to serialise bookings that never overlap.
		self::assertSame(
			array( 'resource_day:4@2026-08-20' ),
			self::names( LockKey::forItems( array( self::appointment( 4, '2026-08-20 23:00:00', '2026-08-21 00:00:00' ) ) ) )
		);
	}

	public function test_a_range_crossing_midnight_claims_both_days(): void {
		self::assertSame(
			array( 'resource_day:4@2026-08-20', 'resource_day:4@2026-08-21' ),
			self::names( LockKey::forItems( array( self::appointment( 4, '2026-08-20 23:30:00', '2026-08-21 00:30:00' ) ) ) )
		);
	}

	public function test_a_range_spanning_several_days_claims_every_one_of_them(): void {
		self::assertSame(
			array( 'resource_day:4@2026-08-20', 'resource_day:4@2026-08-21', 'resource_day:4@2026-08-22' ),
			self::names( LockKey::forItems( array( self::appointment( 4, '2026-08-20 10:00:00', '2026-08-22 10:00:00' ) ) ) )
		);
	}

	public function test_an_event_item_claims_its_occurrence_and_no_resource_day(): void {
		$keys = LockKey::forItems(
			array(
				array(
					'resource_id'     => null,
					'occurrence_id'   => 9,
					'block_start_utc' => '2026-08-20 18:00:00',
					'block_end_utc'   => '2026-08-20 20:00:00',
				),
			)
		);
		self::assertSame( array( 'occurrence:9' ), self::names( $keys ) );
	}

	public function test_an_item_whose_resource_is_not_picked_yet_contributes_nothing(): void {
		// A planned appointment before the pick happens under the lock. Its candidate days are keyed
		// per candidate by the caller that knows the candidates, so deriving from the item alone
		// would key the wrong resource - or, worse, none.
		$planned = self::appointment( 0, '2026-08-20 09:00:00', '2026-08-20 09:30:00' );
		$planned['resource_id'] = null;
		self::assertSame( array(), LockKey::forItems( array( $planned ) ) );
	}

	public function test_two_items_on_one_resource_and_day_take_one_mutex_not_two(): void {
		$keys = LockKey::forItems(
			array(
				self::appointment( 4, '2026-08-20 09:00:00', '2026-08-20 09:30:00' ),
				self::appointment( 4, '2026-08-20 14:00:00', '2026-08-20 14:45:00' ),
			)
		);
		self::assertSame( array( 'resource_day:4@2026-08-20' ), self::names( $keys ) );
	}

	public function test_occurrence_mutexes_are_always_taken_before_resource_day_ones(): void {
		$keys = LockKey::forItems(
			array(
				self::appointment( 4, '2026-08-20 09:00:00', '2026-08-20 09:30:00' ),
				array( 'resource_id' => null, 'occurrence_id' => 9, 'block_start_utc' => '2026-08-20 18:00:00', 'block_end_utc' => '2026-08-20 20:00:00' ),
			)
		);
		self::assertSame( array( 'occurrence:9', 'resource_day:4@2026-08-20' ), self::names( $keys ) );
	}

	/**
	 * The deadlock property itself, as a unit test.
	 *
	 * Two chains that book the same pair of staff in opposite order must take the same mutexes in
	 * the same sequence, or they deadlock. `bin/run-concurrency.sh` proves this against a live
	 * server with real parallel requests and stays the authority; what this adds is that the
	 * ordering rule can now be contradicted in ten milliseconds instead of two minutes.
	 */
	public function test_opposing_chain_orders_produce_one_identical_lock_sequence(): void {
		$alexThenBella = LockKey::forItems(
			array(
				self::appointment( 4, '2026-08-20 09:00:00', '2026-08-20 09:30:00' ),
				self::appointment( 7, '2026-08-20 09:30:00', '2026-08-20 10:15:00' ),
			)
		);
		$bellaThenAlex = LockKey::forItems(
			array(
				self::appointment( 7, '2026-08-20 09:00:00', '2026-08-20 09:45:00' ),
				self::appointment( 4, '2026-08-20 09:45:00', '2026-08-20 10:15:00' ),
			)
		);
		self::assertSame( self::names( $alexThenBella ), self::names( $bellaThenAlex ) );
		self::assertSame( array( 'resource_day:4@2026-08-20', 'resource_day:7@2026-08-20' ), self::names( $alexThenBella ) );
	}

	public function test_ordering_is_by_resource_then_day_so_a_multi_day_chain_is_still_deterministic(): void {
		$forward = LockKey::forItems(
			array(
				self::appointment( 7, '2026-08-22 09:00:00', '2026-08-22 09:30:00' ),
				self::appointment( 4, '2026-08-21 09:00:00', '2026-08-21 09:30:00' ),
				self::appointment( 7, '2026-08-20 09:00:00', '2026-08-20 09:30:00' ),
			)
		);
		self::assertSame(
			array( 'resource_day:4@2026-08-21', 'resource_day:7@2026-08-20', 'resource_day:7@2026-08-22' ),
			self::names( $forward )
		);
	}

	public function test_shuffling_the_items_never_changes_the_lock_sequence(): void {
		$items = array(
			self::appointment( 7, '2026-08-22 09:00:00', '2026-08-22 09:30:00' ),
			array( 'resource_id' => null, 'occurrence_id' => 3, 'block_start_utc' => '2026-08-20 18:00:00', 'block_end_utc' => '2026-08-20 20:00:00' ),
			self::appointment( 4, '2026-08-21 23:30:00', '2026-08-22 00:30:00' ),
		);
		$expected = self::names( LockKey::forItems( $items ) );
		foreach ( array( array( 2, 0, 1 ), array( 1, 2, 0 ), array( 2, 1, 0 ) ) as $order ) {
			$permuted = array_map( static fn ( int $i ): array => $items[ $i ], $order );
			self::assertSame( $expected, self::names( LockKey::forItems( $permuted ) ), 'Input order must never reach the lock sequence.' );
		}
	}
}
