import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { ApiError, errorMessage, isReferencedConflict } from '../api/client';
import { useSaveSeatMap, useSeatMaps } from '../api/queries';
import type { Seat, SeatMap } from '../api/types';
import { RowSelectButton } from '../components/RowSelectButton';
import { useToasts } from '../components/Toasts';

const EXAMPLE_SPEC = 'rows A-J, 12 per row, aisle after 6';

interface SeatRow {
	sortRow: number;
	seats: Seat[];
}

/**
 * Groups a map's seats into rows by `sort_row` (never assumed contiguous or 1-based - a spec's
 * rows are lettered, `SeatMapSpec::seats()` numbers `sort_row` off that label range), each row's
 * own seats ordered by `sort_col` - the shape `SeatMapPreview` renders.
 */
function groupIntoRows( seats: Seat[] ): SeatRow[] {
	const byRow = new Map< number, Seat[] >();
	for ( const seat of seats ) {
		const row = byRow.get( seat.sort_row ) ?? [];
		row.push( seat );
		byRow.set( seat.sort_row, row );
	}
	return Array.from( byRow.entries() )
		.sort( ( [ a ], [ b ] ) => a - b )
		.map( ( [ sortRow, rowSeats ] ) => ( { sortRow, seats: [ ...rowSeats ].sort( ( a, b ) => a.sort_col - b.sort_col ) } ) );
}

function cellLabel( seat: Seat ): string {
	return 'seat' === seat.kind ? seat.seat_label : '';
}

/**
 * The live grid preview (Task 16 brief): one row element per distinct `sort_row`, one cell per
 * seat - chips for `kind` (seat/aisle/blocked), the aisle/blocked ones marked distinctly via both
 * `data-kind` and a `--{kind}` class. Server-driven, not a client-side spec parser: it renders
 * whatever `seats` the currently loaded map carries, which only changes once a save round-trips
 * through `SeatMapSpec::parse()` on the server - there is no local grammar for the spec text here,
 * so a brand-new, unsaved map has nothing to preview yet.
 */
export function SeatMapPreview( { seats }: { seats: Seat[] } ) {
	const rows = groupIntoRows( seats );
	return (
		<div className="reservant-seatmap-preview">
			{ rows.map( ( row ) => (
				<div className="reservant-seatmap-preview__row" data-testid="seatmap-row" key={ row.sortRow }>
					{ row.seats.map( ( seat ) => (
						<span
							key={ seat.id }
							data-testid="seatmap-cell"
							data-kind={ seat.kind }
							className={ `reservant-seatmap-preview__cell reservant-seatmap-preview__cell--${ seat.kind }` }
						>
							{ cellLabel( seat ) }
						</span>
					) ) }
				</div>
			) ) }
		</div>
	);
}

interface SeatMapListProps {
	seatMaps: SeatMap[];
	selectedId: number | null;
	lockedIds: ReadonlySet< number >;
	onSelect: ( id: number ) => void;
	onNew: () => void;
}

