<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Domain\Enum\PaymentMode;
use Reservant\Infrastructure\Db\AuditLog;
use Reservant\Infrastructure\Db\BookingRepository;

/**
 * The confirmation path (AGENTS.md section 2.3). Free / pay-on-site callers use it as-is; the
 * WooCommerce bridge lands a PAID order on it too, asserting `$paymentSettled` - see that
 * parameter's docblock for why the two callers get different guards through one door rather than
 * two doors.
 *
 * **This use case has NO transaction and takes no lock: it is one guarded UPDATE**, and
 * `BookingRepository::reapExpiredTouching()` and `CancelBooking::execute()` both build their own
 * correctness arguments on exactly that. `transition()` is itself the atomic, race-safe step, so
 * there is nothing for a transaction to make atomic *with*.
 *
 * That has a consequence for everything after it: once `transition()` returns true, the change is
 * COMMITTED - there is no open transaction to roll back. Every statement below that line is
 * therefore post-commit, and none of them may refuse (`AuditLog::record()`'s docblock states the
 * pre-decision / post-commit split in full). A refusal there would answer 409 for a booking that IS
 * confirmed, skip `reservant/booking/confirmed` so the confirmation email never goes out, and leave
 * the retry it invites answering `not_confirmable` forever. Hence `recordAfterCommit()` and
 * `findByUuidAfterCommit()` below - the post-commit halves of the two statements that used to throw.
 */
