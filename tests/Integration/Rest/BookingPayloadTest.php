<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Rest\BookingPayload;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The payload is a whitelist, and this is the proof.
 *
 * The rule "contact details require `reservant_manage_bookings`" was implemented twice with
 * opposite defaults: the booking routes forwarded the whole row and unset two columns, the calendar
 * copied permitted columns and forwarded nothing else. The two never disagreed about the columns
 * they named - they disagreed about every column nobody had added yet, and no test could see that,
 * because a test can only assert about fields that exist.
 *
 * So the first test below asserts about the SCHEMA instead. It reads the live column list and
 * requires every column to be classified: emitted, or withheld on purpose. A migration that adds
 * `customer_mobile` fails here, naming the column, before it can ship to a staff-only caller on one
 * surface and be invisible on the other.
 */
final class BookingPayloadTest extends ReservantTestCase {

	/**
	 * Columns that must never reach any caller. `id` is the surrogate key the wire has no use for;
	 * `manage_token_hash` is the stored half of the guest credential (AGENTS.md section 5).
	 */
	private const WITHHELD = array( 'id', 'manage_token_hash' );

	/** The container's id under another name - see `BookingPayload::ITEM`. */
	private const WITHHELD_ITEM = array( 'booking_id' );

	/** @return list<string> */
	private static function columns( string $table ): array {
		global $wpdb;
		/** @var list<string> $names */
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return $names;
	}

	/** @return array<string, mixed> */
	private static function row(): array {
		return array(
			'id'                => 7,
			'uuid'              => 'u-1',
			'status'            => 'confirmed',
			'hold_class'        => null,
			'hold_expires_at'   => null,
			'customer_name'     => 'Maria',
			'customer_email'    => 'maria@example.com',
			'customer_phone'    => '+30 210 0000000',
			'total_minor'       => 4500,
			'currency'          => 'EUR',
			'payment_mode'      => 'onsite',
			'requires_approval' => '0',
			'approved_at'       => null,
			'approved_by'       => null,
			'rejection_reason'  => null,
			'wc_order_id'       => null,
			'manage_token_hash' => 'deadbeef',
			'manage_token'      => 'the-secret',
			'created_at'        => '2026-08-18 10:00:00',
			'updated_at'        => '2026-08-18 10:00:00',
			'items'             => array(
				array(
					'id'                  => 11,
					'booking_id'          => 7,
					'sort'                => 0,
					'service_id'          => 3,
					'resource_id'         => 2,
					'occurrence_id'       => null,
					'start_utc'           => '2026-08-20 09:00:00',
					'end_utc'             => '2026-08-20 09:30:00',
					'block_start_utc'     => '2026-08-20 09:00:00',
					'block_end_utc'       => '2026-08-20 09:30:00',
					'processing_ends_utc' => null,
					'seats'               => 1,
					'seat_claim'          => null,
					'price_minor'         => 4500,
				),
			),
		);
	}

	public function test_every_bookings_column_is_either_emitted_or_withheld_on_purpose(): void {
		$fields     = BookingPayload::fields();
		$classified = array_merge( $fields['container'], $fields['contact'], $fields['optional'], self::WITHHELD, array( 'requires_approval' ) );
		$unclassed  = array_values( array_diff( self::columns( 'reservant_bookings' ), $classified ) );

		self::assertSame(
			array(),
			$unclassed,
			'New column(s) on reservant_bookings that BookingPayload does not classify: '
				. implode( ', ', $unclassed )
				. '. Add each to CONTAINER, CONTACT or OPTIONAL if a caller should see it, or to this '
				. 'test\'s WITHHELD list if nobody should. Leaving it unclassified means it is silently '
				. 'invisible, which is safe but probably not what was intended.'
		);
	}

	public function test_every_booking_items_column_is_either_emitted_or_withheld_on_purpose(): void {
		$unclassed = array_values(
			array_diff( self::columns( 'reservant_booking_items' ), BookingPayload::fields()['item'], self::WITHHELD_ITEM )
		);
		self::assertSame( array(), $unclassed, 'Unclassified booking_items column(s): ' . implode( ', ', $unclassed ) );
	}

