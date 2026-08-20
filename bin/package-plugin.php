#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * P8 packaging: builds `reservant-<version>.zip` at the repository root, laid out the way
 * wp-admin's "Add Plugin -> Upload" expects it - ONE top-level `reservant/` directory holding the
 * runtime and nothing else. Upload that file and the plugin activates; there is no other step.
 *
 * Four things make this more than `zip -r reservant.zip .`:
 *
 * 1. THE DEVELOPER'S `vendor/` IS NOT THE SHIPPED ONE, AND MUST SURVIVE. This repository's
 *    `vendor/` holds phpunit, phpstan, phpcs, wpcs and the polyfills, and four of the CI gates in
 *    AGENTS.md section 8 (`composer lint`, `composer stan`, `composer test:unit`,
 *    `composer test:integration`) run straight out of it. A `composer install --no-dev` in this
 *    directory would delete every one of them and break the repository for whoever pulls next -
 *    the packaging step is the last place that should cost somebody an afternoon. So the
 *    production tree is installed into a STAGING COPY with an explicit `--working-dir`, and this
 *    script asserts afterwards that `vendor/bin/phpunit` is still where it found it rather than
 *    trusting itself not to have wandered.
 *
 * 2. `vendor/` AND `build/` ARE BOTH GITIGNORED, so neither is guaranteed to exist and neither can
 *    be assumed current. They are PRODUCED here: `npm run build` runs first (see below), and the
 *    shipped dependency tree is installed from `composer.lock` with `--no-dev
 *    --optimize-autoloader`, so the zip is reproducible from the lock file rather than from
 *    whatever the last developer happened to have on disk.
 *
 * 3. THE BUILD IS RUN, NOT REQUIRED. The alternative - document `npm run build` as a prerequisite
 *    and package whatever is in `build/` - fails silently and invisibly: a zip carrying last
 *    week's `widget.js` looks exactly like a correct one, installs cleanly, and misbehaves on a
 *    customer's site with nothing to point at. Nobody re-reads a prerequisite, and a stale bundle
 *    leaves no trace in the artefact. The build is deterministic and cheap, so this takes the
 *    decision away instead of writing it down. There is deliberately no `--skip-build` escape
 *    hatch: it would reintroduce exactly the failure it is here to prevent.
 *
 * 4. THE OUTPUT IS VERIFIED, NOT TRUSTED. A packaging script is trusted by definition - nobody
 *    unzips the artefact to check - so one that can cheerfully emit a zip that fatals on
 *    activation is worse than no script at all. Both `require` statements in `reservant.php` are
 *    checked against the staged tree, every asset the three enqueuers name is checked present and
 *    non-empty, the staged autoloader is asked to resolve a real class in a subprocess, the root
 *    of the archive is compared against an exact manifest, and the finished zip is re-opened and
 *    walked before it is allowed near the repository root. Any one of those failing means no zip
 *    is written at all.
 *
 * Everything is assembled under a temporary staging directory that is removed on the way out,
 * including on failure and including on a fatal, and the finished archive is renamed into place
 * only once it has passed inspection - so a run that dies half way leaves the previous zip, or no
 * zip, but never half of one.
 *
 * Requires `composer` and `npm` on PATH, and `npm install` already run.
 *
 * Usage: composer package   (or: php bin/package-plugin.php)
 * Exit:  0 the zip was written and verified, 1 something was wrong and nothing was written.
 */

/**
 * The plugin's shipped top level, exactly. This is a MANIFEST, not a filter: the staging copy
 * takes these entries and nothing else, which is why no exclusion list of tracked-but-unshipped
 * paths (AGENTS.md, tests/, assets/, bin/, docs/, the dotfiles, the tool configs) has
 * to be maintained anywhere - a file ships because it is named here or because Composer put it in
 * `vendor/`, and there is no third way in.
 *
 * `build/` and `vendor/` are produced first and copied last; the rest are tracked files.
 * `templates/` and `languages/` from the AGENTS.md section 3 layout do not exist yet - when the
 * first one does, it ships by being added here, and the root check below is what will notice.
 */
const SHIPPED = array(
	'README.md',
	'build',
	'composer.json',
	'composer.lock',
	'reservant.php',
	'src',
	'uninstall.php',
	'vendor',
);

