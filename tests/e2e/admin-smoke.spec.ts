import { expect, test } from '@playwright/test';

/**
 * The one Playwright smoke spec (AGENTS.md P4, final task): log in, configure a staff member and
 * a service linked to them, confirm they show up on the calendar, then book them through the
 * manual-booking drawer and confirm the booking lands. One test, serial, on purpose - this is a
 * smoke test for the whole admin SPA wired to the real REST surface, not a per-screen unit suite
 * (those already exist under `assets/src/admin/**\/__tests__`).
 *
 * Every selector below was read off the real components rather than guessed:
 * - `src/Admin/AdminPage.php` for the wp-admin menu slugs (`reservant`, `reservant-staff`,
 *   `reservant-services`, `reservant-bookings`).
 * - `assets/src/admin/screens/StaffScreen.tsx` for the staff form - `name`/`email` fields, the
 *   per-weekday "Add window" button (not "Add hours"), and the "Services performed" checklist
 *   (checkbox label = the service's own name; a service must exist before it can be checked).
 * - `assets/src/admin/screens/ServicesScreen.tsx` for the service form - defaults already give a
 *   bookable appointment service (`type: 'appointment'`, `duration_min: 30`) once `name` is set.
 * - `assets/src/admin/components/App.tsx` for "New booking" - it lives in the SPA header, not on
 *   any one screen, and opens `ManualBookingDrawer` with no slot prefill.
 * - `assets/src/admin/screens/ManualBookingDrawer.tsx` for the drawer's own fields ("Service",
 *   "Staff", "Available times" slot buttons labelled `HH:MM`, "Customer name"/"Customer email",
 *   and the submit button "Create booking" - the sketch's guessed "Book" does not exist).
 *
 * The service is created before the staff member (reversed from the plan's sketch) because
 * linking only happens one way in this UI: the "Services performed" checklist on the STAFF form,
 * which can only list a service that already exists. Creating staff first and the service second
 * would need a second edit round-trip to go back and check the box - functionally identical, just
 * an extra step. The flow's contract - staff-with-hours and a service ends up linked to them,
 * visible on the calendar, then bookable - holds either way.
 *
 * The drawer's date is deliberately pushed a week out rather than left on "today": the staff
 * member's weekly hours are seeded for every weekday precisely so this test's result does not
 * depend on what time of day (or which weekday) the suite happens to run.
 *
 * Permalinks: `.wp-env.json`'s `lifecycleScripts.afterStart` forces pretty permalinks
 * (`wp rewrite structure '/%postname%/'`) on this environment. WordPress's own default for a
 * fresh install is PLAIN permalinks, under which `rest_url()` returns a URL that already owns a
 * `?` (`.../index.php?rest_route=/`) rather than a plain directory URL - a shape that broke this
 * exact flow (`GET /admin/calendar` and `/admin/availability`, both query-bearing) until
 * `assets/src/admin/api/client.ts`'s `buildRequestUrl()` was fixed to merge query strings rather
 * than naively concatenate them. That fix is covered directly and exhaustively by
 * `assets/src/admin/__tests__/client.test.ts` (exact-URL assertions under both permalink modes,
 * with and without a query-bearing path) - far cheaper and more deterministic than running this
 * whole multi-step browser flow twice, once per permalink mode, would be. The lifecycle hook stays
 * for a different reason: it keeps this smoke test's environment deterministic (wp-env's own
 * default permalink structure is not part of its documented contract) rather than to hide the
 * permalink-join bug class, which jest now owns.
 */
