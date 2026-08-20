<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Licensing;

use Reservant\Infrastructure\Scheduler\Jobs;
use Reservant\Licensing\LicenseManager;
use Reservant\Licensing\LicenseState;
use Reservant\Licensing\LocalKeyLicense;
use Reservant\Licensing\Providers;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The seam a real validator arrives through, and the daily job that drives it.
 *
 * `LocalKeyLicense` is a placeholder for a platform that does not exist yet, so the thing worth
 * proving here is that replacing it costs one filter and nothing else - no caller, no job and no
 * scheduling changes when the vendor is finally chosen.
 */
final class LicenseProvidersTest extends ReservantTestCase {

	public function test_the_default_manager_is_the_local_stub(): void {
		self::assertInstanceOf( LocalKeyLicense::class, Providers::get() );
	}

	public function test_a_site_can_swap_in_its_own_manager(): void {
		$mine = new SpyLicenseManager();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => $mine, 10, 1 );

		self::assertSame( $mine, Providers::get() );
	}

	/** Third-party code returning junk must not fatal the site - the `Payment\Providers` guard. */
	public function test_a_filter_returning_something_else_is_ignored_rather_than_fatal(): void {
		add_filter( 'reservant/license_manager', static fn (): string => 'not a manager', 10, 1 );

		self::assertInstanceOf( LocalKeyLicense::class, Providers::get() );
	}

	public function test_resolution_is_memoized_until_it_is_reset(): void {
		$first = Providers::get();
		self::assertSame( $first, Providers::get() );

		Providers::reset();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => new SpyLicenseManager(), 10, 1 );

		self::assertInstanceOf( SpyLicenseManager::class, Providers::get() );
	}

	public function test_the_recheck_job_is_registered_and_already_scheduled(): void {
		// `Plugin::register()` runs on every `plugins_loaded`, including this process's bootstrap,
		// so the recurring re-check already exists without this test scheduling anything.
		self::assertNotFalse( has_action( Jobs::LICENSE, array( Jobs::class, 'licenseRecheck' ) ) );
		self::assertTrue( as_has_scheduled_action( Jobs::LICENSE, array(), 'reservant' ) );
	}

	public function test_the_job_asks_the_resolved_manager_to_revalidate(): void {
		$spy = new SpyLicenseManager();
		Providers::reset();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => $spy, 10, 1 );

		Jobs::licenseRecheck();

		self::assertSame( 1, $spy->revalidations );
	}

	/**
	 * A failing re-check is an expected, handled condition (it opens the grace window), so this one
	 * job swallows rather than failing its action - and a manager filtered in by a site is
	 * third-party code, so the catch is `\Throwable`. The exception still reaches
	 * `reservant/error`, the documented channel for swallowed failures.
	 */
	public function test_a_manager_that_explodes_does_not_fail_the_scheduled_action(): void {
		$seen = array();
		add_action( 'reservant/error', static function ( \Throwable $e, string $context = '' ) use ( &$seen ): void {
			$seen[] = $context;
		}, 10, 2 );

		Providers::reset();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => new ExplodingLicenseManager(), 10, 1 );

		Jobs::licenseRecheck();

		self::assertContains( 'license_recheck', $seen, 'a swallowed failure must still be visible to an operator' );
	}

	/** End to end through the seam callers actually use, with nothing faked but the key check. */
	public function test_the_job_moves_a_real_license_through_the_state_machine(): void {
		Providers::reset();
		add_filter( 'reservant/license_manager', static fn (): LicenseManager => new LocalKeyLicense( true ), 10, 1 );

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		Providers::get()->activate( 'RSVT-JOBS-0000-ABCD', $now );

		Jobs::licenseRecheck();

		self::assertSame( LicenseState::Active, Providers::get()->status( $now )->state );
	}
}
