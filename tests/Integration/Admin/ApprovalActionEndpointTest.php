<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Admin;

use Reservant\Admin\ApprovalActionEndpoint;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SignedAction;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The no-login signed approve/reject link (Task 7; AGENTS.md "Approval holds"). Drives
 * `ApprovalActionEndpoint::handle()` directly with crafted `$_GET`/`$_POST`/`$_SERVER`
 * superglobals - exactly the way `admin-post.php` would call it, minus the HTTP layer.
 */
final class ApprovalActionEndpointTest extends ReservantTestCase {

	private int $serviceId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId = $services->insert(
			array(
				'name'                => 'Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$staff           = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/** @return array<string, mixed> the freshly held, awaiting_approval booking row. */
	private function holdAwaitingApproval(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->serviceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	private function farFutureExp(): int {
		return time() + DAY_IN_SECONDS;
	}

	private function setRequest( string $method, string $uuid, string $decision, int $exp, string $sig, string $reason = '' ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$fields                    = array(
			'uuid'     => $uuid,
			'decision' => $decision,
			'exp'      => (string) $exp,
			'sig'      => $sig,
		);
		if ( '' !== $reason ) {
			$fields['reason'] = $reason;
		}
		if ( 'POST' === $method ) {
			$_POST = $fields;
			$_GET  = array();
		} else {
			$_GET  = $fields;
			$_POST = array();
		}
	}

	public function testRegisterWiresBothAdminPostHooks(): void {
		( new ApprovalActionEndpoint() )->register();

		self::assertNotFalse(
			has_action( 'admin_post_' . ApprovalActionEndpoint::ACTION ),
			'a logged-in admin who clicks the link must still be routed to handle()'
		);
		self::assertNotFalse(
			has_action( 'admin_post_nopriv_' . ApprovalActionEndpoint::ACTION ),
			'the common case - no WordPress session at all - must be routed to handle()'
		);
	}

	/**
	 * Proves `url()` and `handle()` agree end to end on every query param name (`uuid`, `decision`,
	 * `exp`, `sig`) - not just that each half unit-tests in isolation.
	 */
	public function testUrlRoundTripsIntoAWorkingConfirmLink(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();

		$url = ApprovalActionEndpoint::url( $booking['uuid'], 'approve', $updatedAt, $exp );

		$query = array();
		$parts = wp_parse_url( $url );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		self::assertSame( ApprovalActionEndpoint::ACTION, $query['action'] ?? null );
		self::assertSame( $booking['uuid'], $query['uuid'] ?? null );
		self::assertSame( 'approve', $query['decision'] ?? null );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $query;
		$_POST                     = array();

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<form', $output );
		self::assertStringContainsString( $query['sig'], $output );
		self::assertStringContainsString( 'Consultation', $output );

		// A GET round trip must never change state.
		self::assertSame( 'awaiting_approval', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );
	}

	public function testGetRendersConfirmFormWithSigEchoed(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'GET', $booking['uuid'], 'approve', $exp, $sig );

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( $sig, $output, 'the confirm form must echo the same signature back' );
		self::assertStringContainsString( $booking['uuid'], $output );
		self::assertStringContainsString( 'Consultation', $output, 'the booking summary must name the service' );
		self::assertStringContainsString( 'Maria', $output, 'the booking summary must show the customer first name' );
		self::assertStringContainsString( '<form', $output );

		// A GET must never change state.
		self::assertSame( 'awaiting_approval', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );
	}

	/**
	 * A GET stays a GET even when `$_POST` is fully populated with a VALID signature.
	 *
	 * `isSubmission()` is the only predicate in this endpoint that can turn a request into a state
	 * change, and it now reads `REQUEST_METHOD` alone - it used to also accept `isset( $_POST['sig'] )`,
	 * which was unreachable over real HTTP but was the one clause that could have let a GET mutate.
	 * That matters because these links are opened by prefetchers, mail scanners and clients that
	 * follow URLs for the reader: a GET has to render the confirm form and nothing else.
	 */
	public function testGetWithAPopulatedPostArrayStillOnlyRendersTheConfirmForm(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'GET', $booking['uuid'], 'approve', $exp, $sig );
		$_POST = array(
			'uuid'     => $booking['uuid'],
			'decision' => 'approve',
			'exp'      => (string) $exp,
			'sig'      => $sig,
		);

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<form', $output, 'the confirm page, not the decision' );
		self::assertStringNotContainsString( 'Booking approved.', $output );
		self::assertSame(
			'awaiting_approval',
			( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'],
			'a GET must never mutate, whatever $_POST happens to hold'
		);
	}

	public function testPostWithValidSigApproves(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $sig );

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Booking approved.', $output );

		$stored = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'confirmed', $stored['status'] );
		self::assertNull( $stored['approved_by'], 'a signed-link approval has no WP user behind it' );
	}

	public function testPostRejectWithReasonRejects(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'reject', $exp, $updatedAt );

		$this->setRequest( 'POST', $booking['uuid'], 'reject', $exp, $sig, 'no_show_history' );

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Booking rejected.', $output );

