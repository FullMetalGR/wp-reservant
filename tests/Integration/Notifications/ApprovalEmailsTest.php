<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Notifications;

use Reservant\Admin\ApprovalActionEndpoint;
use Reservant\Application\ApproveBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\RejectBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Infrastructure\Scheduler\Jobs;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `Notifications\ApprovalEmails` (Task 9): the mailer seam wired on the approval-flow hooks.
 *
 * `ApprovalEmails::register()` is never called directly here - exactly like `JobsTest` relies on
 * `Plugin::register()` having already wired `Jobs::register()` at bootstrap
 * (`tests/Integration/bootstrap.php` requires `reservant.php` on `muplugins_loaded`), these tests
 * rely on the same bootstrap having wired `ApprovalEmails::register()`. That is itself the wiring
 * assertion: if `Plugin::register()` never calls it, every test below sees zero captured mail.
 *
 * Mail is captured via the `pre_wp_mail` filter (WP core, `wp-includes/pluggable.php`): returning
 * non-null short-circuits `wp_mail()` before any real transport is touched.
 */
final class ApprovalEmailsTest extends ReservantTestCase {

	private int $approvalServiceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->approvalServiceId = $services->insert(
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
		$this->staffId           = $resources->insert(
			array(
				'name'  => 'Alex',
				'email' => 'alex@example.com',
			)
		);
		$resources->linkService( $this->approvalServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	private function customer(): Customer {
		return new Customer( 'Maria', 'maria@example.com' );
	}

	/** @return array<string, mixed> */
	private function holdAwaitingApproval(): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->approvalServiceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	/**
	 * @return list<array{to: string, subject: string, message: string}>
	 */
	private function captureMail( callable $trigger ): array {
		$captured = array();
		$listener = static function ( $preempt, array $atts ) use ( &$captured ) {
			$to         = $atts['to'] ?? '';
			$captured[] = array(
				'to'      => is_array( $to ) ? implode( ',', $to ) : (string) $to,
				'subject' => (string) ( $atts['subject'] ?? '' ),
				'message' => (string) ( $atts['message'] ?? '' ),
			);
			return true; // Short-circuits wp_mail() - never touches a real transport.
		};
		add_filter( 'pre_wp_mail', $listener, 10, 2 );
		$trigger();
		remove_filter( 'pre_wp_mail', $listener, 10 );
		return $captured;
	}

	/**
	 * The messages addressed to one recipient.
	 *
	 * An approval-gated hold now sends TWO emails from the one `reservant/booking/held` - this
	 * class's `approval_request` to the approver, and `BookingEmails`' `booking_received` to the
	 * guest, who would otherwise hear nothing at all until a human decided. The two are told apart
	 * by who they are for, which is also the thing worth asserting.
	 *
	 * @param list<array{to: string, subject: string, message: string}> $sent
	 * @return list<array{to: string, subject: string, message: string}>
	 */
	private function addressedTo( array $sent, string $recipient ): array {
		return array_values( array_filter( $sent, static fn ( array $mail ): bool => $recipient === $mail['to'] ) );
	}

	public function testHeldApprovalRequestGoesToAssignedStaffWithBothSignedUrls(): void {
		global $wpdb;
		$booking = null;
		$sent    = $this->captureMail(
			function () use ( &$booking ): void {
				$booking = $this->holdAwaitingApproval();
			}
		);

		// Two emails, one hook: the approver's decision request and the guest's acknowledgement.
		self::assertCount( 2, $sent );
		self::assertCount( 1, $this->addressedTo( $sent, 'maria@example.com' ), 'the guest is told their request is waiting on a human' );

		$approver = $this->addressedTo( $sent, 'alex@example.com' );
		self::assertCount( 1, $approver );
		$sent = $approver;
		self::assertStringContainsString( 'approval', strtolower( $sent[0]['subject'] ) );

		$fresh      = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		$expiresTs  = ( new \DateTimeImmutable( (string) $fresh['hold_expires_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$approveUrl = ApprovalActionEndpoint::url( $booking['uuid'], 'approve', (string) $fresh['updated_at'], $expiresTs );
		$rejectUrl  = ApprovalActionEndpoint::url( $booking['uuid'], 'reject', (string) $fresh['updated_at'], $expiresTs );

		self::assertStringContainsString( $approveUrl, $sent[0]['message'], 'body must carry the signed approve URL' );
		self::assertStringContainsString( $rejectUrl, $sent[0]['message'], 'body must carry the signed reject URL' );
	}

	public function testNagSendsApprovalNagEmailWithTheSameLinksToTheSameApprover(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();

		$sent = $this->captureMail(
			static function () use ( $booking ): void {
				do_action( Jobs::NAG, $booking['uuid'], 50 );
			}
		);

		self::assertCount( 1, $sent );
		self::assertSame( 'alex@example.com', $sent[0]['to'] );

		$fresh      = ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] );
		$expiresTs  = ( new \DateTimeImmutable( (string) $fresh['hold_expires_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$approveUrl = ApprovalActionEndpoint::url( $booking['uuid'], 'approve', (string) $fresh['updated_at'], $expiresTs );
		$rejectUrl  = ApprovalActionEndpoint::url( $booking['uuid'], 'reject', (string) $fresh['updated_at'], $expiresTs );

		self::assertStringContainsString( $approveUrl, $sent[0]['message'] );
		self::assertStringContainsString( $rejectUrl, $sent[0]['message'] );
	}

	public function testApprovedSendsBookingApprovedEmailToTheCustomer(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();

		$sent = $this->captureMail(
			function () use ( $wpdb, $booking ): void {
				ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' );
			}
		);

		self::assertCount( 1, $sent );
		self::assertSame( 'maria@example.com', $sent[0]['to'] );
		self::assertStringContainsString( 'approved', strtolower( $sent[0]['subject'] ) );
	}

	/**
	 * An approval that lands on `awaiting_payment` mails the guest the PAYMENT LINK, not the plain
	 * "you are approved" - the latter has nothing actionable to say to someone who still owes money,
	 * and two emails announcing one decision reads as a mistake.
	 */
	public function testAnOnlineApprovalSendsThePaymentLinkInsteadOfBookingApproved(): void {
		global $wpdb;
		$this->usePaymentProvider( new \Reservant\Tests\Integration\Payment\FakePaymentProvider( true, 4242, 9001 ) );
		$services = new ServiceRepository( $wpdb );
		$onlineId = $services->insert(
			array(
				'name'                => 'Online Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'online',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		( new ResourceRepository( $wpdb ) )->linkService( $onlineId, $this->staffId );

		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				$this->customer(),
				new AppointmentRequest( $this->utc( 1, '13:00' ), array( new SegmentChoice( $onlineId ) ) )
			),
			$this->utc( 0 )
		);

		$sent = $this->captureMail(
			function () use ( $wpdb, $booking ): void {
				ApproveBooking::make( $wpdb )->execute( $booking['uuid'], $this->utc( 0, '01:00' ), 'admin' );
			}
		);

		self::assertCount( 1, $sent, 'exactly one email per decision - the payment link replaces booking_approved, it does not join it' );
		self::assertSame( 'maria@example.com', $sent[0]['to'] );
		self::assertStringContainsString( 'payment', strtolower( $sent[0]['subject'] ) );
		self::assertStringContainsString( 'https://example.test/pay/9001', $sent[0]['message'], 'the body must carry the checkout URL' );
	}

	public function testRejectedEmailToCustomerIncludesTheReason(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();

		$sent = $this->captureMail(
			function () use ( $wpdb, $booking ): void {
				RejectBooking::make( $wpdb )->execute( $booking['uuid'], 'not enough staff available', $this->utc( 0, '01:00' ), 'admin' );
			}
		);

		self::assertCount( 1, $sent );
		self::assertSame( 'maria@example.com', $sent[0]['to'] );
		self::assertStringContainsString( 'not enough staff available', $sent[0]['message'] );
	}

	public function testApprovalRequestArgsFilterCanRewriteTheRecipient(): void {
		$rewrite = static function ( array $args ): array {
			$args['to'] = 'override@example.com';
			return $args;
		};
		add_filter( 'reservant/email/approval_request/args', $rewrite );

		$sent = $this->captureMail(
			function (): void {
				$this->holdAwaitingApproval();
			}
		);

		remove_filter( 'reservant/email/approval_request/args', $rewrite );

		self::assertCount( 1, $this->addressedTo( $sent, 'override@example.com' ) );
		// The filter is keyed, so rewriting the approver's recipient must not touch the guest's
		// acknowledgement going out of the same hook.
		self::assertCount( 1, $this->addressedTo( $sent, 'maria@example.com' ) );
		self::assertCount( 0, $this->addressedTo( $sent, 'alex@example.com' ) );
	}

	public function testHeldWithoutApprovalSendsNoMail(): void {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$plainId   = $services->insert(
			array(
				'name'         => 'Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2000,
				'payment_mode' => 'onsite',
			)
		);
		$resources = new ResourceRepository( $wpdb );
		$staff     = $resources->insert(
			array(
				'name'  => 'Sam',
				'email' => 'sam@example.com',
			)
		);
		$resources->linkService( $plainId, $staff );
		$avail = new AvailabilityRepository( $wpdb );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}

		$sent = $this->captureMail(
			function () use ( $wpdb, $plainId ): void {
				HoldBooking::make( $wpdb )->execute(
					new HoldRequest(
						$this->customer(),
						new AppointmentRequest( $this->utc( 1, '13:00' ), array( new SegmentChoice( $plainId ) ) )
					),
					$this->utc( 0 )
				);
			}
		);

		self::assertCount( 0, $sent );
	}

	/**
	 * No assigned resource (an event booking's item carries no `resource_id`) falls back to the
	 * site admin - AGENTS.md "Approval decisions are made by admins or by the staff member assigned
	 * to the booking." Simulated directly on the row rather than via a real event fixture: the only
	 * thing under test is the approver-email fallback, not event booking mechanics.
	 */
	public function testApprovalRequestFallsBackToAdminEmailWithNoAssignedResource(): void {
		global $wpdb;
		$booking = $this->holdAwaitingApproval();
		$wpdb->update(
			$wpdb->prefix . 'reservant_booking_items',
			array( 'resource_id' => null ),
			array( 'booking_id' => ( new BookingRepository( $wpdb ) )->findByUuid( $booking['uuid'] )['id'] )
		);

		$sent = $this->captureMail(
			static function () use ( $booking ): void {
				do_action( Jobs::NAG, $booking['uuid'], 25 );
			}
		);

		self::assertCount( 1, $sent );
		self::assertSame( (string) get_option( 'admin_email' ), $sent[0]['to'] );
	}

	/**
	 * A DB fault inside this post-commit notification hook must not strand the customer.
	 *
	 * `sendApproverEmail()` re-reads the booking (`findByUuid()`, for the `updated_at` the signed
	 * links bind to), and that read is reached from `reservant/booking/held`, which
	 * `HoldBooking::execute()` fires AFTER `$this->txn->run()` has returned - the hold is committed.
	 * Once `findByUuid()` began refusing a failed read, that refusal travelled out of
	 * `HoldBooking::execute()` into `HoldsController::create()`'s own `catch`, which answered 409
	 * `lock_unavailable` - for a booking that exists - and never reached the two lines below it that
	 * hand the guest their `manage_token`. The result was a committed booking the customer could
	 * neither manage nor cancel, plus a 409 inviting them to book it a second time. It also skipped
	 * `scheduleApprovalTimers()`, which runs after the same hook, so the hold got no nags and no
	 * timeout either.
	 *
	 * Pre-guard this read answered `null` and the email was simply skipped. That is the behaviour
	 * restored here: a lost approver email is not worth a lost booking.
	 *
	 * The sabotage is installed at priority 9 on that hook and removed at 11, so it covers exactly
	 * the queries `ApprovalEmails::onHeld()` (priority 10) issues - never `HoldBooking`'s own
	 * in-transaction re-read, which is the identical statement shape.
	 */
	public function testAFailedApproverLookupNeitherLosesTheHoldNorItsManageToken(): void {
		global $wpdb;
		$sabotage = static function ( $query ) {
			$q = (string) $query;
			return ( str_contains( $q, 'reservant_bookings' ) && str_contains( $q, 'uuid' ) && ! str_contains( $q, 'FOR UPDATE' ) )
				? 'SELECT * FROM reservant_no_such_table WHERE 1 = 1'
				: $query;
		};
		$arm        = static function () use ( $sabotage ): void {
			add_filter( 'query', $sabotage );
		};
		$disarm     = static function () use ( $sabotage ): void {
			remove_filter( 'query', $sabotage );
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_action( 'reservant/booking/held', $arm, 9 );
		add_action( 'reservant/booking/held', $disarm, 11 );

		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer'    => array( 'name' => 'Maria', 'email' => 'maria@example.com' ),
				'appointment' => array(
					'start_utc' => $this->sql( 1, '09:00' ),
					'segments'  => array( array( 'service_id' => $this->approvalServiceId ) ),
				),
			)
		);
		try {
			$response = rest_do_request( $request );
		} finally {
			remove_action( 'reservant/booking/held', $arm, 9 );
			remove_action( 'reservant/booking/held', $disarm, 11 );
			remove_filter( 'query', $sabotage ); // Belt and braces if the hook never reached priority 11.
			$wpdb->suppress_errors( $suppressed );
		}

		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertNotSame( '', (string) ( $data['manage_token'] ?? '' ), 'the guest must still receive the only credential they have for this booking' );

		$fresh = ( new BookingRepository( $wpdb ) )->findByUuid( (string) $data['uuid'] );
		self::assertNotNull( $fresh, 'the hold committed - the notification hook runs after the transaction' );
		self::assertSame( 'awaiting_approval', $fresh['status'] );

		// The approval timers are scheduled after the same hook, so they were skipped too.
		foreach ( array( Jobs::NAG, Jobs::TIMEOUT ) as $hook ) {
			self::assertNotEmpty(
				as_get_scheduled_actions( array( 'hook' => $hook, 'group' => 'reservant', 'per_page' => 10 ), 'ids' ),
				"a failed approver lookup must not cost the hold its {$hook} timers"
			);
		}
	}
}
