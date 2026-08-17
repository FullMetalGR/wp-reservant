<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

/**
 * THE renderer for the widget's mount node - the single place that knows what
 * `<div class="reservant-widget">` looks like.
 *
 * Three surfaces render this node: the shortcodes (Shortcode), the block's render callback
 * (Block), and the magic-link manage route (plan Task 10). All three MUST delegate here, because
 * the Task 9 parity test - block output byte-identical to shortcode output - only guards against
 * the surfaces drifting apart while there is exactly one renderer. A copied markup string would
 * turn that test into a tautology that passes while the copies diverge.
 *
 * The div is deliberately empty: React's `createRoot()` owns the node and replaces whatever is
 * inside on first render. The bundle (assets/src/public/index.tsx) queries `.reservant-widget`
 * and reads the `data-` contract: `data-mode`, `data-service`, `data-staff`, `data-uuid`,
 * `data-token` - plan Tasks 9-16 depend on exactly these names.
 *
 * Rendering a mount point also force-enqueues the assets (see `Assets::force()`): content
 * detection covers the normal case, but a shortcode or block rendered from a template part or a
 * page builder module lives outside the queried post's content, and a mount point without its
 * bundle is a permanently empty div a site owner cannot diagnose. THE CALL ORDER IS LOAD-BEARING
 * for callers that print their own page (ManageRoute): build the mount BEFORE the shell starts -
 * before `get_header()` / before anything fires `wp_head` - or `force()` lands past
 * `wp_enqueue_scripts` and takes the late path: footer script, stylesheet via
 * `print_late_styles()` after the mount, a flash of unstyled widget on every visit.
 *
 * The appearance parameters exist for the block's panel and default to "nothing": a caller with
 * no appearance to convey (the shortcodes and the manage route) passes only
 * mode and data and gets the bare node - it is never forced to thread appearance arguments it
 * has no use for. `$css` lands as an inline `style` attribute ON the mount div itself, because
 * style.css declares the theming tokens on `.reservant-widget` and an inline declaration on that
 * SAME element is what beats them; on a wrapper it would lose. Callers pass already-sanitised
 * values (Block owns sanitisation policy); everything still goes through `esc_attr()` here,
 * which prevents an ATTRIBUTE breakout - quotes and angle brackets die - but nothing more:
 * `esc_attr()` leaves ';' and ':' untouched, so a hostile `$css` value is a CSS-DECLARATION
 * INJECTION, e.g. ['--z' => '1px; background: url(...)'] emits valid extra declarations inside
 * the style attribute. Today no caller can deliver one (Block sanitises its two properties;
 * Shortcode and ManageRoute pass no `$css` at all), but whoever next routes user-derived values
 * into `$css` owns sanitising them BEFORE this renderer - it will not do it for them.
 */
final class MountPoint {

	/** Nullable so the class stays constructible in isolation; Plugin wires the real instance. */
	public function __construct( private readonly ?Assets $assets = null ) {}

	/**
	 * Renders one mount node.
	 *
	 * @param string                $mode    'book' or 'manage' - `data-mode` on the node.
	 * @param array<string, string> $data    Further `data-` attributes (name => value). Values
	 *                                       render even when empty: the bundle's readers collapse
	 *                                       '' to "nothing preselected", and a fixed attribute
	 *                                       set keeps the markup byte-stable across surfaces.
	 * @param array<string, string> $css     Inline CSS custom properties (property => value),
	 *                                       e.g. ['--reservant-accent' => '#c00']. Empty: no
	 *                                       style attribute at all, so stylesheet defaults win.
	 * @param list<string>          $classes Modifier classes appended after `reservant-widget`.
	 */
	public function render( string $mode, array $data, array $css = array(), array $classes = array() ): string {
		$this->assets?->force();

		$class = 'reservant-widget';
		foreach ( $classes as $modifier ) {
			$class .= ' ' . $modifier;
		}

		$html = '<div class="' . esc_attr( $class ) . '"';

		if ( array() !== $css ) {
			$style = '';
			foreach ( $css as $property => $value ) {
				$style .= ( '' === $style ? '' : ';' ) . $property . ':' . $value;
			}
			$html .= ' style="' . esc_attr( $style ) . '"';
		}

		$html .= ' data-mode="' . esc_attr( $mode ) . '"';
		foreach ( $data as $name => $value ) {
			$html .= ' data-' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $html . '></div>';
	}
}
