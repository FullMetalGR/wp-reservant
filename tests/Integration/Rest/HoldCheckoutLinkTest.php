<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\Payment\FakePaymentProvider;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `POST /holds` and the `checkout_url` it hands a guest who cannot confirm until they have paid
 * (AGENTS.md section 6, non-approval flow).
 *
 * THE PROPERTY UNDER TEST IS AN IDENTITY, not a feature: the link is emitted for exactly the holds
 * whose confirm would answer `402 online_payment_required`, so every case below asserts BOTH halves
 * - what the 201 carried, and what `POST /bookings/{uuid}/confirm` then does. Before this the two
 * disagreed in the worst possible direction: an `online` service that needs no approval refused the
 * confirm and named nowhere to go, so the flow section 6 documents was unreachable by any visitor.
 *
 * The fast half of the two-layer strategy (`Payment\FakePaymentProvider`): everything here is a
 * claim about WHEN a link is offered, which is `HoldsController`'s and `ConfirmBooking`'s to decide
 * and needs no shop in the loop. WHERE the link goes, and that walking it really boards a cart, is
 * the other half's claim (`Integrations/WooCommerce/CartBridgeTest`).
 */
final class HoldCheckoutLinkTest extends ReservantTestCase {

	private int $onlineId;
	private int $onsiteId;
	private int $staffId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$this->onlineId = $services->insert(
			array(
				'name'         => 'Online Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'online',
			)
		);
		$this->onsiteId = $services->insert(
			array(
				'name'         => 'Onsite Cut',
				'type'         => 'appointment',
				'duration_min' => 30,
				'price_minor'  => 2500,
				'payment_mode' => 'onsite',
			)
		);
		$this->staffId  = $resources->insert( array( 'name' => 'Alex' ) );
		foreach ( array( $this->onlineId, $this->onsiteId ) as $serviceId ) {
			$resources->linkService( $serviceId, $this->staffId );
		}
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $this->staffId, new AvailabilityRule( $weekday, '09:00', '18:00' ) );
		}
	}

	/**
	 * The gap this task closed. The 201 names the door, the door carries the guest's own credential,
	 * and the confirm the visitor would otherwise have pressed still refuses - which is precisely
	 * why the link has to be there.
	 */
	public function test_an_online_hold_is_handed_the_checkout_its_confirm_refuses_without(): void {
		$provider = new FakePaymentProvider( true );
		$this->usePaymentProvider( $provider );

		$held = $this->hold( $this->onlineId, '10:00' );

		self::assertArrayHasKey( 'checkout_url', $held, 'an online hold that needs no approval must be told where to pay' );
		self::assertSame( 'https://example.test/checkout', $held['checkout_url'] );
		self::assertSame( 402, $this->confirm( $held )->get_status(), 'the link exists because the confirm refuses' );

		// The provider was handed THIS booking and the guest's RAW manage token - the plaintext
		// credential that exists only inside this one response (AGENTS.md section 5). A link built
		// from anything else could not open the door it points at.
		self::assertCount( 1, $provider->checkoutsAsked );
		self::assertSame(
			array(
				'uuid'  => (string) $held['uuid'],
				'token' => (string) $held['manage_token'],
			),
			$provider->checkoutsAsked[0]
		);
	}

	/** Nothing to pay online, so nowhere to send anybody: the booking confirms where it stands. */
	public function test_an_onsite_hold_is_offered_no_checkout(): void {
		$this->usePaymentProvider( new FakePaymentProvider( true ) );

		$held = $this->hold( $this->onsiteId, '11:00' );

		self::assertArrayNotHasKey( 'checkout_url', $held );
		self::assertSame( 200, $this->confirm( $held )->get_status() );
	}

	/**
	 * The section 6 degrade. With no provider able to take money an `online` service behaves as
	 * `onsite` - `ConfirmBooking` stops refusing - so a payment link would point at a door that
	 * opens onto nothing, and the provider is never even asked.
	 */
	public function test_a_site_with_no_payment_provider_offers_no_checkout_and_simply_confirms(): void {
		$provider = new FakePaymentProvider( false );
		$this->usePaymentProvider( $provider );

		$held = $this->hold( $this->onlineId, '12:00' );

		self::assertArrayNotHasKey( 'checkout_url', $held );
		self::assertSame( 200, $this->confirm( $held )->get_status() );
		self::assertSame( array(), $provider->checkoutsAsked, 'a provider that cannot take money is not asked where to pay' );
	}

	/**
	 * `reservant/allow_direct_confirm` is the bridge's escape hatch: it says this `online` booking
	 * confirms without paying. Sending its guest to a checkout would collect money the site just
	 * said it did not require, so the hatch closes the link as well as the refusal.
	 */
	public function test_the_direct_confirm_hatch_closes_the_checkout_link_too(): void {
		$this->usePaymentProvider( new FakePaymentProvider( true ) );
		add_filter( 'reservant/allow_direct_confirm', '__return_true' );

		$held = $this->hold( $this->onlineId, '13:00' );

		self::assertArrayNotHasKey( 'checkout_url', $held );
		self::assertSame( 200, $this->confirm( $held )->get_status() );
	}

	/**
	 * The provider has the last word on WHERE, and null is an ordinary answer - the shape an
	 * `awaiting_approval` hold takes against real WooCommerce, whose order is created at approval
	 * time. The refusal still stands, so nothing is invented to fill the silence: no key at all
	 * beats a link that leads nowhere.
	 */
	public function test_a_provider_with_nowhere_to_send_the_guest_emits_no_key(): void {
		$this->usePaymentProvider( new FakePaymentProvider( true, 4242, 9001, null ) );

		$held = $this->hold( $this->onlineId, '14:00' );

		self::assertArrayNotHasKey( 'checkout_url', $held );
		self::assertSame( 402, $this->confirm( $held )->get_status() );
	}

	/**
	 * The 201 payload for a hold on one service.
	 *
	 * @return array<string, mixed>
	 */
	private function hold( int $serviceId, string $time ): array {
		$request = new \WP_REST_Request( 'POST', '/reservant/v1/holds' );
		$request->set_body_params(
			array(
				'customer'    => array(
					'name'  => 'Maria',
					'email' => 'maria@example.com',
				),
				'appointment' => array(
					'start_utc' => $this->sql( 1, $time ),
					'segments'  => array(
						array(
							'service_id'  => $serviceId,
							'resource_id' => $this->staffId,
						),
					),
				),
			)
		);
		$response = rest_do_request( $request );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		/** @var array<string, mixed> $data */
		$data = $response->get_data();
		self::assertSame( 'pending', (string) $data['status'] );
		return $data;
	}

	/** @param array<string, mixed> $held */
	private function confirm( array $held ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/reservant/v1/bookings/' . (string) $held['uuid'] . '/confirm' );
		$request->set_param( 'token', (string) $held['manage_token'] );
		return rest_do_request( $request );
	}
}
