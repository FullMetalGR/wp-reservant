<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

/**
 * The widget's shortcode surface. Both shortcodes map their user-entered attributes onto the
 * shared MountPoint renderer - the ONE place that produces `<div class="reservant-widget">` (see
 * its docblock for the mount contract and why sharing it is load-bearing). This class owns only
 * what is shortcode-specific: the tag names, the per-mode attribute defaults, and core's
 * attribute normalisation quirks.
 */
final class Shortcode {

	public const BOOKING = 'reservant_booking';
	public const MANAGE  = 'reservant_manage';

	/** Nullable so the class stays constructible in isolation; Plugin wires the real instance. */
	public function __construct( private readonly ?MountPoint $renderer = null ) {}

	public function register(): void {
		add_shortcode( self::BOOKING, fn ( $attrs ): string => $this->mount( self::attrs( $attrs ), 'book' ) );
		add_shortcode( self::MANAGE, fn ( $attrs ): string => $this->mount( self::attrs( $attrs ), 'manage' ) );
	}

	/**
	 * Purely defensive - core no longer produces the string shape. Since WP 6.5,
	 * `shortcode_parse_atts()` always returns an array, so a shortcode written without attributes
	 * reaches its callback as `array()`, not the historical `''` (core's `pre_do_shortcode_tag`
	 * docblock: "@since 6.5.0 The `$attr` parameter is always an array"; verified empirically on
	 * 7.0.4). This plugin's floor is WP 6.6, so through core the string branch is unreachable and
	 * the stubs' array-only typing of the callback parameter is CORRECT for every supported
	 * version. The normalization stays as belt-and-braces against a non-core caller (a theme or
	 * builder invoking the callback directly) still following the pre-6.5 `array|string`
	 * convention, and its native union type is what keeps PHPStan treating both branches as live
	 * rather than flagging the array branch's guard as dead code.
	 *
	 * @param array<array-key, string>|string $attrs
	 * @return array<array-key, string>
	 */
	private static function attrs( array|string $attrs ): array {
		return is_array( $attrs ) ? $attrs : array();
	}

	/**
	 * Maps the shortcode's attributes onto the shared renderer. Unset attributes still reach it
	 * as empty strings: the bundle's readers collapse '' to "nothing preselected", and a fixed
	 * attribute set keeps the markup byte-stable for the Task 9 block-vs-shortcode comparison.
	 * No appearance arguments: the shortcode has no appearance surface (that is the block's
	 * panel), so the stylesheet defaults on `.reservant-widget` apply untouched.
	 *
	 * @param array<array-key, string> $attrs
	 */
	private function mount( array $attrs, string $mode ): string {
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

		return ( $this->renderer ?? new MountPoint() )->render(
			$mode,
			array_map( 'strval', $pairs )
		);
	}
}
