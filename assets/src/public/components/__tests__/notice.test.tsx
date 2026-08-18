import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ProgressStatus } from '../Notice';

/**
 * `ProgressStatus` is the mount-empty half of the widget's live-region convention (index.tsx): a
 * region born with text announces nothing, so the component must enter the document empty and
 * take its text one effect later. Task 15's suites assert only the END state (the text is
 * there), which a component born pre-filled satisfies too - the coordinator's Task 16 mutation
 * pass proved that changing `ProgressStatus` to render its text on the mounting commit kills no
 * test. This suite closes that hole with the DOM-write-ORDER technique from commit 9465b1b.
 */
describe( 'ProgressStatus', () => {
	it( 'mounts its region empty - the text arrives only once the region exists', () => {
		// The deferral itself, pinned on DOM-write ORDER: render() cannot observe it (Testing
		// Library flushes effects inside act, so the end state always carries the text); a
		// MutationObserver sees the raw writes. Records are collected in the callback AND
		// drained with takeRecords(): anything that yields to microtasks between observe() and
		// the read hands pending records to the callback, and takeRecords() alone would then
		// answer an empty list.
		const records: MutationRecord[] = [];
		const observer = new MutationObserver( ( batch ) => records.push( ...batch ) );
		observer.observe( document.body, {
			childList: true,
			subtree: true,
			characterData: true,
		} );
		render(
			<ProgressStatus
				text="Loading your booking..."
				className="reservant-manage__loading"
			/>
		);
		records.push( ...observer.takeRecords() );
		observer.disconnect();

		const region = screen.getByRole( 'status' );
		expect( region ).toHaveTextContent( 'Loading your booking...' );
		const insertedAt = records.findIndex( ( record ) =>
			Array.from( record.addedNodes ).some(
				( node ) =>
					node === region || ( node instanceof Element && node.contains( region ) )
			)
		);
		const textAt = records.findIndex(
			( record ) =>
				( 'characterData' === record.type && region.contains( record.target ) ) ||
				( 'childList' === record.type &&
					record.target === region &&
					Array.from( record.addedNodes ).some(
						( node ) => Node.TEXT_NODE === node.nodeType
					) )
		);
		expect( insertedAt ).toBeGreaterThanOrEqual( 0 );
		expect( textAt ).toBeGreaterThan( insertedAt );
	} );
} );
