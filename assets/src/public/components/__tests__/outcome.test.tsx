/**
 * Outcome pins (P5 plan, Task 14): the end screen follows the SERVER'S returned `status` and
 * nothing else. Every fixture here carries a `requires_approval` flag that DISAGREES with its
 * status - an owner can flip approval settings between page load and the hold - so an
 * implementation branching on the flag cannot pass a single row (R3). "Request sent" and
 * "confirmed" stay distinct screens, `awaiting_payment` gets its honest pre-P7 sentence, and
 * every other status the union admits falls to a sentence that promises nothing.
 *
 * The message lands in a `role="status"` region per the widget's live-region convention - the
 * outcome is a polite answer to the visitor's own action, never an alert.
 */
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { Outcome } from '../Outcome';
import type { Booking } from '../../api/types';

function booking( status: Booking[ 'status' ], requiresApproval: boolean ): Booking {
	return {
		uuid: '11111111-1111-4111-8111-111111111111',
		status,
		hold_class: null,
		hold_expires_at: null,
		customer_name: 'Ada',
		customer_email: 'ada@example.com',
		customer_phone: '',
		total_minor: 4500,
		currency: 'EUR',
		payment_mode: 'free',
		requires_approval: requiresApproval,
		created_at: '2026-06-01 00:00:00',
		updated_at: '2026-06-01 00:00:00',
		items: [],
	};
}

describe( 'Outcome', () => {
	it.each< [ Booking[ 'status' ], boolean, string ] >( [
		[ 'confirmed', true, 'Your booking is confirmed.' ],
		[
			'awaiting_approval',
			false,
			'Request sent. We will be in touch once it has been reviewed.',
		],
		[
			'awaiting_payment',
			false,
			'Your booking is reserved and will be completed once payment is arranged.',
		],
		[ 'completed', true, 'Your booking has been received.' ],
	] )(
		'renders the %s outcome off the returned status alone, in a status region',
		( status, requiresApproval, sentence ) => {
			render( <Outcome booking={ booking( status, requiresApproval ) } /> );
			expect( screen.getByRole( 'status' ) ).toHaveTextContent( sentence );
		}
	);

	it( 'never flattens "request sent" into "confirmed"', () => {
		render( <Outcome booking={ booking( 'awaiting_approval', false ) } /> );
		expect( screen.queryByText( /confirmed/i ) ).not.toBeInTheDocument();
	} );
} );
