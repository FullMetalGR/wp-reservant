<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Infrastructure\Db\LockManager;
use Reservant\Infrastructure\Db\ResourceDayRepository;
use Reservant\Infrastructure\Db\TransactionRunner;

/**
 * The AGENTS.md section 2.2 write protocol, stated once, for the four use cases that are the same
 * act: a guarded booking state transition.
 *
 * `CancelBooking`, `ApproveBooking`, `RejectBooking` and `ExpireHolds` each ran their own copy of
 * this sequence - mutex rows outside the transaction, open, lock in `LockKey::sorted()` order,
 * re-read under the mutex, guard, compare-and-set, release seats, bump the revision, audit, commit,
 * and only then fire the hook. Nine ordered steps, four transcriptions, and several of the steps are
 * invisible in their absence: a missing `bumpRev()` leaves a freed slot cached busy with no failing
 * test (`RejectBooking`'s docblock records exactly that near-miss), and a hook fired one line too
 * early runs a listener inside a transaction that can still roll back.
 *
 * **Deliberately NOT covering all six full-protocol sites.** `HoldBooking` is an insert with a nested
 * reap, and `RescheduleBooking` is a delete-then-insert behind a key-set staleness guard. Folding
 * those in needs knobs for locking-vs-plain re-reads, nested audit writes inside another site's
 * transaction, a conditional rev bump and a branching hook - six knobs for six callers, which moves
 * the complexity rather than hiding it. Four sites collapsing to one is worth having; six is not.
 *
 * **What is derived rather than passed**, because a parameter is a thing the fifth caller can set
 * wrongly:
 *
 *  - **The `from` status** comes off the freshly-read row, never from the caller. `ApproveBooking`
 *    and `RejectBooking` used to name `AwaitingApproval` literally; their guard already refuses any
 *    other status, so the literal was a second copy of a fact the guard owned.
 *  - **Whether seats are released** comes from `BookingStatus::releasesSeatClaims()`. Three of the
 *    four release and one does not, and the difference is not a property of the use case - it is
 *    the difference between a booking that stopped happening and one that is going ahead.
 *
 * **What stays a parameter, and why each one has to.** The guard itself (four genuinely different
 * questions); the extra columns written with the transition; `$lostRace` (the compare-and-set losing
 * is `stale_state` to a cancel and `not_approvable` to an approval - both reach the customer as
 * different sentences, so folding them would change what the wire says); and `$lockRow`. That last
 * is the one worth naming: `ApproveBooking` and `RejectBooking` re-read `FOR UPDATE`, `CancelBooking`
 * and `ExpireHolds` re-read plain. Both are correct - the guarded compare-and-set is what decides the
 * race, and every read on this path raises `lock_unavailable` on a DB-level failure rather than
 * returning null - so this is preserved as it was found rather than unified upward, because
 * unifying it would change the contention profile of the sweeper, and the sweeper is one of the
 * paths `bin/run-concurrency.sh` exists to protect.
 */
final class GuardedWrite {

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
	 * Run one guarded transition end to end and return the stored post-transition snapshot.
	 *
	 * @param list<LockKey>                                              $keys     Slot mutexes, in
	 *                                                                             `LockKey::forItems()`
	 *                                                                             order.
	 * @param string                                                     $uuid     The booking to move.
	 * @param BookingStatus                                              $to       Target status.
	 * @param \Closure(array<string, mixed>, BookingStatus): void        $guard    Re-decides the move
	 *                                                                             on the locked row.
	 *                                                                             Returns void and
	 *                                                                             THROWS its own
	 *                                                                             refusal - each use
	 *                                                                             case owns a
	 *                                                                             different question
	 *                                                                             and a different
	 *                                                                             answer, so it
	 *                                                                             cannot be a bool.
	 * @param string                                                     $lostRace Refusal reason when
	 *                                                                             the compare-and-set
	 *                                                                             finds the row
	 *                                                                             already moved.
	 * @param string                                                     $actor    Audit actor label.
	 * @param string                                                     $action   Audit action label.
	 * @param string                                                     $hook     Fired AFTER commit.
	 * @param array<string, mixed>                                       $columns  Extra columns set
	 *                                                                             with the status.
	 * @param bool                                                       $lockRow  Re-read `FOR UPDATE`.
	 * @return array<string, mixed>
	 * @throws TransitionRefused When the row vanished, the guard declined, or the race was lost.
	 * @throws \RuntimeException `lock_unavailable` from any lock, read or write on the path.
	 */
	public function transition(
		array $keys,
		string $uuid,
		BookingStatus $to,
		\Closure $guard,
		string $lostRace,
		string $actor,
		string $action,
		string $hook,
		array $columns = array(),
		bool $lockRow = true
	): array {
		// Mutex rows must exist before the transaction opens - SELECT ... FOR UPDATE cannot lock a
		// row that is not there (AGENTS.md section 2.2).
		$this->resourceDays->ensure( $keys );

		$snapshot = $this->txn->run(
			function () use ( $keys, $uuid, $to, $guard, $lostRace, $actor, $action, $columns, $lockRow ): array {
				// The slot mutexes FIRST, the booking row after - the codebase-wide lock order.
				$this->locks->acquire( $keys );

				// Everything the caller decided before this line ran on an unlocked snapshot, and
				// `ConfirmBooking` takes no lock at all - it is one guarded UPDATE - so the booking
				// may have moved on since. The status this transaction acts on is the one read here.
				$fresh = $lockRow ? $this->bookings->findByUuidForUpdate( $uuid ) : $this->bookings->findByUuid( $uuid );
				if ( null === $fresh ) {
					throw new TransitionRefused( 'stale_state' );
				}

				$from = BookingStatus::from( (string) $fresh['status'] );
				$guard( $fresh, $from );

				$bookingId = (int) $fresh['id'];
				// The compare-and-set. Guarded on `$from`, so the residual window between the read
				// above and this statement is a refusal rather than a wrong outcome.
				if ( ! $this->bookings->transition( $bookingId, $from, $to, $columns ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A caller-supplied refusal CODE from `Errors::KNOWN_REASONS`, never output; `Errors::failure()` maps it to a translated sentence.
					throw new TransitionRefused( $lostRace );
				}
				if ( $to->releasesSeatClaims() ) {
					$this->bookings->releaseSeatClaims( $bookingId );
				}
				// The free/busy mask is cached on `reservant_resource_days.rev` (AGENTS.md section
				// 2.4 step 6). A transition that changes whether the slot blocks and does not bump
				// it leaves the slot cached at its old answer.
				$this->resourceDays->bumpRev( $keys );
				$this->audit->record( $bookingId, $actor, $action );

				/** @var array<string, mixed> $stored */
				$stored = $this->bookings->findByUuid( $uuid );
				return $stored;
			}
		);

		// AFTER the commit, never inside it: a listener must not run against a transaction that can
		// still roll back, and no listener may fail the transition that already happened.
		do_action( $hook, BookingSnapshot::fromArray( $snapshot ) );
		return $snapshot;
	}
}
