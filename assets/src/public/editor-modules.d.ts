/**
 * Ambient declarations for the two block-editor packages the editor entry imports.
 *
 * Neither @wordpress/blocks (13.x) nor @wordpress/block-editor (14.x) ships TypeScript types,
 * and DefinitelyTyped's copies lag the runtime - so the surface editor.tsx actually uses is
 * declared here, strictly, and nothing more. The packages are deliberately ABSENT from
 * package.json (not installed at all, not even transitively), and fallow's unlisted-dependency
 * check - the check that would otherwise force the manifest to name what the source imports - is
 * suppressed for exactly these two by ignoreDependencies in .fallowrc.json. Nothing cross-checks
 * this file: at build time wp-scripts' DependencyExtractionWebpackPlugin externalises every
 * `@wordpress/*` import by request pattern (to the `wp.*` global plus a script-handle
 * dependency) without ever resolving it, at runtime the code is WordPress core's own bundled
 * copy, and at type-check time these ambient declarations are the sole authority - no package is
 * on disk to carry types that could contradict or replace them.
 *
 * Every import stays INSIDE the `declare module` blocks: a top-level import would turn this
 * file into a module, silently downgrading these declarations to augmentations of modules that
 * do not exist.
 *
 * If a later task grows the editor's usage, extend these declarations to exactly the new
 * surface - or, if the packages have started shipping their own types, delete this file.
 */

declare module '@wordpress/blocks' {
	import type { ComponentType } from 'react';

	export interface BlockEditProps< Attributes > {
		readonly attributes: Attributes;
		setAttributes( next: Partial< Attributes > ): void;
	}

	export interface BlockConfiguration< Attributes > {
		edit: ComponentType< BlockEditProps< Attributes > >;
		save: () => null;
	}

	export function registerBlockType< Attributes >(
		name: string,
		settings: BlockConfiguration< Attributes >
	): unknown;
}

declare module '@wordpress/block-editor' {
	import type { ComponentType, HTMLAttributes, ReactNode } from 'react';

	export const InspectorControls: ComponentType< { children?: ReactNode } >;

	/**
	 * `style` is a plain string record rather than React's closed CSSProperties: the one style
	 * this entry ever passes is a bag of `--reservant-*` custom properties, which CSSProperties
	 * rejects by design and would force an assertion at every call site.
	 */
	export function useBlockProps( props?: {
		className?: string;
		style?: Readonly< Record< string, string | number > >;
	} ): HTMLAttributes< HTMLDivElement >;
}
