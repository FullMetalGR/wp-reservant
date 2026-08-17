/**
 * Block editor entry - builds to `build/editor.js` plus `build/editor.asset.php`, registered by
 * `src/Frontend/Block.php` under the `reservant-widget-editor` handle and loaded ONLY in the
 * block editor. This bundle may import `@wordpress/components` freely: it ships to wp-admin,
 * never to visitors, so `bin/widget-contract.mjs`'s allowlist (which reads
 * `build/widget.asset.php` alone) is untouched by anything imported here. The import direction
 * is one-way: nothing reachable from the `widget` entry may ever import from this file.
 *
 * This entry is listed AFTER `widget=` on the build command line (package.json) and must stay
 * there: wp-scripts names the shared chunk for any source file called `style.css` after the
 * FIRST entry that imports it, so an `editor=` entry listed first that imports
 * `assets/src/public/style.css` would silently rename `build/style-widget.css` to
 * `build/style-editor.css` out from under every PHP enqueue. This file deliberately imports no
 * CSS at all - the editor previews with the SAME `build/style-widget.css` visitors get, wired
 * as the block's `editorStyle` handle in Block.php - but the entry order stays correct so a
 * future import cannot spring the rename either. `npm run size` fails the build if it happens.
 *
 * The preview is STATIC markup mirroring the mount shell the front end renders (index.tsx's
 * loading panel) - no REST calls from the editor, and the real widget is never mounted here.
 * The appearance attributes are applied to the preview exactly as Block.php applies them to the
 * mount node: values equal to their defaults emit nothing, so the preview obeys the same
 * stylesheet defaults the front end does.
 */
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	ColorPalette,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from '../../blocks/booking-widget/block.json';

/** Mirrors the block.json attribute schema; the server validates against it independently. */
interface BookingWidgetAttributes {
	serviceId: number;
	resourceId: number;
	accent: string;
	radius: number;
	density: 'comfortable' | 'compact';
}

/** Kept equal to block.json's default and style.css's token; equal values emit nothing. */
const RADIUS_DEFAULT = 6;

/**
 * Suggested accents only - the palette keeps the custom picker, so any hex is reachable. The
 * first entry is the stylesheet default (`--reservant-accent` in style.css).
 */
const ACCENTS = [
	{ name: __( 'Blue', 'reservant' ), color: '#2271b1' },
	{ name: __( 'Green', 'reservant' ), color: '#007017' },
	{ name: __( 'Red', 'reservant' ), color: '#b32d2e' },
	{ name: __( 'Black', 'reservant' ), color: '#1e1e1e' },
];

/**
 * Row ids are positive integers; anything else collapses to 0, the attribute's own "not
 * preselected" value (which the render callback then collapses to an empty data- attribute,
 * matching how index.tsx's readId treats it).
 */
function toId( raw: string ): number {
	const value = Number.parseInt( raw, 10 );

	return Number.isInteger( value ) && 0 < value ? value : 0;
}

function Edit( { attributes, setAttributes }: BlockEditProps< BookingWidgetAttributes > ): JSX.Element {
	const { serviceId, resourceId, accent, radius, density } = attributes;

	const style: Record< string, string > = {};
	if ( '' !== accent ) {
		style[ '--reservant-accent' ] = accent;
	}
	if ( RADIUS_DEFAULT !== radius ) {
		style[ '--reservant-radius' ] = `${ Math.max( 0, Math.min( 32, radius ) ) }px`;
	}

	const blockProps = useBlockProps( {
		className:
			'compact' === density
				? 'reservant-widget reservant-widget--compact'
				: 'reservant-widget',
		style,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Preselection', 'reservant' ) }>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						label={ __( 'Service ID', 'reservant' ) }
						help={ __( '0 lets the visitor choose a service.', 'reservant' ) }
						value={ String( serviceId ) }
						onChange={ ( value ) => setAttributes( { serviceId: toId( value ) } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						label={ __( 'Staff ID', 'reservant' ) }
						help={ __( '0 means no staff preference.', 'reservant' ) }
						value={ String( resourceId ) }
						onChange={ ( value ) => setAttributes( { resourceId: toId( value ) } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Appearance', 'reservant' ) }>
					<ColorPalette
						colors={ ACCENTS }
						value={ '' === accent ? undefined : accent }
						onChange={ ( color ) => setAttributes( { accent: color ?? '' } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Corner radius', 'reservant' ) }
						min={ 0 }
						max={ 32 }
						value={ radius }
						onChange={ ( value ) =>
							setAttributes( { radius: 'number' === typeof value ? value : RADIUS_DEFAULT } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Density', 'reservant' ) }
						value={ density }
						options={ [
							{ label: __( 'Comfortable', 'reservant' ), value: 'comfortable' },
							{ label: __( 'Compact', 'reservant' ), value: 'compact' },
						] }
						onChange={ ( value ) =>
							setAttributes( { density: 'compact' === value ? 'compact' : 'comfortable' } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="reservant-widget__panel">
					<span>{ __( 'Booking widget - visitors will book here.', 'reservant' ) }</span>
				</div>
			</div>
		</>
	);
}

registerBlockType< BookingWidgetAttributes >( metadata.name, {
	edit: Edit,
	// Dynamic block: PHP renders the mount node through the shared renderer; nothing is saved
	// into post content.
	save: () => null,
} );
