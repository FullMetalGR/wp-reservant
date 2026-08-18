import { act } from '@testing-library/react';
import type { WidgetBootstrap } from '../api/types';

/**
 * `window.reservantWidgetMounted` is a shared global on a public page, and any third-party script
 * that runs first can have assigned it something that is not a WeakSet (an analytics shim
 * initialising "missing" globals to 0, say). A bare `??=` would keep that value - it only
 * replaces null/undefined - and the first `mounted.has()` would then throw inside the module's
 * import-time self-boot, rendering NOTHING anywhere on the page with no plugin-owned diagnostic.
 * The guard must treat any non-WeakSet value as absent and replace it.
 *
 * This lives in its own file on purpose: the poisoning has to happen before the module's FIRST
 * evaluation, and every other suite imports `../index` at the top of the file.
 *
 * The bootstrap and the never-settling fetch exist because book mode mounts the real journey
 * (Task 14), whose catalog query reads both at mount: the pending fetch parks the widget on its
 * stable loading frame, so this test stays about the boot guard and nothing updates outside act.
 */
it( 'boots and mounts even when a foreign script clobbered the registry global', () => {
	document.body.innerHTML = '<div class="reservant-widget" data-mode="book"></div>';
	( window as Window & { reservantWidgetMounted?: unknown } ).reservantWidgetMounted = 0;
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

	// First evaluation of the module: its import-time self-boot must survive the junk value.
	act( () => {
		jest.requireActual( '../index' );
	} );

	expect( document.querySelector( '.reservant-widget__panel' ) ).not.toBeNull();
	expect(
		( window as Window & { reservantWidgetMounted?: unknown } ).reservantWidgetMounted
	).toBeInstanceOf( WeakSet );
} );
