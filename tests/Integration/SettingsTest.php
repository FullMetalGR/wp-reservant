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
				'currency'            => 'EUR',
				'checkout_ttl_min'    => 15,
				'approval_ttl_hours'  => 48,
				'payment_ttl_hours'   => 24,
				'purge_on_uninstall'  => false,
				'reminder_lead_hours' => 24,
				'emails_off'          => array(),
			),
			$settings->toArray()
		);
	}

	public function testUpdatePersistsAndRoundTrips(): void {
		Settings::make()->update( array( 'currency' => 'USD' ) );
		self::assertSame( 'USD', Settings::make()->currency() );
	}

	public function testReminderLeadHoursAcceptsZeroAsTheOffSwitch(): void {
		// The one field where zero is an answer rather than a malformed value: a reminder scheduled
		// for the appointment's own start time would be a notification about something already
		// happening, so "no reminders" is what zero means.
		self::assertSame( 0, Settings::make()->update( array( 'reminder_lead_hours' => 0 ) )->reminderLeadHours() );
	}

	public function testUpdateRejectsANegativeReminderLeadTime(): void {
		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'reminder_lead_hours' => -1 ) );
	}

	public function testEmailsOffRoundTripsTheKeysItIsGiven(): void {
		self::assertSame(
			array( 'booking_reminder', 'approval_nag' ),
			Settings::make()->update( array( 'emails_off' => array( 'booking_reminder', 'approval_nag' ) ) )->emailsOff()
		);
	}

	/**
	 * A switch for a message that does not exist is a typo, and storing it would leave the owner
	 * believing they had turned something off.
	 */
	public function testUpdateRejectsASwitchForAnEmailThisPluginDoesNotSend(): void {
		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'emails_off' => array( 'booking_confirmed', 'no_such_email' ) ) );
	}

	public function testUpdateRejectsANonListEmailsOff(): void {
		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'emails_off' => 'booking_reminder' ) );
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
