import { act } from '@testing-library/react';
import { mountAll, mountWidget } from '../index';
import type { WidgetBootstrap } from '../api/types';

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

/**
 * Book mode mounts the real journey (Task 14), whose catalog query reads the bootstrap and calls
 * `fetch` - so this suite, which is about MOUNTING and not the journey, provides both. The fetch
 * never settles on purpose: every book-mode mount then rests on its stable first frame (the
 * catalog loading state), no state ever updates outside `act()`, and no retry timers arm.
 */
beforeEach( () => {
	const bootstrap: WidgetBootstrap = {
		restRoot: '/wp-json/',
		nonce: '',
		currency: 'EUR',
		timezone: 'Pacific/Kiritimati',
		granularityMin: 5,
		checkoutTtlMin: 15,
	};
	window.reservantWidget = bootstrap;
	global.fetch = jest.fn(
		() => new Promise< Response >( () => {} )
	) as unknown as typeof fetch;
} );

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
 *
 * The DOM-identity assertion alone is NOT what discriminates here, and must not be relied on:
 * `jest.isolateModules` also re-instantiates `react-dom/client`, so the isolated instance renders
 * through a second React copy whose container marker the first copy cannot see - against a
 * module-scoped guard the final `toBe( panel )` still passes, and the run only failed
 * incidentally, on the jest-console rule catching a stray act() warning from that second copy.
 * The registry assertions below observe the guard itself: a module-scoped guard never touches
 * `window.reservantWidgetMounted` and fails the `toBeInstanceOf` line on this suite's own
 * assertion, and a guard that re-CREATES the registry per execution fails the final `toBe`.
 */
it( 'a second execution of the bundle does not remount the page', () => {
	document.body.innerHTML = '<div class="reservant-widget" data-mode="book"></div>';

	act( () => {
		mountAll();
	} );

	const panel = document.querySelector( '.reservant-widget__panel' );
	expect( panel ).not.toBeNull();

	// The cross-execution guard must live on window, where the second execution can find it.
	const registry = ( window as Window & { reservantWidgetMounted?: unknown } )
		.reservantWidgetMounted;
	expect( registry ).toBeInstanceOf( WeakSet );

	act( () => {
		jest.isolateModules( () => {
			jest.requireActual( '../index' );
		} );
	} );

	expect( document.querySelector( '.reservant-widget__panel' ) ).toBe( panel );
	// ...and the second execution ADOPTED the first's registry rather than replacing it.
	expect(
		( window as Window & { reservantWidgetMounted?: unknown } ).reservantWidgetMounted
	).toBe( registry );
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
	// Book mode mounts the real journey (Task 14): its first frame is the catalog loading state.
	expect( mounted[ 0 ]?.textContent ).toBe( 'Loading services...' );
	expect( mounted[ 1 ]?.textContent ).toBe( 'Loading your booking...' );
} );
