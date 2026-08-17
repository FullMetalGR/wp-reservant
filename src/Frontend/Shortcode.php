<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

/**
 * The widget's mount points. Both shortcodes render the same empty div the bundle self-boots
 * into (assets/src/public/index.tsx queries `.reservant-widget` and reads the `data-` contract:
 * `data-mode`, `data-service`, `data-staff`, `data-uuid`, `data-token` - plan Tasks 9-16 depend
 * on exactly these names). The div is deliberately empty: React's `createRoot()` owns the node
 * and replaces whatever is inside on first render.
 *
 * Rendering a mount point also force-enqueues the assets (see `Assets::force()`): content
 * detection covers the normal case, but a shortcode rendered from a template part or a page
 * builder module lives outside `get_post()->post_content`, and a mount point without its bundle
 * is a permanently empty div a site owner cannot diagnose.
 */
final class Shortcode {

	public const BOOKING = 'reservant_booking';
	public const MANAGE  = 'reservant_manage';

	/** Nullable so the class stays constructible in isolation; Plugin wires the real instance. */
	public function __construct( private readonly ?Assets $assets = null ) {}

	public function register(): void {
		add_shortcode( self::BOOKING, fn ( $attrs ): string => $this->mount( self::attrs( $attrs ), 'book' ) );
		add_shortcode( self::MANAGE, fn ( $attrs ): string => $this->mount( self::attrs( $attrs ), 'manage' ) );
	}

	/**
	 * Core passes '' - not an array - when a shortcode is written without attributes. The stubs
	 * type the callback's first parameter as array only, so this normalization sits behind a
	 * native union type, which keeps both branches analysable instead of one looking dead.
	 *
	 * @param array<array-key, string>|string $attrs
	 * @return array<array-key, string>
	 */
	private static function attrs( array|string $attrs ): array {
		return is_array( $attrs ) ? $attrs : array();
	}

	/**
	 * Renders one mount node. Every attribute value passes through `esc_attr()` - shortcode
	 * attributes are user-entered text. Unset attributes still render as empty `data-` attributes:
	 * the bundle's readers collapse '' to "nothing preselected", and a fixed attribute set keeps
	 * the markup byte-stable for Task 9's block-vs-shortcode comparison.
	 *
	 * @param array<array-key, string> $attrs
	 */
	private function mount( array $attrs, string $mode ): string {
		$this->assets?->force();

		$defaults = 'manage' === $mode
			? array(
				'uuid'  => '',
				'token' => '',
			)
			: array(
				'service' => '',
				'staff'   => '',
			);
		$pairs    = shortcode_atts( $defaults, $attrs, 'manage' === $mode ? self::MANAGE : self::BOOKING );

		$html = '<div class="reservant-widget" data-mode="' . esc_attr( $mode ) . '"';
		foreach ( $pairs as $name => $value ) {
			$html .= ' data-' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html . '></div>';
	}
}
