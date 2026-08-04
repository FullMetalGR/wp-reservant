<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Db;

use Reservant\Domain\Availability\AvailabilityException;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\OccurrenceRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

final class RepositoriesTest extends ReservantTestCase {

	public function test_service_roundtrip(): void {
		global $wpdb;
		$repo = new ServiceRepository( $wpdb );
		$id   = $repo->insert( array( 'name' => 'Haircut', 'type' => 'appointment', 'duration_min' => 30, 'price_minor' => 2500, 'currency' => 'EUR', 'payment_mode' => 'onsite' ) );
		$row  = $repo->find( $id );
		self::assertNotNull( $row );
		self::assertSame( 'Haircut', $row['name'] );
		self::assertSame( 30, $row['duration_min'] );   // int-cast verified
		self::assertSame( 2500, $row['price_minor'] );
		self::assertNull( $repo->find( 999999 ) );
	}

	public function test_resource_service_links_sorted(): void {
		global $wpdb;
		$resources = new ResourceRepository( $wpdb );
		$services  = new ServiceRepository( $wpdb );
		$serviceId = $services->insert( array( 'name' => 'Cut', 'type' => 'appointment' ) );
		$b         = $resources->insert( array( 'name' => 'Bella' ) );
		$a         = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $serviceId, $b );
		$resources->linkService( $serviceId, $a );
		$resources->linkService( $serviceId, $a ); // duplicate is ignored
		self::assertSame( array( min( $a, $b ), max( $a, $b ) ), $resources->idsForService( $serviceId ) );
	}

	public function test_availability_rules_and_business_wide_exceptions(): void {
		global $wpdb;
		$repo = new AvailabilityRepository( $wpdb );
		$repo->insertRule( 1, new AvailabilityRule( 1, '09:00', '17:00' ) );
		$repo->insertRule( 2, new AvailabilityRule( 2, '10:00', '18:00' ) );
		$repo->insertException( null, new AvailabilityException( '2026-12-25', true ) ); // business-wide
		$repo->insertException( 1, new AvailabilityException( '2026-06-01', true ) );
		$rules = $repo->rulesForResources( array( 1, 2 ) );
		self::assertCount( 1, $rules[1] );
		self::assertSame( '09:00', $rules[1][0]->startTime ); // HH:MM round-trip
		$exceptions = $repo->exceptionsForResources( array( 1, 2 ) );
		self::assertCount( 2, $exceptions[1] ); // own + business-wide
		self::assertCount( 1, $exceptions[2] ); // business-wide only
	}

	public function test_business_wide_exception_appears_once_for_duplicated_requested_id(): void {
		global $wpdb;
		$repo = new AvailabilityRepository( $wpdb );
		$repo->insertException( null, new AvailabilityException( '2026-12-25', true ) ); // business-wide
		$exceptions = $repo->exceptionsForResources( array( 1, 1, 2 ) );
		self::assertCount( 1, $exceptions[1] ); // deduped, not once per duplicate occurrence in the input
		self::assertCount( 1, $exceptions[2] );
	}

	public function test_occurrence_blocking_sums_ignore_expired_holds(): void {
		global $wpdb;
		$occurrences = new OccurrenceRepository( $wpdb );
		$occId       = $occurrences->insert( array( 'service_id' => 1, 'start_utc' => '2026-05-01 18:00:00', 'end_utc' => '2026-05-01 20:00:00', 'capacity' => 40 ) );
		$p           = $wpdb->prefix;
		// A confirmed booking of 3 seats and an EXPIRED-hold booking of 5 seats.
		$wpdb->query( "INSERT INTO {$p}reservant_bookings (uuid, status, created_at, updated_at) VALUES ('u-1', 'confirmed', UTC_TIMESTAMP(), UTC_TIMESTAMP())" );
		$confirmed = (int) $wpdb->insert_id;
		$wpdb->query( "INSERT INTO {$p}reservant_bookings (uuid, status, hold_expires_at, created_at, updated_at) VALUES ('u-2', 'pending', '2020-01-01 00:00:00', UTC_TIMESTAMP(), UTC_TIMESTAMP())" );
		$expired = (int) $wpdb->insert_id;
		foreach ( array( array( $confirmed, 3 ), array( $expired, 5 ) ) as [ $bookingId, $seats ] ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$p}reservant_booking_items (booking_id, service_id, occurrence_id, start_utc, end_utc, block_start_utc, block_end_utc, seats)
				 VALUES (%d, 1, %d, '2026-05-01 18:00:00', '2026-05-01 20:00:00', '2026-05-01 18:00:00', '2026-05-01 20:00:00', %d)",
				$bookingId, $occId, $seats
			) );
		}
		self::assertSame( 3, $occurrences->blockingSeatSum( $occId ) ); // expired hold ignored by time predicate
	}
}