final class ConfirmBooking {

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly AuditLog $audit,
	) {}

	public static function make( \wpdb $db ): self {
		return new self( new BookingRepository( $db ), new AuditLog( $db ) );
	}

	/**
	 * @param string|null $manageToken The plaintext credential the caller presented, when there was
	 *                                 one. It is not an authorisation argument - `Rest\Routes::guard()`
	 *                                 has already decided that, and a manager confirming from wp-admin
	 *                                 supplies none - it exists so the confirmation email can carry the
	 *                                 guest's manage link. See `carriedToken()` for why it is
	 *                                 re-verified here rather than trusted, and `Dto\BookingSnapshot`
	 *                                 for why the credential rides on the snapshot at all.
	 * @param bool        $paymentSettled The bridge's assertion that the money for this booking has
	 *                                 actually arrived - a WooCommerce order carrying this uuid just
	 *                                 reached a paid status. Only `Integrations\WooCommerce\OrderObserver`
	 *                                 passes true. It widens two guards below: `awaiting_payment`
	 *                                 becomes confirmable (payment is exactly what that status waits
	 *                                 for), and the `online_payment_required` refusal is skipped
	 *                                 (the payment it demands is the one being reported).
	 *
	 *                                 A PARAMETER, not a loosened guard and not filter-juggling, and
	 *                                 the distinction is load-bearing. Loosening the guards for
	 *                                 everyone would let `POST /bookings/{uuid}/confirm` - a route
	 *                                 any guest with a manage token can call - confirm an `online`
	 *                                 or `awaiting_payment` booking with no money behind it: the
	 *                                 payment step, the entire point of those states, would be
	 *                                 skippable from the wire. And toggling the
	 *                                 `reservant/allow_direct_confirm` filter around this call would
	 *                                 encode "this one call is paid" in global hook state: not
	 *                                 reentrant (a `confirmed` listener holding a second booking
	 *                                 would inherit the exemption), and a throw between add and
	 *                                 remove leaks it to every later confirm in the request. A
	 *                                 boolean argument has neither failure mode - it reaches exactly
	 *                                 one call, no REST controller forwards it, so the only code
	 *                                 that can assert it is code that watched the order transition.
	 *                                 That filter keeps its actual job: a SITE'S standing policy
	 *                                 that online bookings may confirm unpaid, not a caller's
	 *                                 per-call fact.
	 *
	 *                                 What it deliberately does NOT widen: the hold-TTL guard. The
	 *                                 TTL is the authority, not the payment link (AGENTS.md section
	 *                                 6) - money arriving for a lapsed hold is refused `hold_expired`
	 *                                 here and surfaced by the observer, never silently seated over
	 *                                 whoever holds the slot now.
	 * @return array<string, mixed>
	 */
	public function execute( string $uuid, \DateTimeImmutable $nowUtc, ?string $manageToken = null, bool $paymentSettled = false ): array {
		$booking = $this->bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			throw new SlotConflict( 'not_found' );
		}
		$from = BookingStatus::from( (string) $booking['status'] );
		// Which held states may end here depends on who is asking. On the plain path only the
		// checkout hold: awaiting_approval needs a human (ApproveBooking) and awaiting_payment
		// needs the money - neither may shortcut to confirmed through a route any token holder can
		// call. A settled payment IS the money, so the bridge additionally ends awaiting_payment:
		// that is the `paid -> confirmed` edge of both payment flows in section 2.3. The lockless
		// one-guarded-UPDATE argument in the class docblock carries over unchanged - a live
		// awaiting_payment hold is already blocking (section 2.1), so confirming it consumes no
		// capacity that was not already consumed, exactly as with pending.
		$accepted = $paymentSettled
			? array( BookingStatus::Pending, BookingStatus::AwaitingPayment )
			: array( BookingStatus::Pending );
		if ( ! in_array( $from, $accepted, true ) ) {
			throw new \RuntimeException( BookingStatus::AwaitingApproval === $from ? 'approval_required' : 'not_confirmable' );
		}
		// `isAvailable()` FIRST, and it is the degrade-to-onsite rule of AGENTS.md section 6 rather
		// than an optimisation: with no payment provider - WooCommerce never installed, or
		// deactivated yesterday - refusing here would strand every `online` booking on a site where
		// no order could ever be created to satisfy the refusal. The owner is told once by
		// `Admin\PaymentNotice` and takes the money in person; the alternative is a booking form
		// that answers 402 forever and looks broken. A settled payment skips the refusal outright:
		// the payment it would be demanding is the one being reported.
		if ( ! $paymentSettled && self::requiresOnlinePayment( $booking ) ) {
			throw new \RuntimeException( 'online_payment_required' );
		}
		// The hold TTL is the authority: a checkout that outlives it loses the slot (section 6).
		if ( null !== $booking['hold_expires_at'] && (string) $booking['hold_expires_at'] <= $nowUtc->format( 'Y-m-d H:i:s' ) ) {
			throw new \RuntimeException( 'hold_expired' );
		}

		$released = array(
			'hold_expires_at' => null,
			'hold_class'      => null,
		);
		if ( ! $this->bookings->transition( (int) $booking['id'], $from, BookingStatus::Confirmed, $released ) ) {
			throw new \RuntimeException( 'stale_state' );
		}
		// ---- Everything below this line runs AFTER the transition committed. See the class docblock:
		// ---- no statement here may turn that committed transition into a failure report.
		$this->audit->recordAfterCommit( (int) $booking['id'], 'customer', 'confirmed' );

		$snapshot = $this->bookings->findByUuidAfterCommit(
			$uuid,
			array( 'status' => BookingStatus::Confirmed->value ) + $released,
			$booking
		);
		do_action(
			'reservant/booking/confirmed',
			BookingSnapshot::fromArray( $snapshot + self::carriedToken( $manageToken, $booking ) )
		);
		return $snapshot;
	}

	/**
	 * Whether this booking must be paid online before anyone may confirm it - the
	 * `online_payment_required` refusal above, asked as a question.
	 *
	 * One implementation, two readers, so the refusal and the surface that must ROUTE a guest around
	 * it cannot drift: `Rest\HoldsController` emits a checkout link on exactly the holds this answers
	 * true for, which is precisely the set that would otherwise meet a 402 with nowhere to go. Three
	 * clauses, each already load-bearing above - the `online` mode, the section 6 degrade when no
	 * provider can take money (an `online` service then behaves as `onsite`), and the site's
	 * `reservant/allow_direct_confirm` escape hatch, which says this booking confirms without paying
	 * and must therefore not be sent to a checkout either.
	 *
	 * NOT a statement about WHERE the payment happens - `Payment\PaymentProvider::checkoutUrl()`
	 * answers that, and answers null for a booking that has no business in a checkout yet.
	 *
	 * @param array<string, mixed> $booking booking row
	 */
	public static function requiresOnlinePayment( array $booking ): bool {
		return PaymentMode::Online->value === (string) ( $booking['payment_mode'] ?? '' )
			&& Payment\Providers::get()->isAvailable()
			&& ! apply_filters( 'reservant/allow_direct_confirm', false, $booking );
	}

	/**
	 * The guest's own credential, echoed back onto the snapshot so the confirmation email can carry
	 * a link they can still use tomorrow.
	 *
	 * This is the ordinary guest's ONLY chance to be sent one. The secret is minted inside
	 * `HoldBooking::execute()` and stored only as a SHA-256 hash, so nothing downstream can
	 * reconstruct it and minting a fresh one would invalidate the copy the widget is still holding
	 * in memory for this very session. On the approval path the guest already received it with
	 * `booking_received` at hold time; on the ordinary checkout path no email has gone out yet, and
	 * without this the moment they close the tab the booking becomes unmanageable - the cancel and
	 * reschedule routes exist for them and they would have no way to reach either.
	 *
	 * Re-VERIFIED rather than trusted, because the caller's token is not necessarily the one this
	 * booking's hash was made from: `Routes::guard()` short-circuits on `reservant_manage_bookings`
	 * before it ever looks at the `token` parameter, so a manager confirming a booking could carry
	 * any string at all in that slot - including one belonging to a different booking. A wrong token
	 * emitted here would become a manage link that 403s, mailed to the guest as if it were theirs.
	 * Verifying costs one hash of a value already in hand and makes the wrong answer unreachable.
	 *
	 * @param array<string, mixed> $booking the pre-transition row, which carries the stored hash
	 * @return array<string, mixed> the key to graft, or nothing at all
	 */
	private static function carriedToken( ?string $manageToken, array $booking ): array {
		if ( null === $manageToken || '' === $manageToken ) {
			return array();
		}
		$storedHash = null === ( $booking['manage_token_hash'] ?? null ) ? null : (string) $booking['manage_token_hash'];
		return ManageToken::verify( $manageToken, $storedHash )
			? array( 'manage_token' => $manageToken )
			: array();
	}
}
