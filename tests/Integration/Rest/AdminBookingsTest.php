<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `reservant/v1/admin/bookings` and `admin/availability` (AGENTS.md Task 10): permission matrix,
 * search, the manual booking drawer, the approve/reject/cancel/outcome transitions, and the
 * differential property between `GET /admin/availability` and an admin hold.
 */
final class AdminBookingsTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		Capabilities::sync(); // The `reservant_staff` role must exist before a test assigns it.

		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->cutId  = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->staffA = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB = $resources->insert( array( 'name' => 'Bella' ) );
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			$resources->linkService( $this->cutId, $staff );
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	// ---------------------------------------------------------------- helpers

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/** A `reservant_staff` user, optionally linked to a resource of their own. */
	private function asStaff( ?int $ownResourceId = null ): int {
		global $wpdb;
		$id = self::factory()->user->create( array( 'role' => 'reservant_staff' ) );
		if ( null !== $ownResourceId ) {
			$wpdb->update( "{$wpdb->prefix}reservant_resources", array( 'wp_user_id' => $id ), array( 'id' => $ownResourceId ) );
		}
		wp_set_current_user( $id );
		return $id;
	}

	private function asSubscriber(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function asAnonymous(): void {
		wp_set_current_user( 0 );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** @param array<string, mixed> $body */
	private function jsonRequest( string $method, string $route, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	/** @return array<string, mixed> the manual-booking 201 payload */
	private function manualBooking( string $startUtc, int $serviceId, ?int $resourceId = null ): array {
		$segment = array( 'service_id' => $serviceId );
		if ( null !== $resourceId ) {
			$segment['resource_id'] = $resourceId;
		}
		$response = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/bookings',
			array(
				'customer'    => array( 'name' => 'Walk-in', 'email' => 'walkin@example.com' ),
				'appointment' => array( 'start_utc' => $startUtc, 'segments' => array( $segment ) ),
			)
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		return $data;
	}

	/** A held (pending) customer booking through the ordinary, non-admin path. */
	private function customerHold( \DateTimeImmutable $start, int $serviceId, int $resourceId ): string {
		global $wpdb;
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Customer', 'customer@example.com' ),
				new AppointmentRequest( $start, array( new SegmentChoice( $serviceId, $resourceId ) ) )
			),
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);
		return (string) $booking['uuid'];
	}

	private function forceExpired( string $uuid ): void {
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'uuid' => $uuid ) );
	}

	/** Next 5-minute-aligned instant at or after `$dt`. */
	private static function alignedUp( \DateTimeImmutable $dt ): \DateTimeImmutable {
		$ts = (int) ceil( $dt->getTimestamp() / 300 ) * 300;
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	private static function realNow(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	// ---------------------------------------------------------------- permission matrix

	/**
	 * Every admin bookings route x {no auth, subscriber, staff, manager}: 401 with no session, 403
	 * for a logged-in user lacking the capability, 200/201 for whoever holds it.
	 */
	public function test_permission_matrix_for_admin_bookings_routes(): void {
		$held = $this->customerHold( $this->utc( 1, '09:00' ), $this->cutId, $this->staffA );

		// GET /admin/bookings (reservant_manage_bookings only).
		$this->asAnonymous();
		self::assertSame( 401, $this->request( 'GET', '/reservant/v1/admin/bookings' )->get_status() );
		$this->asSubscriber();
		self::assertSame( 403, $this->request( 'GET', '/reservant/v1/admin/bookings' )->get_status() );
		$this->asStaff();
		self::assertSame( 403, $this->request( 'GET', '/reservant/v1/admin/bookings' )->get_status() );
		$this->asAdmin();
		self::assertSame( 200, $this->request( 'GET', '/reservant/v1/admin/bookings' )->get_status() );

		// GET /admin/bookings/{uuid}.
		$this->asAnonymous();
		self::assertSame( 401, $this->request( 'GET', "/reservant/v1/admin/bookings/{$held}" )->get_status() );
		$this->asSubscriber();
		self::assertSame( 403, $this->request( 'GET', "/reservant/v1/admin/bookings/{$held}" )->get_status() );
		$this->asAdmin();
		self::assertSame( 200, $this->request( 'GET', "/reservant/v1/admin/bookings/{$held}" )->get_status() );

		// POST /admin/bookings (manual booking): manager only.
		$this->asAnonymous();
		self::assertSame( 401, $this->jsonRequest( 'POST', '/reservant/v1/admin/bookings', array() )->get_status() );
		$this->asStaff();
		self::assertSame( 403, $this->jsonRequest( 'POST', '/reservant/v1/admin/bookings', array() )->get_status() );

		// POST .../cancel, /no_show, /complete: manager only, staff forbidden.
		foreach ( array( 'cancel', 'no_show', 'complete' ) as $action ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$held}/{$action}", array() )->get_status(), $action );
			$this->asSubscriber();
			self::assertSame( 403, $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$held}/{$action}", array() )->get_status(), $action );
			$this->asStaff();
			self::assertSame( 403, $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$held}/{$action}", array() )->get_status(), $action );
		}

		// POST .../approve, /reject: reservant_approve_bookings suffices (staff has it), but a
		// subscriber and an anonymous caller are still refused at the capability gate itself.
		foreach ( array( 'approve', 'reject' ) as $action ) {
			$this->asAnonymous();
			self::assertSame( 401, $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$held}/{$action}", array() )->get_status(), $action );
			$this->asSubscriber();
			self::assertSame( 403, $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$held}/{$action}", array() )->get_status(), $action );
		}
	}

	/** GET /admin/availability shares the calendar's capability gate. */
	public function test_permission_matrix_for_admin_availability(): void {
		$params = array(
			'items' => wp_json_encode( array( array( 'service_id' => $this->cutId ) ) ),
			'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
			'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
		);

		$this->asAnonymous();
		self::assertSame( 401, $this->request( 'GET', '/reservant/v1/admin/availability', $params )->get_status() );
		$this->asSubscriber();
		self::assertSame( 403, $this->request( 'GET', '/reservant/v1/admin/availability', $params )->get_status() );
		$this->asStaff(); // reservant_view_own_calendar suffices.
		self::assertSame( 200, $this->request( 'GET', '/reservant/v1/admin/availability', $params )->get_status() );
		$this->asAdmin();
		self::assertSame( 200, $this->request( 'GET', '/reservant/v1/admin/availability', $params )->get_status() );
	}

	// ---------------------------------------------------------------- search

	public function test_search_filters_by_status_and_customer_text(): void {
		$this->asAdmin();
		$pending = $this->customerHold( $this->utc( 1, '09:00' ), $this->cutId, $this->staffA );

		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'customer_name' => 'Zoe Zephyr' ), array( 'uuid' => $pending ) );

		$this->manualBooking( $this->sql( 1, '11:00' ), $this->cutId, $this->staffB ); // confirmed, "Walk-in".

		$byStatus = $this->request( 'GET', '/reservant/v1/admin/bookings', array( 'status' => 'pending' ) );
		self::assertSame( 200, $byStatus->get_status() );
		$data = $byStatus->get_data();
		self::assertSame( 1, $data['total'] );
		self::assertSame( $pending, $data['bookings'][0]['uuid'] );

		$byText = $this->request( 'GET', '/reservant/v1/admin/bookings', array( 'search' => 'zephyr' ) );
		self::assertSame( 1, $byText->get_data()['total'] );
		self::assertSame( $pending, $byText->get_data()['bookings'][0]['uuid'] );

		// Blocking-agnostic: with no filter, both the pending hold and the confirmed walk-in show.
		$all = $this->request( 'GET', '/reservant/v1/admin/bookings' );
		self::assertSame( 2, $all->get_data()['total'] );

		// The join names each item's service/resource, and the manage token never leaks.
		$item = $byStatus->get_data()['bookings'][0]['items'][0];
		self::assertSame( 'Cut', $item['service_name'] );
		self::assertSame( 'Alex', $item['resource_name'] );
		self::assertArrayNotHasKey( 'manage_token_hash', $byStatus->get_data()['bookings'][0] );
	}

	// ---------------------------------------------------------------- manual booking

	public function test_manual_booking_inside_lead_time_succeeds_and_omits_manage_token(): void {
		global $wpdb;
		// 30 days' notice - the ordinary customer path would refuse a start only 8 days out.
		$farAheadId = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Bespoke', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'lead_time_min' => 43200 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $farAheadId, $this->staffA );

		$this->asAdmin();
		$customerRefused = $this->jsonRequest(
			'POST',
			'/reservant/v1/holds',
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 1, '09:00' ), 'segments' => array( array( 'service_id' => $farAheadId, 'resource_id' => $this->staffA ) ) ),
			)
		);
		self::assertSame( 409, $customerRefused->get_status() );
		self::assertSame( 'lead_time', $customerRefused->get_data()['message'] );

		$created = $this->manualBooking( $this->sql( 1, '09:00' ), $farAheadId, $this->staffA );
		self::assertSame( 'confirmed', $created['status'] );
		self::assertArrayNotHasKey( 'manage_token', $created );
		self::assertArrayNotHasKey( 'manage_token_hash', $created );

		// The audit trail attributes the create to the real WP user, not just HoldBooking's
		// generic system tag.
		$user  = wp_get_current_user();
		$audit = $this->request( 'GET', "/reservant/v1/admin/bookings/{$created['uuid']}" )->get_data()['audit'];
		$actors = array_column( $audit, 'actor' );
		self::assertContains( 'admin', $actors );
		self::assertContains( $user->user_login, $actors );
	}

	public function test_manual_booking_rejects_a_malformed_body(): void {
		$this->asAdmin();
		$response = $this->jsonRequest( 'POST', '/reservant/v1/admin/bookings', array( 'customer' => array( 'name' => 'M', 'email' => 'm@example.com' ) ) );
		self::assertSame( 400, $response->get_status() );
	}

	// ---------------------------------------------------------------- approve / reject scoping

	public function test_staff_can_approve_only_a_booking_on_their_own_resource(): void {
		global $wpdb;
		$approvalService = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Consult', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'free', 'requires_approval' => 1, 'approval_hold_hours' => 48 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $approvalService, $this->staffA );
		( new ResourceRepository( $wpdb ) )->linkService( $approvalService, $this->staffB );

		$own   = $this->customerHold( $this->utc( 1, '09:00' ), $approvalService, $this->staffA );
		$other = $this->customerHold( $this->utc( 1, '11:00' ), $approvalService, $this->staffB );

		$this->asStaff( $this->staffA );
		$refused = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$other}/approve", array() );
		self::assertSame( 403, $refused->get_status() );

		$approved = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$own}/approve", array() );
		self::assertSame( 200, $approved->get_status(), (string) wp_json_encode( $approved->get_data() ) );
		self::assertSame( 'confirmed', $approved->get_data()['status'] );

		// A manager needs no resource of their own.
		$this->asAdmin();
		$managerApproved = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$other}/approve", array() );
		self::assertSame( 200, $managerApproved->get_status() );
	}

	public function test_reject_via_rest_records_the_reason(): void {
		global $wpdb;
		$approvalService = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'Consult', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'free', 'requires_approval' => 1, 'approval_hold_hours' => 48 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $approvalService, $this->staffA );
		$uuid = $this->customerHold( $this->utc( 1, '09:00' ), $approvalService, $this->staffA );

		$this->asAdmin();
		$response = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$uuid}/reject", array( 'reason' => 'Not a fit' ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'rejected', $response->get_data()['status'] );
		self::assertSame( 'Not a fit', $response->get_data()['rejection_reason'] );
	}

	// ---------------------------------------------------------------- cancel / no_show / complete

	public function test_cancel_no_show_complete_via_rest(): void {
		$this->asAdmin();
		$confirmed = $this->manualBooking( $this->sql( 1, '09:00' ), $this->cutId, $this->staffA );

		$noShow = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$confirmed['uuid']}/no_show", array() );
		self::assertSame( 200, $noShow->get_status() );
		self::assertSame( 'no_show', $noShow->get_data()['status'] );

		$confirmed2 = $this->manualBooking( $this->sql( 1, '11:00' ), $this->cutId, $this->staffA );
		$complete   = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$confirmed2['uuid']}/complete", array() );
		self::assertSame( 200, $complete->get_status() );
		self::assertSame( 'completed', $complete->get_data()['status'] );

		$confirmed3 = $this->manualBooking( $this->sql( 1, '13:00' ), $this->cutId, $this->staffA );
		$cancel     = $this->jsonRequest( 'POST', "/reservant/v1/admin/bookings/{$confirmed3['uuid']}/cancel", array() );
		self::assertSame( 200, $cancel->get_status() );
		self::assertSame( 'cancelled', $cancel->get_data()['status'] );
	}

	// ---------------------------------------------------------------- differential property test

	/**
	 * Fixture: a booking held then left to lapse without the sweeper running (AGENTS.md section 2.1:
	 * correctness never depends on the reaper). Not blocking either endpoint's view of the slot.
	 */
	public function test_differential_expired_hold_is_offered_and_holdable(): void {
		global $wpdb;
		$service = ( new ServiceRepository( $wpdb ) )->insert( array( 'name' => 'ExpFree', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		( new ResourceRepository( $wpdb ) )->linkService( $service, $this->staffA );

		$slot = $this->sql( 1, '10:00' );
		$this->forceExpired( $this->customerHold( $this->utc( 1, '10:00' ), $service, $this->staffA ) );

		$this->asAdmin();
		$availability = $this->request(
			'GET',
			'/reservant/v1/admin/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $service, 'resource_id' => $this->staffA ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		$starts = array_column( $availability->get_data()['starts'], 'utc' );
		self::assertContains( $slot, $starts, 'An expired hold must not withhold its slot.' );

		$held = $this->manualBooking( $slot, $service, $this->staffA );
		self::assertSame( 'confirmed', $held['status'] );
	}

	/** Fixture: a still-valid `awaiting_approval` hold. Blocking on both endpoints. */
	public function test_differential_awaiting_approval_hold_is_withheld_and_refused(): void {
		global $wpdb;
		$service = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'NeedsOk', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'free', 'requires_approval' => 1, 'approval_hold_hours' => 48 )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $service, $this->staffA );

		$slot = $this->sql( 1, '10:00' );
		$this->customerHold( $this->utc( 1, '10:00' ), $service, $this->staffA ); // Lands awaiting_approval, not expired.

		$this->asAdmin();
		$availability = $this->request(
			'GET',
			'/reservant/v1/admin/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $service, 'resource_id' => $this->staffA ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		$starts = array_column( $availability->get_data()['starts'], 'utc' );
		self::assertNotContains( $slot, $starts, 'A live awaiting_approval hold must withhold its slot.' );

		$refused = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/bookings',
			array(
				'customer'    => array( 'name' => 'Walk-in', 'email' => 'walkin@example.com' ),
				'appointment' => array( 'start_utc' => $slot, 'segments' => array( array( 'service_id' => $service, 'resource_id' => $this->staffA ) ) ),
			)
		);
		self::assertSame( 409, $refused->get_status() );
		self::assertSame( 'overlap', $refused->get_data()['message'] );
	}

	/** Fixture: a buffered service right at the resource's opening edge. */
	public function test_differential_buffered_service_at_opening_edge(): void {
		global $wpdb;
		$service = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'EdgeBuffered', 'type' => 'appointment', 'duration_min' => 30, 'buffer_before_min' => 15, 'payment_mode' => 'onsite' )
		);
		( new ResourceRepository( $wpdb ) )->linkService( $service, $this->staffA );

		$this->asAdmin();
		$availability = $this->request(
			'GET',
			'/reservant/v1/admin/availability',
			array(
				'items' => wp_json_encode( array( array( 'service_id' => $service, 'resource_id' => $this->staffA ) ) ),
				'from'  => $this->utc( 1 )->format( 'Y-m-d' ),
				'to'    => $this->utc( 2 )->format( 'Y-m-d' ),
			)
		);
		$starts = array_column( $availability->get_data()['starts'], 'utc' );
		self::assertContains( $this->sql( 1, '09:00' ), $starts );
		self::assertNotContains( $this->sql( 1, '08:55' ), $starts );

		$offered = $this->manualBooking( $this->sql( 1, '09:00' ), $service, $this->staffA );
		self::assertSame( 'confirmed', $offered['status'] );
		self::assertSame( $this->sql( 1, '08:45' ), $offered['items'][0]['block_start_utc'] ); // Buffer reaches before opening; blocks nobody.

		$refused = $this->jsonRequest(
			'POST',
			'/reservant/v1/admin/bookings',
			array(
				'customer'    => array( 'name' => 'Walk-in', 'email' => 'walkin@example.com' ),
				'appointment' => array( 'start_utc' => $this->sql( 1, '08:55' ), 'segments' => array( array( 'service_id' => $service, 'resource_id' => $this->staffA ) ) ),
			)
		);
		self::assertSame( 409, $refused->get_status() );
		self::assertSame( 'outside_hours', $refused->get_data()['message'] );
	}

	/**
	 * Fixture: a start inside an ordinary customer's lead-time window (AGENTS.md Task 10: this is
	 * the divergence `AvailabilityQuery::$ignoreWindow` exists to close). The admin endpoint must
	 * offer it - and an admin hold must accept it - exactly where the public endpoint refuses.
	 */
	public function test_differential_lead_time_boundary_is_admin_only(): void {
		global $wpdb;
		$service = ( new ServiceRepository( $wpdb ) )->insert(
			array( 'name' => 'LeadEdge', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite', 'lead_time_min' => 180 )
		);
		$staff = ( new ResourceRepository( $wpdb ) )->insert( array( 'name' => 'AllDay' ) );
		( new ResourceRepository( $wpdb ) )->linkService( $service, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			( new AvailabilityRepository( $wpdb ) )->insertRule( $staff, new AvailabilityRule( $weekday, '00:00', '23:55' ) );
		}

		$now    = self::realNow();
		$target = self::alignedUp( $now->modify( '+1 hour' ) ); // Well inside the 180-minute lead window.
		$from   = $now->format( 'Y-m-d' );
		$to     = $now->modify( '+2 days' )->format( 'Y-m-d' );
		$targetSql = $target->format( 'Y-m-d H:i:s' );

		$params = array(
			'items' => wp_json_encode( array( array( 'service_id' => $service, 'resource_id' => $staff ) ) ),
			'from'  => $from,
			'to'    => $to,
		);

		$this->asAdmin();
		$adminStarts = array_column( $this->request( 'GET', '/reservant/v1/admin/availability', $params )->get_data()['starts'], 'utc' );
		self::assertContains( $targetSql, $adminStarts, 'The admin endpoint must ignore the lead-time clamp.' );

		$publicStarts = array_column( $this->request( 'GET', '/reservant/v1/availability', $params )->get_data()['starts'], 'utc' );
		self::assertNotContains( $targetSql, $publicStarts, 'The public endpoint must still respect the lead-time clamp.' );

		$adminHeld = $this->manualBooking( $targetSql, $service, $staff );
		self::assertSame( 'confirmed', $adminHeld['status'] );

		$customerRefused = $this->jsonRequest(
			'POST',
			'/reservant/v1/holds',
			array(
				'customer'    => array( 'name' => 'M', 'email' => 'm@example.com' ),
				'appointment' => array( 'start_utc' => $targetSql, 'segments' => array( array( 'service_id' => $service, 'resource_id' => $staff ) ) ),
			)
		);
		// The admin's own booking already occupies the slot, so a customer request refuses on
		// `overlap` at worst - and would have refused on `lead_time` even against a free slot,
		// as the direct public-availability assertion above already demonstrates.
		self::assertSame( 409, $customerRefused->get_status() );
	}
}
