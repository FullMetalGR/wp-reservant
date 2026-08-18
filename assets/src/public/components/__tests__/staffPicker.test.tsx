/**
 * Task 13 pins (P5 plan): "No preference" is the DEFAULT - a mount with no `value` renders it
 * pressed - and choosing it reports null, which is the value `toChainItems()` (Task 12) writes to
 * the wire as `resource_id: null`, the form the engine reads as "any staff". No concrete
 * resource_id is ever sent unless the visitor pressed a named staff member; there is no
 * `same_staff` control here and none is sent - the widget's availability reads and holds carry
 * neither, and the server defaults both sides to false.
 *
 * Everything is a real `<button>` (the P4 lesson - keyboard support is never faked with
 * `onKeyDown` on a non-button element), on the `reservant-` class prefix.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { StaffPicker } from '../StaffPicker';
import type { PublicService } from '../../api/types';

const RESOURCES: PublicService[ 'resources' ] = [
	{ id: 7, name: 'Alex' },
	{ id: 8, name: 'Billie' },
];

describe( 'StaffPicker', () => {
	it( 'defaults to "No preference" when no value is given - null is the resting state', () => {
		render( <StaffPicker serviceId={ 3 } resources={ RESOURCES } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'button', { name: 'No preference' } ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		expect( screen.getByRole( 'button', { name: 'Alex' } ) ).toHaveAttribute( 'aria-pressed', 'false' );
		expect( screen.getByRole( 'button', { name: 'Billie' } ) ).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	it( 'lists "No preference" first, ahead of every named staff member', () => {
		render( <StaffPicker serviceId={ 3 } resources={ RESOURCES } onChange={ jest.fn() } /> );

		const buttons = screen.getAllByRole( 'button' );
		expect( buttons[ 0 ] ).toHaveTextContent( 'No preference' );
		expect( buttons ).toHaveLength( 3 );
	} );

	it( 'reports a chosen staff member by id', () => {
		const onChange = jest.fn();
		render( <StaffPicker serviceId={ 3 } resources={ RESOURCES } onChange={ onChange } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Alex' } ) );

		expect( onChange ).toHaveBeenCalledWith( 7 );
	} );

	it( 'reports null for "No preference" - the value that sends no resource_id', () => {
		// null flows through Task 12's `toChainItems()` as `resource_id: null`, which
		// `AvailabilityController::decodeItems()` and `HoldsController::appointment()` both read
		// as "any staff": the engine's `ChainResolver` picks at hold time. A numeric id here
		// would instead pin the segment to that resource.
		const onChange = jest.fn();
		render( <StaffPicker serviceId={ 3 } resources={ RESOURCES } value={ 7 } onChange={ onChange } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'No preference' } ) );

		expect( onChange ).toHaveBeenCalledWith( null );
	} );

	it( 'marks the chosen staff member pressed and "No preference" not', () => {
		render( <StaffPicker serviceId={ 3 } resources={ RESOURCES } value={ 7 } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'button', { name: 'Alex' } ) ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( screen.getByRole( 'button', { name: 'No preference' } ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
	} );

	it( 'is a plain list of real buttons on the reservant- prefix, carrying its segment hook', () => {
		const { container } = render(
			<StaffPicker serviceId={ 3 } resources={ RESOURCES } onChange={ jest.fn() } />
		);

		const list = container.querySelector( 'ul.reservant-staff-picker' );
		expect( list ).toBeInTheDocument();
		// A chain renders one picker per segment; data-service-id is the stable hook the e2e
		// flow (Task 17) targets a specific segment's picker with.
		expect( list ).toHaveAttribute( 'data-service-id', '3' );
		for ( const button of screen.getAllByRole( 'button' ) ) {
			expect( button.tagName ).toBe( 'BUTTON' );
			expect( button.className ).toContain( 'reservant-staff-picker__choice' );
		}
	} );
} );
