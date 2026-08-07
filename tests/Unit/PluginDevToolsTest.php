<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reservant\Plugin;

/**
 * The gate that keeps the demo-data seeder off production sites.
 *
 * `wp reservant fixture` injects demo services, staff and events into the live catalog, which the
 * unauthenticated GET /availability and GET /services/{id} then serve to the public. The gate is a
 * pure function of its two inputs precisely so it can be pinned here with no WordPress bootstrap.
 */
final class PluginDevToolsTest extends TestCase {

	public function test_production_is_refused_when_nothing_overrides_it(): void {
		self::assertFalse( Plugin::devToolsAllowed( 'production', null ) );
	}

	public function test_non_production_environments_are_allowed(): void {
		self::assertTrue( Plugin::devToolsAllowed( 'local', null ) );
		self::assertTrue( Plugin::devToolsAllowed( 'development', null ) );
		self::assertTrue( Plugin::devToolsAllowed( 'staging', null ) );
	}

	/** An unrecognised value is not 'production', so it is allowed - the default only bites live sites. */
	public function test_an_unknown_environment_is_treated_as_non_production(): void {
		self::assertTrue( Plugin::devToolsAllowed( 'whatever', null ) );
	}

	public function test_an_explicit_override_wins_in_both_directions(): void {
		self::assertTrue( Plugin::devToolsAllowed( 'production', true ), 'a staging box self-reporting as production can opt in' );
		self::assertFalse( Plugin::devToolsAllowed( 'local', false ), 'a local site that must never carry demo rows can opt out' );
	}

	/**
	 * The environment string is matched exactly. WordPress only ever returns one of its four known
	 * values, so a near miss like this can only come from a site that set something odd - and
	 * allowing it is the safe direction anyway (it is the CLI half, still behind WP_CLI).
	 */
	public function test_the_production_check_is_exact(): void {
		self::assertTrue( Plugin::devToolsAllowed( 'Production', null ) );
	}
}
