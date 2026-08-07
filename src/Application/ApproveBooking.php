<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * Owner approval of a booking that required it (AGENTS.md "Approval queue"). Free/onsite only:
 * the approve -> payment-link step for a paid service is the WooCommerce bridge's job, not this
 * use case's - it always lands the booking `confirmed`.
 *
 * Runs under the section-2.2 locks, the same ones a hold takes (`CancelBooking`'s shape). For a
 * HUMAN approval that looks like belt and braces: `approvable()` demands a hold that has not
 * lapsed, and a live `awaiting_approval` hold is already blocking (section 2.1), so confirming it
 * consumes no capacity that was not already consumed. `Jobs::timeout()` breaks exactly that
 * assumption on purpose: for `on_approval_timeout = auto_approve` it synthesizes
 * `$nowUtc = hold_expires_at - 1 second`, so this use case is reached AFTER the hold really lapsed,
 * at an instant when the blocking predicate already reads the row as free. Turning it back into a
 * permanent `confirmed` row is a capacity acquisition, and every capacity acquisition in this
 * codebase happens under the mutex governing that slot.
 *
 * Unlocked, a rival hold (T1) and an auto-approve (T2) interleave into a double booking without any
 * of the three ever being wrong on its own: T1 locks the resource-day, `reapExpiredTouching()`
 * blocks on the booking row T2 already holds, T2 confirms and commits, T1's locking re-read
 * correctly declines to reap a now-`confirmed` row - and then T1's `overlapCount()`, a PLAIN
 * consistent read on a REPEATABLE READ snapshot taken before T2 committed, still sees the old
 * lapsed `awaiting_approval` row, calls it non-blocking, and inserts on top of it.
 *
 * Taking the mutex is the whole fix; no extra re-validation is added, deliberately:
 *
 *  - It makes both orderings correct rather than one. If the hold wins the lock, its reap - which
 *    is authoritative precisely because it re-reads `FOR UPDATE` - moves the lapsed row to
 *    `expired` first, and `approvable()` here then refuses it as `not_approvable` (which
 *    `Jobs::timeout()` already swallows as the benign outcome it is). If the approval wins, the
 *    hold's snapshot is taken after this commit and its `overlapCount()`/`blockingSeatSum()` see a
 *    `confirmed` row, so it is refused `overlap`/`capacity`.
 *  - Re-validating instead would have to exclude the booking's own items from every count (a live
 *    approval hold blocks against itself), i.e. new repository surface for a case the lock already
 *    settles - and it would make a human approval both more expensive and, if the exclusion were
 *    ever wrong, capable of failing where today it cannot.
 *
 * `bumpRev()` for the same reason `CancelBooking` and `ExpireHolds` call it: on the timeout path a
 * lapsed hold is not in the free/busy mask and a confirmed booking is, so the mask cache key
 * (`reservant_resource_days.rev`, AGENTS.md section 2.4 step 6) must move.
 *
 * What the lock covers, and what it does not: it serialises this use case against every other
 * writer that also takes the same resource-day/occurrence mutex - `HoldBooking`, `CancelBooking`,
 * `ExpireHolds`, and an admin edit through `OccurrencesAdminController`. It does NOT close every
 * over-capacity path against a THIRD actor that never takes the lock at all. `OccurrencesAdminController`'s
 * own capacity-shrink guard counts only BLOCKING seats (`blockingSeatSum()`); a lapsed
 * `awaiting_approval` hold is not blocking. So: an event service with `requires_approval` and
 * `on_approval_timeout = auto_approve`, a hold that lapses, and an admin capacity shrink that lands
 * inside the gap between the lapse and `Jobs::timeout()` actually running its auto-approve can still
 * see the shrink's guard pass (nothing blocking to count against) and then this class's own
 * synthesized-`$nowUtc` auto-approve land the booking over the new, smaller capacity. Narrower than
 * the pre-fix state - it takes a queued timeout job racing an admin edit, not any ordinary hold - and
 * not a regression this class introduces; just not a case the lock reaches.
 *
 * Lock order is the codebase-wide one and must stay so: resource_days/occurrences via
 * `LockManager::acquire()` FIRST, the bookings row (`findByUuidForUpdate()`) after.
 */
final class ApproveBooking {

	public function __construct(
		private readonly TransactionRunner $txn,
		private readonly LockManager $locks,
		private readonly ResourceDayRepository $resourceDays,
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new TransactionRunner( $db ),
			new LockManager( $db ),
			new ResourceDayRepository( $db ),
			new BookingRepository( $db ),
			new AuditLog( $db )
		);
	}

	/**
	 * @param string   $actor       Free-form audit actor label (e.g. 'admin', a signed-link token
	 *                              name). Recorded on the audit row only.
	 * @param int|null $actorUserId The approving WP user id, when one exists. `approved_by` is a
	 *                              BIGINT UNSIGNED FK (Migrations::run()) - it takes this, never
	 *                              the free-form `$actor` string, and is left a real SQL NULL for
	 *                              system/signed-link approvals that have no WP user behind them.
	 * @return array<string, mixed>
	 */
	public function execute( string $uuid, \DateTimeImmutable $nowUtc, string $actor, ?int $actorUserId = null ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		// Cheap refusal on the unlocked read (CancelBooking's pattern): re-decided under the lock
		// below, which is what actually binds.
		if ( ! self::approvable( $booking, $nowUtc ) ) {
			throw new \RuntimeException( 'not_approvable' );
		}

		/** @var list<array<string, mixed>> $items */
		$items = $booking['items'];
		$keys  = HoldBooking::lockKeysForItems( $items );
		// Mutex rows must exist before the transaction opens - SELECT ... FOR UPDATE cannot lock a
		// row that is not there (AGENTS.md section 2.2).
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run(
			function () use ( $keys, $uuid, $nowUtc, $actor, $actorUserId ): array {
				// The slot mutexes FIRST, the booking row after - the codebase-wide lock order.
				$this->locks->acquire( $keys );

				// Re-read under FOR UPDATE: a lapsed hold or a rival decision (reject/cancel) may
				// have landed between the unlocked read above and this transaction opening, and the
				// row this one acts on is the one read here.
				$fresh = $this->bookings->findByUuidForUpdate( $uuid );
				if ( null === $fresh ) {
					throw new \RuntimeException( 'stale_state' );
				}
				if ( ! self::approvable( $fresh, $nowUtc ) ) {
					throw new \RuntimeException( 'not_approvable' );
				}

				$extra = array(
					'hold_class'      => null,
					'hold_expires_at' => null,
					'approved_at'     => $nowUtc->format( 'Y-m-d H:i:s' ),
					'approved_by'     => $actorUserId,
				);
				if ( ! $this->bookings->transition( (int) $fresh['id'], BookingStatus::AwaitingApproval, BookingStatus::Confirmed, $extra ) ) {
					throw new \RuntimeException( 'not_approvable' );
				}
				$this->resourceDays->bumpRev( $keys );
				$this->audit->record( (int) $fresh['id'], $actor, 'approve' );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		do_action( 'reservant/booking/approved', BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}

	/**
	 * `awaiting_approval` and the hold has not lapsed - the same window the customer's approval
	 * email is still valid for.
	 *
	 * @param array<string, mixed> $booking
	 */
	private static function approvable( array $booking, \DateTimeImmutable $nowUtc ): bool {
		if ( BookingStatus::AwaitingApproval->value !== $booking['status'] ) {
			return false;
		}
		return null !== $booking['hold_expires_at'] && (string) $booking['hold_expires_at'] > $nowUtc->format( 'Y-m-d H:i:s' );
	}
}