test( 'owner can configure a staff member and service, then book them end to end', async ( { page } ) => {
	const unique = Date.now();
	const serviceName = `Trim E2E ${ unique }`;
	const staffName = `Eva E2E ${ unique }`;
	const staffEmail = `eva-e2e-${ unique }@example.com`;
	const customerName = `Walk In E2E ${ unique }`;
	const customerEmail = `walkin-e2e-${ unique }@example.com`;

	// A week out, `yyyy-MM-dd` - inside every service's default 60-day horizon, and always in the
	// future regardless of when in the day (or week) this test runs.
	const bookingDate = new Date();
	bookingDate.setDate( bookingDate.getDate() + 7 );
	const bookingDateStr = bookingDate.toISOString().slice( 0, 10 );

	// --- Log in (wp-env's own default admin account) ---------------------------------------
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );

	// --- Create a service (`reservant-services`) - defaults already make it bookable ---------
	await page.goto( '/wp-admin/admin.php?page=reservant-services' );
	await page.getByRole( 'button', { name: 'New service' } ).click();
	await page.getByLabel( 'Name', { exact: true } ).fill( serviceName );
	await page.getByRole( 'button', { name: 'Save service' } ).click();
	// A real id came back - proven by the delete button, which only renders once `selectedId` is set.
	await expect( page.getByRole( 'button', { name: 'Delete service' } ) ).toBeVisible();

	// --- Create a staff member with hours, linked to that service (`reservant-staff`) -------
	await page.goto( '/wp-admin/admin.php?page=reservant-staff' );
	await page.getByRole( 'button', { name: 'New staff member' } ).click();
	await page.getByLabel( 'Name', { exact: true } ).fill( staffName );
	await page.getByLabel( 'Email', { exact: true } ).fill( staffEmail );

	// One "Add window" button per weekday row (Monday..Sunday, in that order) - clicking each
	// once gives every weekday a 09:00-17:00 window (`WeeklyRulesEditor`'s own default for a
	// freshly added rule).
	const addWindowButtons = page.getByRole( 'button', { name: 'Add window' } );
	await expect( addWindowButtons ).toHaveCount( 7 );
	for ( let day = 0; day < 7; day += 1 ) {
		await addWindowButtons.nth( day ).click();
	}

	// `.first()` guards a locally re-run dev site where an earlier attempt's same-named service
	// was never cleaned up - any checkbox literally labelled with `serviceName` links the two
	// correctly, since `serviceName` itself is unique per run.
	await page.getByRole( 'checkbox', { name: serviceName } ).first().check();

	await page.getByRole( 'button', { name: 'Save staff member' } ).click();
	// The per-resource exceptions panel only renders once `selected` resolves to the saved
	// resource (`StaffScreen`'s own `null !== selected` guard) - proof the save round-tripped a
	// real id, the same way the service's "Delete service" button proved it above.
	await expect( page.getByText( 'Exceptions (this staff member)' ) ).toBeVisible();

	// --- The new staff member shows up on the calendar (`reservant`, the top-level slug) ----
	await page.goto( '/wp-admin/admin.php?page=reservant' );
	// `selectOption` throws if no option carries this label - a direct assertion that Eva is a
	// real, selectable entry in the calendar's own staff filter, not just present elsewhere.
	await page.getByLabel( 'Staff', { exact: true } ).selectOption( { label: staffName } );

	// --- Book them through the manual-booking drawer (header action, not screen-specific) ---
	await page.getByRole( 'button', { name: 'New booking' } ).click();
	const drawer = page.getByRole( 'dialog', { name: 'New booking' } );
	await expect( drawer ).toBeVisible();

	await drawer.getByLabel( 'Date', { exact: true } ).fill( bookingDateStr );
	await drawer.getByLabel( 'Service', { exact: true } ).selectOption( { label: serviceName } );
	await drawer.getByLabel( 'Staff', { exact: true } ).selectOption( { label: staffName } );

	const firstSlot = drawer.getByRole( 'button', { name: /^\d{2}:\d{2}$/ } ).first();
	await expect( firstSlot ).toBeVisible();
	await firstSlot.click();

	await drawer.getByLabel( 'Customer name', { exact: true } ).fill( customerName );
	await drawer.getByLabel( 'Customer email', { exact: true } ).fill( customerEmail );
	await drawer.getByRole( 'button', { name: 'Create booking' } ).click();

	// The drawer closes only on a successful create (`ManualBookingDrawer`'s `onSuccess` calls
	// `onClose()`); a validation or server error leaves it open instead.
	await expect( drawer ).toBeHidden();

	// --- The booking appears (`reservant-bookings`), confirmed - an admin-mode booking never
	// sits in a hold state (AGENTS.md Task 6/10: "`HoldRequest::$admin` lands it straight on
	// confirmed") -------------------------------------------------------------------------
	await page.goto( '/wp-admin/admin.php?page=reservant-bookings' );
	const bookingRow = page.getByRole( 'row' ).filter( { hasText: customerName } );
	await expect( bookingRow ).toBeVisible();
	await expect( bookingRow ).toContainText( 'Confirmed' );
} );
