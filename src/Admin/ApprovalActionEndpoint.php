<?php
declare( strict_types=1 );

namespace Reservant\Admin;

use Reservant\Application\ApproveBooking;
use Reservant\Application\RejectBooking;
use Reservant\Application\SignedAction;
use Reservant\Domain\Enum\BookingStatus;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Rest\Input;

/**
 * The no-login, one-click approve/reject link an approval-request email carries (AGENTS.md
 * "Approval holds": "Owner emails carry one-click signed approve/reject links so the decision
 * never requires a wp-admin login").
 *
 * Registered on both `admin_post_reservant_approval` and `admin_post_nopriv_reservant_approval` -
 * the common case is the second one, an owner or customer with no WordPress session at all.
 * Authentication is the HMAC signature (`SignedAction`), never a nonce or a capability check, so
 * every superglobal read below is deliberately unguarded by
 * `WordPress.Security.NonceVerification`.
 *
 * The page is standalone HTML - no wp-admin chrome, nothing that assumes the wp-admin bootstrap
 * ran (this hook fires from `admin-post.php`, which does not load `wp-admin/includes/template.php`,
 * so helpers like `submit_button()` are not safe to call here).
 */
final class ApprovalActionEndpoint {

	public const ACTION = 'reservant_approval';

	private const DECISIONS = array( 'approve', 'reject' );

	/**
	 * The three reasons `ApproveBooking`/`RejectBooking` refuse a still-pending booking
	 * (AGENTS.md "Approval queue"; see also `Rest\Errors::KNOWN_REASONS`) - a stale replay, a
	 * rival decision that landed first, or a row that vanished from under the lock. Every other
	 * `\RuntimeException` message is unexpected and must not be mistaken for one of these.
	 */
	private const BENIGN_REFUSAL_REASONS = array( 'not_approvable', 'not_found', 'stale_state' );

	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The signed URL an approval email points at.
	 *
	 * @param string $updatedAt the booking's `updated_at` at the moment of issue
	 *                          (`BookingRepository::findByUuid()`) - the value the signature is
	 *                          bound to, so any later state change invalidates a stale copy.
	 */
	public static function url( string $uuid, string $action, string $updatedAt, int $expiresTs ): string {
		$sig = SignedAction::sign( wp_salt( 'auth' ), $uuid, $action, $expiresTs, $updatedAt );
		return (string) add_query_arg(
			array(
				'action'   => self::ACTION,
				'uuid'     => rawurlencode( $uuid ),
				'decision' => $action,
				'exp'      => $expiresTs,
				'sig'      => $sig,
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function handle(): void {
		global $wpdb;

		$uuid     = $this->field( 'uuid' );
		$decision = $this->field( 'decision' );
		$exp      = $this->intField( 'exp' );
		$sig      = $this->field( 'sig' );

		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			$this->badSignature();
		}

		$bookings = new BookingRepository( $wpdb );
		$booking  = $bookings->findByUuid( $uuid );
		if ( null === $booking ) {
			$this->badSignature();
		}

		$now       = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$updatedAt = (string) $booking['updated_at'];
		$valid     = SignedAction::verify( wp_salt( 'auth' ), $sig, $uuid, $decision, $exp, $updatedAt, $now->getTimestamp() );

		if ( ! $valid ) {
			// The booking has already moved on (approved/rejected/expired elsewhere) or this
			// specific link's own expiry has passed: a benign, expected replay - "no longer
			// valid", not a 403. Anything else that fails to verify while the booking is still
			// sitting there waiting, unexpired, can only be a forged or corrupted signature.
			$stillPending = BookingStatus::AwaitingApproval->value === $booking['status'] && $now->getTimestamp() <= $exp;
			if ( $stillPending ) {
				$this->badSignature();
			}
			$this->renderStale();
			return;
		}

		if ( ! $this->isSubmission() ) {
			$this->renderConfirm( $booking, $uuid, $decision, $exp, $sig );
			return;
		}

		try {
			if ( 'approve' === $decision ) {
				ApproveBooking::make( $wpdb )->execute( $uuid, $now, 'signed_link' );
				$message = __( 'Booking approved.', 'reservant' );
			} else {
				$reason = $this->postField( 'reason' );
				RejectBooking::make( $wpdb )->execute( $uuid, $reason, $now, 'signed_link' );
				$message = __( 'Booking rejected.', 'reservant' );
			}
		} catch ( \RuntimeException $e ) {
			if ( $this->isBenignRefusal( $e ) ) {
				// A rival decision landed between the signature check above and this call
				// (TOCTOU) - the use case's own re-validation under lock refused it for one of
				// its known, expected reasons. Same outcome as a replay.
				$this->renderStale();
				return;
			}
			// Anything else is an infrastructure failure (a DB error, or a future use-case
			// refusal reason this endpoint does not yet know about) - never silently fold into
			// the benign "already handled" page, where the owner would have no reason to retry.
			// Logged on the same channel Rest\Errors::failure() uses; never the signature, never
			// customer details - just enough to find the row again and the failure itself.
			do_action( 'reservant/error', $e, $uuid );
			$this->renderFailure();
			return;
		}

		$this->renderResult( $message );
	}

	private function isBenignRefusal( \RuntimeException $e ): bool {
		return in_array( $e->getMessage(), self::BENIGN_REFUSAL_REASONS, true );
	}

	/**
	 * Whether this is the confirm page's own form submission rather than the initial link click.
	 * `REQUEST_METHOD` is authoritative for a real HTTP request; the `$_POST['sig']` check is a
	 * defensive fallback so a caller that only populates `$_POST` still resolves correctly.
	 */
	private function isSubmission(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a routing signal only; the signature check above is the authorization.
		return ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) || isset( $_POST['sig'] );
	}

	/**
	 * Reads `$key` from `$_GET` (the email link) or `$_POST` (the confirm page's own form
	 * submission) - deliberately not `$_REQUEST`, which PHP populates once from the real request
	 * at bootstrap and never re-derives from either superglobal again, unlike `$_GET`/`$_POST`
	 * themselves.
	 */
	private function rawField( string $key ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- see class docblock: the HMAC signature verified in handle() is the auth mechanism, not a nonce.
		return $_GET[ $key ] ?? $_POST[ $key ] ?? '';
	}

	private function field( string $key ): string {
		return sanitize_text_field( Input::text( wp_unslash( $this->rawField( $key ) ) ) );
	}

	private function postField( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock: the HMAC signature verified in handle() is the auth mechanism, not a nonce.
		$raw = wp_unslash( $_POST[ $key ] ?? '' );
		return sanitize_textarea_field( Input::text( $raw ) );
	}

	private function intField( string $key ): int {
		return Input::posInt( wp_unslash( $this->rawField( $key ) ) ) ?? 0;
	}

	private function badSignature(): never {
		wp_die(
			esc_html__( 'This link is not valid.', 'reservant' ),
			esc_html__( 'Invalid link', 'reservant' ),
			array( 'response' => 403 )
		);
	}

	/** @param array<string, mixed> $booking */
	private function renderConfirm( array $booking, string $uuid, string $decision, int $exp, string $sig ): void {
		$summary = $this->summary( $booking );

		$this->header( __( 'Confirm your decision', 'reservant' ) );
		echo '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: customer first name, 2: service name(s), 3: local start time */
				__( '%1$s - %2$s - %3$s', 'reservant' ),
				$summary['customer'],
				implode( ', ', $summary['services'] ),
				$summary['when']
			)
		) . '</p>';

