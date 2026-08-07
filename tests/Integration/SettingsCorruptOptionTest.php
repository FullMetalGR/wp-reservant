<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration;

use Reservant\Settings;

/**
 * Recovery from a `reservant_settings` row no in-repo writer could have produced.
 *
 * `Settings::make()` used to validate on read and throw, which made a single bad field fatal on
 * three unrelated paths at once: every public POST /holds (`Rest\HoldsController` catches only
 * SlotConflict and RuntimeException, and InvalidArgumentException is neither), the whole admin SPA
 * (`Admin\AdminPage` reads the currency while building its bootstrap config), and GET
 * /admin/settings. That left no UI route and no REST route back to a good value.
 *
 * Reading is now lenient per field while writing stays strict, so each test here asserts both
 * halves: the bad field degrades to its default, and the good fields beside it survive untouched.
 */
final class SettingsCorruptOptionTest extends ReservantTestCase {

	/**
	 * Writes a row directly, bypassing `Settings::update()` - which is the point, since `update()`
	 * is exactly what cannot produce these shapes.
	 *
	 * @param array<string, mixed> $values
	 */
	private function storeRaw( array $values ): void {
		update_option( 'reservant_settings', $values, false );
	}

	/** A valid row with one field replaced, so the assertions can prove the rest came through. */
	private function goodRowExcept( string $key, mixed $badValue ): void {
		$this->storeRaw(
			array_merge(
				array(
					'currency'           => 'USD',
					'checkout_ttl_min'   => 30,
					'approval_ttl_hours' => 72,
					'payment_ttl_hours'  => 12,
					'purge_on_uninstall' => true,
				),
				array( $key => $badValue )
			)
		);
	}

	public function testStringIntegerFallsBackToDefaultWithoutDiscardingItsNeighbours(): void {
		$this->goodRowExcept( 'checkout_ttl_min', '15' );

		$settings = Settings::make();
		self::assertSame( 15, $settings->checkoutTtlMin(), 'a numeric string is malformed, so the default stands' );
		self::assertSame( 'USD', $settings->currency() );
		self::assertSame( 72, $settings->approvalTtlHours() );
		self::assertSame( 12, $settings->paymentTtlHours() );
		self::assertTrue( $settings->purgeOnUninstall() );
	}

	public function testLowercaseCurrencyFallsBackToDefaultWithoutDiscardingItsNeighbours(): void {
		$this->goodRowExcept( 'currency', 'eur' );

		$settings = Settings::make();
		self::assertSame( 'EUR', $settings->currency() );
		self::assertSame( 30, $settings->checkoutTtlMin() );
		self::assertSame( 72, $settings->approvalTtlHours() );
	}

	public function testNullCurrencyFallsBackToDefaultWithoutDiscardingItsNeighbours(): void {
		$this->goodRowExcept( 'currency', null );

		$settings = Settings::make();
		self::assertSame( 'EUR', $settings->currency() );
		self::assertSame( 30, $settings->checkoutTtlMin() );
	}

	public function testZeroTtlFallsBackToDefaultWithoutDiscardingItsNeighbours(): void {
		$this->goodRowExcept( 'checkout_ttl_min', 0 );

		$settings = Settings::make();
		self::assertSame( 15, $settings->checkoutTtlMin() );
		self::assertSame( 'USD', $settings->currency() );
	}

	public function testNegativeAndNonScalarTtlsFallBackPerField(): void {
		$this->storeRaw(
			array(
				'currency'           => 'GBP',
				'checkout_ttl_min'   => -5,
				'approval_ttl_hours' => array( 'nope' ),
				'payment_ttl_hours'  => 12,
				'purge_on_uninstall' => false,
			)
		);

		$settings = Settings::make();
		self::assertSame( 'GBP', $settings->currency(), 'the one good string field is kept' );
		self::assertSame( 15, $settings->checkoutTtlMin() );
		self::assertSame( 48, $settings->approvalTtlHours() );
		self::assertSame( 12, $settings->paymentTtlHours(), 'the one good int field is kept' );
	}

	public function testNonBooleanPurgeFlagFallsBackToFalse(): void {
		$this->goodRowExcept( 'purge_on_uninstall', 'yes' );

		self::assertFalse( Settings::make()->purgeOnUninstall(), 'uninstall data loss must never be inferred from a junk value' );
	}

	public function testScalarOptionInsteadOfArrayYieldsAllDefaults(): void {
		update_option( 'reservant_settings', 'not-an-array', false );

		self::assertSame(
			array(
				'currency'           => 'EUR',
				'checkout_ttl_min'   => 15,
				'approval_ttl_hours' => 48,
				'payment_ttl_hours'  => 24,
				'purge_on_uninstall' => false,
			),
			Settings::make()->toArray()
		);
	}

	public function testUnknownKeysAreDropped(): void {
		$this->storeRaw(
			array(
				'currency' => 'USD',
				'wat'      => 'stray',
			)
		);

		self::assertSame(
			array( 'currency', 'checkout_ttl_min', 'approval_ttl_hours', 'payment_ttl_hours', 'purge_on_uninstall' ),
			array_keys( Settings::make()->toArray() )
		);
	}

	/**
	 * The lenient read must not soften the write. A corrupt row is recoverable precisely because
	 * the settings screen still loads on top of it and can still reject a bad replacement.
	 */
	public function testWritingStaysStrictOnTopOfACorruptRow(): void {
		$this->goodRowExcept( 'currency', 'eur' );

		$this->expectException( \InvalidArgumentException::class );
		Settings::make()->update( array( 'checkout_ttl_min' => 0 ) );
	}

	public function testUpdateOverACorruptRowPersistsTheRepairedValues(): void {
		$this->goodRowExcept( 'currency', 'eur' );

		Settings::make()->update( array( 'currency' => 'CHF' ) );

		$settings = Settings::make();
		self::assertSame( 'CHF', $settings->currency() );
		self::assertSame( 30, $settings->checkoutTtlMin(), 'the fields that were fine are written back as they were' );
		self::assertSame( 72, $settings->approvalTtlHours() );
	}
}
