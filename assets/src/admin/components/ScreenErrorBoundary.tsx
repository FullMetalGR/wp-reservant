import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import type { ErrorInfo, ReactNode } from 'react';

interface ScreenErrorBoundaryProps {
	children: ReactNode;
}

interface ScreenErrorBoundaryState {
	error: Error | null;
	componentStack: string | null;
}

/**
 * Contains a render error to the ONE screen that threw, instead of letting React unmount the whole
 * tree and leave the admin looking at bare wp-admin chrome with no dashboard in it at all.
 *
 * This exists because that is exactly what happened: `GET /admin/seat-maps` answered rows with no
 * `seats` key while the `SeatMap` type declared it required, `SeatMapsScreen` read
 * `map.seats.length`, and the resulting `TypeError` took down every other screen's chrome with it -
 * a wire-shape mismatch on one route presenting as "the plugin's admin is blank".
 *
 * It deliberately SURFACES the failure rather than hiding it: the thrown message and the React
 * component stack are rendered inline, and the original error is re-reported through
 * `console.error` so it still reaches the browser console (and any error-reporting integration
 * listening there) with its own stack intact. A swallowed error would trade one visible bug for an
 * invisible one - a screen that silently renders nothing is harder to diagnose than a blank page,
 * not easier.
 *
 * Reset is by remount: `App` keys this boundary on the current route, so navigating anywhere else
 * (or back) mounts a fresh boundary and the screen gets another honest attempt.
 */
export class ScreenErrorBoundary extends Component< ScreenErrorBoundaryProps, ScreenErrorBoundaryState > {
	constructor( props: ScreenErrorBoundaryProps ) {
		super( props );
		this.state = { error: null, componentStack: null };
	}

	static getDerivedStateFromError( error: Error ): Partial< ScreenErrorBoundaryState > {
		return { error };
	}

	componentDidCatch( error: Error, info: ErrorInfo ): void {
		this.setState( { componentStack: info.componentStack ?? null } );
		// eslint-disable-next-line no-console
		console.error( 'Reservant: a dashboard screen failed to render.', error );
	}

	render(): ReactNode {
		const { error, componentStack } = this.state;
		if ( null === error ) {
			return this.props.children;
		}

		return (
			<Notice status="error" isDismissible={ false }>
				<p>
					<strong>{ __( 'This screen could not be displayed.', 'reservant' ) }</strong>
				</p>
				<p>{ __( 'The rest of the dashboard still works - the details below are what went wrong here.', 'reservant' ) }</p>
				<pre className="reservant-admin__error-detail" data-testid="screen-error-detail">
					{ `${ error.name }: ${ error.message }${ null === componentStack ? '' : componentStack }` }
				</pre>
			</Notice>
		);
	}
}