function SeatMapList( { seatMaps, selectedId, lockedIds, onSelect, onNew }: SeatMapListProps ) {
	return (
		<div className="reservant-seatmaps-screen__list">
			<Button variant="primary" onClick={ onNew }>
				{ __( 'New seat map', 'reservant' ) }
			</Button>
			<table className="reservant-seatmaps-table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'reservant' ) }</th>
						<th>{ __( 'Seats', 'reservant' ) }</th>
						<th>{ __( 'Status', 'reservant' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ seatMaps.map( ( map ) => (
						<tr
							key={ map.id }
							className={
								map.id === selectedId
									? 'reservant-seatmaps-table__row reservant-seatmaps-table__row--selected'
									: 'reservant-seatmaps-table__row'
							}
							onClick={ () => onSelect( map.id ) }
						>
							<td>
								<RowSelectButton label={ map.name } isSelected={ map.id === selectedId } onSelect={ () => onSelect( map.id ) } />
							</td>
							<td>{ map.seats.length }</td>
							<td>{ lockedIds.has( map.id ) ? __( 'Locked (in use)', 'reservant' ) : __( 'Editable', 'reservant' ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * The seat map catalog screen (Task 16 brief): a list, a name/spec editor with the live
 * `SeatMapPreview`, inline parse errors surfaced from a 400 (`SpecParseError`'s own message,
 * forwarded verbatim in `ApiError.detail`), and an edit lock once a save answers 409 `referenced` -
 * some seat on the map is already claimed by an active booking, so re-parsing it would silently
 * renumber a seat a live claim still names (`SeatMapsAdminController`'s own class docblock). The
 * lock is per-map and client-side only (there is no "is this map locked" GET field); it persists
 * for the rest of this session once observed, so a caller cannot retry the same doomed edit twice.
 */
export function SeatMapsScreen() {
	const { addToast } = useToasts();
	const seatMapsQuery = useSeatMaps();
	const saveSeatMap = useSaveSeatMap();

	const [ selectedId, setSelectedId ] = useState< number | null >( null );
	const [ name, setName ] = useState( '' );
	const [ spec, setSpec ] = useState( '' );
	const [ parseError, setParseError ] = useState< string | null >( null );
	const [ lockedIds, setLockedIds ] = useState< ReadonlySet< number > >( new Set() );
	// The map a save just answered with, kept only until another row is selected. `GET
	// /admin/seat-maps` carries every row's `seats` (`SeatMapsAdminController::index()`), so the
	// selected row's grid is normally read straight off the list - but a save's own invalidation
	// refetch is asynchronous, and a re-parsed spec's NEW grid is already in hand before it lands.
	// Preferring it here is what makes the preview show what was just saved rather than the previous
	// parse for the width of that refetch.
	const [ savedMap, setSavedMap ] = useState< SeatMap | null >( null );

	const seatMaps = seatMapsQuery.data ?? [];
	const listed = seatMaps.find( ( map ) => map.id === selectedId ) ?? null;
	const selected = null !== savedMap && savedMap.id === selectedId ? savedMap : listed;
	const isLocked = null !== selectedId && lockedIds.has( selectedId );

	function selectMap( id: number ): void {
		const map = seatMaps.find( ( entry ) => entry.id === id );
		if ( undefined === map ) {
			return;
		}
		setSelectedId( id );
		setName( map.name );
		setSpec( map.spec );
		setParseError( null );
		setSavedMap( null );
	}

	function startNew(): void {
		setSelectedId( null );
		setName( '' );
		setSpec( '' );
		setParseError( null );
		setSavedMap( null );
	}

	function handleSave(): void {
		setParseError( null );
		saveSeatMap.mutate(
			{ id: selectedId ?? undefined, name, spec },
			{
				onSuccess: ( saved ) => {
					addToast( __( 'Seat map saved.', 'reservant' ) );
					setSelectedId( saved.id );
					setSavedMap( saved );
				},
				onError: ( error ) => {
					// A 400 here is a `SpecParseError` - `Rest\Errors::badRequest()` puts the parser's
					// own sentence in `detail` (its `message` is the useless literal
					// `invalid_request`), which `errorMessage()` is precisely what prefers.
					if ( error instanceof ApiError && 400 === error.status ) {
						setParseError( errorMessage( error ) );
						return;
					}
					if ( isReferencedConflict( error ) && null !== selectedId ) {
						setLockedIds( ( current ) => new Set( current ).add( selectedId ) );
						addToast( errorMessage( error ), 'error' );
						return;
					}
					addToast( errorMessage( error ), 'error' );
				},
			}
		);
	}

	const canSave = '' !== name.trim() && '' !== spec.trim() && ! isLocked;

	return (
		<div className="reservant-seatmaps-screen">
			{ seatMapsQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load seat maps.', 'reservant' ) }
				</Notice>
			) }
			{ seatMapsQuery.isLoading && <Spinner /> }

			<SeatMapList seatMaps={ seatMaps } selectedId={ selectedId } lockedIds={ lockedIds } onSelect={ selectMap } onNew={ startNew } />

			<div className="reservant-seatmaps-screen__editor">
				<h2>{ null === selectedId ? __( 'New seat map', 'reservant' ) : __( 'Edit seat map', 'reservant' ) }</h2>

				{ isLocked && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'This seat map is locked: a seat on it is already claimed by an active booking, so it can no longer be edited.', 'reservant' ) }
					</Notice>
				) }

				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'reservant' ) }
					value={ name }
					disabled={ isLocked }
					onChange={ setName }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Spec', 'reservant' ) }
					help={ __( 'e.g. "rows A-J, 12 per row, aisle after 6"', 'reservant' ) }
					placeholder={ EXAMPLE_SPEC }
					value={ spec }
					disabled={ isLocked }
					onChange={ setSpec }
				/>

				{ null !== parseError && (
					<Notice status="error" isDismissible={ false }>
						{ parseError }
					</Notice>
				) }

				<Button variant="primary" disabled={ ! canSave } isBusy={ saveSeatMap.isPending } onClick={ handleSave }>
					{ __( 'Save seat map', 'reservant' ) }
				</Button>

				<h3>{ __( 'Preview', 'reservant' ) }</h3>
				<SeatMapPreview seats={ selected?.seats ?? [] } />
			</div>
		</div>
	);
}
