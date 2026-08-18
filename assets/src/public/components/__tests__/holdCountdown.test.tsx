/**
 * HoldCountdown pins (P5 plan, Task 14): the component encodes two rulings - the deadline is a
 * REAL INSTANT the flow parsed from the server's `hold_expires_at` (never a client-computed TTL),
 * and seconds are counted with `ceil` so the display never runs ahead of the deadline it
 * enforces. Both are asserted here at the component boundary, where the fractional-second edges
 * are reachable directly - the flow suite can only bound the rendered value.
 *
 * The m:ss label is a plain ASCII composition (`sprintf` over digits), not an `Intl` rendering,
 * so literal expectations are locale-safe here - unlike every date assertion in the flow suite.
 *
 * The milestone paragraph follows the widget's live-region convention: the `role="status"`
 * region exists from the first paint, EMPTY, and the milestone text lands in it later - an
 * announcement in a region that was already there. The ticking m:ss is deliberately outside any
 * live region (a per-second announcement would be hostile), which the region's text content
 * proves: it never contains the clock.
 */
import { act, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { HoldCountdown } from '../HoldCountdown';

const NOW_UTC = '2026-06-01T00:00:00Z';

beforeEach( () => {
	jest.useFakeTimers( { now: new Date( NOW_UTC ) } );
} );

afterEach( () => {
	jest.useRealTimers();
} );

function renderCountdown( msAhead: number ): jest.Mock {
	const onExpire = jest.fn();
	render( <HoldCountdown expiresAt={ new Date( Date.now() + msAhead ) } onExpire={ onExpire } /> );
	return onExpire;
}

describe( 'HoldCountdown', () => {
	it( 'renders the remainder as m:ss, ceiled so the display never runs ahead', () => {
		// 90.5s left reads 1:31, not 1:30: a floor would show a second the deadline no longer
		// grants for the whole fractional second before it.
		renderCountdown( 90_500 );
		expect( screen.getByText( 'Time left to confirm: 1:31' ) ).toBeInTheDocument();

		act( () => {
			jest.advanceTimersByTime( 1_000 );
		} );
		expect( screen.getByText( 'Time left to confirm: 1:30' ) ).toBeInTheDocument();
	} );

	it( 'announces the last minute in a status region that existed, empty, from the start', () => {
		renderCountdown( 90_000 );
		const region = screen.getByRole( 'status' );
		expect( region.textContent ).toBe( '' );

		// At exactly one minute left the milestone has not fired - the boundary is strict.
		act( () => {
			jest.advanceTimersByTime( 30_000 );
		} );
		expect( region.textContent ).toBe( '' );

		act( () => {
			jest.advanceTimersByTime( 1_000 );
		} );
		expect( region.textContent ).toBe( 'Less than a minute left to confirm.' );
	} );

	it( 'reaches 0:00 at the deadline, says so, and fires onExpire exactly once', () => {
		const onExpire = renderCountdown( 5_000 );
		expect( onExpire ).not.toHaveBeenCalled();

		act( () => {
			jest.advanceTimersByTime( 5_000 );
		} );
		expect( screen.getByText( 'Time left to confirm: 0:00' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Your time to confirm has run out. Please pick another time.' )
		).toBeInTheDocument();
		expect( onExpire ).toHaveBeenCalledTimes( 1 );

		// Later ticks re-render the zero state but never re-fire the expiry.
		act( () => {
			jest.advanceTimersByTime( 3_000 );
		} );
		expect( onExpire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clamps a past deadline to 0:00 and expires immediately, never counting negative', () => {
		// The flow can mount this with a deadline already gone (a long-suspended tab); the first
		// paint must already be the expired state.
		const onExpire = renderCountdown( -60_000 );
		expect( screen.getByText( 'Time left to confirm: 0:00' ) ).toBeInTheDocument();
		expect( onExpire ).toHaveBeenCalledTimes( 1 );
	} );
} );
