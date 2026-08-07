<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Reservant\Application\Dto\BookingSnapshot;

final class BookingSnapshotTest extends TestCase {

	/** A BookingRepository::findByUuid()-shaped row: ints already cast, DB booleans as '0'/'1' strings. */
	private function row(): array {
		return array(
			'id'                => 7,
			'uuid'              => 'a1b2c3',
			'status'            => 'pending',
			'hold_class'        => 'checkout',
			'hold_expires_at'   => '2026-08-05 12:00:00',
			'customer_name'     => 'Maria',
			'customer_email'    => 'maria@example.com',
			'customer_phone'    => '+30123456789',
			'total_minor'       => 2000,
			'currency'          => 'EUR',
			'payment_mode'      => 'onsite',
			'requires_approval' => '1', // DB row shape: string, not bool.
			'items'             => array(
				array(
					'id'         => 1,
					'service_id' => 3,
				),
			),
			'rejection_reason'  => null,
		);
	}

	public function test_from_array_round_trips_through_to_array_on_a_full_row(): void {
		$row      = $this->row();
		$snapshot = BookingSnapshot::fromArray( $row );

		self::assertSame( 7, $snapshot->id );
		self::assertSame( 'a1b2c3', $snapshot->uuid );
		self::assertSame( 'pending', $snapshot->status );
		self::assertSame( 'checkout', $snapshot->holdClass );
		self::assertSame( '2026-08-05 12:00:00', $snapshot->holdExpiresAt );
		self::assertSame( 'Maria', $snapshot->customerName );
		self::assertSame( 'maria@example.com', $snapshot->customerEmail );
		self::assertSame( '+30123456789', $snapshot->customerPhone );
		self::assertSame( 2000, $snapshot->totalMinor );
		self::assertSame( 'EUR', $snapshot->currency );
		self::assertSame( 'onsite', $snapshot->paymentMode );
		self::assertTrue( $snapshot->requiresApproval ); // '1' normalised to bool.
		self::assertSame(
			array(
				array(
					'id'         => 1,
					'service_id' => 3,
				),
			),
			$snapshot->items
		);
		self::assertNull( $snapshot->rejectionReason );

		// toArray() matches the row shape verbatim, except requires_approval is normalised to
		// bool (fromArray's job), never round-tripped back to the DB's raw '1' string.
		$expected                      = $row;
		$expected['requires_approval'] = true;
		self::assertSame( $expected, $snapshot->toArray() );
	}

	public function test_from_array_normalises_requires_approval_zero_string(): void {
		$row                      = $this->row();
		$row['requires_approval'] = '0';
		self::assertFalse( BookingSnapshot::fromArray( $row )->requiresApproval );
	}

	public function test_from_array_carries_a_rejection_reason_when_present(): void {
		$row                     = $this->row();
		$row['rejection_reason'] = 'no_show_history';
		self::assertSame( 'no_show_history', BookingSnapshot::fromArray( $row )->rejectionReason );
	}

	public function test_from_array_tolerates_a_completely_empty_row(): void {
		$snapshot = BookingSnapshot::fromArray( array() );

		self::assertSame( 0, $snapshot->id );
		self::assertSame( '', $snapshot->uuid );
		self::assertSame( '', $snapshot->status );
		self::assertNull( $snapshot->holdClass );
		self::assertNull( $snapshot->holdExpiresAt );
		self::assertSame( '', $snapshot->customerName );
		self::assertSame( '', $snapshot->customerEmail );
		self::assertSame( '', $snapshot->customerPhone );
		self::assertSame( 0, $snapshot->totalMinor );
		self::assertSame( '', $snapshot->currency );
		self::assertSame( '', $snapshot->paymentMode );
		self::assertFalse( $snapshot->requiresApproval );
		self::assertSame( array(), $snapshot->items );
		self::assertNull( $snapshot->rejectionReason );
	}

	public function test_from_array_tolerates_partial_rows_missing_some_keys(): void {
		$snapshot = BookingSnapshot::fromArray(
			array(
				'uuid'   => 'only-uuid',
				'status' => 'confirmed',
			)
		);

		self::assertSame( 'only-uuid', $snapshot->uuid );
		self::assertSame( 'confirmed', $snapshot->status );
		self::assertSame( 0, $snapshot->id );
		self::assertSame( array(), $snapshot->items );
	}
}
