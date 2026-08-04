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
