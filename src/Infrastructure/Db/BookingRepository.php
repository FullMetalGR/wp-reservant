<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

use Reservant\Domain\Enum\BookingStatus;

final class BookingRepository {

	/** The one blocking predicate (AGENTS.md section 2.1). Alias `b` = bookings, `i` = booking_items. */
	public const BLOCKING_SQL = "( b.status = 'confirmed' OR ( b.status IN ('pending','awaiting_approval','awaiting_payment') AND b.hold_expires_at > UTC_TIMESTAMP() ) )";

	private const BOOKING_INT_COLUMNS = array( 'id', 'total_minor', 'requires_approval', 'approved_by', 'wc_order_id' );
	private const ITEM_INT_COLUMNS    = array( 'id', 'booking_id', 'sort', 'service_id', 'resource_id', 'occurrence_id', 'seats', 'seat_claim', 'price_minor' );

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Insert container + items. Call inside a transaction only.
	 * @param array<string, mixed>       $booking
	 * @param list<array<string, mixed>> $items
	 */
	public function insertWithItems( array $booking, array $items ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $this->db->insert(
			$this->db->prefix . 'reservant_bookings',
			$booking + array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( 'booking_insert_failed: ' . $this->db->last_error );
		}
		$bookingId = (int) $this->db->insert_id;
		foreach ( $items as $sort => $item ) {
			$ok = $this->db->insert(
				$this->db->prefix . 'reservant_booking_items',
				$item + array(
					'booking_id' => $bookingId,
					'sort'       => $sort,
				)
			);
			if ( false === $ok ) {
				if ( str_contains( (string) $this->db->last_error, 'Duplicate entry' ) ) {
					throw new \RuntimeException( 'seat_taken' );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \RuntimeException( 'booking_item_insert_failed: ' . $this->db->last_error );
			}
		}
		return $bookingId;
	}

	/** @return array<string, mixed>|null booking row + 'items' list, ints cast */
	public function findByUuid( string $uuid ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_bookings WHERE uuid = %s", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Same shape as `findByUuid()`, but row-locking: the authoritative read for a use case that is
	 * about to guard a status/expiry check and then transition on it. Call inside a transaction
	 * only - the lock is released at COMMIT/ROLLBACK.
	 *
	 * @return array<string, mixed>|null booking row + 'items' list, ints cast
	 */
	public function findByUuidForUpdate( string $uuid ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_bookings WHERE uuid = %s FOR UPDATE", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return null === $row ? null : $this->hydrate( $row );
	}

	/** @return array<string, mixed>|null booking row + 'items' list, ints cast */
	public function findById( int $id ): ?array {
		$p   = $this->db->prefix;
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$p}reservant_bookings WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$p = $this->db->prefix;
		foreach ( self::BOOKING_INT_COLUMNS as $column ) {
			if ( null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		$items        = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$p}reservant_booking_items WHERE booking_id = %d ORDER BY sort ASC", $row['id'] ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$row['items'] = array_map(
			function ( array $item ): array {
				foreach ( self::ITEM_INT_COLUMNS as $column ) {
					if ( null !== $item[ $column ] ) {
						$item[ $column ] = (int) $item[ $column ];
					}
				}
				return $item;
			},
			$items
		);
		return $row;
	}

	/** Range overlap against blocking items - never start-time equality. */
	public function overlapCount( int $resourceId, string $blockStartUtc, string $blockEndUtc ): int {
		$p = $this->db->prefix;
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*)
			 FROM {$p}reservant_booking_items i
			 JOIN {$p}reservant_bookings b ON b.id = i.booking_id
			 WHERE i.resource_id = %d
			   AND i.block_start_utc < %s
			   AND i.block_end_utc > %s
			   AND " . self::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$resourceId,
				$blockEndUtc,
				$blockStartUtc
			)
		);
	}

	/**
	 * Expire timed-out holds touching the locked keys and free the seat claims of those rows.
	 * Call inside the transaction, after LockManager::acquire().
	 *
	 * Returns the reaped booking ids so the caller can audit them and notify - these rows can
	 * never be seen by ExpireHolds again (they are no longer in a held status), so the
	 * bookkeeping has to happen here or not at all.
	 *
	 * **Every statement here repeats the full held-and-lapsed guard, and the set is pinned with
	 * `FOR UPDATE` before anything is written.** The resource-day and occurrence mutexes serialise
	 * holds against each other, but they do not touch bookings: `ConfirmBooking` takes no lock at
	 * all, it is a single guarded UPDATE. A candidate list selected without the row lock is
	 * therefore stale the instant it is read - a confirm committing between the SELECT and the
	 * UPDATE would be overwritten `confirmed` -> `expired`, its seat claims NULLed and its slot
	 * resold. The locking re-read closes that: a confirm that got there first has already left the
	 * held statuses and is not in the returned set, and one that arrives later blocks on the row
	 * lock until COMMIT and then fails its own `status = 'pending'` guard. Because the rows are
	 * X-locked from the re-read to COMMIT, the ids returned here are exactly the ids the guarded
	 * UPDATE flips.
	 *
	 * @param list<LockKey> $keys
	 * @return list<int> ids moved to expired
	 */
	public function reapExpiredTouching( array $keys ): array {
		$p       = $this->db->prefix;
		$clauses = array();
		$args    = array();
		foreach ( $keys as $key ) {
			if ( 'resource_day' === $key->type ) {
				$dayStart  = $key->day . ' 00:00:00';
				$nextDay   = ( new \DateTimeImmutable( $key->day, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00';
				$clauses[] = '( i.resource_id = %d AND i.block_start_utc < %s AND i.block_end_utc > %s )';
				array_push( $args, $key->id, $nextDay, $dayStart );
			} else {
				$clauses[] = 'i.occurrence_id = %d';
				$args[]    = $key->id;
			}
		}
		if ( array() === $clauses ) {
			return array();
		}
		$where = implode( ' OR ', $clauses );

		// Candidates: the join that narrows a whole table to the handful of bookings touching these
		// keys. Unlocked and advisory - it only decides which primary keys the authoritative read
		// below has to look at.
		$candidates = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT b.id
			 FROM {$p}reservant_bookings b
			 JOIN {$p}reservant_booking_items i ON i.booking_id = b.id
			 WHERE b.status IN ('pending','awaiting_approval','awaiting_payment')
			   AND b.hold_expires_at <= UTC_TIMESTAMP()
			   AND ( {$where} )", // phpcs:ignore WordPress.DB.PreparedSQL
				...$args
			)
		);
		if ( array() === $candidates ) {
			return array();
		}
		$candidateIds = array_map( 'intval', $candidates );
		sort( $candidateIds ); // Ascending primary-key order: the same lock order for every reaper.

		// The authority: the same guard again, by primary key, under a row lock. A locking read
		// never sees the transaction's snapshot - it reads the latest committed row - so this is
		// where a racing confirm is noticed.
		$locked = $this->db->get_col(
			"SELECT id FROM {$p}reservant_bookings
			 WHERE id IN (" . implode( ',', $candidateIds ) . ")
			   AND status IN ('pending','awaiting_approval','awaiting_payment')
			   AND hold_expires_at <= UTC_TIMESTAMP()
			 ORDER BY id ASC
			 FOR UPDATE" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( array() === $locked ) {
			return array();
		}
		$reaped = array_map( 'intval', $locked );
		$in     = implode( ',', $reaped );

		// The guard is repeated a third time so the write is safe even read in isolation: an UPDATE
		// keyed on `id IN (...)` alone is a foot-gun the moment anyone moves it out from under the
		// lock.
		$this->db->query(
			"UPDATE {$p}reservant_bookings
			 SET status = 'expired', updated_at = UTC_TIMESTAMP()
			 WHERE id IN ({$in})
			   AND status IN ('pending','awaiting_approval','awaiting_payment')
			   AND hold_expires_at <= UTC_TIMESTAMP()" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		// Scoped to the rows that were actually expired - never to the candidate list. Releasing a
		// seat claim is releasing the seat.
		$this->db->query( "UPDATE {$p}reservant_booking_items SET seat_claim = NULL WHERE booking_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $reaped;
	}

	/**
	 * Guarded status transition - single atomic statement.
	 * @param array<string, mixed> $extra
	 */
	public function transition( int $bookingId, BookingStatus $from, BookingStatus $to, array $extra = array() ): bool {
		if ( ! $from->canTransitionTo( $to ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \LogicException( sprintf( 'Illegal transition %s -> %s.', $from->value, $to->value ) );
		}
		$updated = $this->db->update(
			$this->db->prefix . 'reservant_bookings',
			$extra + array(
				'status'     => $to->value,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $bookingId,
				'status' => $from->value,
			)
		);
		return 1 === $updated;
	}

	public function releaseSeatClaims( int $bookingId ): void {
		$this->db->update( $this->db->prefix . 'reservant_booking_items', array( 'seat_claim' => null ), array( 'booking_id' => $bookingId ) );
	}

	/** @return list<int> */
	public function expiredHeldIds( int $limit ): array {
		$p   = $this->db->prefix;
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$p}reservant_bookings
			 WHERE status IN ('pending','awaiting_approval','awaiting_payment')
			   AND hold_expires_at <= UTC_TIMESTAMP()
			 ORDER BY hold_expires_at ASC
			 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Blocking block-ranges per resource for the SlotGenerator's busyByResource input.
	 * @param list<int> $resourceIds
	 * @return array<int, list<array{string,string}>>
	 */
	public function blockingIntervalsForResources( array $resourceIds, string $fromUtc, string $toUtc ): array {
		$out = array_fill_keys( $resourceIds, array() );
		if ( array() === $resourceIds ) {
			return $out;
		}
		$p    = $this->db->prefix;
		$in   = implode( ',', array_map( 'intval', $resourceIds ) );
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT i.resource_id, i.block_start_utc, i.block_end_utc
			 FROM {$p}reservant_booking_items i
			 JOIN {$p}reservant_bookings b ON b.id = i.booking_id
			 WHERE i.resource_id IN ({$in})
			   AND i.block_start_utc < %s
			   AND i.block_end_utc > %s
			   AND " . self::BLOCKING_SQL, // phpcs:ignore WordPress.DB.PreparedSQL
				$toUtc,
				$fromUtc
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$out[ (int) $row['resource_id'] ][] = array( $row['block_start_utc'], $row['block_end_utc'] );
		}
		return $out;
	}
}
