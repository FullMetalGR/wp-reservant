import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { apiFetch } from '../../api/client';
import { ToastProvider } from '../../components/Toasts';
import { SeatMapsScreen } from '../SeatMapsScreen';

jest.mock( '../../api/client', () => ( {
	...jest.requireActual( '../../api/client' ),
	apiFetch: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

// `ToastProvider`'s `SnackbarList` animates via framer-motion, which calls `window.scrollTo()` -
// unimplemented in jsdom. Same stub as `staffScreenExceptions.test.tsx`.
window.scrollTo = jest.fn();

function renderWithClient( ui: ReactElement ) {
	const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false }, mutations: { retry: false } } } );
	return render(
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>{ ui }</ToastProvider>
		</QueryClientProvider>
	);
}

/**
 * THE WIRE SHAPE, not a hand-written approximation of the TypeScript type.
 *
 * This is a literal transcription of what `GET /admin/seat-maps` puts on the wire: the envelope
 * `{seat_maps: [...]}` from `SeatMapsAdminController::index()`, each row built by that controller's
 * `present()` - `id`/`name`/`spec` from `SeatMapRepository::all()`'s own `SELECT`, plus `seats` from
 * `seatsForMap()` (`id`, `seat_map_id`, `row_label`, `seat_label`, `sort_row`, `sort_col`, `kind`,
 * ordered `sort_row ASC, sort_col ASC`, aisle and blocked cells included because they are geometry
 * the grid must draw). The parsed grid below is what `SeatMapSpec::parse('rows A-B, 2 per row,
 * aisle after 1')` produces: two lettered rows, an aisle cell after the first seat of each.
 *
 * Its counterpart on the server side is
 * `AdminCatalogTest::test_seat_map_list_carries_seats_like_the_single_seat_map_shape`, which asserts
 * against a REAL request that this is in fact the shape PHP emits. The pair is deliberate: this
 * test alone could only ever confirm a fixture against itself - which is exactly how a seats-less
 * `index()` reached HEAD while `SeatMap.seats` was declared required and this screen dereferenced it.
 */
function seatMapsWireResponse() {
	return {
		seat_maps: [
			{
				id: 1,
				name: 'Main Hall',
				spec: 'rows A-B, 2 per row, aisle after 1',
				seats: [
					{ id: 10, seat_map_id: 1, row_label: 'A', seat_label: 'A1', sort_row: 1, sort_col: 1, kind: 'seat' },
					{ id: 11, seat_map_id: 1, row_label: 'A', seat_label: '', sort_row: 1, sort_col: 2, kind: 'aisle' },
					{ id: 12, seat_map_id: 1, row_label: 'A', seat_label: 'A2', sort_row: 1, sort_col: 3, kind: 'seat' },
					{ id: 13, seat_map_id: 1, row_label: 'B', seat_label: 'B1', sort_row: 2, sort_col: 1, kind: 'seat' },
					{ id: 14, seat_map_id: 1, row_label: 'B', seat_label: '', sort_row: 2, sort_col: 2, kind: 'aisle' },
					{ id: 15, seat_map_id: 1, row_label: 'B', seat_label: 'B2', sort_row: 2, sort_col: 3, kind: 'seat' },
				],
			},
			{
				id: 2,
				name: 'Balcony',
				spec: 'rows A-A, 2 per row',
				seats: [
					{ id: 20, seat_map_id: 2, row_label: 'A', seat_label: 'A1', sort_row: 1, sort_col: 1, kind: 'seat' },
					{ id: 21, seat_map_id: 2, row_label: 'A', seat_label: 'A2', sort_row: 1, sort_col: 2, kind: 'seat' },
				],
			},
		],
	};
}

/**
 * Task 17 final-review finding (critical): `SeatMapsAdminController::index()` returned
 * `SeatMapRepository::all()`'s rows verbatim - id/name/spec, no `seats` - while `SeatMap` declared
 * `seats` required and `SeatMapsScreen` rendered `map.seats.length` in the catalog table. The
 * resulting `TypeError` unmounted the entire React tree (there was no error boundary), so the admin
 * saw wp-admin chrome and nothing else, and the whole seat-map feature was unreachable.
 *
 * These mount the screen against the real response shape - the regression is that the screen must
 * render at all, and that the grid it renders is the one the loaded map actually carries.
 */
