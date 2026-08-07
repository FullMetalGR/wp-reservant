import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, ComboboxControl, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import {
	useAddException,
	useExceptions,
	useRemoveException,
	useResources,
	useSaveResource,
	useServices,
	useWpUser,
	useWpUsers,
	type ExceptionInput,
} from '../api/queries';
import type { AvailabilityExceptionListItem, Resource, Service } from '../api/types';
import { useToasts } from '../components/Toasts';

function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : __( 'Something went wrong.', 'reservant' );
}

/** ISO-8601: 1 = Monday .. 7 = Sunday (`AvailabilityRule`'s own convention). */
const WEEKDAYS: { value: number; label: string }[] = [
	{ value: 1, label: __( 'Monday', 'reservant' ) },
	{ value: 2, label: __( 'Tuesday', 'reservant' ) },
	{ value: 3, label: __( 'Wednesday', 'reservant' ) },
	{ value: 4, label: __( 'Thursday', 'reservant' ) },
	{ value: 5, label: __( 'Friday', 'reservant' ) },
	{ value: 6, label: __( 'Saturday', 'reservant' ) },
	{ value: 7, label: __( 'Sunday', 'reservant' ) },
];

/** A weekly rule with a stable client-only key (`RuleInput` itself carries none) so add/remove never re-keys an unrelated row. */
interface RuleRow {
	key: string;
	weekday: number;
	start_time: string;
	end_time: string;
}

let nextRuleKey = 0;
function newRuleKey(): string {
	nextRuleKey += 1;
	return `rule-${ nextRuleKey }`;
}

function rulesFromResource( resource: Resource | null ): RuleRow[] {
	if ( null === resource ) {
		return [];
	}
	return resource.rules.map( ( rule ) => ( { key: newRuleKey(), weekday: rule.weekday, start_time: rule.start_time, end_time: rule.end_time } ) );
}

interface WeeklyRulesEditorProps {
	rules: RuleRow[];
	onAdd: ( weekday: number ) => void;
	onRemove: ( key: string ) => void;
	onChange: ( key: string, patch: Partial< Pick< RuleRow, 'start_time' | 'end_time' > > ) => void;
}

/** 7 rows (one per weekday), each listing its own windows with an "Add window" button - Task 16 brief. */
function WeeklyRulesEditor( { rules, onAdd, onRemove, onChange }: WeeklyRulesEditorProps ) {
	return (
		<div className="reservant-staff-screen__rules">
			{ WEEKDAYS.map( ( day ) => (
				<div className="reservant-staff-screen__rules-day" key={ day.value }>
					<strong>{ day.label }</strong>
					{ rules
						.filter( ( rule ) => rule.weekday === day.value )
						.map( ( rule ) => (
							<div className="reservant-staff-screen__rules-window" key={ rule.key }>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'Start', 'reservant' ) }
									value={ rule.start_time }
									onChange={ ( value ) => onChange( rule.key, { start_time: value } ) }
								/>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'End', 'reservant' ) }
									value={ rule.end_time }
									onChange={ ( value ) => onChange( rule.key, { end_time: value } ) }
								/>
								<Button variant="tertiary" onClick={ () => onRemove( rule.key ) }>
									{ __( 'Remove', 'reservant' ) }
								</Button>
							</div>
						) ) }
					<Button variant="secondary" onClick={ () => onAdd( day.value ) }>
						{ __( 'Add window', 'reservant' ) }
					</Button>
				</div>
			) ) }
		</div>
	);
}

interface ServicesChecklistProps {
	services: Service[];
	selectedIds: number[];
	onToggle: ( serviceId: number, checked: boolean ) => void;
}

function ServicesChecklist( { services, selectedIds, onToggle }: ServicesChecklistProps ) {
	return (
		<div className="reservant-staff-screen__services">
			{ services.map( ( service ) => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ service.id }
					label={ service.name }
					checked={ selectedIds.includes( service.id ) }
					onChange={ ( checked ) => onToggle( service.id, checked ) }
				/>
			) ) }
		</div>
	);
}

