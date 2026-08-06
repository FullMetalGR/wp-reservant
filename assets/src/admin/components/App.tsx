import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Notice } from '@wordpress/components';
import { ToastProvider } from './Toasts';
import { CalendarScreen } from '../screens/CalendarScreen';
import { MyCalendarScreen } from '../screens/MyCalendarScreen';

export interface HashRoute {
	screen: string;
	id?: string;
}

/**
 * Hash routing is detail-only (AGENTS.md P4 spec, "Screens and menu"): the wp-admin page itself
 * already picks the screen - one real page per menu slug, `data-screen` on the mount point set by
 * `AdminPage::render()` - so the hash never carries a screen of its own, only a detail id for
 * that same screen, e.g. `admin.php?page=reservant-bookings#/{uuid}`.
 */
export function parseHash( screen: string, hash: string ): HashRoute {
	const match = /^#\/(.*)$/.exec( hash );
	if ( null === match ) {
		return { screen };
	}
	const id = decodeURIComponent( match[ 1 ] ?? '' );
	return '' === id ? { screen } : { screen, id };
}

const SCREEN_TITLES: Record< string, () => string > = {
	calendar: () => __( 'Calendar', 'reservant' ),
	bookings: () => __( 'Bookings', 'reservant' ),
	services: () => __( 'Services', 'reservant' ),
	staff: () => __( 'Staff', 'reservant' ),
	events: () => __( 'Events', 'reservant' ),
	'seat-maps': () => __( 'Seat Maps', 'reservant' ),
	settings: () => __( 'Settings', 'reservant' ),
	'my-calendar': () => __( 'My Calendar', 'reservant' ),
};

interface NavAction {
	cap: string;
	label: string;
}

/**
 * The only nav action the chassis itself knows about; later tasks (15) wire it to
 * `ManualBookingDrawer`. Filtered by the caller's Reservant caps (from the inline boot config) so
 * a user without `reservant_manage_bookings` - a staff-only viewer, most notably - never sees it:
 * "the SPA renders no action buttons for staff-role users" (AGENTS.md P4 spec).
 */
function visibleActions( caps: string[] ): NavAction[] {
	const actions: NavAction[] = [ { cap: 'reservant_manage_bookings', label: __( 'New booking', 'reservant' ) } ];
	return actions.filter( ( action ) => caps.includes( action.cap ) );
}

/**
 * Renders the current route's screen. `calendar` and `my-calendar` are real (Task 14); every other
 * screen is still the Task 13 placeholder - Tasks 15-16 replace each in turn without touching this
 * switch's shape.
 */
function renderScreen( route: HashRoute ): JSX.Element {
	switch ( route.screen ) {
		case 'calendar':
			return <CalendarScreen />;
		case 'my-calendar':
			return <MyCalendarScreen />;
		default:
			return (
				<Notice status="info" isDismissible={ false }>
					{ route.id
						? __( 'This detail view is not built yet.', 'reservant' )
						: __( 'This screen is not built yet.', 'reservant' ) }
				</Notice>
			);
	}
}

function useHash(): string {
	const [ hash, setHash ] = useState( window.location.hash );

	useEffect( () => {
		const onHashChange = (): void => setHash( window.location.hash );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	return hash;
}

const queryClient = new QueryClient();

export interface AppProps {
	/** The page's own screen, read off `data-screen` on the mount point - static for the page's lifetime. */
	screen: string;
	/** The current user's Reservant capabilities, from the inline boot config. */
	caps: string[];
}

/**
 * The router shell (AGENTS.md P4, Task 13): mounts the query cache and toast queue once, then
 * switches on the page's own screen plus whatever detail id the hash carries via `renderScreen()`.
 * Tasks 15-16 replace the remaining placeholder cases with real components as they land, without
 * touching the chassis around it.
 */
export function App( { screen, caps }: AppProps ) {
	const hash = useHash();
	const route = parseHash( screen, hash );
	const title = ( SCREEN_TITLES[ route.screen ] ?? ( () => route.screen ) )();
	const actions = visibleActions( caps );

	return (
		<QueryClientProvider client={ queryClient }>
			<ToastProvider>
				<div className="reservant-admin">
					<div className="reservant-admin-header">
						<h1>{ title }</h1>
						{ actions.map( ( action ) => (
							<button key={ action.cap } type="button" className="button button-primary">
								{ action.label }
							</button>
						) ) }
					</div>
					{ renderScreen( route ) }
				</div>
			</ToastProvider>
		</QueryClientProvider>
	);
}
