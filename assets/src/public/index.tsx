/**
 * Public booking widget entry - builds to `build/widget.js` + `build/widget.css`.
 *
 * This bundle is served to visitors, so its dependency list is deliberately tiny: `wp-element`,
 * `wp-i18n` and React only. `@wordpress/components` may NOT be imported from anywhere reachable
 * here - it alone is bigger than the whole 100 KB budget `npm run size` enforces. The block editor
 * script is a separate entry and may use it.
 *
 * The mount node is rendered by PHP (`src/Frontend/`), which is the only place that knows the
 * booking context, so every input arrives as a `data-` attribute on that node:
 *
 *   <div class="reservant-widget" data-mode="book"   data-service="3" data-staff="7">
 *   <div class="reservant-widget" data-mode="manage" data-uuid="..."  data-token="...">
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

export type WidgetMode = 'book' | 'manage';

export interface WidgetConfig {
	/** Which journey the mount node asked for. Anything unrecognised books. */
	mode: WidgetMode;
	/** Preselected service, or null when the visitor picks one. */
	serviceId: number | null;
	/** Preselected staff member, or null for "no preference". */
	resourceId: number | null;
	/** Manage mode only: the booking being managed. */
	uuid: string | null;
	/** Manage mode only: the magic-link secret. Verified server-side, never here. */
	token: string | null;
}

/**
 * Row ids are positive integers. The shortcode's default is an empty attribute and the block's is
 * `0`, and both mean "nothing preselected" - so they collapse to null rather than to a service id
 * that cannot exist.
 */
function readId( raw: string | undefined ): number | null {
	const value = Number.parseInt( raw ?? '', 10 );

	return Number.isInteger( value ) && value > 0 ? value : null;
}

function readText( raw: string | undefined ): string | null {
	const value = ( raw ?? '' ).trim();

	return '' === value ? null : value;
}

function readConfig( el: HTMLElement ): WidgetConfig {
	return {
		mode: 'manage' === el.dataset.mode ? 'manage' : 'book',
		serviceId: readId( el.dataset.service ),
		resourceId: readId( el.dataset.staff ),
		uuid: readText( el.dataset.uuid ),
		token: readText( el.dataset.token ),
	};
}

/**
 * The scaffold body. It renders the widget shell and the state the real journey starts in; the
 * booking flow and the manage view replace it (plan Tasks 14 and 15), which is why the mode is
 * already read and branched on here rather than later.
 */
function Widget( { config }: { config: WidgetConfig } ): JSX.Element {
	return (
		<div className="reservant-widget__panel" role="status" aria-live="polite">
			{ 'manage' === config.mode
				? __( 'Loading your booking...', 'reservant' )
				: __( 'Loading booking options...', 'reservant' ) }
		</div>
	);
}

/** Mounts one widget into the node PHP rendered. */
export function mountWidget( el: HTMLElement ): void {
	createRoot( el ).render( <Widget config={ readConfig( el ) } /> );
}

/**
 * Self-boot. Nothing else calls in: the shortcode, the block and the manage route all render the
 * same `.reservant-widget` node and enqueue this script, so the script finds its own mount points.
 * A page may hold more than one.
 */
export function mountAll(): void {
	document
		.querySelectorAll< HTMLElement >( '.reservant-widget' )
		.forEach( ( el ) => mountWidget( el ) );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
