/**
 * Task 13 pins (P5 plan), and the one the plan calls NOT OPTIONAL: slots render in SITE time -
 * the salon's clock - never the browser's. The bootstrap timezone here is Pacific/Kiritimati
 * (UTC+14, no DST, the same portability zone the shared time suite pins), whose offset differs
 * from any timezone a dev box or CI runner actually sits in: 09:00 UTC must render as the site's
 * 23:00, and a component that read the browser clock instead would render the RUNNER's wall time
 * (12:00 on this repo's own EEST machine, 09:00 on a UTC CI runner) and fail the exact-text
 * assertion. The expected strings are BUILT with the component's own `Intl` options over the
 * expected SITE wall-clock digits packed local-constructor style (the `utcToSite` contract), so
 * the pin survives any locale - `el_GR` renders "11:00 m.m." in Greek script where `en_US`
 * renders "11:00 PM" - and the identity normalizer keeps the no-break-space padding some locales
 * emit from being collapsed on one side of the comparison only.
 *
 * Also pinned: an empty `starts` renders the empty state, never a blank panel; a chosen start is
 * reported WHOLE, its `utc` half untouched, because that exact string is what `POST /holds` wants
 * back; and every slot is a real `<button>` - the P4 lesson, keyboard support is never faked.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { SlotGrid } from '../SlotGrid';
import type { SlotStart, WidgetBootstrap } from '../../api/types';

const SITE_TZ = 'Pacific/Kiritimati';

function bootstrapFixture(): WidgetBootstrap {
	return {
		restRoot: '/wp-json/',
		nonce: '',
		currency: 'EUR',
		timezone: SITE_TZ,
		granularityMin: 5,
		checkoutTtlMin: 15,
	};
}

/**
 * The component's own options, verbatim, over the EXPECTED site wall-clock digits - only those
 * digits are each test's contribution, the glyphs stay the machine's own.
 */
function siteClock( year: number, monthIndex: number, day: number, hour: number, minute: number ): string {
	return new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } ).format(
		new Date( year, monthIndex, day, hour, minute, 0 )
	);
}

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

/** The second start crosses midnight in site time (21:30 UTC is 11:30 the NEXT day at +14). */
const STARTS: SlotStart[] = [
	{ utc: '2026-06-01 09:00:00', local: '2026-06-01T23:00:00+14:00' },
	{ utc: '2026-06-01 21:30:00', local: '2026-06-02T11:30:00+14:00' },
];

beforeEach( () => {
	window.reservantWidget = bootstrapFixture();
} );

describe( 'SlotGrid', () => {
	it( 'renders every start on the SITE clock, not the browser\'s', () => {
		render( <SlotGrid starts={ STARTS } onSelect={ jest.fn() } /> );

		// 09:00 UTC is 23:00 at UTC+14. A browser-clock rendering shows 12:00 on this repo's own
		// EEST machine and 09:00 on a UTC CI runner - neither can match this exact text.
		expect( screen.getByText( siteClock( 2026, 5, 1, 23, 0 ), EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( siteClock( 2026, 5, 2, 11, 30 ), EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'renders the empty state for an empty starts array, never a blank panel', () => {
		const { container } = render( <SlotGrid starts={ [] } onSelect={ jest.fn() } /> );

		// role="status", the widget's convention for polite answers to the visitor's own action
		// (they just picked this date) - never role="alert", nothing went wrong.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'No times are available on this day. Please pick another date.'
		);
		expect( screen.queryAllByRole( 'button' ) ).toHaveLength( 0 );
		expect( container.firstChild ).not.toBeNull();
	} );

	it( 'reports the chosen start whole, its utc half untouched for POST /holds', () => {
		const onSelect = jest.fn();
		render( <SlotGrid starts={ STARTS } onSelect={ onSelect } /> );

		fireEvent.click( screen.getByText( siteClock( 2026, 5, 1, 23, 0 ), EXACT_TEXT ) );

		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( STARTS[ 0 ] );
	} );

	it( 'is a list of real buttons on the reservant- prefix', () => {
		const { container } = render( <SlotGrid starts={ STARTS } onSelect={ jest.fn() } /> );

		expect( container.querySelector( 'ul.reservant-slot-grid' ) ).toBeInTheDocument();
		const buttons = screen.getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 2 );
		for ( const button of buttons ) {
			expect( button.tagName ).toBe( 'BUTTON' );
			expect( button.className ).toContain( 'reservant-slot-grid__slot' );
		}
	} );
} );
