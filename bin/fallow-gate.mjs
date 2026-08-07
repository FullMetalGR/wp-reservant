#!/usr/bin/env node
/**
 * The enforcing half of the fallow CI gate (AGENTS.md section 7).
 *
 * `fallow --ci` is documented as `--format sarif --fail-on-issues --quiet`, but as of fallow 3.14.0
 * it exits 0 even when it has just written SARIF results at level "error" - verified directly:
 * `npx fallow --ci; echo $?` prints four `"level": "error"` findings and `0`. A CI step that only
 * runs it can therefore never fail, which is exactly the "decorative gate" AGENTS.md must not
 * claim to have. This reads the SARIF fallow just wrote and exits non-zero on any error-level
 * result, so the bar is enforced rather than merely reported.
 *
 * Only level "error" fails. fallow's own defaults put dead code, unresolved imports, dependency
 * cycles and boundary violations there; complexity, duplication and hotspot findings arrive as
 * "warning"/"note" and stay advisory, which is the same split PHPStan's level and TypeScript's
 * `strict` already draw.
 *
 * Usage: node bin/fallow-gate.mjs <sarif-file>
 */
import { readFileSync } from 'node:fs';

const path = process.argv[ 2 ];
if ( undefined === path ) {
	console.error( 'usage: node bin/fallow-gate.mjs <sarif-file>' );
	process.exit( 2 );
}

/** @type {{ runs?: Array<{ results?: Array<Record<string, any>> }> }} */
const sarif = JSON.parse( readFileSync( path, 'utf8' ) );
const results = ( sarif.runs ?? [] ).flatMap( ( run ) => run.results ?? [] );
const errors = results.filter( ( result ) => 'error' === result.level );

for ( const error of errors ) {
	const physical = error.locations?.[ 0 ]?.physicalLocation;
	const file = physical?.artifactLocation?.uri ?? '(project)';
	const line = physical?.region?.startLine ?? 0;
	console.error( `${ file }:${ line }: [${ error.ruleId }] ${ error.message?.text ?? '' }` );
}

console.error(
	`fallow: ${ results.length } finding(s), ${ errors.length } at level "error"` +
		( 0 === errors.length ? '.' : ' - failing the build.' )
);
process.exit( 0 === errors.length ? 0 : 1 );
