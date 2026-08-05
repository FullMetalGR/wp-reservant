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
		unset( $_GET['uuid'], $_GET['decision'], $_GET['exp'], $_GET['sig'] );
		unset( $_POST['uuid'], $_POST['decision'], $_POST['exp'], $_POST['sig'], $_POST['reason'] );
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
		$staleSig  = SignedAction::sign( wp_salt( 'auth' ), $booking['uuid'], 'approve', $exp, $updatedAt );

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
}