/**
 * Files that must exist and carry bytes in the staged tree, each one because something breaks
 * loudly on a customer's site without it:
 *
 * - the two `require` targets in `reservant.php` (lines 28 and 35). The autoloader is unguarded,
 *   so a zip without it is a white screen the moment WordPress activates the plugin; Action
 *   Scheduler is behind a `file_exists()` guard, so its absence is silent instead - and every
 *   reminder, hold-expiry sweep and approval nag simply never fires (AGENTS.md section 7:
 *   scheduling is Action Scheduler only, never bare wp_cron).
 * - every asset the three enqueuers name: `Admin\AdminPage` (admin.*), `Frontend\Assets`
 *   (widget.*, style-widget.css) and `Frontend\Block` (editor.*). The RTL twins are in the list
 *   because both stylesheets are registered with `wp_style_add_data( ..., 'rtl', 'replace' )`,
 *   which makes WordPress swap the whole sheet on an RTL locale - a missing twin is an unstyled
 *   widget for those visitors and nobody else, which is precisely the kind of bug that ships.
 * - `src/Plugin.php`, the container the bootstrap calls into.
 */
const MUST_CARRY_BYTES = array(
	'reservant.php',
	'uninstall.php',
	'README.md',
	'composer.json',
	'composer.lock',
	'src/Plugin.php',
	'vendor/autoload.php',
	'vendor/woocommerce/action-scheduler/action-scheduler.php',
	'build/admin.js',
	'build/admin.asset.php',
	'build/admin.css',
	'build/admin-rtl.css',
	'build/widget.js',
	'build/widget.asset.php',
	'build/style-widget.css',
	'build/style-widget-rtl.css',
	'build/editor.js',
	'build/editor.asset.php',
);

/**
 * Names that may not appear anywhere in the archive, at any depth. The manifest above already
 * makes it impossible for the repository's own unshipped files to get in, so this can only ever
 * fire on something Composer dragged along - which is the point: it is the check that keeps
 * working after somebody adds a dependency. Entries are limited to things no production
 * dependency has any business shipping, so a hit is a real finding rather than a false alarm to
 * be trained out of. Directory names that ARE legitimate inside a vendor tree (`bin`, `docs`,
 * `tests`) are deliberately absent here and covered at the root by SHIPPED instead.
 */
const NEVER_SHIPPED = array( '.git', '.github', 'node_modules', '.DS_Store', 'AGENTS.md' );

/** Extensions that are never part of a plugin's runtime: a zip inside the zip, or a screenshot. */
const NEVER_SHIPPED_EXTENSIONS = array( 'zip', 'png' );

$root = dirname( __DIR__ );
chdir( $root );

$version   = read_agreed_version( $root . '/reservant.php' );
$finalZip  = $root . '/reservant-' . $version . '.zip';
$partial   = $finalZip . '.part';
$staging   = make_staging_dir();
$tree      = $staging . '/reservant';
$devVendor = $root . '/vendor/bin/phpunit';
$hadDev    = is_file( $devVendor );

// Registered before anything is created: `fail()` exits, an uncaught error exits, and both run
// shutdown functions, so this is the one cleanup path that cannot be skipped. The half-written
// archive goes with it - `$partial` only ever becomes the real zip by an atomic rename, so an
// interrupted run leaves the previous zip untouched rather than a truncated replacement.
register_shutdown_function(
	static function () use ( $staging, $partial ): void {
		remove_tree( $staging );
		if ( is_file( $partial ) ) {
			unlink( $partial );
		}
	}
);

fwrite( STDOUT, "package-plugin: reservant {$version}\n" );

// ---------------------------------------------------------------------------------------------
// 1. The bundles. Point 3 in the header: run, never require.
// ---------------------------------------------------------------------------------------------
run_or_fail( 'npm run build', 'the asset build failed - `npm run build` is what fills build/, and a zip without it is a plugin with no UI' );

// ---------------------------------------------------------------------------------------------
// 2. The staged tree: the manifest, then the production dependency install on top of it.
// ---------------------------------------------------------------------------------------------
if ( ! mkdir( $tree ) ) {
	fail( "cannot create the staging tree at {$tree}." );
}

foreach ( SHIPPED as $entry ) {
	// vendor/ is Composer's to write in the next step; everything else is copied from here.
	if ( 'vendor' === $entry ) {
		continue;
	}

	$source = $root . '/' . $entry;

	if ( ! file_exists( $source ) ) {
		fail( "{$entry} is missing from the repository root, and it is on the shipped manifest." );
	}

	copy_into_tree( $source, $tree . '/' . $entry );
}

