/**
 * Task 13 pins (P5 plan): an occurrence with `remaining: 0` renders as a REAL `<button disabled>`
 * - never a styled div, never a button that fires and fails - and says "Sold out" in text, so the
 * state is announced, not conveyed by colour alone. `remaining` is advisory like every
 * availability read (the hold under the occurrence row lock is the authority), which is exactly
 * why the sold-out rendering mirrors the read instead of pretending to be one.
 *
 * Occurrence starts render on the SITE clock through the same `utcToSite` + bootstrap-timezone
 * spine as the slot grid - the occurrence wire shape carries only `*_utc` fields, so there is no
 * server-formatted local string to lean on. Same fixture zone (Pacific/Kiritimati, UTC+14) and
 * same built-not-hardcoded `Intl` expectations as the slot grid suite, for the same reasons.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { OccurrencePicker } from '../OccurrencePicker';
import type { OccurrenceOption, WidgetBootstrap } from '../../api/types';

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

/** The component's own options, verbatim, over the EXPECTED site wall-clock digits. */
function siteWhen( year: number, monthIndex: number, day: number, hour: number, minute: number ): string {
	return new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} ).format( new Date( year, monthIndex, day, hour, minute, 0 ) );
}

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

const OCCURRENCES: OccurrenceOption[] = [
	{ id: 11, start_utc: '2026-06-01 09:00:00', end_utc: '2026-06-01 11:00:00', remaining: 3 },
	{ id: 12, start_utc: '2026-06-08 09:00:00', end_utc: '2026-06-08 11:00:00', remaining: 0 },
];

beforeEach( () => {
	window.reservantWidget = bootstrapFixture();
} );

describe( 'OccurrencePicker', () => {
	it( 'renders each occurrence start on the SITE clock, not the browser\'s', () => {
		render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ jest.fn() } /> );

		// 2026-06-01 09:00 UTC is Mon Jun 1, 23:00 at UTC+14 - a browser-clock rendering shows
		// 12:00 on this repo's own EEST machine and 09:00 on a UTC CI runner instead.
		expect( screen.getByText( siteWhen( 2026, 5, 1, 23, 0 ), EXACT_TEXT ) ).toBeInTheDocument();
		expect( screen.getByText( siteWhen( 2026, 5, 8, 23, 0 ), EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'shows the remaining places for a bookable occurrence', () => {
		render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ jest.fn() } /> );

		expect( screen.getByText( '3 places left' ) ).toBeInTheDocument();
	} );

	it( 'renders remaining: 0 as a real disabled button that announces "Sold out"', () => {
		const onSelect = jest.fn();
		render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ onSelect } /> );

		const soldOut = screen.getByRole( 'button', { name: /Sold out/ } );
		expect( soldOut.tagName ).toBe( 'BUTTON' );
		expect( soldOut ).toBeDisabled();

		// A disabled button must not fire and fail - the click is inert by the platform, not by
		// a guard inside the handler.
		fireEvent.click( soldOut );
		expect( onSelect ).not.toHaveBeenCalled();
	} );

	it( 'keeps the bookable occurrence enabled beside a sold-out one', () => {
		render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ jest.fn() } /> );

		expect( screen.getByRole( 'button', { name: /3 places left/ } ) ).toBeEnabled();
	} );

	it( 'reports the chosen occurrence whole - the id books it, the times review it', () => {
		const onSelect = jest.fn();
		render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ onSelect } /> );

		fireEvent.click( screen.getByRole( 'button', { name: /3 places left/ } ) );

		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( OCCURRENCES[ 0 ] );
	} );

	it( 'renders the empty state for an empty occurrence list, never a blank panel', () => {
		render( <OccurrencePicker occurrences={ [] } onSelect={ jest.fn() } /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'There are no upcoming dates for this event.'
		);
		expect( screen.queryAllByRole( 'button' ) ).toHaveLength( 0 );
	} );

	it( 'is a list of real buttons on the reservant- prefix', () => {
		const { container } = render( <OccurrencePicker occurrences={ OCCURRENCES } onSelect={ jest.fn() } /> );

		expect( container.querySelector( 'ul.reservant-occurrence-picker' ) ).toBeInTheDocument();
		for ( const button of screen.getAllByRole( 'button' ) ) {
			expect( button.className ).toContain( 'reservant-occurrence-picker__choice' );
		}
	} );
} );