		echo '<h2>' . esc_html(
			'approve' === $decision
				? __( 'Approve this booking?', 'reservant' )
				: __( 'Reject this booking?', 'reservant' )
		) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"/>';
		echo '<input type="hidden" name="uuid" value="' . esc_attr( $uuid ) . '"/>';
		echo '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '"/>';
		echo '<input type="hidden" name="exp" value="' . esc_attr( (string) $exp ) . '"/>';
		echo '<input type="hidden" name="sig" value="' . esc_attr( $sig ) . '"/>';

		if ( 'reject' === $decision ) {
			echo '<p><label for="reservant-approval-reason">' . esc_html__( 'Reason (optional)', 'reservant' ) . '</label><br/>';
			echo '<textarea id="reservant-approval-reason" name="reason" rows="3" cols="40"></textarea></p>';
		}

		echo '<p><input type="submit" value="' . esc_attr(
			'approve' === $decision ? __( 'Approve', 'reservant' ) : __( 'Reject', 'reservant' )
		) . '"/></p>';
		echo '</form>';
		$this->footer();
	}

	private function renderStale(): void {
		$this->header( __( 'Link no longer valid', 'reservant' ) );
		echo '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
		echo '<p>' . esc_html__( 'This link is no longer valid. The booking may already have been handled.', 'reservant' ) . '</p>';
		$this->footer();
	}

	private function renderResult( string $message ): void {
		$this->header( __( 'Done', 'reservant' ) );
		echo '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
		echo '<p>' . esc_html( $message ) . '</p>';
		$this->footer();
	}

	/**
	 * The generic-failure page for an unexpected `\RuntimeException` - distinct from
	 * `renderStale()` on purpose: "no longer valid" tells the owner there is nothing left to do,
	 * this tells them the opposite - the booking was NOT changed and the action is worth retrying.
	 */
	private function renderFailure(): void {
		$this->header( __( 'Something went wrong', 'reservant' ) );
		echo '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
		echo '<p>' . esc_html__( 'Something went wrong; the booking was not changed. Please try again or log in.', 'reservant' ) . '</p>';
		$this->footer();
	}

	private function header( string $title ): void {
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"/><title>' . esc_html( $title ) . '</title></head><body>';
	}

	private function footer(): void {
		echo '</body></html>';
	}

	/**
	 * Booking summary shown to whoever holds the link: service names + local start time +
	 * customer FIRST NAME ONLY - deliberately never the email or phone, which a URL holder (an
	 * inbox forward, a shared clipboard) has no business seeing.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{customer: string, services: list<string>, when: string}
	 */
	private function summary( array $booking ): array {
		global $wpdb;
		$services = new ServiceRepository( $wpdb );

		$names = array();
		/** @var list<array<string, mixed>> $items */
		$items = is_array( $booking['items'] ) ? $booking['items'] : array();
		foreach ( $items as $item ) {
			$service = $services->find( (int) $item['service_id'] );
			if ( null !== $service ) {
				$names[] = (string) $service['name'];
			}
		}

		$when      = '';
		$firstItem = $items[0] ?? null;
		if ( null !== $firstItem ) {
			$startUtc = new \DateTimeImmutable( (string) $firstItem['start_utc'], new \DateTimeZone( 'UTC' ) );
			$format   = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
			$when     = (string) wp_date( $format, $startUtc->getTimestamp(), wp_timezone() );
		}

		$nameParts = explode( ' ', trim( (string) $booking['customer_name'] ) );

		return array(
			'customer' => (string) ( $nameParts[0] ?? '' ),
			'services' => $names,
			'when'     => $when,
		);
	}
}