		$stored = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		self::assertSame( 'rejected', $stored['status'] );
		self::assertSame( 'no_show_history', $stored['rejection_reason'] );
	}

	public function testPostAfterAlreadyApprovedRendersStaleAndDoesNotChangeState(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		// The signature a real approval email would have carried, bound to the pre-approval
		// updated_at - kept around to be replayed after the booking has already moved on.
		$staleSig = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		// First use: a legitimate approval, exactly as testPostWithValidSigApproves.
		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $staleSig );
		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		ob_get_clean();
		self::assertSame( 'confirmed', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );

		// Replay: the same old link, now that updated_at has moved on - must not throw 403 and
		// must not touch the booking again; it must render the "no longer valid" page.
		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $staleSig );
		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'no longer valid', $output );
		self::assertSame(
			'confirmed',
			( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'],
			'a replayed stale link must not change state'
		);
	}

	public function testTamperedSignatureDies403(): void {
		global $wpdb;
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'GET', $booking['uuid'], 'approve', $exp, $sig . 'tampered' );

		ob_start();
		try {
			( new ApprovalActionEndpoint() )->handle();
			self::fail( 'Expected a 403 wp_die on a tampered signature.' );
		} catch ( \WPDieException $e ) {
			self::assertSame( 403, $e->getCode() );
		} finally {
			ob_get_clean();
		}

		// Never touched.
		self::assertSame( 'awaiting_approval', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );
	}

	/**
	 * An unexpected `\RuntimeException` (an infrastructure failure, or a future use-case refusal
	 * reason this endpoint does not yet classify) must not be folded into the benign "no longer
	 * valid" page - that would tell the owner there is nothing left to do when in fact nothing
	 * has been retried. Forced here by hooking `reservant/booking/approved`, which `ApproveBooking`
	 * fires AFTER its own transaction has already committed - throwing from that listener yields a
	 * genuine, unclassified `\RuntimeException` reaching the endpoint's catch block without
	 * touching Task 5's code at all.
	 */
	public function testUnknownRuntimeExceptionFiresErrorActionAndRendersGenericFailure(): void {
		global $wpdb;
		// Task 9 wires `Notifications\ApprovalEmails` on the same `reservant/booking/held` and
		// `reservant/booking/approved` hooks this test already drives; a real `wp_mail()` in this
		// environment has no transport and fails, which would fire its own `reservant/error` and
		// break the exact count below. Short-circuited because this test's only concern is the
		// injected RuntimeException, not mail delivery.
		add_filter( 'pre_wp_mail', '__return_true' );
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $sig );

		$blowUp = static function (): void {
			throw new \RuntimeException( 'unexpected_test_failure' );
		};
		add_action( 'reservant/booking/approved', $blowUp );

		$errors   = array();
		$listener = static function ( \Throwable $e ) use ( &$errors ): void {
			$errors[] = $e;
		};
		add_action( 'reservant/error', $listener );

		ob_start();
		( new ApprovalActionEndpoint() )->handle();
		$output = (string) ob_get_clean();

		remove_action( 'reservant/booking/approved', $blowUp );
		remove_action( 'reservant/error', $listener );
		remove_filter( 'pre_wp_mail', '__return_true' );

		self::assertCount( 1, $errors, 'the unknown RuntimeException must fire reservant/error' );
		self::assertInstanceOf( \RuntimeException::class, $errors[0] );
		self::assertSame( 'unexpected_test_failure', $errors[0]->getMessage() );

		self::assertStringNotContainsString( 'no longer valid', $output, 'an unknown failure is not a benign replay' );
		self::assertStringContainsString( 'Something went wrong', $output );
		self::assertStringNotContainsString( $sig, $output, 'the signature must never be echoed back on a failure page' );

		// The real approval had already committed before the injected failure fired - proof this
		// is a genuinely different scenario from a stale replay, where nothing changes at all.
		self::assertSame( 'confirmed', ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'] );
	}

	/**
	 * A lock the approval could not take is a RETRY, not a booking somebody else already handled.
	 *
	 * The distinction is the whole point of `lock_unavailable` existing separately from
	 * `stale_state`. `stale_state` means "a rival moved this booking between the plan and the
	 * transaction" - genuinely benign here, which is why it is in `BENIGN_REFUSAL_REASONS` and
	 * renders "may already have been handled". A busy lock means the opposite: nothing happened, the
	 * booking is exactly where it was, and the staff member's click is still worth making. Rendering
	 * that as benign would tell them the work was done when it was not, and would suppress precisely
	 * the retry the `renderFailure()` docblock says must not be suppressed.
	 *
	 * The sabotage is the codebase's usual one (see
	 * `RescheduleBookingTest::test_a_lock_that_cannot_be_taken_refuses_the_move_rather_than_writing_without_it`):
	 * a `query` filter rewriting `LockManager`'s `SELECT ... FOR UPDATE` to a table that does not
	 * exist, which is the `false`-with-`last_error` shape a 1205 lock-wait timeout produces.
	 */
	public function testALockFailureRendersARetryablePageRatherThanAlreadyHandled(): void {
		global $wpdb;
		// Same reason as the test above: a real wp_mail() has no transport here and would fire its
		// own reservant/error, breaking the count below.
		add_filter( 'pre_wp_mail', '__return_true' );
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $sig );

		$sabotage = static function ( $query ) {
			return 1 === preg_match( '/^\s*SELECT\s+resource_id\s+FROM\s+\S*reservant_resource_days\b.*FOR UPDATE/is', (string) $query )
				? 'SELECT resource_id FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};
		$errors   = array();
		$listener = static function ( \Throwable $e ) use ( &$errors ): void {
			$errors[] = $e;
		};
		add_action( 'reservant/error', $listener );

		// try/finally, matching this file's own idiom at testTamperedSignatureDies403()
		// (`ob_start()` BEFORE the try, `ob_get_clean()` in the finally): a `\WPDieException` or an
		// `Error` escaping `handle()` would otherwise leak a query-rewriting filter and an open output
		// buffer into every test that runs after this one. Putting `ob_get_clean()` inside the try, as
		// this test used to, does not protect against that - the very exception the comment warns about
		// would skip straight past it, leaving the buffer open.
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		ob_start();
		try {
			( new ApprovalActionEndpoint() )->handle();
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
			remove_action( 'reservant/error', $listener );
			remove_filter( 'pre_wp_mail', '__return_true' );
		}

		self::assertStringNotContainsString(
			'no longer valid',
			$output,
			'a busy lock is not a booking someone else already handled - never render it as benign'
		);
		self::assertStringContainsString( 'Something went wrong', $output );
		self::assertStringContainsString( 'try again', $output, 'the page must invite the staff member to retry' );
		self::assertStringContainsString( 'was not changed', $output );
		self::assertStringNotContainsString( $sig, $output, 'the signature must never be echoed back on a failure page' );

		self::assertCount( 1, $errors, 'a lock failure is an infrastructure failure and must be logged' );
		self::assertSame( 'lock_unavailable', $errors[0]->getMessage() );

		// The committed state, not merely the page: nothing was decided, so the link still works.
		self::assertSame(
			'awaiting_approval',
			( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'],
			'a refused approval must leave the booking exactly where it was'
		);
	}

	/**
	 * The SECOND half of the lock order must answer the same way as the first.
	 *
	 * `BookingRepository::findByUuidForUpdate()` is the booking-row lock every decision use case takes
	 * straight after `LockManager::acquire()` (`ApproveBooking:119` then `:124`). It reads through
	 * `get_row()`, which answers `null` on a DB failure and `null` for a row that is genuinely gone -
	 * and the caller maps `null` to `stale_state`, which is benign here. So a 1205 on that one
	 * statement rendered "This link is no longer valid. The booking may already have been handled."
	 * for a completely untouched booking: the exact false sentence the test above exists to prevent,
	 * reached through a different statement.
	 *
	 * It fails closed, so this was a wrong-reason bug rather than a lost-mutex one - which is
	 * precisely why it needed the same treatment. Two statements that are both part of one lock
	 * sequence must not disagree about what a failure means.
	 */
	public function testALockFailureOnTheBookingRowAlsoRendersARetryablePage(): void {
		global $wpdb;
		add_filter( 'pre_wp_mail', '__return_true' );
		$booking   = $this->holdAwaitingApproval();
		$updatedAt = (string) ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['updated_at'];
		$exp       = $this->farFutureExp();
		$sig       = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

		$this->setRequest( 'POST', $booking['uuid'], 'approve', $exp, $sig );

		// The booking-row lock only - the resource-day mutexes above it are left working, so this
		// isolates the second statement of the lock sequence.
		$sabotage = static function ( $query ) {
			return 1 === preg_match( '/^\s*SELECT\s+\*\s+FROM\s+\S*reservant_bookings\s+WHERE\s+uuid\s*=.*FOR UPDATE/is', (string) $query )
				? 'SELECT * FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};
		$errors   = array();
		$listener = static function ( \Throwable $e ) use ( &$errors ): void {
			$errors[] = $e;
		};
		add_action( 'reservant/error', $listener );

		// Same idiom as the sabotage test above (`ob_start()` before the try, `ob_get_clean()` in the
		// finally) - not the "both inside the try" form, which a `\WPDieException` or an `Error` would
		// skip past, leaking the buffer.
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $sabotage );
		ob_start();
		try {
			( new ApprovalActionEndpoint() )->handle();
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'query', $sabotage );
			$wpdb->suppress_errors( $suppressed );
			remove_action( 'reservant/error', $listener );
			remove_filter( 'pre_wp_mail', '__return_true' );
		}

		self::assertStringNotContainsString( 'no longer valid', $output, 'a busy booking-row lock is not a settled decision either' );
		self::assertStringContainsString( 'Something went wrong', $output );
		self::assertStringContainsString( 'try again', $output );

		self::assertCount( 1, $errors );
		self::assertSame( 'lock_unavailable', $errors[0]->getMessage() );

		self::assertSame(
			'awaiting_approval',
			( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['status'],
			'a refused approval must leave the booking exactly where it was'
		);
	}
}
