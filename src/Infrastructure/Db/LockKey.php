<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/** A unit of lock contention. Sorted globally before acquisition - deadlock prevention. */
final class LockKey {

	private function __construct(
		public readonly string $type, // one of: occurrence, resource_day
		public readonly int $id,
		public readonly string $day,  // Y-m-d for resource_day, empty string otherwise
	) {}

	public static function resourceDay( int $resourceId, string $dayUtc ): self {
		return new self( 'resource_day', $resourceId, $dayUtc );
	}

	public static function occurrence( int $occurrenceId ): self {
		return new self( 'occurrence', $occurrenceId, '' );
	}

	/**
	 * The keys an already-planned or persisted set of booking items contends on: every UTC day each
	 * block range touches, per resource, plus the occurrence row for event items.
	 *
	 * This lives here rather than on a use case because it is the other half of what this class
	 * already owns. `sorted()` says in what order mutexes are taken; this says which mutexes an item
	 * needs at all, and `day` above only means anything alongside the rule that decomposes a range
	 * into days. It used to be a public static on `HoldBooking`, which made `CancelBooking`,
	 * `ApproveBooking`, `RejectBooking` and `ExpireHolds` depend on the hold use case for no reason
	 * beyond wanting its lock keys.
	 *
	 * An item with no `resource_id` yet - a planned appointment before the pick happens under the
	 * lock - contributes nothing: its candidate days are keyed per candidate resource by the caller
	 * that knows the candidates.
	 *
	 * @param list<array<string, mixed>> $items `booking_items` shape, persisted or planned
	 * @return list<self> deduplicated, globally ordered
	 */
	public static function forItems( array $items ): array {
		$keys = array();
		foreach ( $items as $item ) {
			if ( null !== ( $item['occurrence_id'] ?? null ) ) {
				$keys[] = self::occurrence( (int) $item['occurrence_id'] );
				continue;
			}
			if ( null === ( $item['resource_id'] ?? null ) ) {
				continue;
			}
			foreach ( self::daysTouched( (string) $item['block_start_utc'], (string) $item['block_end_utc'] ) as $day ) {
				$keys[] = self::resourceDay( (int) $item['resource_id'], $day );
			}
		}
		return self::sorted( $keys );
	}

	/**
	 * The UTC days a block range touches, one mutex each.
	 *
	 * The end is exclusive - a range ending at midnight contends on the day it ran through, not on
	 * the one it stops at - which is why the last day is computed from one second before it.
	 *
	 * @return list<string> `Y-m-d`, ascending
	 */
	public static function daysTouched( string $blockStartUtc, string $blockEndUtc ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $blockStartUtc, $utc );
		$end   = new \DateTimeImmutable( $blockEndUtc, $utc );
		$last  = max( $start, $end->modify( '-1 second' ) );

		$days = array();
		for ( $day = $start->setTime( 0, 0 ); $day <= $last; $day = $day->modify( '+1 day' ) ) {
			$days[] = $day->format( 'Y-m-d' );
		}
		return $days;
	}

	/**
	 * @param list<self> $keys
	 * @return list<self> deduplicated, globally ordered
	 */
	public static function sorted( array $keys ): array {
		$unique = array();
		foreach ( $keys as $key ) {
			$unique[ $key->type . '|' . $key->id . '|' . $key->day ] = $key;
		}
		$keys = array_values( $unique );
		usort( $keys, static fn ( self $a, self $b ): int => array( $a->type, $a->id, $a->day ) <=> array( $b->type, $b->id, $b->day ) );
		return $keys;
	}
}
