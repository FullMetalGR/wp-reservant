import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ScreenErrorBoundary } from '../components/ScreenErrorBoundary';

function Boom(): JSX.Element {
	throw new TypeError( "Cannot read properties of undefined (reading 'length')" );
}

function Fine(): JSX.Element {
	return <p>the screen rendered</p>;
}

/**
 * The critical finding's blast radius, not its cause: `GET /admin/seat-maps` shipped rows with no
 * `seats` key, `SeatMapsScreen` read `map.seats.length`, and because nothing caught the resulting
 * `TypeError` React unmounted the WHOLE tree - the admin saw wp-admin chrome and an empty page, on
 * every screen, from one route's wire-shape mismatch.
 *
 * These pin that the boundary contains the failure AND surfaces it. A boundary that swallowed the
 * error would be worse than the blank page it replaced: a screen that silently renders nothing is
 * harder to diagnose, not easier.
 */
describe( 'ScreenErrorBoundary', () => {
	it( 'renders its children untouched when nothing throws', () => {
		render(
			<ScreenErrorBoundary>
				<Fine />
			</ScreenErrorBoundary>
		);

		expect( screen.getByText( 'the screen rendered' ) ).toBeInTheDocument();
	} );

	it( 'catches a render error and shows what went wrong instead of blanking the page', () => {
		render(
			<ScreenErrorBoundary>
				<Boom />
			</ScreenErrorBoundary>
		);

		expect( screen.getByText( 'This screen could not be displayed.' ) ).toBeInTheDocument();

		// The actual failure is SHOWN, not swallowed - name, message, and the component stack.
		const detail = screen.getByTestId( 'screen-error-detail' );
		expect( detail ).toHaveTextContent( 'TypeError' );
		expect( detail ).toHaveTextContent( "Cannot read properties of undefined (reading 'length')" );
		expect( detail ).toHaveTextContent( 'Boom' );

		// ...and re-reported to the console, where a browser's own error tooling can still see it.
		// (React logs its own boundary notice here too; both are expected.)
		expect( console ).toHaveErrored();
	} );

	it( 'contains the failure: siblings outside the boundary keep rendering', () => {
		render(
			<div>
				<p>dashboard chrome</p>
				<ScreenErrorBoundary>
					<Boom />
				</ScreenErrorBoundary>
			</div>
		);

		expect( screen.getByText( 'dashboard chrome' ) ).toBeInTheDocument();
		expect( screen.getByText( 'This screen could not be displayed.' ) ).toBeInTheDocument();
		expect( console ).toHaveErrored();
	} );
} );
