/**
 * The stylesheet's CASCADE, tested against the real file. `BlockTest` pins that the block emits
 * the `reservant-widget--compact` class NAME; nothing else proves the class DOES anything -
 * moving the modifier block above the token block, or adding a second `.reservant-widget` token
 * block below it, neutralises `--reservant-space` (equal single-class specificity, later source
 * order wins) with every other gate green. This suite loads `style.css` into the test document
 * and reads the values the cascade actually resolves.
 *
 * jsdom facts this rests on (probed against jsdom 20, the jest environment's version): custom
 * properties DO cascade through `getComputedStyle` - source order and specificity honoured, both
 * neutralisation traps above collapse compact to the base value - but `var()` is NOT substituted
 * into standard properties (a padding of `var(--x)` computes to ''), so the "someone consumes
 * the token" half walks the PARSED rules instead of computed style. Parsed, not the raw text: a
 * consumer inside a comment cannot satisfy it, and a stylesheet jsdom cannot parse at all fails
 * the suite through `@wordpress/jest-console` (jsdom reports parse failures via console.error).
 */
import { readFileSync } from 'fs';

const CSS = readFileSync( 'assets/src/public/style.css', 'utf8' );

function mountWidgetRoot( className: string ): HTMLElement {
	const el = document.createElement( 'div' );
	el.className = className;
	document.body.appendChild( el );
	return el;
}

describe( 'style.css', () => {
	beforeEach( () => {
		const style = document.createElement( 'style' );
		style.textContent = CSS;
		document.head.appendChild( style );
	} );

	afterEach( () => {
		document.head.innerHTML = '';
		document.body.innerHTML = '';
	} );

	it( 'compact actually tightens the spacing token - the modifier class has an effect', () => {
		const plain = mountWidgetRoot( 'reservant-widget' );
		const compact = mountWidgetRoot( 'reservant-widget reservant-widget--compact' );

		const plainSpace = getComputedStyle( plain )
			.getPropertyValue( '--reservant-space' )
			.trim();
		const compactSpace = getComputedStyle( compact )
			.getPropertyValue( '--reservant-space' )
			.trim();

		// Both roots resolve a real length - a selector typo resolves '' on both, and a
		// difference between two empty strings would prove nothing.
		expect( plainSpace ).toMatch( /^[0-9.]+px$/ );
		expect( compactSpace ).toMatch( /^[0-9.]+px$/ );
		expect( parseFloat( compactSpace ) ).toBeLessThan( parseFloat( plainSpace ) );
	} );

	it( 'a rule below the token blocks consumes the spacing token', () => {
		// The other way density dies with the class name intact: nothing referencing
		// var(--reservant-space) anywhere, so the resolved token feeds no declaration.
		const sheet = document.styleSheets[ 0 ];
		expect( sheet ).toBeDefined();

		const consumers: string[] = [];
		for ( const rule of Array.from( ( sheet as CSSStyleSheet ).cssRules ) ) {
			if ( ! ( rule instanceof CSSStyleRule ) ) {
				continue;
			}
			for ( let i = 0; i < rule.style.length; i++ ) {
				const property = rule.style[ i ] as string;
				const value = rule.style.getPropertyValue( property );
				if (
					'--reservant-space' !== property &&
					value.replace( /\s+/g, '' ).includes( 'var(--reservant-space' )
				) {
					consumers.push( rule.selectorText );
				}
			}
		}

		expect( consumers.length ).toBeGreaterThan( 0 );
	} );
} );
