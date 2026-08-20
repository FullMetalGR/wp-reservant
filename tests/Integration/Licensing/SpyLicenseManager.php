<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Licensing;

use Reservant\Licensing\LicenseManager;
use Reservant\Licensing\LicenseState;
use Reservant\Licensing\LicenseStatus;

/**
 * Records what it was asked, and answers `Inactive` to everything - the licensing counterpart of
 * `tests/Integration/Payment/FakePaymentProvider.php`. Installed through the real
 * `reservant/license_manager` filter, so a test using it exercises the same seam a site would.
 */
final class SpyLicenseManager implements LicenseManager {

	public int $revalidations = 0;

	public function activate( string $key, \DateTimeImmutable $nowUtc ): LicenseStatus {
		return $this->nothing();
	}

	public function deactivate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		return $this->nothing();
	}

	public function revalidate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		++$this->revalidations;
		return $this->nothing();
	}

	public function status( \DateTimeImmutable $nowUtc ): LicenseStatus {
		return $this->nothing();
	}

	private function nothing(): LicenseStatus {
		return new LicenseStatus( LicenseState::Inactive, '', '', null, null );
	}
}
