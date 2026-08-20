<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\HoldClass;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\LockKey;
use Reservant\Settings;

/**
 * Owner approval of a booking that required it (AGENTS.md "Approval queue"). Where the approval
 * lands depends on whether there is money to collect (section 2.3): a free/onsite booking goes
 * straight to `confirmed`, while an `online` booking with a provider able to take payment goes to
 * `awaiting_payment` - the order is created and the guest is emailed the payment link, and the paid
 * order confirms the booking through `Integrations\WooCommerce\OrderObserver`, never through this
 * use case. With no provider available the same degrade rule as `ConfirmBooking`'s applies: the
 * booking confirms rather than stranding the guest in a state nothing could ever pay for.
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
 *    `confirmed` row (or a freshly re-armed `awaiting_payment` hold, equally blocking), so it is
 *    refused `overlap`/`capacity`.
 *  - Re-validating instead would have to exclude the booking's own items from every count (a live
 *    approval hold blocks against itself), i.e. new repository surface for a case the lock already
 *    settles - and it would make a human approval both more expensive and, if the exclusion were
 *    ever wrong, capable of failing where today it cannot.
 *
 * `GuardedWrite` bumps `rev` on every transition it runs, and this use case needs it for a reason
 * worth keeping written down: on the timeout path a lapsed hold is not in the free/busy mask and a
 * confirmed booking is, so the mask cache key (`reservant_resource_days.rev`, AGENTS.md section 2.4
 * step 6) must move.
 *
 * This is also the one of the four transitions that does NOT release its seat claims - the booking
 * is going ahead. `GuardedWrite` derives that from the target status
 * (`BookingStatus::releasesSeatClaims()`) rather than taking a flag, so approving cannot acquire the
 * slot and hand the seat back in the same statement.
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
 * `LockManager::acquire()` FIRST, the bookings row (`findByUuidForUpdate()`) after. `GuardedWrite`
 * owns that order now, for all four transitions rather than this one alone.
 */
final class ApproveBooking {

	public function __construct(
		private readonly GuardedWrite $guarded,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( GuardedWrite::make( $db ), new BookingRepository( $db ) );
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

		// The landing is decided on the unlocked read, and that is safe where deciding the STATUS
		// there would not be: `payment_mode` is fixed when the hold is inserted and no transition
		// writes it, and the provider's availability is a property of the request, not the row.
		// Everything a rival could have changed - the status itself - is re-decided by the guard
		// under the lock. Landing on `awaiting_payment` re-arms the hold: `AwaitingPayment` is a
		// held status (section 2.1), so the slot stays blocked while the payment-link window
		// (`Settings::paymentTtlHours()`) runs, and the existing sweeper reclaims it if the money
		// never arrives - `BookingRepository::expiredHeldIds()` already selects `awaiting_payment`
		// rows, so payment expiry needs no machinery of its own.
		$online = PaymentMode::Online->value === (string) $booking['payment_mode']
			&& Payment\Providers::get()->isAvailable();

		$snapshot = $this->guarded->transition(
			LockKey::forItems( $items ),
			$uuid,
			$online ? BookingStatus::AwaitingPayment : BookingStatus::Confirmed,
			// Re-read under FOR UPDATE and re-decided: a lapsed hold or a rival decision
			// (reject/cancel) may have landed between the unlocked read above and the transaction
			// opening, and the row this acts on is the one read there.
			static function ( array $fresh ) use ( $nowUtc ): void {
				if ( ! self::approvable( $fresh, $nowUtc ) ) {
					throw new TransitionRefused( 'not_approvable' );
				}
			},
			'not_approvable',
			$actor,
			'approve',
			'reservant/booking/approved',
			array(
				'hold_class'      => $online ? HoldClass::Payment->value : null,
				'hold_expires_at' => $online
					? $nowUtc->modify( '+' . Settings::make()->paymentTtlHours() . ' hours' )->format( 'Y-m-d H:i:s' )
					: null,
				'approved_at'     => $nowUtc->format( 'Y-m-d H:i:s' ),
				'approved_by'     => $actorUserId,
			)
		);

		return $online ? $this->issuePaymentLink( $snapshot ) : $snapshot;
	}

	/**
	 * Create the one order this booking pays and announce the link - POST-COMMIT, all of it.
	 *
	 * The booking is `awaiting_payment` by the time this runs, and an order write is an external
	 * side effect that must never sit inside Reservant's transaction (section 2.2: hooks fire after
	 * commit and no listener may throw - the same rule binds this block, which runs in the same
	 * position). So nothing here may turn the committed approval into a failure report: any
	 * `\Throwable` - `WooPaymentProvider::createOrder()` refusing, the id write failing, a provider
	 * that cannot mint a URL - is reported on `reservant/error` with the uuid as context and
	 * swallowed. The booking is deliberately NOT rolled back to `awaiting_approval`: the approval
	 * happened, and the payment TTL just written is exactly the mechanism that reclaims the seat if
	 * no order ever materializes for the guest to pay.
	 *
	 * `reservant/booking/payment_due` carries the URL as its second argument (the
	 * `reservant/approval/nag` shape) because the snapshot cannot: `BookingSnapshot` carries
	 * columns, and the URL is the provider's answer, not a column.
	 *
	 * @param array<string, mixed> $snapshot the stored post-transition row, plus `wc_order_id` once
	 *                                       the order exists - grafted here so the caller's response
	 *                                       reports the order it just created without a re-read that
	 *                                       could itself refuse post-commit.
	 * @return array<string, mixed>
	 */
	private function issuePaymentLink( array $snapshot ): array {
		try {
			$provider = Payment\Providers::get();
			$orderId  = $provider->createOrder( $snapshot );
			if ( null === $orderId ) {
				// The interface's "this provider cannot create orders at all" answer, from a
				// provider that claimed `isAvailable()`. Internal - never a wire reason.
				throw new \RuntimeException( 'order_create_failed' );
			}
			$this->bookings->storeOrderId( (int) $snapshot['id'], $orderId );
			$snapshot['wc_order_id'] = $orderId;

			$url = $provider->paymentUrl( $orderId );
			if ( null === $url ) {
				throw new \RuntimeException( 'payment_url_unavailable' );
			}
			do_action( 'reservant/booking/payment_due', BookingSnapshot::fromArray( $snapshot ), $url );
		} catch ( \Throwable $e ) {
			do_action( 'reservant/error', $e, (string) $snapshot['uuid'] );
		}
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
