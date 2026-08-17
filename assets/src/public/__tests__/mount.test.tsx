import { act } from '@testing-library/react';
import { mountAll, mountWidget } from '../index';

/**
 * `mountWidget` renders through a React 18 concurrent root, whose work is scheduled rather than
 * flushed inside the `render()` call - so the mount is wrapped in `act()` and the assertion reads
 * the DOM afterwards. Without it this asserts on an empty container no matter what the widget
 * renders, and React additionally warns to `console.error`, which this suite treats as a failure.
 */
function mount( el: HTMLElement ): void {
	act( () => {
		mountWidget( el );
	} );
}

it( 'renders into the element it is given', () => {
	const el = document.createElement( 'div' );
	el.dataset.service = '0';
	document.body.appendChild( el );
	mount( el );
	expect( el.textContent ).not.toBe( '' );
} );

it( 'reads the mode off the mount node', () => {
	const el = document.createElement( 'div' );
	el.dataset.mode = 'manage';
	el.dataset.uuid = '11111111-1111-4111-8111-111111111111';
	el.dataset.token = 'secret';
	document.body.appendChild( el );
	mount( el );
	expect( el.textContent ).toBe( 'Loading your booking...' );
} );

/**
 * Shortcode attributes are user text and `shortcode_atts` does not trim, so `data-mode="manage "`
 * is a value a real site will produce. The uuid and token readers trim; the mode reader has to
 * match, or a stray space silently drops the visitor's magic link into the booking flow.
 */
it( 'trims data-mode like the other data- readers', () => {
	const el = document.createElement( 'div' );
	el.dataset.mode = ' manage ';
	el.dataset.uuid = '11111111-1111-4111-8111-111111111111';
	el.dataset.token = 'secret';
	document.body.appendChild( el );
	mount( el );
	expect( el.textContent ).toBe( 'Loading your booking...' );
} );

/**
 * The bundle can execute twice over the same page: the block's `viewScript` and the shortcode's
 * enqueue may register the same file under two handles, and browsers do not deduplicate classic
 * scripts by URL. A second `createRoot()` over the same node replaces the first root - destroying
 * a visitor's in-progress state - and warns to `console.error`, which this suite fails on. The
 * guard has to make the second mount a no-op that leaves the first render's DOM untouched.
 */
it( 'mounting the same node twice keeps the first render', () => {
	const el = document.createElement( 'div' );
	document.body.appendChild( el );
	mount( el );

	const panel = el.firstElementChild;
	expect( panel ).not.toBeNull();

	mount( el );
	expect( el.firstElementChild ).toBe( panel );
} );

/**
 * The two-handles case is not two calls into one module - it is the bundle EXECUTING twice, and
 * the second execution gets fresh module scope. A guard that lives only in module scope resets
 * with it, which is exactly what this test distinguishes: it boots a second, isolated instance of
 * the module (whose import-time self-boot runs `mountAll`, just like a second script tag would)
 * and asserts the first instance's render survives untouched.
 */
it( 'a second execution of the bundle does not remount the page', () => {
	document.body.innerHTML = '<div class="reservant-widget" data-mode="book"></div>';

	act( () => {
		mountAll();
	} );

	const panel = document.querySelector( '.reservant-widget__panel' );
	expect( panel ).not.toBeNull();

	act( () => {
		jest.isolateModules( () => {
			jest.requireActual( '../index' );
		} );
	} );

	expect( document.querySelector( '.reservant-widget__panel' ) ).toBe( panel );
} );

/**
 * Nothing on the page calls the widget: the shortcode, the block and the manage route render a
 * `.reservant-widget` node and enqueue the script, which then has to find its own mount points -
 * including a second one, since a page may embed the widget more than once.
 */
it( 'mounts every widget node on the page', () => {
	document.body.innerHTML =
		'<div class="reservant-widget" data-mode="book"></div>' +
		'<div class="reservant-widget" data-mode="manage"></div>';

	act( () => {
		mountAll();
	} );

	const mounted = document.querySelectorAll( '.reservant-widget__panel' );
	expect( mounted ).toHaveLength( 2 );
	expect( mounted[ 0 ]?.textContent ).toBe( 'Loading booking options...' );
	expect( mounted[ 1 ]?.textContent ).toBe( 'Loading your booking...' );
} );
