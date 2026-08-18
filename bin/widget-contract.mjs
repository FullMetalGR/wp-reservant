#!/usr/bin/env node
/**
 * Shape checks on the built public widget that a byte count cannot see (P5 plan, global
 * constraints). `npm run size` runs this right after `bin/bundle-budget.mjs`.
 *
 * 1. The dependency handles in build/widget.asset.php must stay inside the allowed externals.
 *    Every `@wordpress/*` package is a webpack external under DependencyExtractionWebpackPlugin,
 *    so importing one adds almost nothing to build/widget.js - measured: `@wordpress/components`
 *    plus one rendered <Button> is +59 bundled bytes - while the visitor is really handed core's
 *    components.min.js (~800 KB) plus ~97 KB of CSS through the new `wp-components` handle. The
 *    byte budget alone would wave that through; this reads the handle list and refuses it. An
 *    allowlist rather than a wp-components denylist because wp-components is only the heaviest
 *    member of the class - wp-data, wp-api-fetch or wp-compose would sneak core weight past a
 *    denylist the same way. Growing the list is a deliberate, visible one-line edit here.
 *
 * 2. build/style-widget.css must exist. wp-scripts names the shared style chunk after the FIRST
 *    webpack entry that imports a file named style.css (see assets/src/public/style.css), so
 *    adding an entry can silently rename the stylesheet PHP enqueues - and wp_enqueue_style never
 *    checks that its URL resolves, so the widget would ship unstyled with CI green.
 *
 * The asset file is PHP, parsed textually. The parse REFUSES to pass on anything it does not
 * recognise: a missing file, an unmatched dependency list, or an empty handle list (this entry
 * always imports wp-element, so zero handles means the parser no longer understands the file).
 * A gate that cannot fail is not a gate.
 *
 * Usage: node bin/widget-contract.mjs
 * Exit:  0 contract holds, 1 contract broken, 2 build output missing or unparseable.
 */
import { readFileSync, statSync } from 'node:fs';

const ASSET_FILE = 'build/widget.asset.php';
const STYLE_FILE = 'build/style-widget.css';

/**
 * The complete set of script handles the widget may depend on: the externals the P5 plan allows
 * for this entry (react, react-dom, wp-element, wp-i18n) plus react-jsx-runtime, which the JSX
 * transform itself emits. Anything else is core weight shipped to every visitor and must be a
 * deliberate edit to this list, not an accident of an import.
 */
const ALLOWED = new Set( [ 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-i18n' ] );

let source;

try {
	source = readFileSync( ASSET_FILE, 'utf8' );
} catch ( error ) {
	console.error(
		`widget-contract: cannot read ${ ASSET_FILE } (${ error.code ?? error.message }). ` +
			'Run `npm run build` first - an unbuilt widget is a failure, not a pass.'
	);
	process.exit( 2 );
}

const list = source.match( /'dependencies'\s*=>\s*array\s*\(([^)]*)\)/ );

if ( null === list ) {
	console.error(
		`widget-contract: no 'dependencies' => array( ... ) in ${ ASSET_FILE }. ` +
			'The file no longer has the shape this parser understands - refusing to pass on a guess.'
	);
	process.exit( 2 );
}

const handles = [ ...list[ 1 ].matchAll( /'([^']+)'/g ) ].map( ( m ) => m[ 1 ] );

if ( 0 === handles.length ) {
	console.error(
		`widget-contract: parsed zero dependency handles out of ${ ASSET_FILE }. ` +
			'The widget always imports wp-element, so either the build broke or this parser no ' +
			'longer understands the file - refusing to pass either way.'
	);
	process.exit( 2 );
}

const forbidden = handles.filter( ( handle ) => ! ALLOWED.has( handle ) );

if ( 0 < forbidden.length ) {
	console.error(
		`widget-contract: ${ ASSET_FILE } depends on ${ forbidden.join( ', ' ) } - not in the ` +
			'allowed externals for the public widget. Externals add no bundled bytes; they add ' +
			'core scripts served to every visitor (wp-components alone is ~800 KB plus CSS). ' +
			'Find and remove the import, or - only as a deliberate product decision - extend ' +
			'ALLOWED in bin/widget-contract.mjs.'
	);
	process.exit( 1 );
}

try {
	if ( ! statSync( STYLE_FILE ).isFile() ) {
		throw Object.assign( new Error( 'not a file' ), { code: 'ENOTFILE' } );
	}
} catch ( error ) {
	console.error(
		`widget-contract: ${ STYLE_FILE } is missing (${ error.code ?? error.message }). ` +
			'wp-scripts names the shared style chunk after the first entry importing a ' +
			'style.css, so a new entry importing assets/src/public/style.css can silently ' +
			'rename this file - check the entry order in package.json before anything else.'
	);
	process.exit( 1 );
}

console.log(
	`widget.asset.php: depends on ${ handles.join( ', ' ) } - all allowed. ` +
		`${ STYLE_FILE } exists.`
);
