<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Payment;

use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\Payment\NullPaymentProvider;
use Reservant\Application\Payment\Providers;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The payment seam as everything outside `Integrations/WooCommerce/` sees it: which provider is
 * resolved, and what `online` means when nothing can take the money.
 *
 * No WooCommerce here on purpose - see `FakePaymentProvider`'s docblock for why the two layers are
 * split. What IS asserted here is the rule with the sharpest failure mode: AGENTS.md section 6 says
 * `online` degrades to `onsite` when no provider is available, and getting that backwards means a
 * site without a payment plugin answers 402 to every guest forever while looking, from the owner's
 * side, like nobody wants to book.
 */
final class PaymentSeamTest extends ReservantTestCase {

	public function test_woocommerce_being_active_resolves_the_woo_provider(): void {
		// The container really does have WooCommerce (see `.wp-env.json`), which is what makes the
		// degrade tests below need a fake rather than an uninstall.
		self::assertTrue( class_exists( 'WooCommerce' ), 'the bridge suite needs WooCommerce active' );
		self::assertTrue( Providers::get()->isAvailable() );
		self::assertInstanceOf( \Reservant\Integrations\WooCommerce\WooPaymentProvider::class, Providers::get() );
	}

	public function test_the_filter_can_replace_the_provider(): void {
		$fake = $this->usePaymentProvider( new FakePaymentProvider() );
		self::assertSame( $fake, Providers::get() );
	}

	/**
	 * A filter is third-party code. Returning a string where a provider was expected must degrade to
	 * the default rather than fatal on every front-end request - the same guard
	 * `Notifications\Mailer` applies to its own filter.
	 */
	public function test_a_filter_returning_rubbish_is_ignored(): void {
		Providers::reset();
		add_filter( 'reservant/payment_provider', static fn () => 'not a provider' );
		self::assertInstanceOf( \Reservant\Application\Payment\PaymentProvider::class, Providers::get() );
	}

	public function test_the_null_provider_answers_every_question_without_throwing(): void {
		$null = new NullPaymentProvider();
		self::assertFalse( $null->isAvailable() );
		self::assertNull( $null->syncService( array( 'payment_mode' => 'online' ) ) );
		self::assertNull( $null->createOrder( array( 'uuid' => 'x' ) ) );
		self::assertNull( $null->paymentUrl( 7 ) );
		$null->flagOrder( 7, 'note' );
		self::assertTrue( true, 'flagOrder() on the null provider is a no-op, not a fatal' );
	}

	/** With a provider available, an online service still refuses a direct confirm - 402. */
	public function test_an_online_booking_refuses_direct_confirm_when_payment_is_possible(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		$hold = $this->onlineHold();

		$refusal = null;
		try {
			ConfirmBooking::make( $wpdb )->execute( (string) $hold['uuid'], $this->utc( 0, '00:05' ) );
		} catch ( \RuntimeException $e ) {
			$refusal = $e->getMessage();
		}
		self::assertSame( 'online_payment_required', $refusal );
	}

	/**
	 * The degrade. With nothing able to take the money, the same booking confirms rather than
	 * stranding the guest behind a 402 no order could ever satisfy.
	 */
	public function test_an_online_booking_confirms_when_no_provider_can_take_the_money(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( false ) );
		$hold = $this->onlineHold();

		$confirmed = ConfirmBooking::make( $wpdb )->execute( (string) $hold['uuid'], $this->utc( 0, '00:05' ) );
		self::assertSame( 'confirmed', $confirmed['status'] );
	}

	/** @return array<string, mixed> */
	private function onlineHold(): array {
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$rules     = new AvailabilityRepository( $wpdb );

		$serviceId  = $services->insert(
			array(
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$resourceId = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $serviceId, $resourceId );
		// Every weekday, so the fixture does not depend on which day the suite runs.
		foreach ( range( 1, 7 ) as $weekday ) {
			$rules->insertRule( $resourceId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}

		return HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Guest', 'guest@example.test', '' ),
				new AppointmentRequest(
					$this->utc( 7, '10:00' ),
					array( new SegmentChoice( $serviceId, $resourceId ) )
				)
			),
			$this->utc( 0 )
		);
	}
}
