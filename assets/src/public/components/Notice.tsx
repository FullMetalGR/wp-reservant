/**
 * The widget's one notice surface: `noticeOf` classifies a failure under the live-region
 * convention documented in `index.tsx`, `NoticeRegion` renders the result, and `ProgressStatus`
 * is the same mount-empty mechanism for progress lines. One home for all three: while Tasks 14
 * and 15 were fenced off from each other's files, `BookingFlow` and `RescheduleDialog` each
 * carried a copy, and `ManageView` imported the manage copies from its own child dialog - a
 * backwards dependency this module exists to end.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ApiError, errorMessage } from '../../shared';

/** One rendered notice: polite (`role="status"`) for a server-worded refusal, alert otherwise. */
export interface Notice {
	text: string;
	alert: boolean;
}

/**
 * A 4xx carries a sentence the server worded for the visitor (`data.detail`), a polite answer to
 * what they just did; a 5xx is a genuine failure with nothing better to show - the one case the
 * widget's live-region convention reserves `role="alert"` for. A non-`ApiError` never reaches
 * `errorMessage()`: its `.message` is browser jargon ("Failed to fetch") no guest should be
 * handed, so it gets a fixed human sentence instead - handled here rather than in
 * `shared/errors.ts`, because `errorMessage` also serves admin screens where the raw message is
 * the most useful thing to show.
 */
export function noticeOf( error: unknown ): Notice {
	if ( ! ( error instanceof ApiError ) ) {
		return {
			text: __(
				'We could not reach the server. Please check your connection and try again.',
				'reservant'
			),
			alert: true,
		};
	}
	return { text: errorMessage( error ), alert: error.status >= 500 };
}

/**
 * The always-mounted polite region plus a conditional alert: the status region exists before its
 * message so the announcement lands in a region that was already there, held back until one
 * effect after mount so a region mounting with a notice already in hand still announces; an
 * alert announces on appearance, so it may mount with its text.
 *
 * The two former copies (`BookingFlow`'s `StepNotice`, the dialog's `NoticeRegion`) behaved
 * identically and differed only in the BEM block their class names hang off - so this is ONE
 * component taking that block as `classBase` (`reservant-flow` or `reservant-manage`), not two
 * components.
 */
export function NoticeRegion( {
	notice,
	classBase,
}: {
	notice: Notice | null;
	classBase: string;
} ): JSX.Element {
	const [ settled, setSettled ] = useState( false );
	useEffect( () => {
		setSettled( true );
	}, [] );
	return (
		<>
			<p className={ `${ classBase }__notice` } role="status">
				{ settled && null !== notice && ! notice.alert ? notice.text : '' }
			</p>
			{ null !== notice && notice.alert && (
				<p className={ `${ classBase }__alert` } role="alert">
					{ notice.text }
				</p>
			) }
		</>
	);
}

/**
 * A progress line that mounts EMPTY and takes its text one effect later - the NoticeRegion
 * mechanism above, for the same reason: a region born with text announces nothing, and every
 * progress line on this surface mounts at the exact moment its work starts.
 */
export function ProgressStatus( {
	text,
	className,
}: {
	text: string;
	className: string;
} ): JSX.Element {
	const [ settled, setSettled ] = useState( false );
	useEffect( () => {
		setSettled( true );
	}, [] );
	return (
		<p className={ className } role="status">
			{ settled ? text : '' }
		</p>
	);
}
