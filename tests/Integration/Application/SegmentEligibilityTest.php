<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\AvailabilityQuery;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The advisory read and the authoritative write must answer "who may serve this segment" the same
 * way (`Application\SegmentEligibility`).
 *
 * These are paired assertions on purpose. Every test here checks BOTH paths for the same setup,
 * because the failure this module exists to prevent is not "one path is wrong" - each path was
 * self-consistent - but "the two disagree", which only a test that asks both can see. Before the
 * module, availability drew its pool from every linked row while the hold drew from the active ones
 * and applied no filter, so the widget offered slots the hold then refused as `no_staff`.
 */
final class SegmentEligibilityTest extends ReservantTestCase {

	private int $cutId;
	private int $staffA;
	private int $staffB;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services      = new ServiceRepository( $wpdb );
		$resources     = new ResourceRepository( $wpdb );
		$avail         = new AvailabilityRepository( $wpdb );
		$this->cutId   = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2000, 'payment_mode' => 'onsite' ) );
		$this->staffA  = $resources->insert( array( 'name' => 'Alex' ) );
		$this->staffB  = $resources->insert( array( 'name' => 'Bella' ) );
		foreach ( array( $this->staffA, $this->staffB ) as $staff ) {
			$resources->linkService( $this->cutId, $staff );
			foreach ( range( 1, 7 ) as $weekday ) {
				$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
			}
		}
	}

	/** @return list<\DateTimeImmutable> */
	private function starts( ?int $resourceId = null ): array {
		global $wpdb;
		return AvailabilityQuery::make( $wpdb )->appointmentStarts(
			array( new SegmentChoice( $this->cutId, $resourceId ) ),
			$this->utc( 1 ),
			$this->utc( 2 ),
			$this->utc( 0 )
		);
	}

	/** @return array<string, mixed> */
	private function hold( \DateTimeImmutable $start, ?int $resourceId = null ): array {
		global $wpdb;
		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $start, array( new SegmentChoice( $this->cutId, $resourceId ) ) )
			),
			$this->utc( 0 )
		);
	}

	private function assertRefused( string $reason, callable $call ): void {
		try {
			$call();
		} catch ( SlotConflict $e ) {
			self::assertSame( $reason, $e->reason );
			return;
		}
		self::fail( 'Expected SlotConflict ' . $reason . '.' );
	}

	public function test_a_deactivated_staff_member_is_offered_by_neither_path(): void {
		global $wpdb;
		( new ResourceRepository( $wpdb ) )->setStatus( $this->staffB, 'inactive' );
		// Bella keeps her link and her working hours; only her status changed.

		$this->assertRefused( 'no_staff', fn () => $this->starts( $this->staffB ) );
		$this->assertRefused( 'no_staff', fn () => $this->hold( $this->utc( 1, '09:00' ), $this->staffB ) );

		// Alex is still active, so the segment is still bookable on both paths.
		self::assertNotSame( array(), $this->starts( $this->staffA ) );
		self::assertSame( 'pending', $this->hold( $this->utc( 1, '09:00' ), $this->staffA )['status'] );
	}

	public function test_the_last_active_staff_member_leaving_empties_both_pools(): void {
		global $wpdb;
		$resources = new ResourceRepository( $wpdb );
		$resources->setStatus( $this->staffA, 'inactive' );
		$resources->setStatus( $this->staffB, 'inactive' );

		// This is the case that used to advertise a full day of slots and refuse every one of them:
		// the advisory pool was every linked row, so it never noticed the shop had nobody working.
		$this->assertRefused( 'no_staff', fn () => $this->starts() );
		$this->assertRefused( 'no_staff', fn () => $this->hold( $this->utc( 1, '09:00' ) ) );
	}

	public function test_the_candidates_filter_narrows_the_hold_and_not_only_the_offer(): void {
		$excluded = $this->staffB;
		$removeB  = static fn ( array $ids ): array => array_values( array_diff( $ids, array( $excluded ) ) );
		add_filter( 'reservant/chain/candidates', $removeB, 10, 3 );
		try {
			// Pinning the filtered-out staff member is refused by BOTH paths. Before the module the
			// hold ignored the filter entirely and took the booking.
			$this->assertRefused( 'no_staff', fn () => $this->starts( $this->staffB ) );
			$this->assertRefused( 'no_staff', fn () => $this->hold( $this->utc( 1, '09:00' ), $this->staffB ) );

			// And "any staff" never lands on the excluded person, which is the quieter half of the
			// same defect: auto-assignment used to draw from the unfiltered pool.
			self::assertSame( $this->staffA, $this->hold( $this->utc( 1, '10:00' ) )['items'][0]['resource_id'] );
		} finally {
			remove_filter( 'reservant/chain/candidates', $removeB, 10 );
		}
	}

	public function test_a_filter_that_empties_the_pool_refuses_the_hold_too(): void {
		$emptyOut = static fn ( array $ids ): array => array();
		add_filter( 'reservant/chain/candidates', $emptyOut, 10, 3 );
		try {
			$this->assertRefused( 'no_staff', fn () => $this->starts() );
			$this->assertRefused( 'no_staff', fn () => $this->hold( $this->utc( 1, '09:00' ) ) );
		} finally {
			remove_filter( 'reservant/chain/candidates', $emptyOut, 10 );
		}
	}

	public function test_an_archived_service_is_offered_by_neither_path(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'reservant_services', array( 'status' => 'archived' ), array( 'id' => $this->cutId ) );

		// `HoldBooking` has always refused this; availability used to advertise starts for it.
		$this->assertRefused( 'not_found', fn () => $this->starts() );
		$this->assertRefused( 'not_found', fn () => $this->hold( $this->utc( 1, '09:00' ) ) );
	}

	public function test_a_link_row_whose_resource_was_deleted_is_not_a_candidate(): void {
		global $wpdb;
		// There are no FK constraints on `reservant_service_resource` (AGENTS.md section 4), so a row
		// deleted by any path other than the guarded admin route leaves its link behind. The
		// unfiltered pool counted that orphan as a bookable staff member.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}reservant_resources WHERE id = %d", $this->staffB ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$this->assertRefused( 'no_staff', fn () => $this->starts( $this->staffB ) );
		$this->assertRefused( 'no_staff', fn () => $this->hold( $this->utc( 1, '09:00' ), $this->staffB ) );
	}
}