// `--optimize-autoloader` builds a classmap over the whole PSR-4 root, so `src/` has to be in
// place before this runs (it is - the loop above just put it there). `--no-dev` is the entire
// reason for the staging copy: run here, it installs the shipped tree; run in $root, it would
// delete the toolchain four CI gates depend on. `--working-dir` is what keeps the two apart.
run_or_fail(
	'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir=' . escapeshellarg( $tree ),
	'the production dependency install failed - without vendor/autoload.php the plugin fatals on activation'
);

// Paranoia with a reason: `--working-dir` is one typo away from being the repository root, and the
// damage would be invisible until somebody ran a gate. Assert it, do not assume it.
if ( $hadDev && ! is_file( $devVendor ) ) {
	fail( "the development vendor/ tree lost vendor/bin/phpunit during packaging - the --no-dev install escaped the staging directory. Run `composer install` in {$root} to repair it." );
}

verify_tree( $tree );

// ---------------------------------------------------------------------------------------------
// 3. The archive, written in staging and inspected before it is allowed anywhere near $root.
// ---------------------------------------------------------------------------------------------
$stagedZip = $staging . '/reservant-' . $version . '.zip';
$written   = write_zip( $tree, $stagedZip );
verify_zip( $stagedZip, $written );

// ---------------------------------------------------------------------------------------------
// 4. Publication: copy in, then rename over the old one. `rename()` inside the same directory is
// atomic, so a reader either sees the previous zip or the new one, never a partial file - and
// because the archive was created fresh in staging, re-running can never append to the zip that
// is already there (a zip appended to is how deleted files get shipped for months).
// ---------------------------------------------------------------------------------------------
if ( ! copy( $stagedZip, $partial ) ) {
	fail( "cannot write {$partial} - check the permissions on the repository root." );
}

if ( ! rename( $partial, $finalZip ) ) {
	fail( "cannot move the finished archive into place at {$finalZip}." );
}

fwrite(
	STDOUT,
	sprintf(
		"package-plugin: %s (%d files, %s bytes) - upload it at Plugins -> Add Plugin -> Upload Plugin.\n",
		basename( $finalZip ),
		count( $written ),
		number_format( (int) filesize( $finalZip ), 0, '.', ',' )
	)
);

exit( 0 );

// =============================================================================================
// Helpers.
// =============================================================================================

/**
 * Stops the run. Nothing partial survives it: the shutdown function registered above clears the
 * staging directory and any half-copied archive on the way out.
 */
function fail( string $message ): never {
	fwrite( STDERR, "package-plugin: {$message}\n" );
	exit( 1 );
}

/**
 * The version, read from `reservant.php` and required to agree with itself.
 *
 * It lives in two places - the `Version:` plugin header WordPress reads, and the
 * `RESERVANT_VERSION` constant the migration runner compares against on every load (AGENTS.md
 * section 4) - and the two drifting apart is a real bug with real consequences: WordPress would
 * show one number while the schema upgrade fired on the other. Packaging is the last moment
 * anybody looks, so it is the right moment to refuse. Reading rather than taking an argument for
 * the same reason: a zip whose name disagrees with the plugin inside it is a support ticket.
 */
function read_agreed_version( string $pluginFile ): string {
	$source = file_get_contents( $pluginFile );

	if ( false === $source ) {
		fail( "cannot read {$pluginFile}." );
	}

	if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', (string) $source, $header ) ) {
		fail( 'no `* Version:` line in reservant.php - WordPress reads the plugin version from that header and refusing to guess it is the only safe answer.' );
	}

	if ( ! preg_match( "/define\(\s*'RESERVANT_VERSION',\s*'([^']*)'\s*\)/", (string) $source, $constant ) ) {
		fail( "no `define( 'RESERVANT_VERSION', ... )` in reservant.php - the migration runner keys on that constant." );
	}

	if ( $header[1] !== $constant[1] ) {
		fail( "reservant.php disagrees with itself: the plugin header says {$header[1]}, RESERVANT_VERSION says {$constant[1]}. WordPress would show one and the migration runner would act on the other. Fix both before packaging." );
	}

	if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $header[1] ) ) {
		fail( "\"{$header[1]}\" is not a version this script will put in a filename." );
	}

	return $header[1];
}

