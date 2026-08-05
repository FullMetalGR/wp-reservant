<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Admin\Capabilities;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/** `GET reservant/v1/admin/calendar` (AGENTS.md Task 10): permission gate, scoping, and status inclusion. */
final class AdminCalendarTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		Capabilities::sync();

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

	private function calendar( int $dayFrom = 1, int $dayTo = 2, ?int $resourceId = null ): \WP_REST_Response {
		$params = array(
			'from' => $this->utc( $dayFrom )->format( 'Y-m-d' ),
			'to'   => $this->utc( $dayTo )->format( 'Y-m-d' ),
		);
		if ( null !== $resourceId ) {
			$params['resource_id'] = $resourceId;
		}
		return $this->request( 'GET', '/reservant/v1/admin/calendar', $params );
	}

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

	private function setStatus( string $uuid, string $status ): void {
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'status' => $status ), array( 'uuid' => $uuid ) );
	}

	// ---------------------------------------------------------------- permission matrix

	public function test_permission_matrix_for_admin_calendar(): void {
		$this->asAnonymous();
		self::assertSame( 401, $this->calendar()->get_status() );
		$this->asSubscriber();
		self::assertSame( 403, $this->calendar()->get_status() );
		$this->asStaff(); // reservant_view_own_calendar suffices.
		self::assertSame( 200, $this->calendar()->get_status() );
		$this->asAdmin();
		self::assertSame( 200, $this->calendar()->get_status() );
	}

	public function test_window_wider_than_62_days_is_rejected(): void {
		$this->asAdmin();
		$response = $this->request(
			'GET',
			'/reservant/v1/admin/calendar',
			array( 'from' => $this->utc( 1 )->format( 'Y-m-d' ), 'to' => $this->utc( 1 + 63 )->format( 'Y-m-d' ) )
		);
		self::assertSame( 400, $response->get_status() );
	}

	// ---------------------------------------------------------------- scoping

	public function test_staff_scoping_hides_other_staff_bookings_and_customer_contact(): void {
		$ownUuid   = $this->customerHold( $this->utc( 1, '09:00' ), $this->cutId, $this->staffA );
		$otherUuid = $this->customerHold( $this->utc( 1, '11:00' ), $this->cutId, $this->staffB );
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'status' => 'confirmed' ), array( 'uuid' => $ownUuid ) );
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'status' => 'confirmed' ), array( 'uuid' => $otherUuid ) );

		$this->asStaff( $this->staffA );
		$data = $this->calendar()->get_data();

		$uuids = array_column( $data['bookings'], 'uuid' );
		self::assertContains( $ownUuid, $uuids );
		self::assertNotContains( $otherUuid, $uuids, "A staff member's calendar must not show another staff member's bookings." );

		$own = $data['bookings'][ array_search( $ownUuid, $uuids, true ) ];
		self::assertArrayHasKey( 'customer_name', $own );
		self::assertArrayNotHasKey( 'customer_email', $own );
		self::assertArrayNotHasKey( 'customer_phone', $own );
		self::assertSame( 'Cut', $own['items'][0]['service_name'] );
		self::assertSame( 'Alex', $own['items'][0]['resource_name'] );

		// A manager sees both, with full contact fields.
		$this->asAdmin();
		$full = $this->calendar()->get_data();
		self::assertCount( 2, $full['bookings'] );
		foreach ( $full['bookings'] as $row ) {
			self::assertArrayHasKey( 'customer_email', $row );
		}
	}

	public function test_staff_with_no_linked_resource_sees_an_empty_calendar_but_still_sees_occurrences(): void {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert( array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' ) );
		( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 1, '18:00' ), 'end_utc' => $this->sql( 1, '20:00' ), 'capacity' => 10 )
		);
		$this->customerHold( $this->utc( 1, '09:00' ), $this->cutId, $this->staffA );

		$this->asStaff(); // No resource linked at all.
		$data = $this->calendar()->get_data();
		self::assertSame( array(), $data['bookings'] );
		self::assertCount( 1, $data['occurrences'] );
	}

	public function test_occurrences_are_always_visible_regardless_of_resource_scope(): void {
		global $wpdb;
		$eventId = ( new ServiceRepository( $wpdb ) )->insert( array( 'name' => 'Seminar', 'type' => 'event', 'price_minor' => 1000, 'payment_mode' => 'onsite' ) );
		$occId   = ( new OccurrenceRepository( $wpdb ) )->insert(
			array( 'service_id' => $eventId, 'start_utc' => $this->sql( 1, '18:00' ), 'end_utc' => $this->sql( 1, '20:00' ), 'capacity' => 10 )
		);

		$this->asStaff( $this->staffA );
		$data = $this->calendar()->get_data();
		self::assertCount( 1, $data['occurrences'] );
		self::assertSame( $occId, $data['occurrences'][0]['id'] );
		self::assertSame( 10, $data['occurrences'][0]['remaining'] );
		self::assertSame( 'Seminar', $data['occurrences'][0]['service_name'] );
	}

	// ---------------------------------------------------------------- status inclusion

	public function test_status_inclusion_excludes_terminal_states(): void {
		global $wpdb;

		$confirmed = $this->customerHold( $this->utc( 1, '09:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $confirmed, 'confirmed' );

		$completed = $this->customerHold( $this->utc( 1, '11:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $completed, 'completed' );

		$noShow = $this->customerHold( $this->utc( 1, '13:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $noShow, 'no_show' );

		$cancelled = $this->customerHold( $this->utc( 1, '15:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $cancelled, 'cancelled' );

		$rejected = $this->customerHold( $this->utc( 2, '09:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $rejected, 'rejected' );

		$expired = $this->customerHold( $this->utc( 2, '11:00' ), $this->cutId, $this->staffA );
		$this->setStatus( $expired, 'expired' );

		// A held-but-lapsed row: the row is still `pending`, but its hold has already elapsed, so
		// the blocking predicate (section 2.1) treats it exactly like `expired` - excluded from the
		// calendar too, without waiting on the sweeper.
		$lapsed = $this->customerHold( $this->utc( 2, '13:00' ), $this->cutId, $this->staffA );
		$wpdb->update( "{$wpdb->prefix}reservant_bookings", array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'uuid' => $lapsed ) );

		$live = $this->customerHold( $this->utc( 2, '15:00' ), $this->cutId, $this->staffA ); // Still awaiting a decision.

		$this->asAdmin();
		$uuids = array_column( $this->calendar( 1, 3 )->get_data()['bookings'], 'uuid' );

		self::assertContains( $confirmed, $uuids );
		self::assertContains( $completed, $uuids );
		self::assertContains( $noShow, $uuids );
		self::assertContains( $live, $uuids );
		self::assertNotContains( $cancelled, $uuids );
		self::assertNotContains( $rejected, $uuids );
		self::assertNotContains( $expired, $uuids );
		self::assertNotContains( $lapsed, $uuids );
	}
}
