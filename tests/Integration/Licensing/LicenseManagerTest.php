<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Licensing;

use Reservant\Licensing\LicenseState;
use Reservant\Licensing\LocalKeyLicense;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The licensing state machine driven end to end against the real `reservant_license` option row.
 *
 * Only one thing about `LocalKeyLicense` is a stub - the answer to "is this key genuine" - so
 * everything these cases exercise (storage, domain binding, the grace window, the refusal to
 * rebind) is production code that a remote validator will drive unchanged.
 *
 * The two constructor arguments are how a validator's CHANGE OF MIND is staged without a network:
 * `new LocalKeyLicense( true )` accepts any non-empty key, `new LocalKeyLicense( false )` accepts
 * only the built-in one. Activating with the first and re-checking with the second is exactly what
 * an outage looks like from inside this plugin - the same stored license, in front of a validator
 * that has stopped saying yes.
 */
final class LicenseManagerTest extends ReservantTestCase {

	private const OPTION = 'reservant_license';
	private const KEY    = 'RSVT-TEST-1234-WXYZ';

	private function accepting(): LocalKeyLicense {
		return new LocalKeyLicense( true );
	}

	private function refusing(): LocalKeyLicense {
		return new LocalKeyLicense( false );
	}

	private function instant( string $iso ): \DateTimeImmutable {
		return new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Moves the whole site, the way a migration or a staging clone does. Returns the callback so a
	 * test that needs to move BACK can remove exactly its own filter rather than every listener on
	 * `home_url`.
	 */
	private function pretendSiteIs( string $url ): callable {
		$mover = static fn (): string => $url;
		add_filter( 'home_url', $mover, 10, 1 );
		return $mover;
	}

	private function thisSitesDomain(): string {
		return \Reservant\Licensing\SiteDomain::current();
	}

	public function test_a_good_key_activates_and_binds_itself_to_this_site(): void {
		$status = $this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		self::assertSame( LicenseState::Active, $status->state );
		self::assertTrue( $status->isActive() );
		self::assertSame( $this->thisSitesDomain(), $status->domain );
		self::assertSame( '2026-01-01 09:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '********WXYZ', $status->maskedKey );
	}

	/** What `activate()` returns is what a later `status()` says - no caller has to read back. */
	public function test_the_status_a_call_returns_is_the_status_that_was_stored(): void {
		$activated = $this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$read      = $this->accepting()->status( $this->instant( '2026-01-02 09:00:00' ) );

		self::assertSame( $activated->state, $read->state );
		self::assertSame( $activated->maskedKey, $read->maskedKey );
		self::assertSame( $activated->domain, $read->domain );
		self::assertEquals( $activated->lastCheckedAt, $read->lastCheckedAt );
	}

	public function test_the_stored_row_is_its_own_option_and_not_part_of_the_settings(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		self::assertIsArray( get_option( self::OPTION ) );
		self::assertFalse( get_option( 'reservant_settings' ), 'the license must not live in the row the admin SPA is handed' );
	}

	public function test_a_key_the_validator_refuses_lands_on_invalid_rather_than_throwing(): void {
		$status = $this->refusing()->activate( 'NOT-A-REAL-KEY-0000', $this->instant( '2026-01-01 09:00:00' ) );

		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertNull( $status->lastCheckedAt );
		self::assertSame( LicenseState::Invalid, $this->refusing()->status( $this->instant( '2026-01-01 09:00:01' ) )->state );
	}

	/**
	 * A blank field is not an activation attempt, so it writes nothing at all. Treated as a refusal
	 * it would store an empty row - and an empty row reads as `Inactive`, so a stray form post would
	 * silently unlicense a paying site.
	 */
	public function test_an_empty_submit_cannot_unlicense_a_site(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		/** @var array<string, mixed> $before */
		$before = get_option( self::OPTION );

		$status = $this->accepting()->activate( '   ', $this->instant( '2026-01-02 09:00:00' ) );

		self::assertSame( LicenseState::Active, $status->state );
		self::assertSame( $before, get_option( self::OPTION ), 'nothing was submitted, so nothing changed' );
	}

	/** On a site with no license it is equally inert - no key means no row, not an invalid one. */
	public function test_an_empty_submit_on_an_unlicensed_site_writes_nothing(): void {
		self::assertSame( LicenseState::Inactive, $this->accepting()->activate( '', $this->instant( '2026-01-01 09:00:00' ) )->state );
		self::assertFalse( get_option( self::OPTION ) );
	}

	/**
	 * Activation REPLACES. The cost is real - a bad paste over a working key loses the working key -
	 * and it is still the right answer: silently keeping the old license would make "I pasted the
	 * wrong key" and "it worked" look identical on screen.
	 */
	public function test_activating_a_bad_key_over_a_good_one_replaces_it_rather_than_lying(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		$status = $this->refusing()->activate( 'TYPO-TYPO-TYPO-TYPO', $this->instant( '2026-01-02 09:00:00' ) );

		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertSame( LicenseState::Invalid, $this->refusing()->status( $this->instant( '2026-01-02 09:00:01' ) )->state );
	}

	public function test_deactivating_forgets_the_row_entirely(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		$status = $this->accepting()->deactivate( $this->instant( '2026-01-03 09:00:00' ) );

		self::assertSame( LicenseState::Inactive, $status->state );
		self::assertSame( '', $status->maskedKey );
		self::assertFalse( get_option( self::OPTION ), 'nothing left to remember, so nothing is kept' );
	}

	/** An owner who cannot deactivate is an owner who cannot move their own site. */
	public function test_deactivating_a_site_that_was_never_activated_still_succeeds(): void {
		self::assertSame( LicenseState::Inactive, $this->refusing()->deactivate( $this->instant( '2026-01-01 09:00:00' ) )->state );
	}

	public function test_a_license_activated_elsewhere_does_not_cover_this_site(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$boundTo = $this->thisSitesDomain();

		$this->pretendSiteIs( 'https://staging.example.org' );

		$status = $this->accepting()->status( $this->instant( '2026-01-02 09:00:00' ) );
		self::assertSame( LicenseState::DomainMismatch, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertSame( $boundTo, $status->domain, 'the status names the domain the key belongs to' );
	}

	/**
	 * The refusal to rebind is the entire point of binding. A re-check on the clone must not adopt
	 * the clone's domain, or one production license would silently cover every copy of the database.
	 */
	public function test_a_recheck_on_the_wrong_domain_never_rebinds_the_license(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		/** @var array<string, mixed> $before */
		$before = get_option( self::OPTION );

		$this->pretendSiteIs( 'https://clone.example.org' );
		$status = $this->accepting()->revalidate( $this->instant( '2026-01-02 09:00:00' ) );

		self::assertSame( LicenseState::DomainMismatch, $status->state );
		self::assertSame( $before, get_option( self::OPTION ), 'the clone must not rewrite the real site\'s record either' );
	}

	/** Moving back is all it takes - the row was never touched, so nothing needs repairing. */
	public function test_returning_to_the_bound_domain_restores_the_license(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$home = home_url();

		$mover = $this->pretendSiteIs( 'https://clone.example.org' );
		self::assertSame( LicenseState::DomainMismatch, $this->accepting()->status( $this->instant( '2026-01-02 09:00:00' ) )->state );

		remove_filter( 'home_url', $mover, 10 );
		self::assertSame( $home, home_url(), 'the site is back where it started' );
		self::assertSame( LicenseState::Active, $this->accepting()->status( $this->instant( '2026-01-02 09:00:00' ) )->state );
	}

	public function test_a_failing_recheck_does_not_unlicense_a_site_that_was_paying(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		$status = $this->refusing()->revalidate( $this->instant( '2026-02-01 03:00:00' ) );

		self::assertSame( LicenseState::Grace, $status->state );
		self::assertTrue( $status->isActive() );
		self::assertSame( '2026-02-15 03:00:00', $status->graceEndsAt?->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-01-01 09:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ), 'a failure is not a check' );
	}

	public function test_a_validator_that_starts_answering_again_ends_the_grace_window(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$this->refusing()->revalidate( $this->instant( '2026-02-01 03:00:00' ) );

		$status = $this->accepting()->revalidate( $this->instant( '2026-02-03 03:00:00' ) );

		self::assertSame( LicenseState::Active, $status->state );
		self::assertNull( $status->graceEndsAt );
		self::assertSame( '2026-02-03 03:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_a_grace_window_that_runs_out_finally_does_invalidate(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$this->refusing()->revalidate( $this->instant( '2026-02-01 03:00:00' ) );

		self::assertSame( LicenseState::Grace, $this->refusing()->status( $this->instant( '2026-02-14 03:00:00' ) )->state );

		$status = $this->refusing()->status( $this->instant( '2026-02-15 03:00:00' ) );
		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertFalse( $status->isActive() );
	}

	/**
	 * Grace expiry is derived from the stored start, not written by a job, so it happens on schedule
	 * on a site whose Action Scheduler queue has not run since the day the failures began.
	 */
	public function test_grace_expires_by_the_clock_even_if_no_further_check_ever_runs(): void {
		$this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		$this->refusing()->revalidate( $this->instant( '2026-02-01 03:00:00' ) );
		/** @var array<string, mixed> $frozen */
		$frozen = get_option( self::OPTION );

		self::assertSame( LicenseState::Invalid, $this->refusing()->status( $this->instant( '2026-06-01 00:00:00' ) )->state );
		self::assertSame( $frozen, get_option( self::OPTION ), 'reading a status never writes one' );
	}

	public function test_reading_the_status_of_an_unlicensed_site_writes_nothing(): void {
		self::assertFalse( get_option( self::OPTION ) );

		self::assertSame( LicenseState::Inactive, $this->accepting()->status( $this->instant( '2026-01-01 09:00:00' ) )->state );

		self::assertFalse( get_option( self::OPTION ), 'status() must not create the row it is reading' );
	}

	/** Nothing to ask, and nobody to ask it of. */
	public function test_rechecking_an_unlicensed_site_is_a_no_op(): void {
		self::assertSame( LicenseState::Inactive, $this->accepting()->revalidate( $this->instant( '2026-01-01 09:00:00' ) )->state );
		self::assertFalse( get_option( self::OPTION ) );
	}

	/**
	 * A row no in-repo writer could have produced - and the one degradation that matters most: an
	 * owner who has paid must still be able to reach Active, and activating is itself a read of this
	 * row. A read that threw would lock them out of the only screen that could fix it.
	 */
	public function test_a_corrupt_row_degrades_to_unlicensed_and_leaves_the_owner_able_to_activate(): void {
		update_option( self::OPTION, 'not-an-array-at-all', false );
		self::assertSame( LicenseState::Inactive, $this->accepting()->status( $this->instant( '2026-01-01 09:00:00' ) )->state );

		update_option(
			self::OPTION,
			array(
				'key'           => array( 'nonsense' ),
				'grace_started' => 'tuesday',
			),
			false
		);
		self::assertSame( LicenseState::Inactive, $this->accepting()->status( $this->instant( '2026-01-01 09:00:00' ) )->state );

		$status = $this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );
		self::assertSame( LicenseState::Active, $status->state, 'recovery is one activation away, not a database repair' );
	}

	/** A corrupt row must not become an accidental license either. */
	public function test_a_corrupt_row_is_never_read_as_active(): void {
		update_option(
			self::OPTION,
			array(
				'key'      => '',
				'rejected' => false,
				'domain'   => $this->thisSitesDomain(),
			),
			false
		);

		self::assertFalse( $this->accepting()->status( $this->instant( '2026-01-01 09:00:00' ) )->isActive() );
	}

	/** The stored row holds the plaintext because a validator has to re-send it; the status never does. */
	public function test_the_plaintext_key_never_reaches_the_status_object(): void {
		$status = $this->accepting()->activate( self::KEY, $this->instant( '2026-01-01 09:00:00' ) );

		self::assertStringNotContainsString( self::KEY, $status->maskedKey );
		self::assertStringNotContainsString( 'RSVT-TEST', $status->maskedKey );
		self::assertSame( 'WXYZ', substr( $status->maskedKey, -4 ) );
	}
}
