import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ErrorBoundary } from '../ErrorBoundary';

function Boom(): JSX.Element {
	throw new TypeError( "Cannot read properties of undefined (reading 'starts')" );
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
 * the console line, asserted here via `toHaveErrored()` - which doubles as the suite-level
 * requirement, because `@wordpress/jest-console` fails any test whose `console.error` goes
 * unexpected (React logs its own boundary notice too; both are expected).
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
		expect( console ).toHaveErrored();
	} );
} );
