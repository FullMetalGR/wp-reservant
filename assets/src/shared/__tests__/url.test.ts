import { buildRequestUrl } from '../url';

// `buildRequestUrl`'s real signature is `(restRoot, namespace, path)`, not the two-argument
// `(root, path)` a first pass at this task's brief assumed - `namespace` and `path` are joined
// before the permalink-mode fold below, exactly as `admin/api/client.ts`'s `apiFetch` already
// calls it. The three cases below are otherwise the brief's own, unchanged: pretty permalinks,
// plain permalinks, and plain permalinks with a query-bearing path.
describe( 'buildRequestUrl', () => {
	it( 'appends a namespace and path to a pretty-permalink root', () => {
		expect( buildRequestUrl( 'https://x.test/wp-json/', 'reservant/v1', '/services' ) )
			.toBe( 'https://x.test/wp-json/reservant/v1/services' );
	} );

	it( 'folds the namespace and path into rest_route under plain permalinks', () => {
		expect( buildRequestUrl( 'https://x.test/index.php?rest_route=/', 'reservant/v1', '/services' ) )
			.toBe( 'https://x.test/index.php?rest_route=/reservant/v1/services' );
	} );

	it( 'turns the path\'s own query string into & under plain permalinks', () => {
		expect( buildRequestUrl( 'https://x.test/index.php?rest_route=/', 'reservant/v1', '/availability?from=2026-08-07' ) )
			.toBe( 'https://x.test/index.php?rest_route=/reservant/v1/availability&from=2026-08-07' );
	} );
} );
