import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { SeatMapPreview } from '../SeatMapsScreen';
import type { Seat } from '../../api/types';

function seat( overrides: Partial< Seat > = {} ): Seat {
	return {
		id: 1,
		seat_map_id: 1,
		row_label: 'A',
		seat_label: 'A1',
		sort_row: 1,
		sort_col: 1,
		kind: 'seat',
		...overrides,
	};
}

/**
 * A 2-row fixture with one aisle cell in row A (Task 16 brief: "given seats fixture (2 rows,
 * aisle) renders 2 row elements and marks the aisle cell"). `sort_row` groups seats into rows -
 * the preview must never assume rows are contiguous or start at 1, so this fixture deliberately
 * uses 1 and 3 rather than 1 and 2.
 */
function twoRowsWithAisle(): Seat[] {
	return [
		seat( { id: 1, row_label: 'A', seat_label: 'A1', sort_row: 1, sort_col: 1, kind: 'seat' } ),
		seat( { id: 2, row_label: 'A', seat_label: 'A2', sort_row: 1, sort_col: 2, kind: 'seat' } ),
		seat( { id: 3, row_label: 'A', seat_label: '', sort_row: 1, sort_col: 3, kind: 'aisle' } ),
		seat( { id: 4, row_label: 'A', seat_label: 'A3', sort_row: 1, sort_col: 4, kind: 'seat' } ),
		seat( { id: 5, row_label: 'B', seat_label: 'B1', sort_row: 3, sort_col: 1, kind: 'seat' } ),
		seat( { id: 6, row_label: 'B', seat_label: 'B2', sort_row: 3, sort_col: 2, kind: 'seat' } ),
	];
}

describe( 'SeatMapPreview', () => {
	it( 'renders one row element per distinct sort_row', () => {
		render( <SeatMapPreview seats={ twoRowsWithAisle() } /> );
		expect( screen.getAllByTestId( 'seatmap-row' ) ).toHaveLength( 2 );
	} );

	it( 'marks the aisle cell distinctly from seat cells', () => {
		render( <SeatMapPreview seats={ twoRowsWithAisle() } /> );

		const cells = screen.getAllByTestId( 'seatmap-cell' );
		expect( cells ).toHaveLength( 6 );

		const aisleCells = cells.filter( ( cell ) => 'aisle' === cell.getAttribute( 'data-kind' ) );
		expect( aisleCells ).toHaveLength( 1 );
		expect( aisleCells[ 0 ] ).toHaveClass( 'reservant-seatmap-preview__cell--aisle' );

		const seatCells = cells.filter( ( cell ) => 'seat' === cell.getAttribute( 'data-kind' ) );
		expect( seatCells ).toHaveLength( 5 );
	} );

	it( 'marks a blocked cell distinctly too', () => {
		const seats = [ ...twoRowsWithAisle(), seat( { id: 7, row_label: 'B', seat_label: '', sort_row: 3, sort_col: 3, kind: 'blocked' } ) ];
		render( <SeatMapPreview seats={ seats } /> );

		const blockedCells = screen.getAllByTestId( 'seatmap-cell' ).filter( ( cell ) => 'blocked' === cell.getAttribute( 'data-kind' ) );
		expect( blockedCells ).toHaveLength( 1 );
		expect( blockedCells[ 0 ] ).toHaveClass( 'reservant-seatmap-preview__cell--blocked' );
	} );

	it( 'renders nothing (no row elements) for an empty seat list', () => {
		render( <SeatMapPreview seats={ [] } /> );
		expect( screen.queryAllByTestId( 'seatmap-row' ) ).toHaveLength( 0 );
	} );
} );