describe( 'SeatMapsScreen - against the real GET /admin/seat-maps payload', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( seatMapsWireResponse() );
	} );

	it( 'renders the catalog table with a seat count per map instead of crashing', async () => {
		renderWithClient( <SeatMapsScreen /> );

		// Renders at all - the crash was `map.seats.length` on `undefined` during this first paint.
		const mainHall = await screen.findByRole( 'button', { name: 'Main Hall' } );
		const row = mainHall.closest( 'tr' );
		expect( row ).not.toBeNull();

		// 4 seats + 2 aisle cells: the count is over every grid cell the map carries, which is what
		// `seats.length` means (aisles and blocked cells are stored too - `insertSeats()`' docblock).
		expect( within( row as HTMLElement ).getByText( '6' ) ).toBeInTheDocument();
		expect( within( row as HTMLElement ).getByText( 'Editable' ) ).toBeInTheDocument();

		expect( screen.getByRole( 'button', { name: 'Balcony' } ) ).toBeInTheDocument();
	} );

	it( 'previews the selected map\'s grid, which is only possible because the LIST carries seats', async () => {
		renderWithClient( <SeatMapsScreen /> );

		// Nothing selected yet - the preview is empty.
		await screen.findByRole( 'button', { name: 'Main Hall' } );
		expect( screen.queryAllByTestId( 'seatmap-row' ) ).toHaveLength( 0 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Main Hall' } ) );

		// `selected` is looked up in the SAME seats-less list the table was built from, so a naive
		// one-line fix that only satisfied `seats.length` would still render an empty preview here.
		await waitFor( () => expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 2 ) );
		expect( screen.getAllByTestId( 'seatmap-cell' ) ).toHaveLength( 6 );
		expect( screen.getAllByTestId( 'seatmap-cell' ).filter( ( cell ) => 'aisle' === cell.getAttribute( 'data-kind' ) ) ).toHaveLength( 2 );

		// And the editor is seeded from the same row.
		expect( screen.getByLabelText( 'Spec' ) ).toHaveValue( 'rows A-B, 2 per row, aisle after 1' );
	} );

	it( 'previews a smaller map after switching selection', async () => {
		renderWithClient( <SeatMapsScreen /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Main Hall' } ) );
		await waitFor( () => expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 2 ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'Balcony' } ) );
		await waitFor( () => expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 1 ) );
		expect( screen.getAllByTestId( 'seatmap-cell' ) ).toHaveLength( 2 );
	} );

	/**
	 * Second-order half of the same finding: a save's response DOES carry the re-parsed grid, but
	 * `setSelectedId( saved.id )` re-derived the preview from the list, whose invalidation refetch
	 * has not landed yet - so the headline feature went blank at the exact moment it mattered most.
	 */
	it( 'previews the newly parsed grid immediately after a save, before the list refetch lands', async () => {
		let listResolutions = 0;
		mockedApiFetch.mockImplementation( ( path, init ) => {
			if ( 'POST' === init?.method || 'PUT' === init?.method ) {
				return Promise.resolve( {
					id: 1,
					name: 'Main Hall',
					spec: 'rows A-A, 2 per row',
					seats: [
						{ id: 30, seat_map_id: 1, row_label: 'A', seat_label: 'A1', sort_row: 1, sort_col: 1, kind: 'seat' },
						{ id: 31, seat_map_id: 1, row_label: 'A', seat_label: 'A2', sort_row: 1, sort_col: 2, kind: 'seat' },
					],
				} );
			}
			listResolutions += 1;
			// Every list read answers the OLD two-row grid, so a preview showing the new one-row grid
			// can only have come from the save response itself.
			return Promise.resolve( seatMapsWireResponse() );
		} );

		renderWithClient( <SeatMapsScreen /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Main Hall' } ) );
		await waitFor( () => expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 2 ) );

		fireEvent.change( screen.getByLabelText( 'Spec' ), { target: { value: 'rows A-A, 2 per row' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save seat map' } ) );

		await waitFor( () => expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 1 ) );
		expect( screen.getAllByTestId( 'seatmap-cell' ) ).toHaveLength( 2 );
		expect( listResolutions ).toBeGreaterThan( 0 );
	} );
} );

/**
 * Final-review finding: `<tr onClick>` with no `tabIndex`, `role` or `onKeyDown` was the ONLY way to
 * select a seat map, so a keyboard or screen-reader user could not reach the editor at all.
 */
describe( 'SeatMapsScreen - keyboard-operable row selection', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( seatMapsWireResponse() );
	} );

	it( 'exposes each row as a focusable button named after the map', async () => {
		renderWithClient( <SeatMapsScreen /> );

		const mainHall = await screen.findByRole( 'button', { name: 'Main Hall' } );
		mainHall.focus();
		expect( mainHall ).toHaveFocus();
	} );

	it( 'selects the map via its focusable button, marking it aria-current', async () => {
		renderWithClient( <SeatMapsScreen /> );

		const balcony = await screen.findByRole( 'button', { name: 'Balcony' } );
		balcony.focus();
		// Enter/Space activation on a real <button> is the browser's own behaviour - it dispatches a
		// click, which jsdom does not synthesize from a bare keyDown. Asserting that would test the
		// browser, not this codebase, so `click` stands in for "the focused control gets activated",
		// same as the previous test already proves the row IS that focusable, accessibly-named control.
		fireEvent.click( balcony );

		await waitFor( () => expect( screen.getByLabelText( 'Spec' ) ).toHaveValue( 'rows A-A, 2 per row' ) );
		expect( screen.getByRole( 'button', { name: 'Balcony' } ) ).toHaveAttribute( 'aria-current', 'true' );
	} );
} );
