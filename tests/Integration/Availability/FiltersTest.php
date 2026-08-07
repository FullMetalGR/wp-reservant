<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Availability;

use Reservant\Application\AvailabilityQuery;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\SlotConflict;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * AGENTS.md section 7's two documented but previously unapplied filters:
 * `reservant/chain/candidates` and `reservant/availability/slots`. Both must fire from
 * `AvailabilityQuery::appointmentStarts()` regardless of `$ignoreWindow`, because
 * `AvailabilityController` (public) and `AvailabilityAdminController` (AGENTS.md Task 10) call
 * that one method with different values of that flag and nothing else - a filter reaching only
 * one of them would let an integrator restrict public availability while leaving the admin
 * booking drawer wide open.
 */
final class FiltersTest extends ReservantTestCase {

	private int $serviceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services        = new ServiceRepository( $wpdb );
		$resources       = new ResourceRepository( $wpdb );
		$avail           = new AvailabilityRepository( $wpdb );
		$this->serviceId = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment', 'duration_min' => 30, 'payment_mode' => 'onsite' ) );
		$this->staffId   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/** @return list<SegmentChoice> */
	private function choices(): array {
		return array( new SegmentChoice( $this->serviceId ) );
	}

	public function test_candidates_filter_can_remove_a_resource_from_a_segment(): void {
		global $wpdb;
		$emptyOut = static fn ( array $ids ): array => array();
		add_filter( 'reservant/chain/candidates', $emptyOut, 10, 3 );
		try {
			$this->expectException( SlotConflict::class );
			AvailabilityQuery::make( $wpdb )->appointmentStarts( $this->choices(), $this->utc( 1 ), $this->utc( 2 ), $this->utc( 0 ) );
		} finally {
			remove_filter( 'reservant/chain/candidates', $emptyOut, 10 );
		}
	}

	public function test_slots_filter_can_drop_a_start(): void {
		global $wpdb;
		$unfiltered = AvailabilityQuery::make( $wpdb )->appointmentStarts( $this->choices(), $this->utc( 1 ), $this->utc( 2 ), $this->utc( 0 ) );
		self::assertNotEmpty( $unfiltered );

		$dropAll = static fn (): array => array();
		add_filter( 'reservant/availability/slots', $dropAll );
		try {
			self::assertSame(
				array(),
				AvailabilityQuery::make( $wpdb )->appointmentStarts( $this->choices(), $this->utc( 1 ), $this->utc( 2 ), $this->utc( 0 ) )
			);
		} finally {
			remove_filter( 'reservant/availability/slots', $dropAll );
		}
	}

	/**
	 * The test that matters: `AvailabilityAdminController::index()` calls `appointmentStarts()`
	 * with `$ignoreWindow = true` in exactly this argument position and nothing else on that path
	 * differs, so calling it directly with `true` here exercises precisely the code the admin
	 * endpoint runs - both filters fire the same way whichever caller reached this method.
	 */
	public function test_both_filters_fire_on_the_admin_path_too(): void {
		global $wpdb;
		$seenSlots        = 0;
		$seenCandidates   = 0;
		$countSlots       = static function ( array $starts ) use ( &$seenSlots ): array {
			++$seenSlots;
			return $starts;
		};
		$countCandidates  = static function ( array $ids ) use ( &$seenCandidates ): array {
			++$seenCandidates;
			return $ids;
		};
		add_filter( 'reservant/availability/slots', $countSlots );
		add_filter( 'reservant/chain/candidates', $countCandidates, 10, 3 );
		try {
			AvailabilityQuery::make( $wpdb )->appointmentStarts(
				$this->choices(),
				$this->utc( 1 ),
				$this->utc( 2 ),
				$this->utc( 0 ),
				false,
				true // ignoreWindow: the admin relaxation - see class docblock.
			);
			self::assertSame( 1, $seenSlots );
			self::assertSame( 1, $seenCandidates );
		} finally {
			remove_filter( 'reservant/availability/slots', $countSlots );
			remove_filter( 'reservant/chain/candidates', $countCandidates, 10 );
		}
	}
}
