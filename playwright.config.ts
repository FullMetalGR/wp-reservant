import { defineConfig, devices } from '@playwright/test';

/**
 * The Playwright config for Reservant's admin-SPA smoke test (AGENTS.md P4, final task): runs
 * against a local `wp-env` instance the caller is responsible for starting first (`npx wp-env
 * start`), with the React bundle already built (`npm run build`) - `Admin\AdminPage` enqueues
 * nothing at all until `build/admin.asset.php` exists, same as CI's `assets`/`integration-and-
 * concurrency` jobs already assume for the PHP suites.
 *
 * `workers: 1` and no `webServer` entry are both deliberate: the target is one shared,
 * stateful wp-env site (not a throwaway server this config spins up itself), so nothing here
 * should race a second worker against it or attempt to manage its lifecycle.
 */
export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 90_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: 'line',
	use: {
		baseURL: 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
