/**
 * Public booking widget entry - builds to `build/widget.js` plus `build/style-widget.css` (and
 * its RTL twin `build/style-widget-rtl.css`). The stylesheet is NOT `widget.css`: wp-scripts
 * collects any source file named `style.css` into a shared chunk named `style-<entry>` after the
 * first entry that imports it. The header of `./style.css` documents the rename hazard; PHP must
 * enqueue `build/style-widget.css`, and `npm run size` asserts that file exists after every build.
 *
 * This bundle is served to visitors, so its dependency list is deliberately tiny: `wp-element`,
 * `wp-i18n`, `react` (already a dependency of core's wp-element, so it costs nothing extra) and
 * the React JSX runtime. `@tanstack/react-query` is a real BUNDLED dependency, counted by the
 * byte budget. `@wordpress/components` may NOT be imported from
 * anywhere reachable here - it alone is ~800 KB of core script plus ~97 KB of CSS handed to every
 * visitor. Bundled bytes cannot catch that: every `@wordpress/*` package is a webpack external,
 * so importing it adds a handle to `build/widget.asset.php`, not weight to `build/widget.js`.
 * `npm run size` therefore runs two checks - `bin/bundle-budget.mjs` bounds the bundled bytes,
 * and `bin/widget-contract.mjs` fails the build on any script handle outside the allowed
 * externals. The block editor script is a separate entry and may use `@wordpress/components`.
 *
 * The mount node is rendered by PHP (`src/Frontend/`), which is the only place that knows the
 * booking context, so every input arrives as a `data-` attribute on that node:
 *
 *   <div class="reservant-widget" data-mode="book"   data-service="3" data-staff="7">
 *   <div class="reservant-widget" data-mode="manage" data-uuid="..."  data-token="...">
 */
import { createRoot } from '@wordpress/element';
import { BookingFlow } from './BookingFlow';
import { ManageView } from './ManageView';
import './style.css';

type WidgetMode = 'book' | 'manage';

/**
 * Exported because `BookingFlow` (Task 14) consumes it. Under the current fallow config (8e519d1
 * dropped this file from `.fallowrc.json`'s entries) an export with NO consumer is an ERROR-level
 * `fallow/unused-export` that fails the build - so this export exists exactly as long as a real
 * importer does, and a test file counts as a real consumer (the adjudicated precedent on this
 * branch). The manage view (Task 15) is expected to be the next importer.
 */
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
		// Through readText like uuid and token: shortcode attributes are user text and
		// `shortcode_atts` does not trim, so `data-mode="manage "` must still mean manage -
		// matching it exactly would drop a magic-link visitor into the booking flow.
		mode: 'manage' === readText( el.dataset.mode ) ? 'manage' : 'book',
		serviceId: readId( el.dataset.service ),
		resourceId: readId( el.dataset.staff ),
		uuid: readText( el.dataset.uuid ),
		token: readText( el.dataset.token ),
	};
}

/**
 * The widget body: book mode mounts the booking journey (`BookingFlow`, plan Task 14), manage
 * mode the magic-link manage journey (`ManageView`, plan Task 15).
 *
 * The widget's live-region convention, which both journeys and every component follow: a live
 * region never sits on `.reservant-widget__panel` or any other container that will hold a form -
 * screen readers would re-announce the whole panel on every keystroke. `role="status"` is for a
 * polite answer to something the visitor just did, `role="alert"` only for a genuine failure
 * with nothing better to show, and a region must exist BEFORE its message lands - a region born
 * with text announces nothing.
 */
function Widget( { config }: { config: WidgetConfig } ): JSX.Element {
	return (
		<div className="reservant-widget__panel">
			{ 'manage' === config.mode ? (
				<ManageView config={ config } />
			) : (
				<BookingFlow config={ config } />
			) }
		</div>
	);
}

/**
 * Nodes this bundle already mounted, shared across bundle EXECUTIONS through `window`: the
 * block's `viewScript` and the shortcode's enqueue can register this same file under two handles,
 * and browsers do not deduplicate classic scripts by URL - so the bundle can run twice on one
 * page, and the second run gets fresh module scope. A module-scoped guard would reset with it,
 * and the second `createRoot()` would silently replace the first root and destroy whatever the
 * visitor had in progress (a half-filled form, a running hold countdown).
 *
 * A WeakSet rather than a `data-` marker attribute: an attribute could arrive pre-set in server
 * markup (a page saved from a live DOM, say) and the widget would then never mount at all.
 * Markup cannot forge the WeakSet, and it releases each node with the page - but a SCRIPT can
 * still clobber the window property, and on a public page third-party scripts run first. Hence
 * `instanceof`, not `??=`: a bare `??=` keeps any non-nullish junk (`window.reservantWidgetMounted
 * = 0` from an analytics shim, say), and the first `mounted.has()` then throws inside the
 * import-time self-boot - no widget mounts anywhere, with no plugin-owned diagnostic. Treating a
 * clobbered value as absent risks at worst one duplicate mount; keeping it kills the page.
 */
const host = window as Window & { reservantWidgetMounted?: unknown };
const mounted: WeakSet< HTMLElement > =
	host.reservantWidgetMounted instanceof WeakSet
		? host.reservantWidgetMounted
		: ( host.reservantWidgetMounted = new WeakSet< HTMLElement >() );

/** Mounts one widget into the node PHP rendered. Mounting the same node again is a no-op. */
export function mountWidget( el: HTMLElement ): void {
	if ( mounted.has( el ) ) {
		return;
	}

	mounted.add( el );
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
