<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Frontend;

use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The editor bundle's dependency contract (Task 17): every handle in `build/editor.asset.php`
 * must be a script WordPress actually registers.
 *
 * `bin/widget-contract.mjs` guards the WIDGET's handles with an allowlist, but no gate checked
 * the EDITOR's (`Frontend\Block` reads the file to register the handle - it validates nothing) -
 * and the failure that invites is silent: a deep import
 * (`@wordpress/blocks/build-module/...`) externalises to a handle core never registers
 * (`wp-blocks/build-module/api/registration`), and `WP_Dependencies::all_deps()` then drops the
 * whole editor script without an error - the block simply stops working in the editor (newer
 * cores add a `_doing_it_wrong` notice; older ones say nothing at all).
 *
 * An allowlist is the wrong shape here: the editor entry may legitimately grow into
 * `wp-components` and whatever else the editor offers, and the real question - "does core
 * register this handle?" - is one only WordPress can answer. So this gate lives in the
 * integration suite: `wp_scripts()` fires `wp_default_scripts` on construction, which registers
 * every core package handle regardless of request context, and the assertion asks that registry
 * directly.
 */
final class EditorAssetContractTest extends ReservantTestCase {

	public function test_every_editor_dependency_is_a_handle_core_registers(): void {
		// A missing asset file is a FAILURE, never a pass (the widget-contract precedent: an
		// unbuilt widget is a failure, not a pass). CI builds before this suite runs - the
		// integration job's `npm run build` precedes `wp-env start` and `composer
		// test:integration` (.github/workflows/ci.yml) - so by the time this executes, an
		// absent build/editor.asset.php means the build broke or was skipped.
		$assetFile = RESERVANT_PATH . 'build/editor.asset.php';
		self::assertFileExists(
			$assetFile,
			'build/editor.asset.php is missing - run `npm run build` first; an unbuilt editor bundle is a failure, not a pass'
		);

		/** @var array{dependencies?: mixed, version?: string} $asset */
		$asset = include $assetFile;
		self::assertIsArray( $asset );
		self::assertArrayHasKey( 'dependencies', $asset );
		$handles = $asset['dependencies'];
		self::assertIsArray( $handles );
		// The editor entry always imports @wordpress/blocks at minimum, so an empty list means
		// the file no longer carries what this test thinks it carries - refuse to pass on it.
		self::assertNotEmpty( $handles, 'zero dependency handles - the editor entry always externalises at least wp-blocks, so the build output no longer has the expected shape' );

		foreach ( $handles as $handle ) {
			self::assertIsString( $handle );
			self::assertTrue(
				wp_script_is( $handle, 'registered' ),
				"build/editor.asset.php depends on '{$handle}', which WordPress does not register - a deep @wordpress/* import externalises to a bogus handle and WP_Dependencies::all_deps() then drops the whole editor script silently"
			);
		}
	}
}
