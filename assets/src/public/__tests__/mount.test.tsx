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
