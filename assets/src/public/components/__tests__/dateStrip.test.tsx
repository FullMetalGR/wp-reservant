/**
 * Task 13 pins (P5 plan): the strip renders one real `<button>` per day from `from`, reports a
 * pick as the `Y-m-d` BUSINESS date `useAvailability()` wants, and normalizes month rollover in
 * that arithmetic. `from` is a SITE-packed `Date` (the `siteNow`/`utcToSite` contract: site
 * wall-clock digits packed via the local constructor), so the strip itself does no timezone work -
 * it must only read the packed digits back through local getters, never call `new Date()` for
 * "today".
 *
 * Day labels are BUILT with the component's own `Intl` options, never written as glyph literals:
 * the component formats in the machine's locale (the `undefined` locale is deliberate, the
 * ServicePicker precedent), so `el_GR` renders "Dev 1 Ioun" in Greek script where `en_US` renders
 * "Mon, Jun 1" - a literal would pass CI and fail on a Greek workstation. Exact-text matching
 * uses an identity normalizer: several locales pad `Intl` output with no-break spaces, which
 * Testing Library's default normalizer collapses on the element side only.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { DateStrip } from '../DateStrip';

/** The component's own options, verbatim - only the date below is each test's contribution. */
function dayLabel( year: number, monthIndex: number, day: number ): string {
	return new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
	} ).format( new Date( year, monthIndex, day ) );
}

const EXACT_TEXT = { normalizer: ( text: string ): string => text };

/** Monday 2026-06-29: two weeks from here cross into July, so rollover is always exercised. */
function fromFixture(): Date {
	return new Date( 2026, 5, 29 );
}

describe( 'DateStrip', () => {
	it( 'renders fourteen days by default, starting at from', () => {
		render( <DateStrip from={ fromFixture() } onSelect={ jest.fn() } /> );

		const buttons = screen.getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 14 );
		expect( screen.getByText( dayLabel( 2026, 5, 29 ), EXACT_TEXT ) ).toBeInTheDocument();
		// The 14th day is 2026-07-12 - the label arithmetic crossed the month boundary.
		expect( screen.getByText( dayLabel( 2026, 6, 12 ), EXACT_TEXT ) ).toBeInTheDocument();
	} );

	it( 'renders exactly the asked-for number of days', () => {
		render( <DateStrip from={ fromFixture() } days={ 3 } onSelect={ jest.fn() } /> );

		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 3 );
		expect( screen.queryByText( dayLabel( 2026, 6, 2 ), EXACT_TEXT ) ).not.toBeInTheDocument();
	} );

	it( 'reports a pick as the Y-m-d business date, month rollover normalized', () => {
		const onSelect = jest.fn();
		render( <DateStrip from={ fromFixture() } onSelect={ onSelect } /> );

		fireEvent.click( screen.getByText( dayLabel( 2026, 6, 1 ), EXACT_TEXT ) );

		expect( onSelect ).toHaveBeenCalledWith( '2026-07-01' );
	} );

	it( 'marks the selected day pressed and every other day not', () => {
		render( <DateStrip from={ fromFixture() } value="2026-07-01" onSelect={ jest.fn() } /> );

		expect( screen.getByText( dayLabel( 2026, 6, 1 ), EXACT_TEXT ).closest( 'button' ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		expect( screen.getByText( dayLabel( 2026, 5, 29 ), EXACT_TEXT ).closest( 'button' ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
	} );

	it( 'is a plain list of real buttons on the reservant- prefix', () => {
		const { container } = render( <DateStrip from={ fromFixture() } onSelect={ jest.fn() } /> );

		expect( container.querySelector( 'ul.reservant-date-strip' ) ).toBeInTheDocument();
		for ( const button of screen.getAllByRole( 'button' ) ) {
			expect( button.tagName ).toBe( 'BUTTON' );
			expect( button.className ).toContain( 'reservant-date-strip__day' );
		}
	} );
} );
