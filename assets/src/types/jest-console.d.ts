/**
 * `@wordpress/jest-console` (pulled in by `wp-scripts test-unit-js`) fails any test that logs to
 * the console without saying it expects to, and adds these matchers for the cases where a log IS
 * the behavior under test. The package ships no type declarations of its own, so they are declared
 * here - only the two this suite uses.
 */
declare namespace jest {
	interface Matchers< R > {
		/** Passes when `console.error` was called during the test - and marks those calls as expected. */
		toHaveErrored(): R;
		/** Passes when `console.warn` was called during the test - and marks those calls as expected. */
		toHaveWarned(): R;
	}
}
