<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\ApproveBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\BookingSnapshot;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\ExpireHolds;
use Reservant\Application\HoldBooking;
use Reservant\Application\Payment\PaymentProvider;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Integrations\WooCommerce\OrderObserver;
use Reservant\Settings;
use Reservant\Tests\Integration\Payment\FakePaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The approve -> payment half of the bridge (AGENTS.md section 6: "On approve, create the order and
 * email the payment link. The booking moves to `awaiting_payment` with its own TTL"), driven
 * through `ApproveBooking` with a fake provider - the fast layer of P7's two-part strategy. What
 * the REAL WooCommerce order looks like is `Integrations/WooCommerce/ApprovalPaymentTest`'s claim.
 *
 * The free/onsite landings are already pinned by `ApprovalTest` and stay untouched: those fixtures
 * are `payment_mode = 'onsite'`, and approving them must keep landing `confirmed` no matter what
 * provider is installed - which `test_an_onsite_approval_still_confirms_and_orders_nothing` also
 * asserts here with a LIVE provider, so the branch is proven to key on the booking and not on the
 * provider alone.
 */
final class ApprovePaymentTest extends ReservantTestCase {

	private int $onlineServiceId;
	private int $onsiteServiceId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->onlineServiceId = $services->insert(
			array(
				'name'                => 'Online Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'online',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->onsiteServiceId = $services->insert(
			array(
				'name'                => 'Onsite Consultation',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 3000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$this->staffId         = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->onlineServiceId, $this->staffId );
		$resources->linkService( $this->onsiteServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	public function test_an_online_approval_lands_awaiting_payment_with_the_payment_ttl(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'awaiting_payment', $approved['status'] );
		self::assertSame( 'payment', $approved['hold_class'] );
		// The default `payment_ttl_hours` is 24: approved at utc(0, 01:00), the link dies a day later.
		self::assertSame( $this->sql( 1, '01:00' ), (string) $approved['hold_expires_at'] );
		self::assertSame( $this->sql( 0, '01:00' ), (string) $approved['approved_at'] );
	}

	public function test_the_payment_ttl_setting_is_honoured(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		Settings::make()->update( array( 'payment_ttl_hours' => 6 ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( $this->sql( 0, '07:00' ), (string) $approved['hold_expires_at'] );
	}

	public function test_the_order_is_created_recorded_and_announced_with_its_payment_url(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001 ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$announced = array();
		$listener  = static function ( BookingSnapshot $snapshot, string $url ) use ( &$announced ): void {
			$announced[] = array(
				'uuid'   => $snapshot->uuid,
				'status' => $snapshot->status,
				'url'    => $url,
			);
		};
		add_action( 'reservant/booking/payment_due', $listener, 10, 2 );
		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		remove_action( 'reservant/booking/payment_due', $listener, 10 );

		// One order per booking, and the booking handed to the provider is the POST-transition row.
		self::assertCount( 1, $fake->ordered );
		self::assertSame( $uuid, (string) $fake->ordered[0]['uuid'] );
		self::assertSame( 'awaiting_payment', (string) $fake->ordered[0]['status'] );

		// Recorded on the row, and grafted onto the returned snapshot so the approve response
		// reports the order it just created.
		self::assertSame( 9001, (int) $approved['wc_order_id'] );
		$row = ( new BookingRepository( $wpdb ) )->findByUuid( $uuid );
		self::assertSame( 9001, (int) $row['wc_order_id'] );

		// The hook fires after the order exists, carrying the provider's checkout URL.
		self::assertSame(
			array(
				array(
					'uuid'   => $uuid,
					'status' => 'awaiting_payment',
					'url'    => 'https://example.test/pay/9001',
				),
			),
			$announced
		);
	}

	/** The `approved` hook still announces the decision - listeners beyond the mailer rely on it. */
	public function test_the_approved_hook_fires_with_the_awaiting_payment_snapshot(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$notified = array();
		$listener = static function ( BookingSnapshot $snapshot ) use ( &$notified ): void {
			$notified[] = $snapshot->status;
		};
		add_action( 'reservant/booking/approved', $listener );
		ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		remove_action( 'reservant/booking/approved', $listener );

		self::assertSame( array( 'awaiting_payment' ), $notified );
	}

	/**
	 * The section 6 degrade: with nothing able to take money, an online approval must complete as
	 * `confirmed` rather than stranding the guest in a state nobody could ever pay out of.
	 */
	public function test_an_online_approval_confirms_when_no_provider_can_take_the_money(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( false ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'confirmed', $approved['status'] );
		self::assertNull( $approved['hold_expires_at'] );
		self::assertSame( array(), $fake->ordered, 'no order may be created on the degrade path' );
	}

	/** The branch keys on the BOOKING's payment mode, not merely on a provider being installed. */
	public function test_an_onsite_approval_still_confirms_and_orders_nothing(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true ) );
		$uuid = $this->heldUuid( $this->onsiteServiceId, '11:00' );

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'confirmed', $approved['status'] );
		self::assertNull( $approved['hold_expires_at'] );
		self::assertSame( array(), $fake->ordered );
	}

	/**
	 * Order creation is post-commit, so its failure must not un-approve the booking: the row stays
	 * `awaiting_payment`, the failure is reported on `reservant/error`, no payment link is
	 * announced, and the payment TTL is what reclaims the seat (the same sweep
	 * `test_an_unpaid_payment_hold_lapses_and_frees_the_slot` proves).
	 */
	public function test_a_failed_order_leaves_the_booking_awaiting_payment_and_reports(): void {
		global $wpdb;
		$this->usePaymentProvider(
			new class() implements PaymentProvider {
				public function isAvailable(): bool {
					return true;
				}
				/** @param array<string, mixed> $service */
				public function syncService( array $service ): ?int {
					return null;
				}
				/** @param array<string, mixed> $booking */
				public function createOrder( array $booking ): ?int {
					// What `WooPaymentProvider::createOrder()` does when WooCommerce refuses.
					throw new \RuntimeException( 'order_create_failed' );
				}
				public function paymentUrl( int $orderId ): ?string {
					return null;
				}
				/** @param array<string, mixed> $booking */
				public function checkoutUrl( array $booking, string $manageToken ): ?string {
					return null;
				}
				public function flagOrder( int $orderId, string $note ): void {
				}
			}
		);
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$errors = 0;
		add_action(
			'reservant/error',
			static function () use ( &$errors ): void {
				++$errors;
			}
		);
		$due = 0;
		add_action(
			'reservant/booking/payment_due',
			static function () use ( &$due ): void {
				++$due;
			}
		);

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'awaiting_payment', $approved['status'] );
		self::assertSame( 1, $errors, 'the lost order must be visible to an operator' );
		self::assertSame( 0, $due, 'no link exists, so none may be announced' );
		self::assertNull( ( new BookingRepository( $wpdb ) )->findByUuid( $uuid )['wc_order_id'] );
	}

	/**
	 * A provider that claims availability and then answers null from `createOrder()` (the
	 * interface's "cannot create orders at all") is handled exactly like a throw - the booking is
	 * already committed, so the only honest moves are the report and the TTL.
	 */
	public function test_a_null_order_is_handled_like_a_failed_one(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true, 4242, null ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );

		$errors = 0;
		add_action(
			'reservant/error',
			static function () use ( &$errors ): void {
				++$errors;
			}
		);

		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		self::assertSame( 'awaiting_payment', $approved['status'] );
		self::assertSame( 1, $errors );
	}

	/**
	 * The P7.4 carve-out: payment TTL elapsed -> release the seat. No new sweeper - `awaiting_payment`
	 * is a held status and `BookingRepository::expiredHeldIds()` already selects it, so the ordinary
	 * five-minute sweep reclaims the slot, and a rival can then hold the very same time.
	 */
	public function test_an_unpaid_payment_hold_lapses_and_frees_the_slot(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );
		ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		// The guest never pays: the window lapses (backdated by hand, the LifecycleTest idiom).
		$wpdb->update(
			$wpdb->prefix . 'reservant_bookings',
			array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'uuid' => $uuid )
		);

		self::assertSame( 1, ExpireHolds::make( $wpdb )->run() );
		self::assertSame( 'expired', (string) ( new BookingRepository( $wpdb ) )->findByUuid( $uuid )['status'] );

		// The seat is genuinely back on sale: the same slot holds again for somebody else.
		$rival = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Nikos', 'nikos@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->onlineServiceId, $this->staffId ) ) )
			),
			$this->utc( 0, '02:00' )
		);
		self::assertSame( 'awaiting_approval', (string) $rival['status'] );
	}

	/**
	 * The dovetail with P7.4: paying the order confirms the booking through `OrderObserver` and
	 * `ConfirmBooking::execute(..., paymentSettled: true)` - this use case owns no confirm path of
	 * its own, so the whole loop is approve -> awaiting_payment -> (money) -> confirmed.
	 */
	public function test_a_settled_payment_confirms_the_awaiting_payment_booking(): void {
		global $wpdb;
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		$uuid = $this->heldUuid( $this->onlineServiceId, '09:00' );
		ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );

		( new OrderObserver() )->confirm( $uuid );

		self::assertSame( 'confirmed', (string) ( new BookingRepository( $wpdb ) )->findByUuid( $uuid )['status'] );
	}

	/** Holds one appointment on the given service and answers its uuid. */
	private function heldUuid( int $serviceId, string $time ): string {
		global $wpdb;
		$booking = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, $time ), array( new SegmentChoice( $serviceId, $this->staffId ) ) )
			),
			$this->utc( 0 )
		);
		self::assertSame( 'awaiting_approval', (string) $booking['status'] );
		return (string) $booking['uuid'];
	}
}