/** A private staging root under the system temp directory - never inside the repository. */
function make_staging_dir(): string {
	$path = sys_get_temp_dir() . '/reservant-package-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );

	if ( ! mkdir( $path, 0700 ) ) {
		fail( "cannot create a staging directory at {$path}." );
	}

	return $path;
}

/** Recursive delete, used only on the staging directory this script created. */
function remove_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $entries as $entry ) {
		/** @var SplFileInfo $entry */
		if ( $entry->isDir() ) {
			rmdir( $entry->getPathname() );
		} else {
			unlink( $entry->getPathname() );
		}
	}

	rmdir( $path );
}

/** Copies one manifest entry - a file or a whole directory - into the staged tree. */
function copy_into_tree( string $source, string $destination ): void {
	if ( is_file( $source ) ) {
		if ( ! copy( $source, $destination ) ) {
			fail( "cannot copy {$source} into the staging tree." );
		}

		return;
	}

	if ( ! mkdir( $destination, 0755, true ) ) {
		fail( "cannot create {$destination} in the staging tree." );
	}

	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $entries as $entry ) {
		/** @var SplFileInfo $entry */
		$target = $destination . '/' . substr( $entry->getPathname(), strlen( $source ) + 1 );

		if ( $entry->isDir() ) {
			if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
				fail( "cannot create {$target} in the staging tree." );
			}
		} elseif ( ! copy( $entry->getPathname(), $target ) ) {
			fail( "cannot copy {$entry->getPathname()} into the staging tree." );
		}
	}
}

/**
 * Runs a subcommand with its output on the terminal, and stops the packaging run if it failed.
 * The output stays visible deliberately - when a build or an install breaks, its own error message
 * is the useful one, and swallowing it to print something tidier helps nobody.
 */
function run_or_fail( string $command, string $why ): void {
	fwrite( STDOUT, "package-plugin: {$command}\n" );
	passthru( $command, $status );

	if ( 0 !== $status ) {
		fail( "`{$command}` exited {$status} - {$why}." );
	}
}

/**
 * Everything that can be checked about the staged tree before it becomes an archive: the exact
 * shipped root, the files whose absence breaks a customer's site, nothing that should never ship,
 * and an autoloader that actually resolves.
 */
function verify_tree( string $tree ): void {
	$actual = scandir( $tree );

	if ( false === $actual ) {
		fail( "cannot read the staged tree at {$tree}." );
	}

	$actual = array_values( array_diff( $actual, array( '.', '..' ) ) );
	sort( $actual );
	$expected = SHIPPED;
	sort( $expected );

	if ( $actual !== $expected ) {
		$extra   = array_diff( $actual, $expected );
		$missing = array_diff( $expected, $actual );
		fail(
			'the staged plugin root is not the shipped manifest'
			. ( array() === $extra ? '' : ' (unexpected: ' . implode( ', ', $extra ) . ')' )
			. ( array() === $missing ? '' : ' (missing: ' . implode( ', ', $missing ) . ')' )
			. '.'
		);
	}

	foreach ( MUST_CARRY_BYTES as $relative ) {
		$path = $tree . '/' . $relative;

		if ( ! is_file( $path ) ) {
			fail( "{$relative} is missing from the staged tree - see the MUST_CARRY_BYTES note for what breaks without it." );
		}

		if ( 0 === filesize( $path ) ) {
			fail( "{$relative} is empty in the staged tree, which is as broken as missing and harder to see." );
		}
	}

	foreach ( walk( $tree ) as $relative ) {
		reject_forbidden( $relative, 'the staged tree' );
	}

	// The strongest available statement that the shipped tree boots: hand the staged autoloader to
	// a fresh interpreter and make it resolve a class out of `src/`. It catches an optimised
	// classmap that missed the PSR-4 root, a truncated copy, and a Composer platform check that
	// this PHP would fail - none of which a file listing can see. No WordPress is involved:
	// `Plugin` only defines methods at include time. The path travels in the environment rather
	// than interpolated into the code string, so no staging path can ever be read as PHP.
	$output = array();
	$probe  = 'require getenv( \'RESERVANT_STAGED_AUTOLOAD\' ); exit( class_exists( \'Reservant\\Plugin\' ) ? 0 : 1 );';
	putenv( 'RESERVANT_STAGED_AUTOLOAD=' . $tree . '/vendor/autoload.php' );
	exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $probe ) . ' 2>&1', $output, $status );
	putenv( 'RESERVANT_STAGED_AUTOLOAD' );

	if ( 0 !== $status ) {
		fail( 'the staged autoloader cannot resolve Reservant\\Plugin: ' . trim( implode( ' ', $output ) ) . ' - the shipped tree would fatal on activation.' );
	}

	fwrite( STDOUT, 'package-plugin: staged tree verified - ' . implode( ', ', $expected ) . "\n" );
}

