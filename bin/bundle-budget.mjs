#!/usr/bin/env node
/**
 * Byte budget for a built bundle (P5 plan, global constraints).
 *
 * The public widget ships to every visitor of a page that embeds it, so its weight is a product
 * decision, not an accident of whatever got imported. This is the enforcing half: `npm run build`
 * writes the bundle, this reads it and fails the build when it outgrew its budget.
 *
 * A missing file is a HARD failure, not a pass. The whole gate is worthless if it can be satisfied
 * by never running the build - CI would go green on a bundle that does not exist, which is a louder
 * lie than being over budget. Same reason `bin/fallow-gate.mjs` exists: a gate that cannot fail is
 * not a gate.
 *
 * Usage: node bin/bundle-budget.mjs <file> <limit-in-bytes>
 * Exit:  0 within budget, 1 over budget, 2 the invocation or the file itself is wrong.
 */
import { statSync } from 'node:fs';
import { basename } from 'node:path';

const [ path, rawLimit ] = process.argv.slice( 2 );

if ( undefined === path || undefined === rawLimit ) {
	console.error( 'usage: node bin/bundle-budget.mjs <file> <limit-in-bytes>' );
	process.exit( 2 );
}

const limit = Number.parseInt( rawLimit, 10 );

if ( ! Number.isInteger( limit ) || limit <= 0 ) {
	console.error( `bundle-budget: "${ rawLimit }" is not a byte limit.` );
	process.exit( 2 );
}

let bytes;

try {
	const stat = statSync( path );

	if ( ! stat.isFile() ) {
		console.error( `bundle-budget: ${ path } is not a file. Run \`npm run build\` first.` );
		process.exit( 2 );
	}

	bytes = stat.size;
} catch ( error ) {
	console.error(
		`bundle-budget: cannot read ${ path } (${ error.code ?? error.message }). ` +
			'Run `npm run build` first - an unbuilt bundle is a failure, not a pass.'
	);
	process.exit( 2 );
}

const name = basename( path );
const over = bytes > limit;

console.log(
	`${ name }: ${ bytes } bytes (limit ${ limit })` +
		( over ? ` - ${ bytes - limit } over budget.` : `, ${ limit - bytes } to spare.` )
);

process.exit( over ? 1 : 0 );
