import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ErrorBoundary } from '../ErrorBoundary';

function Boom(): JSX.Element {
	throw new TypeError( "Cannot read properties of undefined (reading 'starts')" );
}

/**
 * Did the WIDGET'S diagnostic line reach `console.error` - as opposed to React's own boundary
 * notice, which fires for every catch whether or not `componentDidCatch` re-reports? Reads the
 * jest-console mock directly because that distinction is the whole point (see the docblock).
 */
function widgetErrorLineLogged(): boolean {
	const spy = console.error as jest.MockedFunction< typeof console.error >;
	return spy.mock.calls.some(
		( args ) => 'Reservant: the booking widget failed to render.' === args[ 0 ]
	);
}

function Fine(): JSX.Element {
	return <p>the widget rendered</p>;
}

/**
 * The widget-level twin of the admin's ScreenErrorBoundary pins (Task 16, plan step 1): a child
 * that throws during render must produce an inline sentence instead of React unmounting the
 * whole widget tree, the real error must still reach `console.error` (where browser tooling and
 * error-reporting integrations can see it), and content OUTSIDE the boundary - the visitor is on
 * a public page full of the theme's own markup - must survive untouched.
 *
 * Unlike the admin boundary, the fallback shows NO error detail: the audience is a visitor who
 * cannot act on a component stack, not an owner diagnosing a screen. The diagnostic channel is
 * the console line, and it is asserted by the widget's OWN message, not by `toHaveErrored()`
 * alone: React itself logs a `console.error` for every boundary catch, so a bare
 * `toHaveErrored()` stays green with the widget's re-report deleted (a mutation pass proved
 * exactly that). `toHaveErroredWith` cannot single the line out either - it matches the
 * stringified FULL argument list, and the component stack varies - so the assertion reads the
 * mock's own calls and pins the first argument. `toHaveErrored()` still runs for
 * `@wordpress/jest-console`'s bookkeeping (it fails any test whose `console.error` goes
 * unexpected; React's own notice and the widget's line are both expected).
 */
describe( 'ErrorBoundary', () => {
	it( 'renders its children untouched when nothing throws', () => {
		render(
			<ErrorBoundary>
				<Fine />
			</ErrorBoundary>
		);

		expect( screen.getByText( 'the widget rendered' ) ).toBeInTheDocument();
	} );

	it( 'catches a render error and shows the visitor one sentence instead of nothing', () => {
		render(
			<ErrorBoundary>
				<Boom />
			</ErrorBoundary>
		);

		const fallback = screen.getByText(
			'The booking widget hit an unexpected error. Please reload the page, or contact us directly to book.'
		);
		expect( fallback ).toBeInTheDocument();
		// A genuine failure with nothing better to show - the one case the widget's live-region
		// convention (index.tsx) reserves role="alert" for; an alert may mount with its text.
		expect( fallback ).toHaveAttribute( 'role', 'alert' );

		// No stack traces for visitors - the diagnostic detail belongs to the console line.
		expect( screen.queryByText( /TypeError/ ) ).not.toBeInTheDocument();
		// The widget's OWN line, not merely React's boundary notice (the docblock owns why a
		// bare toHaveErrored() proves nothing here).
		expect( widgetErrorLineLogged() ).toBe( true );
		expect( console ).toHaveErrored();
	} );

	it( 'contains the failure: the page around the widget survives', () => {
		render(
			<div>
				<p>the theme&apos;s own page content</p>
				<ErrorBoundary>
					<Boom />
				</ErrorBoundary>
			</div>
		);

		expect( screen.getByText( "the theme's own page content" ) ).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( widgetErrorLineLogged() ).toBe( true );
		expect( console ).toHaveErrored();
	} );
} );
