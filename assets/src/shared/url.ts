/**
 * Joins `restRoot` (`esc_url_raw( rest_url() )`, `src/Admin/AdminPage.php`) with a namespace and
 * path into one request URL, correctly under BOTH WordPress permalink modes.
 *
 * Under PRETTY permalinks `restRoot` is an ordinary directory URL (`http://site/wp-json/`) and
 * plain string concatenation is fine. Under PLAIN permalinks - WordPress core's own default for a
 * fresh install - `rest_url()` instead returns `http://site/index.php?rest_route=/`: the root URL
 * has ALREADY opened its own query string, and the route is not a path segment but the *value* of
 * that `rest_route` parameter. Naively concatenating a query-bearing path then produces a second,
 * structurally meaningless `?` (`...index.php?rest_route=/reservant/v1/admin/calendar?from=X&to=Y`).
 * PHP's query-string parser only ever splits on `&`, never on an embedded `?`, so it reads that as
 * `rest_route=/reservant/v1/admin/calendar?from=X` (matching no route - 404 `rest_no_route`) plus a
 * stray top-level `to=Y`.
 *
 * The fix: when `restRoot` already owns a `?`, the route's own query string is the only OTHER `?` a
 * well-formed path can carry, so folding just that one into `&` merges both into the single query
 * string `restRoot` already started - the route text itself (still holding its `&`-joined args)
 * lands correctly inside the `rest_route` value, because `restRoot` ends in `rest_route=/` and the
 * route is appended directly onto that trailing slash.
 *
 * This mirrors, step for step, `@wordpress/api-fetch`'s own root-URL middleware
 * (`packages/api-fetch/src/middlewares/root-url.ts` in WordPress/gutenberg - the mechanism WP
 * core's own admin JS uses to reach itself under either permalink mode: replace the first `?` in
 * the path with `&` whenever the root URL already contains one, then concatenate). That package
 * is not a dependency of this project (`package.json` deliberately keeps only `@wordpress/components`
 * / `element` / `i18n`, and the admin bundle already owns a small, precisely-typed fetch wrapper
 * matched to Reservant's own error envelope - pulling in WP's differently-shaped, same-named
 * `apiFetch` runtime just to reach one ~10-line join algorithm would trade a verifiable local
 * function for a second, competing HTTP abstraction), so the algorithm is ported here rather than
 * imported.
 */
export function buildRequestUrl( restRoot: string, namespace: string, path: string ): string {
	let route = `${ namespace }${ path }`;

	if ( restRoot.includes( '?' ) ) {
		route = route.replace( '?', '&' );
	}

	// `restRoot` always ends in a trailing slash (`rest_url()` guarantees this in both permalink
	// modes); `route` must not start with one too, or the join doubles it up.
	return restRoot + route.replace( /^\//, '' );
}
