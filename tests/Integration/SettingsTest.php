<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration;

use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Cli\FixtureCommand;
use Reservant\Settings;

final class SettingsTest extends ReservantTestCase {

	// `reservant_settings` starts clean every test - `ReservantTestCase::set_up()`'s own doc block
	// explains why this has to be more than the table truncation just above it in that method.

	public function testDefaultsWithNoOptionRow(): void {
		$settings = Settings::make();
		self::assertSame( 'EUR', $settings->currency() );
		self::assertSame( 15, $settings->checkoutTtlMin() );
		self::assertSame( 48, $settings->approvalTtlHours() );
		self::assertSame( 24, $settings->paymentTtlHours() );
		self::assertFalse( $settings->purgeOnUninstall() );
		self::assertSame(
			array(
				'currency'           => 'EUR',
				'checkout_ttl_min'   => 15,
				'approval_ttl_hours' => 48,
				'payment_ttl_hours'  => 24,
				'purge_on_uninstall' => false,
			),
			$settings->toArray()
		);
	}

	public function testUpdatePersistsAndRoundTrips(): void {
		Settings::make()->update( array( 'currency' => 'USD' ) );
		self::assertSame( 'USD', Settings::make()->currency() );
	}

	public function testUpdateRejectsBadCurrency(): void {
		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'currency' => 'eu' ) );
	}

	public function testUpdateRejectsNonPositiveCheckoutTtl(): void {
		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'checkout_ttl_min' => 0 ) );
	}

	public function testHoldUsesSettingsTtl(): void {
		Settings::make()->update( array( 'checkout_ttl_min' => 30 ) );
		$ids  = FixtureCommand::ensure( $GLOBALS['wpdb'] );
		// $this->utc(0) is deliberately >= a week ahead of the real wall clock (see its docblock),
		// and HoldBooking::anchor() takes max($nowUtc, wall-clock) - so the injected $now, not
		// time(), is the actual anchor hold_expires_at is measured from.
		$now  = $this->utc( 0 );
		$hold = HoldBooking::make( $GLOBALS['wpdb'] )->execute(
			new HoldRequest(
				new Customer( 'T', 't@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( (int) $ids['cut'] ) ) )
			),
			$now
		);
		$expires = new \DateTimeImmutable( $hold['hold_expires_at'], new \DateTimeZone( 'UTC' ) );
		$mins    = ( $expires->getTimestamp() - $now->getTimestamp() ) / 60;
		self::assertEqualsWithDelta( 30, $mins, 2 );
	}
}