/** The core `/wp/v2/users?search=` combobox linking a resource to a real WP account (Task 16 brief). */
function WpUserLinkControl( { wpUserId, onChange }: { wpUserId: number | null; onChange: ( id: number | null ) => void } ) {
	const [ search, setSearch ] = useState( '' );
	const searchResults = useWpUsers( search );
	const linkedUser = useWpUser( wpUserId );

	const options = new Map< string, string >();
	if ( null !== wpUserId && undefined !== linkedUser.data ) {
		options.set( String( wpUserId ), linkedUser.data.name );
	}
	for ( const user of searchResults.data ?? [] ) {
		options.set( String( user.id ), user.name );
	}

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Linked WP user', 'reservant' ) }
			value={ null === wpUserId ? null : String( wpUserId ) }
			options={ Array.from( options.entries() ).map( ( [ value, label ] ) => ( { value, label } ) ) }
			onFilterValueChange={ setSearch }
			onChange={ ( value ) => onChange( null === value || '' === value ? null : Number( value ) ) }
		/>
	);
}

interface ExceptionsListProps {
	title: string;
	exceptions: AvailabilityExceptionListItem[];
	onDelete: ( exception: AvailabilityExceptionListItem ) => void;
	isBusy: boolean;
	isLoading: boolean;
}

