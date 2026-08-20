<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Application;

use Reservant\Application\ApproveBooking;
use Reservant\Application\CancelBooking;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Application\Payment\PaymentProvider;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Integrations\WooCommerce\OrderObserver;
use Reservant\Tests\Integration\Payment\FakePaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The cancel -> flag half of the bridge: AGENTS.md section 1 promises that refunds are "flagged,
 * never automatic", and this is the test that the flag actually happens. Driven through
 * `CancelBooking` with a fake provider - the fast layer of P7's two-part strategy, the same one
 * `ApprovePaymentTest` uses; what a REAL WooCommerce order note looks like is
 * `WooPaymentProvider::flagOrder()`'s business, not this suite's.
 *
 * The claim under test is deliberately narrow, because the note is deliberately narrow: the owner
 * learns that the booking is gone and which booking it was, and nothing else happens. No refund is
 * issued, no customer is told one is coming, and a provider that fails while being told must not
 * disturb a cancellation that has already committed.
 */
final class CancelPaymentTest extends ReservantTestCase {

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
		$this->staffId = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $this->onlineServiceId, $this->staffId );
		$resources->linkService( $this->onsiteServiceId, $this->staffId );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}
	}

	/**
	 * One booking, one order, one note - and the note names the booking, because an order note the
	 * owner cannot trace back to a reservation tells them nothing they can act on.
	 */
	public function test_a_cancelled_booking_leaves_its_order_a_note_for_the_owner(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001 ) );
		$uuid = $this->approvedOnlineUuid( '09:00' );

		$cancelled = CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '02:00' ) );

		self::assertSame( 'cancelled', (string) $cancelled['status'] );
		self::assertCount( 1, $fake->flagged, 'exactly one note, on exactly one cancellation' );
		self::assertSame( 9001, $fake->flagged[0]['id'], 'the order this booking was paying, and no other' );
		self::assertStringContainsString( $uuid, $fake->flagged[0]['note'] );
	}

	/**
	 * The money question is the owner's, so the note may state the fact and must not make the
	 * promise (AGENTS.md section 6: the plugin never issues a refund by itself in v1).
	 */
	public function test_the_note_reports_the_release_without_promising_a_refund(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001 ) );
		$uuid = $this->approvedOnlineUuid( '09:00' );

		CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '02:00' ) );

		$note = $fake->flagged[0]['note'];
		self::assertStringContainsString( 'cancelled', $note );
		self::assertStringContainsString( 'released', $note );
		self::assertStringContainsString( 'No refund has been issued', $note );
	}

	/**
	 * A paid booking is the case the note exists for - the customer's money is already in the
	 * shop - and it is still only a note.
	 */
	public function test_a_paid_booking_flags_the_order_that_paid_for_it(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001 ) );
		$uuid = $this->approvedOnlineUuid( '09:00' );
		( new OrderObserver() )->confirm( $uuid );
		self::assertSame( 'confirmed', (string) ( new BookingRepository( $wpdb ) )->findByUuid( $uuid )['status'] );

		CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '02:00' ) );

		self::assertCount( 1, $fake->flagged );
		self::assertSame( 9001, $fake->flagged[0]['id'] );
	}

	/**
	 * Most bookings never had an order at all - free and onsite services never get one - and there
	 * is nothing to annotate. The guard is on the booking's `wc_order_id`, not on whether a provider
	 * happens to be installed, which is why this runs with a live one.
	 */
	public function test_a_cancelled_booking_with_no_order_flags_nothing(): void {
		global $wpdb;
		$fake = $this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001 ) );
		$uuid = $this->heldUuid( $this->onsiteServiceId, '11:00' );
		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		self::assertSame( 'confirmed', (string) $approved['status'] );
		self::assertNull( $approved['wc_order_id'] );

		CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '02:00' ) );

		self::assertSame( array(), $fake->flagged );
	}

	/**
	 * The note is post-commit, so its failure may not un-cancel the booking. The seat IS released -
	 * that is the fact of record, already audited and already announced to the customer - and a
	 * WooCommerce fault only earns a `reservant/error` an operator can read.
	 */
	public function test_a_failing_flag_leaves_the_cancellation_standing(): void {
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
					return 9001;
				}
				public function paymentUrl( int $orderId ): ?string {
					return "https://example.test/pay/{$orderId}";
				}
				/** @param array<string, mixed> $booking */
				public function checkoutUrl( array $booking, string $manageToken ): ?string {
					return null;
				}
				public function flagOrder( int $orderId, string $note ): void {
					// What `wc_get_order()` reaching a broken shop looks like from out here.
					throw new \RuntimeException( 'order_note_failed' );
				}
			}
		);
		$uuid = $this->approvedOnlineUuid( '09:00' );

		$errors = 0;
		add_action(
			'reservant/error',
			static function () use ( &$errors ): void {
				++$errors;
			}
		);

		$cancelled = CancelBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '02:00' ) );

		self::assertSame( 'cancelled', (string) $cancelled['status'] );
		self::assertSame( 'cancelled', (string) ( new BookingRepository( $wpdb ) )->findByUuid( $uuid )['status'] );
		self::assertSame( 1, $errors, 'the lost note must be visible to an operator' );

		// And the seat really went back on sale: the same slot holds again for somebody else.
		$rival = HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Nikos', 'nikos@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $this->onlineServiceId, $this->staffId ) ) )
			),
			$this->utc( 0, '03:00' )
		);
		self::assertSame( 'awaiting_approval', (string) $rival['status'] );
	}

	/** Approves an online hold, so the booking carries the order the provider just created. */
	private function approvedOnlineUuid( string $time ): string {
		global $wpdb;
		$uuid     = $this->heldUuid( $this->onlineServiceId, $time );
		$approved = ApproveBooking::make( $wpdb )->execute( $uuid, $this->utc( 0, '01:00' ), 'admin' );
		self::assertSame( 'awaiting_payment', (string) $approved['status'] );
		self::assertSame( 9001, (int) $approved['wc_order_id'] );
		return $uuid;
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
