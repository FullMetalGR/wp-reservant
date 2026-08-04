<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase {
	public function test_autoloader_resolves_plugin_class(): void {
		self::assertTrue( class_exists( \Reservant\Plugin::class ) );
	}
}
