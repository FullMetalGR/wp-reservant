/**
 * The staff step for one chain segment (P5 plan, Task 13): the resources who perform the
 * segment's service, led by "No preference" - which is the DEFAULT, and deliberately so. A null
 * value flows through Task 12's `toChainItems()` as `resource_id: null`, the form both
 * `AvailabilityController::decodeItems()` and `HoldsController::appointment()` read as "any
 * staff", leaving the concrete assignment to `ChainResolver` at hold time. No concrete
 * resource_id ever reaches the wire unless the visitor pressed a named staff member.
 *
 * Plain semantic HTML - a `<ul>` of real `<button>` elements, never a keyboard-simulating div
 * (the P4 lesson) - and no `@wordpress/components`: that package is a webpack external, so
 * importing it would hang the ~800 KB `wp-components` core handle on every visitor while the
 * byte budget saw nothing (`bin/widget-contract.mjs` owns the enforcement).
 *
 * `resources` is typed off the exported `PublicService` by indexed access rather than by
 * exporting `PublicResource` from the wire types: the caller passes `service.resources` verbatim,
 * and this keeps the types module's export surface exactly as narrow as its docblock prescribes.
 */
import { __ } from '@wordpress/i18n';
import type { PublicService } from '../api/types';

interface StaffPickerProps {
	/**
	 * The segment's service. Echoed as `data-service-id` on the root: a chain renders one picker
	 * per segment, and this is the stable hook the e2e flow (Task 17) targets a specific
	 * segment's picker with - index-based selectors break the moment a segment is removed.
	 */
	serviceId: number;
	/** The staff who perform this service - `PublicService.resources`, passed through whole. */
	resources: PublicService[ 'resources' ];
	/**
	 * The chosen staff id, or null for "no preference". Defaults to null: a freshly added
	 * segment starts unpinned (`ChainBuilder.handlePick` creates `resourceId: null`), and the
	 * component embodies the same resting state when the parent passes nothing.
	 */
	value?: number | null;
	onChange: ( id: number | null ) => void;
}

function choiceClass( selected: boolean ): string {
	return selected
		? 'reservant-staff-picker__choice reservant-staff-picker__choice--selected'
		: 'reservant-staff-picker__choice';
}

export function StaffPicker( {
	serviceId,
	resources,
	value = null,
	onChange,
}: StaffPickerProps ): JSX.Element {
	return (
		<ul className="reservant-staff-picker" data-service-id={ serviceId }>
			<li className="reservant-staff-picker__item">
				<button
					type="button"
					className={ choiceClass( null === value ) }
					aria-pressed={ null === value }
					onClick={ () => onChange( null ) }
				>
					{ __( 'No preference', 'reservant' ) }
				</button>
			</li>
			{ resources.map( ( resource ) => (
				<li key={ resource.id } className="reservant-staff-picker__item">
					<button
						type="button"
						className={ choiceClass( value === resource.id ) }
						aria-pressed={ value === resource.id }
						onClick={ () => onChange( resource.id ) }
					>
						{ resource.name }
					</button>
				</li>
			) ) }
		</ul>
	);
}
