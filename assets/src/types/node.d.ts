/**
 * The one Node builtin the test suites use: the style suite reads the real stylesheet off disk.
 * `@types/node` is deliberately absent from this package - the asset sources are browser code,
 * and typing all of Node would let Node APIs pass the type gate inside bundle code, where
 * webpack cannot serve them. Jest transforms the suites to CommonJS, so the import resolves at
 * run time; only this single signature is declared, so anything else from Node still fails
 * `npm run tsc`.
 */
declare module 'fs' {
	export function readFileSync( path: string, encoding: 'utf8' ): string;
}
