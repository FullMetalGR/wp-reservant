<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Payment;

use Reservant\Application\ConfirmBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Integrations\WooCommerce\OrderObserver;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The RESERVANT half of the order observer: what a settled payment or a dead order does to a
 * booking, driven through `OrderObserver::confirm()`/`::release()` with a uuid - no WooCommerce
 * order anywhere in the loop. Whether WooCommerce actually delivers those uuids on the right hook
 * transitions is the other half's claim, tested against the real thing in
 * `tests/Integration/Integrations/WooCommerce/OrderObserverTest`; see `FakePaymentProvider`'s
 * docblock for why the layers split.
 *
 * The provider installed here is an AVAILABLE fake, deliberately: an available provider is exactly
 * the configuration in which `ConfirmBooking` refuses a plain confirm with
 * `online_payment_required`, so every confirmation below succeeding proves `paymentSettled` - not
 * some accident of provider absence - is what let the money-shaped path through.
 */
final class OrderObserverTest extends ReservantTestCase {

	private int $serviceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->serviceId = $services->insert(
			array(
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$this->staffId   = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->serviceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
	}

	public function test_a_paid_order_confirms_a_pending_checkout_hold(): void {
		$uuid   = $this->pendingHold();
		$errors = $this->countErrors();

		( new OrderObserver() )->confirm( $uuid );

		self::assertSame( 'confirmed', $this->status( $uuid ) );
		self::assertSame( 0, $errors() );
	}

	public function test_a_paid_order_confirms_an_awaiting_payment_booking(): void {
		$uuid = $this->pendingHold();
		$this->forceStatus( $uuid, 'awaiting_payment' );

		( new OrderObserver() )->confirm( $uuid );

		self::assertSame( 'confirmed', $this->status( $uuid ) );
	}

	/**
	 * The widening is the BRIDGE's, not the route's: without `paymentSettled`, `awaiting_payment`
	 * stays unconfirmable exactly as before, or a guest with a manage token could skip the payment
	 * step through `POST /bookings/{uuid}/confirm` (no controller forwards the parameter, and this
	 * pins that the default answers as if none ever will).
	 */
	public function test_without_the_settled_flag_awaiting_payment_still_refuses(): void {
		global $wpdb;
		$uuid = $this->pendingHold();
		$this->forceStatus( $uuid, 'awaiting_payment' );

		$refusal = null;
		try {
			ConfirmBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '00:05' ) );
		} catch ( \RuntimeException $e ) {
			$refusal = $e->getMessage();
		}
		self::assertSame( 'not_confirmable', $refusal );
		self::assertSame( 'awaiting_payment', $this->status( $uuid ) );
	}

	/**
	 * WooCommerce delivers paid transitions more than once in real life (a gateway callback plus an
	 * owner's screen refresh; `processing` then `completed`). The repeat must change nothing, alarm
	 * nobody, and - because `ConfirmBooking` refuses before its hook - email the guest exactly once.
	 */
	public function test_a_repeated_paid_transition_confirms_once_and_emails_once(): void {
		$uuid     = $this->pendingHold();
		$errors   = $this->countErrors();
		$observer = new OrderObserver();
		$emails   = $this->countEmails( 'booking_confirmed' );

		$observer->confirm( $uuid );
		$observer->confirm( $uuid );

		self::assertSame( 'confirmed', $this->status( $uuid ) );
		self::assertSame( 1, $emails(), 'the confirmation email goes out exactly once' );
		self::assertSame( 0, $errors(), 'a repeat delivery is a no-op, not an incident' );
	}

	/**
	 * The hold TTL is the authority, not the payment (AGENTS.md section 6): money landing on a
	 * lapsed hold must NOT seat the customer over whoever holds the slot now - and it must not be
	 * silent either, because that customer has now paid for a slot they do not hold. This is the
	 * boundary between the benign refusals the observer absorbs and the ones it reports.
	 */
	public function test_payment_for_a_lapsed_hold_is_refused_and_reported(): void {
		global $wpdb;
		$uuid   = $this->pendingHold();
		$errors = $this->countErrors();
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'hold_expires_at' => '2020-01-01 00:00:00' ), array( 'uuid' => $uuid ) );

		( new OrderObserver() )->confirm( $uuid );

		self::assertSame( 'pending', $this->status( $uuid ), 'a lapsed hold never confirms' );
		self::assertSame( 1, $errors(), 'the owner must hear that money arrived for a dead slot' );
	}

	public function test_a_dead_order_releases_a_still_held_booking(): void {
		$uuid = $this->pendingHold();

		( new OrderObserver() )->release( $uuid );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
	}

	/** The refund shape: the booking already confirmed, the owner refunds, the slot goes back on sale. */
	public function test_a_dead_order_releases_a_confirmed_booking(): void {
		$uuid     = $this->pendingHold();
		$observer = new OrderObserver();
		$observer->confirm( $uuid );
		self::assertSame( 'confirmed', $this->status( $uuid ) );

		$observer->release( $uuid );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
	}

	public function test_a_repeated_release_is_silent_and_emails_once(): void {
		$uuid     = $this->pendingHold();
		$errors   = $this->countErrors();
		$observer = new OrderObserver();
		$emails   = $this->countEmails( 'booking_cancelled' );

		$observer->release( $uuid );
		$observer->release( $uuid );

		self::assertSame( 'cancelled', $this->status( $uuid ) );
		self::assertSame( 1, $emails() );
		self::assertSame( 0, $errors() );
	}

	/**
	 * An order dying AFTER the sweeper already reaped its hold: the slot is already back on sale,
	 * so there is nothing to release and nothing to report - the desired outcome simply already
	 * holds, by another actor's hand.
	 */
	public function test_releasing_an_already_expired_hold_is_silent(): void {
		$uuid   = $this->pendingHold();
		$errors = $this->countErrors();
		$this->forceStatus( $uuid, 'expired' );

		( new OrderObserver() )->release( $uuid );

		self::assertSame( 'expired', $this->status( $uuid ) );
		self::assertSame( 0, $errors() );
	}

	/** Creates a pending checkout hold on the online service and returns its uuid. */
	private function pendingHold(): string {
		global $wpdb;
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest(
					$this->utc( 1, '10:00' ),
					array( new SegmentChoice( $this->serviceId, $this->staffId ) )
				)
			),
			$this->utc( 0 )
		);
		return (string) $booking['uuid'];
	}

	/**
	 * Force a booking into a status by hand - the same idiom `LifecycleTest` uses for lapsed holds.
	 * `awaiting_payment` is minted for real by `ApproveBooking` these days, but this suite is about
	 * the OBSERVER, and rows placed by hand keep its claims independent of the approval flow's
	 * fixtures (an approval-gated service, a human decision) that producing the status honestly
	 * would drag in. The hold TTL is left as the hold minted it (days in the future for these
	 * fixtures), which is a live payment-link window as far as the guards care.
	 */
	private function forceStatus( string $uuid, string $status ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'reservant_bookings', array( 'status' => $status ), array( 'uuid' => $uuid ) );
	}

	private function status( string $uuid ): string {
		global $wpdb;
		$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertNotNull( $row );
		return (string) $row['status'];
	}

	/** @return callable(): int the number of `reservant/error` firings since the call */
	private function countErrors(): callable {
		$count = 0;
		add_action(
			'reservant/error',
			static function () use ( &$count ): void {
				++$count;
			}
		);
		return static function () use ( &$count ): int {
			return $count;
		};
	}

	/**
	 * Counts attempts to send one email KEY, via that key's own args filter - which only runs when
	 * `Mailer::send()` is really sending. Keyed rather than counting `pre_wp_mail`, because the
	 * real-WC twin of this suite has WooCommerce's own order emails in flight on the same
	 * transitions, and both suites should count the same way.
	 *
	 * @return callable(): int the number of sends of `$key` since the call
	 */
	private function countEmails( string $key ): callable {
		$count = 0;
		add_filter(
			"reservant/email/{$key}/args",
			static function ( $args ) use ( &$count ) {
				++$count;
				return $args;
			}
		);
		return static function () use ( &$count ): int {
			return $count;
		};
	}
}
