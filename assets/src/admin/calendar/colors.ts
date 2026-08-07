/**
 * The calendar's default staff palette (Task 14 brief: "8 accessible colors") - the validated
 * 8-hue categorical set from the dataviz reference palette (light-surface steps). Every adjacent
 * pair clears the CVD delta >= 8 and normal-vision delta >= 15 (OKLab) gates, so two staff members
 * are never confusable by color alone.
 */
export const DEFAULT_PALETTE: string[] = [
	'#2a78d6', // blue
	'#eb6834', // orange
	'#1baf7a', // aqua
	'#eda100', // yellow
	'#e87ba4', // magenta
	'#008300', // green
	'#4a3aa7', // violet
	'#e34948', // red
];

/**
 * A stable resource -> color assignment: `resourceId` modulo the palette length. The same staff
 * member always renders in the same color across screens and re-renders with no lookup table or
 * server-assigned color to keep in sync; two resource ids that differ by a multiple of the palette
 * length do share a color (accepted per the brief - the palette is fixed at 8).
 */
export function colorFor( resourceId: number, palette: string[] = DEFAULT_PALETTE ): string {
	if ( 0 === palette.length ) {
		throw new Error( 'colorFor: palette must not be empty' );
	}
	const index = ( ( resourceId % palette.length ) + palette.length ) % palette.length;
	const color = palette[ index ];
	if ( undefined === color ) {
		throw new Error( 'colorFor: palette index out of bounds' );
	}
	return color;
}
