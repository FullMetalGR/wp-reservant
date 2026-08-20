<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Licensing;

use Reservant\Licensing\LicenseManager;
use Reservant\Licensing\LicenseState;
use Reservant\Licensing\LicenseStatus;

/**
 * The third-party implementation that has a bad day on a scheduled request.
 *
 * `revalidate()` throws an `\Error` rather than an exception on purpose: `Jobs::licenseRecheck()`
 * catches `\Throwable`, and a catch narrowed to `\RuntimeException` would let a filtered-in
 * validator's TypeError become this plugin's failed action.
 */
final class ExplodingLicenseManager implements LicenseManager {

	public function activate( string $key, \DateTimeImmutable $nowUtc ): LicenseStatus {
		throw new \RuntimeException( 'validator_unreachable' );
	}

	public function deactivate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		throw new \RuntimeException( 'validator_unreachable' );
	}

	public function revalidate( \DateTimeImmutable $nowUtc ): LicenseStatus {
		throw new \Error( 'a third-party fatal, not merely an exception' );
	}

	public function status( \DateTimeImmutable $nowUtc ): LicenseStatus {
		throw new \RuntimeException( 'validator_unreachable' );
	}
}