/**
 * Re-opens the finished archive and walks it. Written and verified are separate acts on purpose:
 * ZipArchive reports its failures at close time, and this is what turns "the writer said it was
 * fine" into "the file on disk says so".
 *
 * @param list<string> $written Relative paths this run put in, for the count comparison.
 */
function verify_zip( string $path, array $written ): void {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) {
		fail( "the archive this run just wrote does not open cleanly at {$path}." );
	}

	if ( count( $written ) !== $zip->numFiles ) {
		fail( sprintf( 'the archive holds %d entries but %d files were staged.', $zip->numFiles, count( $written ) ) );
	}

	$roots   = array();
	$present = array();

	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = (string) $zip->getNameIndex( $index );

		// WordPress installs a plugin by unzipping into wp-content/plugins/ and taking the single
		// top-level directory as the plugin folder. Two of them, or files at the archive root, and
		// the upload either fails or installs something with the wrong folder name - which then
		// updates and deactivates as a stranger.
		$roots[ explode( '/', $name )[0] ] = true;

		if ( ! str_starts_with( $name, 'reservant/' ) ) {
			fail( "the archive contains {$name}, which is outside reservant/." );
		}

		$relative = substr( $name, strlen( 'reservant/' ) );
		reject_forbidden( $relative, 'the archive' );
		$present[ $relative ] = true;
	}

	if ( array( 'reservant' ) !== array_keys( $roots ) ) {
		fail( 'the archive has more than one top-level directory: ' . implode( ', ', array_keys( $roots ) ) . '.' );
	}

	foreach ( MUST_CARRY_BYTES as $relative ) {
		if ( ! isset( $present[ $relative ] ) ) {
			fail( "{$relative} was verified in the staged tree but is not in the archive." );
		}
	}

	$zip->close();
}

/** One rule, applied to the staged tree and again to the archive, so neither is taken on trust. */
function reject_forbidden( string $relative, string $where ): void {
	foreach ( explode( '/', $relative ) as $segment ) {
		if ( in_array( $segment, NEVER_SHIPPED, true ) ) {
			fail( "{$where} contains {$relative}, and \"{$segment}\" is never shipped." );
		}
	}

	$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

	if ( in_array( $extension, NEVER_SHIPPED_EXTENSIONS, true ) ) {
		fail( "{$where} contains {$relative}, and a .{$extension} is never part of the runtime." );
	}
}

/**
 * Every file under a directory, as sorted paths relative to it. Sorted because an archive whose
 * entry order depends on the filesystem's readdir order is one that differs between two machines
 * packaging the same commit, for no reason anybody can see.
 *
 * @return list<string>
 */
function walk( string $directory ): array {
	$files   = array();
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $entries as $entry ) {
		/** @var SplFileInfo $entry */
		if ( $entry->isFile() ) {
			$files[] = substr( $entry->getPathname(), strlen( $directory ) + 1 );
		}
	}

	sort( $files );

	return $files;
}

/**
 * Writes the archive from scratch. `OVERWRITE` rather than plain `CREATE` because ZipArchive
 * otherwise ADDS to whatever is already at that path, which is how a zip ends up shipping files
 * that were deleted three releases ago.
 *
 * @return list<string> The relative paths written, for verify_zip() to count against.
 */
function write_zip( string $tree, string $path ): array {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fail( "cannot open {$path} for writing." );
	}

	$files = walk( $tree );

	foreach ( $files as $relative ) {
		if ( ! $zip->addFile( $tree . '/' . $relative, 'reservant/' . $relative ) ) {
			fail( "cannot add {$relative} to the archive." );
		}
	}

	// Everything is written here, not at addFile(), so this is where a full disk or an unreadable
	// source shows up. Ignoring the return value is how truncated archives get shipped.
	if ( ! $zip->close() ) {
		fail( "the archive could not be finalised at {$path}." );
	}

	return $files;
}
