import { createRoot } from '@wordpress/element';
import { bootConfig } from './boot';
import { App } from './components/App';

const el = document.getElementById( 'reservant-admin-root' );
if ( el ) {
	const screen = el.dataset.screen ?? 'calendar';
	createRoot( el ).render( <App screen={ screen } caps={ bootConfig().caps } /> );
}
