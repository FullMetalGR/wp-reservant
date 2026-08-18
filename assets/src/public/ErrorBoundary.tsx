/**
 * The widget's last line of defence (P5 plan, Task 16): a render error anywhere in the journey
 * would otherwise make React unmount the WHOLE widget tree, leaving the visitor a silently empty
 * div in the middle of a working page - the public twin of the blank-dashboard failure that
 * created the admin's ScreenErrorBoundary. One boundary around the whole widget, not one per
 * step: a step that crashes mid-booking has no honest partial UI to fall back to (its state is
 * gone with it), so the only truthful render is the widget-level sentence.
 *
 * Unlike the admin boundary, the fallback carries NO error detail - the audience is a visitor
 * who cannot act on a component stack, not an owner diagnosing a screen. The diagnostic channel
 * is `console.error`, which re-reports the original error with its stack intact where the
 * browser's own tooling (and any error-reporting integration listening there) can see it. A
 * swallowed error would trade one visible bug for an invisible one.
 *
 * `role="alert"` per the widget's live-region convention (index.tsx): a genuine failure with
 * nothing better to show is the one case reserved for alert, and an alert announces on
 * appearance, so it may mount with its text - no mount-empty deferral here.
 *
 * A boundary only catches RENDER-phase throws. Failures in event handlers and async work never
 * reach it by React's design - the query layer owns those (NoticeRegion/noticeOf), and they must
 * not be routed here: a fetch refusal is a recoverable answer, not a dead widget.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ErrorInfo, ReactNode } from 'react';

interface ErrorBoundaryProps {
	children: ReactNode;
}

interface ErrorBoundaryState {
	failed: boolean;
}

export class ErrorBoundary extends Component< ErrorBoundaryProps, ErrorBoundaryState > {
	public constructor( props: ErrorBoundaryProps ) {
		super( props );
		this.state = { failed: false };
	}

	public static getDerivedStateFromError(): ErrorBoundaryState {
		return { failed: true };
	}

	public componentDidCatch( error: Error, info: ErrorInfo ): void {
		// eslint-disable-next-line no-console
		console.error( 'Reservant: the booking widget failed to render.', error, info.componentStack );
	}

	public render(): ReactNode {
		if ( ! this.state.failed ) {
			return this.props.children;
		}

		return (
			<p className="reservant-widget__error" role="alert">
				{ __(
					'The booking widget hit an unexpected error. Please reload the page, or contact us directly to book.',
					'reservant'
				) }
			</p>
		);
	}
}
