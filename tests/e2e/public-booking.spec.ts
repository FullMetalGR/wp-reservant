import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

/**
 * The public end-to-end proof (P5 plan, Task 17): publish a post carrying `[reservant_booking]`,
 * visit it logged OUT, book a two-segment chain (Cut + Colour, no staff preference), then open
 * the magic-link manage URL from the created booking and cancel it. The FLOW is the contract;
 * every selector below was read off the real components rather than guessed:
 *
 * - `assets/src/public/index.tsx` for the mount node (`.reservant-widget`, `data-mode="book"`)
 *   that PHP renders and the bundle self-boots into.
 * - `assets/src/public/components/ServicePicker.tsx` for the catalog buttons
 *   (`.reservant-service-picker__choice`, name in `.reservant-service-picker__name` - the
 *   button's accessible name also carries duration and price, so the name span is the exact
 *   match target).
 * - `assets/src/public/components/ChainBuilder.tsx` for "Add another service" and the opened
 *   picker's container (`.reservant-chain__picker`).
 * - `assets/src/public/BookingFlow.tsx` for the step containers (`data-step` on
 *   `.reservant-flow`), the per-step "Continue" buttons and the step order
 *   service -> staff -> when -> details -> review -> done.
 * - `assets/src/public/components/StaffPicker.tsx` for the per-segment pickers
 *   (`.reservant-staff-picker`, one per segment whose service lists staff; "No preference" is
 *   the default, so the staff step is a plain Continue).
 * - `assets/src/public/components/DateStrip.tsx` (`.reservant-date-strip__day`, fourteen
 *   consecutive days from the SITE's own today) and
 *   `assets/src/public/components/SlotGrid.tsx` (`.reservant-slot-grid__slot`).
 * - `assets/src/public/components/CustomerForm.tsx` for the labelled Name/Email fields and the
 *   "Continue" submit that fires the hold.
 * - `assets/src/public/components/HoldCountdown.tsx` (`.reservant-countdown`) and
 *   `assets/src/public/components/ReviewStep.tsx` ("Confirm booking",
 *   `.reservant-review__service`).
 * - `assets/src/public/components/Outcome.tsx` for the outcome sentence: the fixture's services
 *   carry no `requires_approval`, so the journey ends on "Your booking is confirmed.", never
 *   the approval sentence.
 * - `assets/src/public/ManageView.tsx` for the manage journey ("Your booking",
 *   `.reservant-manage__service`, the two-click cancel - "Cancel booking" then
 *   "Yes, cancel it" - and the status region's "This booking has been cancelled.").
 * - `src/Frontend/ManageRoute.php` for the manage URL shape `/booking/{uuid}?token=...` (this
 *   environment runs pretty permalinks via `.wp-env.json`'s afterStart).
 *
 * THE CREDENTIAL CAPTURE IS THE ONE STEP THE UI CANNOT GIVE: nothing renders `manage_token`
 * (the emailed magic link is P6). The secret reaches the browser exactly once, in the
 * `POST /holds` response body, so the spec listens for that response and builds the manage URL
 * from its JSON - which is exactly what the P6 mailer will do server-side.
 *
 * Two live-HTTP assertions ride on the manage page because nothing else in the repo can make
 * them: the jest and PHPUnit suites both prove the MECHANISMS (`ManageRoute::render()` calls
 * core's `nocache_headers()` through an injectable spy, and adds the `wp_robots_sensitive_page`
 * filter), but only a real request through the theme shell proves the headers and the meta tag
 * SURVIVE to the wire. The page's URL is the credential, so a cached response is a leak and an
 * indexed one is a publication.
 *
 * Seeding runs over WP-CLI in the DEV site's `cli` container - NOT `tests-cli`, which is the
 * :8889 tests site `bin/run-concurrency.sh` drives; Playwright's baseURL is the :8888 dev site,
 * and seeding the wrong container leaves this widget an empty catalog. `wp reservant fixture`
 * is idempotent and prints only the id JSON; the post is created with `wp post create` rather
 * than through the block editor, because driving Gutenberg to insert a shortcode would be a
 * second, unrelated flow's worth of failure surface. `/?p={id}` 301s to the pretty permalink
 * and Playwright follows it.
 *
 * `POST /holds` is rate-limited to 10/min per IP (`HoldsController`), and this spec fires one
 * per run - CI never trips it, but a developer iterating locally would after ten runs inside a
 * minute, and the widget would honestly report the refusal. The transient wipe below clears any
 * counter already ticking (the `run-concurrency.sh` precedent, on the tests site there); the
 * limiter itself is never weakened and nothing is left behind.
 *
 * Nothing here logs in (a logged-in visitor is a different code path - core sends nocache
 * headers for logged-in users, which would mask what assertion one proves) and nothing asserts
 * a wall-clock time (ruling R5): the booking day is picked by strip POSITION - the site's own
 * today plus seven, same distance the admin smoke uses - so the site timezone, lead time and
 * the hour the suite runs never decide whether a slot exists.
 */

/** Runs WP-CLI in the DEV site's container; stdout is the command's own (wp-env logs on stderr). */
function wpCli( args: string[] ): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
	} );
}

let postId = '';

test.beforeAll( () => {
	// The plugin ships inactive on a fresh wp-env volume; guard activation explicitly rather
	// than assuming (.wp-env.json registers it, run-concurrency.sh guards it the same way).
	try {
		wpCli( [ 'plugin', 'is-active', 'reservant' ] );
	} catch {
		wpCli( [ 'plugin', 'activate', 'reservant' ] );
	}
	// Idempotent: re-emits the stored id map as long as its rows exist. Cut + Colour + two
	// staff on 09:00-17:00 weekly hours is exactly what a two-segment chain needs.
	wpCli( [ 'reservant', 'fixture' ] );
	// Clear any rate-limit counter a previous local iteration left ticking (see the header).
	wpCli( [ 'transient', 'delete', '--all' ] );
	postId = wpCli( [
		'post',
		'create',
		'--post_status=publish',
		'--post_title=Booking widget e2e',
		'--post_content=[reservant_booking]',
		'--porcelain',
	] ).trim();
} );