/** Backed by `useExceptions()` (`GET /admin/exceptions`, Task 16b) - a null `start_time` marks an all-day closure, matching `AvailabilityExceptionListItem`'s shape. */
function ExceptionsList( { title, exceptions, onDelete, isBusy, isLoading }: ExceptionsListProps ) {
	return (
		<div className="reservant-staff-screen__exceptions">
			<h4>{ title }</h4>
			{ isLoading && <Spinner /> }
			{ ! isLoading && 0 === exceptions.length && <p>{ __( 'No dates on record.', 'reservant' ) }</p> }
			<ul>
				{ exceptions.map( ( exception ) => (
					<li key={ exception.id }>
						{ null === exception.start_time
							? exception.date
							: `${ exception.date } (${ exception.start_time }-${ exception.end_time ?? '' })` }
						<Button variant="tertiary" isBusy={ isBusy } onClick={ () => onDelete( exception ) }>
							{ __( 'Delete', 'reservant' ) }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}

interface BlackoutFormState {
	date: string;
	closedAllDay: boolean;
	startTime: string;
	endTime: string;
	reason: string;
}

function blankBlackoutForm(): BlackoutFormState {
	return { date: '', closedAllDay: true, startTime: '09:00', endTime: '17:00', reason: '' };
}

interface AddExceptionFormProps {
	form: BlackoutFormState;
	onChange: ( patch: Partial< BlackoutFormState > ) => void;
	onSubmit: () => void;
	isBusy: boolean;
}

function AddExceptionForm( { form, onChange, onSubmit, isBusy }: AddExceptionFormProps ) {
	return (
		<div className="reservant-staff-screen__add-exception">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="date"
				label={ __( 'Date', 'reservant' ) }
				value={ form.date }
				onChange={ ( value ) => onChange( { date: value } ) }
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Closed all day', 'reservant' ) }
				checked={ form.closedAllDay }
				onChange={ ( value ) => onChange( { closedAllDay: value } ) }
			/>
			{ ! form.closedAllDay && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'Start', 'reservant' ) }
						value={ form.startTime }
						onChange={ ( value ) => onChange( { startTime: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'End', 'reservant' ) }
						value={ form.endTime }
						onChange={ ( value ) => onChange( { endTime: value } ) }
					/>
				</>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Reason (note only, not stored)', 'reservant' ) }
				value={ form.reason }
				onChange={ ( value ) => onChange( { reason: value } ) }
			/>
			<Button variant="secondary" disabled={ '' === form.date.trim() } isBusy={ isBusy } onClick={ onSubmit }>
				{ __( 'Add date', 'reservant' ) }
			</Button>
		</div>
	);
}

interface StaffFormState {
	name: string;
	email: string;
	status: Resource[ 'status' ];
}

function blankStaffForm(): StaffFormState {
	return { name: '', email: '', status: 'active' };
}

function formFromResource( resource: Resource ): StaffFormState {
	return { name: resource.name, email: resource.email ?? '', status: resource.status };
}

interface StaffTableProps {
	resources: Resource[];
	selectedId: number | null;
	onSelect: ( resource: Resource ) => void;
}

function StaffTable( { resources, selectedId, onSelect }: StaffTableProps ) {
	return (
		<table className="reservant-staff-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'reservant' ) }</th>
					<th>{ __( 'Email', 'reservant' ) }</th>
					<th>{ __( 'Status', 'reservant' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ resources.map( ( resource ) => (
					<tr
						key={ resource.id }
						className={
							resource.id === selectedId ? 'reservant-staff-table__row reservant-staff-table__row--selected' : 'reservant-staff-table__row'
						}
						onClick={ () => onSelect( resource ) }
					>
						<td>{ resource.name }</td>
						<td>{ resource.email ?? '' }</td>
						<td>{ 'active' === resource.status ? __( 'Active', 'reservant' ) : __( 'Inactive', 'reservant' ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The staff catalog screen (Task 16 brief): a table plus an edit form (identity, the linked WP user
 * via a core `/wp/v2/users?search=` combobox, a services checklist, a 7-row weekly rules editor with
 * multiple windows per weekday, and the resource's own exceptions list with delete), plus a
 * business-wide blackouts panel.
 *
 * Both exceptions panels are backed by `useExceptions()` (`GET /admin/exceptions`, Task 16b
 * gap-filler): the business-wide panel calls it with no `resourceId` (business-wide rows only), the
 * per-resource panel calls it with the selected resource's id. Neither keeps its own
 * session-tracking state any more - `useAddException`/`useRemoveException` invalidate the matching
 * `useExceptions()` cache entry on success, so a reload (or another admin's edit, on next refetch)
 * is always reflected rather than only what THIS screen session has added or removed.
 */
export function StaffScreen() {
	const { addToast } = useToasts();
	const resourcesQuery = useResources();
	const servicesQuery = useServices();
	const saveResource = useSaveResource();
	const addException = useAddException();
	const removeException = useRemoveException();

	const [ selectedId, setSelectedId ] = useState< number | null >( null );
	const [ form, setForm ] = useState< StaffFormState >( blankStaffForm() );
	const [ wpUserId, setWpUserId ] = useState< number | null >( null );
	const [ serviceIds, setServiceIds ] = useState< number[] >( [] );
	const [ rules, setRules ] = useState< RuleRow[] >( [] );
	const [ exceptionForm, setExceptionForm ] = useState< BlackoutFormState >( blankBlackoutForm() );
	const [ businessForm, setBusinessForm ] = useState< BlackoutFormState >( blankBlackoutForm() );

	const businessExceptionsQuery = useExceptions();
	// `selectedId ?? undefined` falls back to the business-wide query when nothing is selected - the
	// per-resource panel that consumes this is only rendered once `selected` is non-null, so that
	// fallback result is fetched (react-query dedupes it against `businessExceptionsQuery`'s own
	// identical key) but never actually displayed.
	const resourceExceptionsQuery = useExceptions( selectedId ?? undefined );

	const resources = resourcesQuery.data ?? [];
	// Deliberately NOT kept in sync with `resourcesQuery.data` via an effect: react-query refetches
	// this list in the background (e.g. `refetchOnWindowFocus`), and an effect keyed on the selected
	// row would silently overwrite unsaved local edits (rules mid-build, a service just ticked) the
	// moment that refetch lands. `rules`/`serviceIds`/`wpUserId` are seeded ONLY by an explicit
	// `selectResource()` call (a row click, or a save's own `onSuccess` re-selecting the saved row) -
	// never by a background data change underneath an open, unsaved form.
	const selected = resources.find( ( resource ) => resource.id === selectedId ) ?? null;

	function selectResource( resource: Resource ): void {
		setSelectedId( resource.id );
		setForm( formFromResource( resource ) );
		setWpUserId( resource.wp_user_id );
		setServiceIds( resource.service_ids );
		setRules( rulesFromResource( resource ) );
	}

	function startNew(): void {
		setSelectedId( null );
		setForm( blankStaffForm() );
		setWpUserId( null );
		setServiceIds( [] );
		setRules( [] );
	}

	function toggleService( serviceId: number, checked: boolean ): void {
		setServiceIds( ( current ) => ( checked ? [ ...current, serviceId ] : current.filter( ( id ) => id !== serviceId ) ) );
	}

	function addRule( weekday: number ): void {
		setRules( ( current ) => [ ...current, { key: newRuleKey(), weekday, start_time: '09:00', end_time: '17:00' } ] );
	}

	function removeRule( key: string ): void {
		setRules( ( current ) => current.filter( ( rule ) => rule.key !== key ) );
	}

	function changeRule( key: string, patch: Partial< Pick< RuleRow, 'start_time' | 'end_time' > > ): void {
		setRules( ( current ) => current.map( ( rule ) => ( rule.key === key ? { ...rule, ...patch } : rule ) ) );
	}

	function handleSave(): void {
		saveResource.mutate(
			{
				id: selectedId ?? undefined,
				name: form.name,
				email: '' === form.email.trim() ? null : form.email,
				wp_user_id: wpUserId,
				status: form.status,
				service_ids: serviceIds,
				rules: rules.map( ( rule ) => ( { weekday: rule.weekday, start_time: rule.start_time, end_time: rule.end_time } ) ),
			},
			{
				onSuccess: ( saved ) => {
					addToast( __( 'Staff member saved.', 'reservant' ) );
					selectResource( saved );
				},
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	function exceptionPayload( blackout: BlackoutFormState, resourceId: number | null ): ExceptionInput {
		return {
			resourceId,
			date: blackout.date,
			start_time: blackout.closedAllDay ? undefined : blackout.startTime,
			end_time: blackout.closedAllDay ? undefined : blackout.endTime,
			reason: '' === blackout.reason.trim() ? undefined : blackout.reason,
		};
	}

	function handleAddResourceException(): void {
		if ( null === selectedId ) {
			return;
		}
		addException.mutate( exceptionPayload( exceptionForm, selectedId ), {
			onSuccess: () => {
				addToast( __( 'Blackout date added.', 'reservant' ) );
				setExceptionForm( blankBlackoutForm() );
			},
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	function handleRemoveResourceException( exception: AvailabilityExceptionListItem ): void {
		if ( null === selectedId ) {
			return;
		}
		removeException.mutate(
			{
				resourceId: selectedId,
				date: exception.date,
				start_time: exception.start_time ?? undefined,
				end_time: exception.end_time ?? undefined,
			},
			{
				onSuccess: () => addToast( __( 'Blackout date removed.', 'reservant' ) ),
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	function handleAddBusinessBlackout(): void {
		addException.mutate( exceptionPayload( businessForm, null ), {
			onSuccess: () => {
				addToast( __( 'Business-wide blackout added.', 'reservant' ) );
				setBusinessForm( blankBlackoutForm() );
			},
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	function handleRemoveBusinessBlackout( exception: AvailabilityExceptionListItem ): void {
		removeException.mutate(
			{
				resourceId: null,
				date: exception.date,
				start_time: exception.start_time ?? undefined,
				end_time: exception.end_time ?? undefined,
			},
			{
				onSuccess: () => addToast( __( 'Business-wide blackout removed.', 'reservant' ) ),
				onError: ( error ) => addToast( errorMessage( error ), 'error' ),
			}
		);
	}

	const canSave = '' !== form.name.trim();

	return (
		<div className="reservant-staff-screen">
			{ resourcesQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load staff.', 'reservant' ) }
				</Notice>
			) }
			{ resourcesQuery.isLoading && <Spinner /> }

			<Button variant="primary" onClick={ startNew }>
				{ __( 'New staff member', 'reservant' ) }
			</Button>

			<StaffTable resources={ resources } selectedId={ selectedId } onSelect={ selectResource } />

			<div className="reservant-staff-screen__editor">
				<h2>{ null === selectedId ? __( 'New staff member', 'reservant' ) : __( 'Edit staff member', 'reservant' ) }</h2>

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'reservant' ) }
					value={ form.name }
					onChange={ ( value ) => setForm( ( current ) => ( { ...current, name: value } ) ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="email"
					label={ __( 'Email', 'reservant' ) }
					value={ form.email }
					onChange={ ( value ) => setForm( ( current ) => ( { ...current, email: value } ) ) }
				/>
				<WpUserLinkControl wpUserId={ wpUserId } onChange={ setWpUserId } />

				{ null !== selectedId && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'reservant' ) }
						value={ form.status }
						options={ [
							{ label: __( 'Active', 'reservant' ), value: 'active' },
							{ label: __( 'Inactive', 'reservant' ), value: 'inactive' },
						] }
						onChange={ ( value ) => setForm( ( current ) => ( { ...current, status: value as Resource[ 'status' ] } ) ) }
					/>
				) }

				<h3>{ __( 'Services performed', 'reservant' ) }</h3>
				<ServicesChecklist services={ servicesQuery.data ?? [] } selectedIds={ serviceIds } onToggle={ toggleService } />

				<h3>{ __( 'Weekly availability', 'reservant' ) }</h3>
				<WeeklyRulesEditor rules={ rules } onAdd={ addRule } onRemove={ removeRule } onChange={ changeRule } />

				<Button variant="primary" disabled={ ! canSave } isBusy={ saveResource.isPending } onClick={ handleSave }>
					{ __( 'Save staff member', 'reservant' ) }
				</Button>

				{ null !== selected && (
					<>
						<h3>{ __( 'Exceptions (this staff member)', 'reservant' ) }</h3>
						{ resourceExceptionsQuery.isError && (
							<Notice status="error" isDismissible={ false }>
								{ __( 'Could not load this staff member\'s blackout dates.', 'reservant' ) }
							</Notice>
						) }
						<ExceptionsList
							title={ __( 'Blackout dates', 'reservant' ) }
							exceptions={ resourceExceptionsQuery.data ?? [] }
							onDelete={ handleRemoveResourceException }
							isBusy={ removeException.isPending }
							isLoading={ resourceExceptionsQuery.isLoading }
						/>
						<AddExceptionForm
							form={ exceptionForm }
							onChange={ ( patch ) => setExceptionForm( ( current ) => ( { ...current, ...patch } ) ) }
							onSubmit={ handleAddResourceException }
							isBusy={ addException.isPending }
						/>
					</>
				) }
			</div>

			<div className="reservant-staff-screen__business-blackouts">
				<h2>{ __( 'Business-wide blackouts', 'reservant' ) }</h2>
				{ businessExceptionsQuery.isError && (
					<Notice status="error" isDismissible={ false }>
						{ __( 'Could not load business-wide blackout dates.', 'reservant' ) }
					</Notice>
				) }
				<ExceptionsList
					title={ __( 'Blackout dates', 'reservant' ) }
					exceptions={ businessExceptionsQuery.data ?? [] }
					onDelete={ handleRemoveBusinessBlackout }
					isBusy={ removeException.isPending }
					isLoading={ businessExceptionsQuery.isLoading }
				/>
				<AddExceptionForm
					form={ businessForm }
					onChange={ ( patch ) => setBusinessForm( ( current ) => ( { ...current, ...patch } ) ) }
					onSubmit={ handleAddBusinessBlackout }
					isBusy={ addException.isPending }
				/>
			</div>
		</div>
	);
}