	public function test_a_column_nobody_named_never_reaches_the_wire(): void {
		// Whitelist by construction, stated directly: the presenter is handed a row carrying a
		// column it has never heard of, exactly as it would be the day after a migration lands, and
		// the column does not appear in the payload. Under the old blacklist it did.
		$row                     = self::row();
		$row['customer_mobile']  = '+30 690 0000000';
		$row['internal_score']   = 42;
		$row['items'][0]['cost'] = 1;

		$payload = BookingPayload::present( $row, true );
		self::assertArrayNotHasKey( 'customer_mobile', $payload );
		self::assertArrayNotHasKey( 'internal_score', $payload );
		self::assertArrayNotHasKey( 'cost', $payload['items'][0] );
	}

	public function test_the_surrogate_id_and_both_token_columns_never_reach_the_wire(): void {
		$payload = BookingPayload::present( self::row(), true );
		self::assertArrayNotHasKey( 'id', $payload );
		self::assertArrayNotHasKey( 'manage_token_hash', $payload );
		// `HoldBooking` grafts the plaintext token onto the snapshot it returns; only
		// `HoldsController::create()` may put it back, and it does so after presenting.
		self::assertArrayNotHasKey( 'manage_token', $payload );
		self::assertArrayNotHasKey( 'booking_id', $payload['items'][0] );
	}

	public function test_contact_details_are_present_or_absent_as_a_set_never_by_halves(): void {
		$with    = BookingPayload::present( self::row(), true );
		$without = BookingPayload::present( self::row(), false );

		foreach ( BookingPayload::fields()['contact'] as $column ) {
			self::assertArrayHasKey( $column, $with, $column . ' must reach an authorised caller.' );
			self::assertArrayNotHasKey( $column, $without, $column . ' must not reach an unauthorised one.' );
		}
		// And nothing else moves with them: the two payloads differ by the contact set alone.
		self::assertSame(
			BookingPayload::fields()['contact'],
			array_values( array_diff( array_keys( $with ), array_keys( $without ) ) )
		);
	}

	public function test_the_calendars_contact_fields_are_the_same_set_the_booking_routes_use(): void {
		// The calendar builds its own event shape, so the only thing that must not drift is WHICH
		// columns count as contact details. Both surfaces read that from here.
		self::assertSame(
			BookingPayload::fields()['contact'],
			array_keys( BookingPayload::contact( self::row(), true ) )
		);
		self::assertSame( array(), BookingPayload::contact( self::row(), false ) );
	}

	public function test_null_admin_columns_are_omitted_and_populated_ones_are_sent(): void {
		self::assertArrayNotHasKey( 'approved_at', BookingPayload::present( self::row(), true ) );

		$approved                = self::row();
		$approved['approved_at'] = '2026-08-18 11:00:00';
		$approved['approved_by'] = 5;
		$payload                 = BookingPayload::present( $approved, true );
		self::assertSame( '2026-08-18 11:00:00', $payload['approved_at'] );
		self::assertSame( 5, $payload['approved_by'] );
	}

	public function test_requires_approval_is_a_real_boolean_not_the_databases_string(): void {
		self::assertFalse( BookingPayload::present( self::row(), true )['requires_approval'] );

		$gated                      = self::row();
		$gated['requires_approval'] = '1';
		self::assertTrue( BookingPayload::present( $gated, true )['requires_approval'] );
	}

	public function test_item_names_are_emitted_when_the_query_joined_them_and_skipped_when_it_did_not(): void {
		self::assertArrayNotHasKey( 'service_name', BookingPayload::present( self::row(), true )['items'][0] );

		$joined                              = self::row();
		$joined['items'][0]['service_name']  = 'Cut';
		$joined['items'][0]['resource_name'] = 'Alex';
		$item                                = BookingPayload::present( $joined, true )['items'][0];
		self::assertSame( 'Cut', $item['service_name'] );
		self::assertSame( 'Alex', $item['resource_name'] );
	}
}
