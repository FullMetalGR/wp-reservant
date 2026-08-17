<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

/**
 * The `reservant/booking-widget` block: server registration, the render callback, and the
 * editor-only assets. The render callback delegates to the shared MountPoint renderer, so the
 * block's frontend markup is byte-identical to the shortcode's (BlockTest pins that parity - it
 * only guards drift because there is exactly one renderer).
 *
 * block.json deliberately declares NO `viewScript` and NO `style`. Frontend loading already has
 * one owner - `Assets` enqueues build/widget.js and build/style-widget.css under the
 * `reservant-widget` handle, via `has_block()` detection plus the renderer's `force()` for a
 * block rendered outside the queried post's content (template part, page builder, reusable
 * block). Declaring them here as `file:` paths would make WordPress register the SAME two files
 * under second handles: browsers do not deduplicate classic scripts by URL, so build/widget.js
 * would download and execute twice on every page carrying the block (mounting stays correct
 * only thanks to the window-level mount registry), and on classic themes the style handle would
 * even enqueue site-wide via `wp_enqueue_registered_block_scripts_and_styles()`, block or no
 * block. No byte gate can see any of that - `npm run size` measures the file, not the page -
 * so BlockTest counts the handles on a rendered page instead.
 *
 * `editorScript`/`editorStyle` in block.json are HANDLE references (no `file:` prefix), and this
 * class registers those handles reading build/editor.asset.php dynamically - same idiom as
 * Assets and Admin\AdminPage, so the dependency list can never drift from what the build emitted.
 * The editor style handle points at build/style-widget.css: the editor previews with the SAME
 * stylesheet visitors get, and the block editor copies registered block-type styles into its
 * canvas iframe. The editor bundle may depend on @wordpress/components freely - it ships to the
 * block editor, never to visitors; the widget bundle's allowlist (bin/widget-contract.mjs) is
 * unaffected by anything the `editor` entry imports.
 *
 * block.json also turns OFF the className/customClassName supports: this render callback does
 * not merge `$attributes['className']` into the mount node (the mount markup is the parity
 * surface), and an editor field whose value silently never renders is worse than no field.
 * Theme-level styling belongs to the documented custom properties (Task 16).
 */
final class Block {

	public const NAME = 'reservant/booking-widget';

	/** One handle for both editor assets - they register and enqueue together or not at all. */
	private const EDITOR_HANDLE = 'reservant-widget-editor';

	/** The block panel's default; equal values emit nothing so stylesheet overrides keep working. */
	private const RADIUS_DEFAULT = 6;

	/** Nullable so the class stays constructible in isolation; Plugin wires the real instance. */
	public function __construct( private readonly ?MountPoint $renderer = null ) {}

	/**
	 * Registration must run on `init` (WP warns on earlier calls) and must run in wp-admin too:
	 * the editor's inserter only lists server-registered block types. Plugin therefore calls
	 * this OUTSIDE its `is_admin()` split. The immediate branch serves callers arriving after
	 * `init` already fired (the test harness re-booting the plugin mid-request); it is
	 * idempotent per request - an already-registered block type is left alone rather than
	 * tripping core's duplicate-registration warning.
	 */
	public function register(): void {
		if ( did_action( 'init' ) > 0 ) {
			$this->registerNow();
			return;
		}
		add_action(
			'init',
			function (): void {
				$this->registerNow();
			}
		);
	}

	private function registerNow(): void {
		$this->registerEditorAssets();

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}

		register_block_type(
			self::pluginPath() . 'assets/blocks/booking-widget',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * The render callback - sanitisation policy lives HERE, then the shared renderer does the
	 * markup. WordPress has already validated the attributes against block.json's schema and
	 * filled the defaults, but every value is still treated as user text:
	 *
	 * - `accent` goes through `sanitize_hex_color()`, which returns the hex for valid input,
	 *   '' for the empty default and null for junk. Invalid and empty both emit NO custom
	 *   property at all, so the stylesheet default on `.reservant-widget` wins - an inline junk
	 *   value would instead override a theme's own token override with garbage.
	 * - `radius` is clamped to the 0-32 token range and suffixed `px`. The default (6) emits
	 *   nothing: style.css already says 6px, the editor never serialises an attribute equal to
	 *   its default, and an inline 6px would beat a theme override for a value nobody chose.
	 * - `density` travels as a modifier CLASS (`reservant-widget--compact`), never a data-
	 *   attribute or inline property: it is purely presentational, so the stylesheet owns what
	 *   compact MEANS (a --reservant-space override in style.css; documented by Task 16), and
	 *   index.tsx deliberately reads no density. 'comfortable' adds nothing - that is what
	 *   keeps the default block markup byte-identical to the shortcode's.
	 * - `serviceId`/`resourceId` map to `data-service`/`data-staff`, the names index.tsx reads;
	 *   0 means "not preselected" and collapses to the same empty string the shortcode default
	 *   produces (readId() treats both as null).
	 *
	 * @param array<string, mixed> $attributes Validated block attributes, defaults filled.
	 */
	public function render( array $attributes ): string {
		$service = absint( $attributes['serviceId'] ?? 0 );
		$staff   = absint( $attributes['resourceId'] ?? 0 );

		$css = array();

		$accent = sanitize_hex_color( (string) ( $attributes['accent'] ?? '' ) );
		if ( is_string( $accent ) && '' !== $accent ) {
			$css['--reservant-accent'] = $accent;
		}

		$radius = max( 0, min( 32, (int) ( $attributes['radius'] ?? self::RADIUS_DEFAULT ) ) );
		if ( self::RADIUS_DEFAULT !== $radius ) {
			$css['--reservant-radius'] = $radius . 'px';
		}

		$classes = array();
		if ( 'compact' === ( $attributes['density'] ?? '' ) ) {
			$classes[] = 'reservant-widget--compact';
		}

		return ( $this->renderer ?? new MountPoint() )->render(
			'book',
			array(
				'service' => 0 < $service ? (string) $service : '',
				'staff'   => 0 < $staff ? (string) $staff : '',
			),
			$css,
			$classes
		);
	}

	/**
	 * Registers the editor script and the editor style under one handle, reading
	 * build/editor.asset.php for dependencies and version. Skipped entirely on an unbuilt
	 * checkout (mirrors Assets::enqueue()): block.json's handle references then enqueue
	 * nothing, which WordPress tolerates silently.
	 */
	private function registerEditorAssets(): void {
		$assetFile = self::pluginPath() . 'build/editor.asset.php';
		if ( ! file_exists( $assetFile ) ) {
			return;
		}
		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset = include $assetFile;

		wp_register_script(
			self::EDITOR_HANDLE,
			self::pluginUrl() . 'build/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'reservant' );

		wp_register_style(
			self::EDITOR_HANDLE,
			self::pluginUrl() . 'build/style-widget.css',
			array(),
			$asset['version']
		);
		wp_style_add_data( self::EDITOR_HANDLE, 'rtl', 'replace' );
	}

	/**
	 * `reservant.php` defines these before `Plugin` is ever reachable; the `defined()` guard
	 * exists only so static analysis of `src/` in isolation does not flag an unknown constant
	 * (mirrors `Assets` and `Admin\AdminPage`).
	 */
	private static function pluginPath(): string {
		return defined( 'RESERVANT_PATH' ) ? RESERVANT_PATH : '';
	}

	private static function pluginUrl(): string {
		return defined( 'RESERVANT_URL' ) ? RESERVANT_URL : '';
	}
}