test( 'a visitor books a two-segment chain and cancels it through the manage link', async ( { page } ) => {
	const unique = Date.now();
	const customerName = `Guest E2E ${ unique }`;
	const customerEmail = `guest-e2e-${ unique }@example.com`;

	// --- The published post renders the widget, logged out --------------------------------
	await page.goto( `/?p=${ postId }` );
	const flow = page.locator( '.reservant-flow' );
	await expect( flow ).toBeVisible();

	// --- Service step: Cut, then "+ add another" -> Colour --------------------------------
	// The name span is the exact-match target; the whole button's accessible name also
	// carries "30 min" and the price.
	await page
		.locator( '.reservant-service-picker__choice' )
		.filter( { has: page.locator( '.reservant-service-picker__name', { hasText: /^Cut$/ } ) } )
		.click();
	await page.getByRole( 'button', { name: 'Add another service' } ).click();
	await page
		.locator( '.reservant-chain__picker .reservant-service-picker__choice' )
		.filter( { has: page.locator( '.reservant-service-picker__name', { hasText: /^Colour$/ } ) } )
		.click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	// --- Staff step: one picker per segment, "No preference" already the default ----------
	await expect( flow ).toHaveAttribute( 'data-step', 'staff' );
	await expect( page.locator( '.reservant-staff-picker' ) ).toHaveCount( 2 );
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	// --- When step: a week out by strip position (site-today + 7), first offered slot ------
	await expect( flow ).toHaveAttribute( 'data-step', 'when' );
	await page.locator( '.reservant-date-strip__day' ).nth( 7 ).click();
	await page.locator( '.reservant-slot-grid__slot' ).first().click();

	// --- Details submit fires the hold; the response body is the ONLY place the manage
	//     credential ever appears, so capture it around the click --------------------------
	await expect( flow ).toHaveAttribute( 'data-step', 'details' );
	await page.getByLabel( 'Name', { exact: true } ).fill( customerName );
	await page.getByLabel( 'Email', { exact: true } ).fill( customerEmail );
	const [ holdResponse ] = await Promise.all( [
		page.waitForResponse(
			( response ) =>
				response.url().includes( '/reservant/v1/holds' ) &&
				'POST' === response.request().method()
		),
		page.getByRole( 'button', { name: 'Continue' } ).click(),
	] );
	expect( holdResponse.status() ).toBe( 201 );
	const held = ( await holdResponse.json() ) as { uuid: string; manage_token: string };
	expect( held.uuid ).toMatch( /^[0-9a-f-]{36}$/ );
	expect( held.manage_token ).not.toBe( '' );

	// --- Review: the countdown covers the held stretch; both segments are listed off the
	//     SERVER's booking, then confirm ---------------------------------------------------
	await expect( flow ).toHaveAttribute( 'data-step', 'review' );
	await expect( page.locator( '.reservant-countdown' ) ).toBeVisible();
	await expect( page.locator( '.reservant-review__service', { hasText: /^Cut$/ } ) ).toBeVisible();
	await expect( page.locator( '.reservant-review__service', { hasText: /^Colour$/ } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Confirm booking' } ).click();

	// The fixture's services need no approval, so the honest outcome is CONFIRMED - never
	// the "request sent" sentence, which the engine reserves for `awaiting_approval`.
	await expect( page.getByText( 'Your booking is confirmed.' ) ).toBeVisible();

	// --- The manage page, straight off the captured credential ----------------------------
	const manageResponse = await page.goto(
		`/booking/${ held.uuid }?token=${ held.manage_token }`
	);
	expect( manageResponse ).not.toBeNull();

	// The URL IS the credential. Core's WP::send_headers() sends nocache headers only for
	// logged-in users, feeds, errors and password-protected posts - this logged-out page is
	// none of those, so the no-store below can only have come from ManageRoute's own
	// nocache_headers() call surviving the real theme shell.
	expect( ( await manageResponse?.headerValue( 'cache-control' ) ) ?? '' ).toContain(
		'no-store'
	);
	// And nothing may index it: wp_robots_sensitive_page, printed by wp_head() inside the
	// shell. The meta element is head content, so this asserts attachment, not visibility.
	await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute(
		'content',
		/noindex/
	);

	await expect( page.getByRole( 'heading', { name: 'Your booking' } ) ).toBeVisible();
	await expect( page.locator( '.reservant-manage__service', { hasText: /^Cut$/ } ) ).toBeVisible();
	await expect( page.locator( '.reservant-manage__service', { hasText: /^Colour$/ } ) ).toBeVisible();
	await expect( page.getByText( customerName ) ).toBeVisible();

	// --- Cancel: destructive, so it takes the in-page confirmation's second click ----------
	await page.getByRole( 'button', { name: 'Cancel booking' } ).click();
	await page.getByRole( 'button', { name: 'Yes, cancel it' } ).click();

	// The refreshed booking query lands the cancelled sentence in the always-mounted status
	// region, and a cancelled booking supports neither action - the controls disappear
	// instead of dangling guaranteed failures.
	await expect( page.getByText( 'This booking has been cancelled.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Cancel booking' } ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'button', { name: 'Pick a new time' } ) ).toHaveCount( 0 );
} );
