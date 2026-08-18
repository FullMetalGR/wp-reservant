<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

final class AuditLog {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * **The audit write for a call that runs BEFORE the change it records has committed - and only
	 * for those. Checked, on the write convention every guarded transaction in this codebase follows
	 * (`false === $wpdb->insert()`'s return).**
	 *
	 * THE SPLIT THIS METHOD IS ONE HALF OF, which every guarded statement in this codebase belongs to
	 * on one side or the other:
	 *
	 *  - A **PRE-DECISION** read or write - anything the code reads before deciding, or writes while a
	 *    lock is protecting it - MUST refuse on a DB failure. A silent `null`/`false` there is how a
	 *    request commits with no mutex held, or grants access it should have denied.
	 *  - A **POST-COMMIT** read or write MUST NOT throw out of its path. The change has already
	 *    happened. Throwing there turns a success into a failure report, invites the client to repeat
	 *    something already done, and skips the very hooks that deliver the customer their own access to
	 *    the booking. `recordAfterCommit()` below is this method's post-commit twin, and
	 *    `BookingRepository::findByUuidAfterCommit()` is the same split applied to the re-read that
	 *    becomes the response.
	 *
	 * This method is the PRE-DECISION half. Its seven call sites all sit INSIDE a
	 * `TransactionRunner::run()` closure, as the last statement before that closure's own re-read:
	 * `HoldBooking` (the insert path and the inline `reap()`), `CancelBooking`, `ExpireHolds`,
	 * `RejectBooking`, `ApproveBooking` and `RescheduleBooking`. On a 1213 deadlock the transaction is
	 * already dead server-side by the time this statement runs, and a discarded `false` used to let
	 * execution walk on into that re-read: it sees the row as it stood before the transaction ever
	 * started (the deadlock rolled back everything, including the real status transition a few lines
	 * above), so the caller gets a 200 carrying a snapshot of a change that never happened. Refusing
	 * here, like every other write on these paths, is what makes the transaction's own ROLLBACK visible
	 * to the request instead of silently swallowed.
	 *
	 * **`ConfirmBooking` and `MarkBookingOutcome` are NOT among them**, though an earlier version of
	 * this docblock claimed they were. Neither has a transaction at all - `make()` builds a
	 * `BookingRepository` and an `AuditLog` and nothing else, and `BookingRepository::reapExpiredTouching()`
	 * and `CancelBooking::execute()` both lean on exactly that ("ConfirmBooking takes no lock at all -
	 * it is one guarded UPDATE"). Their status UPDATE has autocommitted before this method is reached,
	 * so they use `recordAfterCommit()`. The same is true of the second, WP-user-naming audit row
	 * `Rest\Admin\BookingsAdminController::create()`/`::cancel()` add after their use case has returned.
	 *
	 * @param array<string, mixed> $payload
	 * @throws \RuntimeException `lock_unavailable` when the insert failed at the DB level.
	 */
	public function record( int $bookingId, string $actor, string $action, array $payload = array() ): void {
		$ok = $this->db->insert(
			$this->db->prefix . 'reservant_audit_log',
			array(
				'booking_id'   => $bookingId,
				'actor'        => $actor,
				'action'       => $action,
				'payload_json' => wp_json_encode( $payload ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'lock_unavailable' );
		}
	}

	/**
	 * **The POST-COMMIT half: record the row, and never throw out of the path that called it.** See
	 * `record()` above for the whole split and why it exists; this is the side of it for a caller
	 * whose change has already been committed by the time the audit row is attempted.
	 *
	 * The reasoning, concretely. The refusal `record()` raises rolls back nothing here: there is no
	 * open transaction to roll back, and the status transition is already durable. Letting it
	 * propagate would (1) answer 409 for a booking that WAS confirmed, cancelled or created, (2) skip
	 * the `do_action( 'reservant/booking/...' )` immediately below every one of these call sites - so
	 * no confirmation email, and on `HoldsController::create()`'s path no `manage_token` reaches the
	 * guest at all - and (3) make the retry the 409 invites answer `not_confirmable`/`stale_state`
	 * forever, because the state the retry re-reads is the one that already succeeded. An audit row is
	 * bookkeeping ABOUT a fact, not part of the fact; a missing one is worth reporting, never worth
	 * discarding the fact.
	 *
	 * Reported on `reservant/error` rather than swallowed silently - the same treatment
	 * `Notifications\Mailer` already gives a post-commit delivery failure, and for the same reason: a
	 * site operator needs to see it, and a customer must not pay for it.
	 *
	 * Only `\RuntimeException` is caught, deliberately narrow: that is the one failure `record()` has,
	 * and this codebase does not swallow programming errors anywhere (`ExpireHolds::run()`'s catch
	 * states the same rule for the same reason).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function recordAfterCommit( int $bookingId, string $actor, string $action, array $payload = array() ): void {
		try {
			$this->record( $bookingId, $actor, $action, $payload );
		} catch ( \RuntimeException $exception ) {
			do_action( 'reservant/error', $exception );
		}
	}

	/**
	 * The full trail for one booking, oldest first - the admin detail view (AGENTS.md Task 10).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forBooking( int $bookingId ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, actor, action, payload_json, created_at FROM {$p}reservant_audit_log WHERE booking_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$bookingId
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['id']      = (int) $row['id'];
				$decoded        = null === $row['payload_json'] ? array() : json_decode( (string) $row['payload_json'], true );
				$row['payload'] = is_array( $decoded ) ? $decoded : array();
				unset( $row['payload_json'] );
				return $row;
			},
			$rows
		);
	}
}
