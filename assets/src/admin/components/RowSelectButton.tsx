import { Button } from '@wordpress/components';
import type { MouseEvent } from 'react';

export interface RowSelectButtonProps {
	/** The row's own identifying text - the button's accessible name, e.g. the customer or service name. */
	label: string;
	onSelect: () => void;
	/** Marks the row this button belongs to as the currently selected one, for assistive technology. */
	isSelected?: boolean;
}

/**
 * The focusable control that selects a table row - the one affordance every listing table in the
 * dashboard uses to open or edit the record a row stands for.
 *
 * It exists because `<tr onClick>` on its own is invisible to anything but a mouse: no tab stop, no
 * role, no Enter/Space activation, no accessible name. All four listing tables (bookings, services,
 * staff, seat maps) made a row click the ONLY way to reach a record, so opening a booking, editing
 * a service, editing a staff member and picking a seat map - the primary interaction of half the
 * dashboard - could not be done from the keyboard or announced by a screen reader at all.
 *
 * Rendered on the row's identifying cell so its accessible name IS the row's name, which is what a
 * screen reader announces when tabbing through the table. `aria-current` marks the selected row, so
 * "which one am I editing" is conveyed rather than left to the row's highlight colour.
 *
 * The row keeps its own `onClick` purely as a larger mouse target; this button stops propagation so
 * a click on it selects once rather than twice.
 */
export function RowSelectButton( { label, onSelect, isSelected = false }: RowSelectButtonProps ) {
	return (
		<Button
			variant="link"
			aria-current={ isSelected ? 'true' : undefined }
			onClick={ ( event: MouseEvent< HTMLButtonElement > ) => {
				event.stopPropagation();
				onSelect();
			} }
		>
			{ label }
		</Button>
	);
}
