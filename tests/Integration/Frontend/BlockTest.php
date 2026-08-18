<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Frontend;

use Reservant\Frontend\Block;
use Reservant\Plugin;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The block half of the widget's PHP surface (plan Task 9): `reservant/booking-widget` renders
 * through the SAME MountPoint renderer the shortcode uses - the parity test compares the two
 * outputs byte for byte, and it only guards drift because there is exactly one renderer. The
 * appearance attributes become inline custom properties on the mount div itself, where they beat
 * the stylesheet defaults declared on the same element (assets/src/public/style.css).
 *
 * The enqueue tests pin the one failure every other gate is blind to: block.json declaring
 * `viewScript`/`style` would make WordPress register SECOND handles for build/widget.js and
 * build/style-widget.css - browsers do not deduplicate classic scripts by URL, so the bundle
 * would download and execute twice on every page carrying the block, with `npm run size` green
 * (it measures the file, not the page) and the page still working (mounting is idempotent).
 * Frontend loading has exactly one owner: Assets (detection plus the renderer's force()).
 */
final class BlockTest extends ReservantTestCase {

	private const BLOCK         = 'reservant/booking-widget';
	private const HANDLE        = 'reservant-widget';
	private const EDITOR_HANDLE = 'reservant-widget-editor';

	public function set_up(): void {
		parent::set_up();
		// Same reasoning as ShortcodeTest: the WP harness restores hooks per test but never the
		// script/style registries, so enqueues would leak between tests without this.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	/** Creates a published post and navigates the test request to it, as a visitor would. */
	private function visitPostWith( string $content ): void {
		$postId = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( (string) get_permalink( $postId ) );
	}

	/** Collapses insignificant whitespace so the parity assertion compares structure, not layout. */
	private function normalise( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	}

	public function test_block_renders_the_same_mount_point_as_the_shortcode(): void {
		// The guard against the two surfaces drifting apart. It has teeth ONLY while both sides
		// run through one shared renderer - if the block ever grows its own copy of the markup,
		// this comparison degrades into a tautology that passes while the surfaces diverge.
		$block     = do_blocks( '<!-- wp:reservant/booking-widget {"serviceId":3} /-->' );
		$shortcode = do_shortcode( '[reservant_booking service="3"]' );
		$this->assertSame( $this->normalise( $shortcode ), $this->normalise( $block ) );
	}

	public function test_a_preselected_staff_member_maps_to_the_data_staff_attribute(): void {
		// resourceId -> data-staff, exactly the name index.tsx's readConfig reads.
		$html = do_blocks( '<!-- wp:reservant/booking-widget {"serviceId":3,"resourceId":7} /-->' );
		$this->assertStringContainsString( 'data-service="3"', $html );
		$this->assertStringContainsString( 'data-staff="7"', $html );
	}

	public function test_a_negative_id_means_nothing_preselected_never_the_absolute_value(): void {
		// block.json sets no minimum, so hand-written markup can deliver a negative id (the
		// editor's toId() clamps, so the panel never can). absint() would render
		// {"serviceId":-3} as data-service="3" - silently binding the widget to a DIFFERENT
		// service than the markup names. The honest reading is max(0, (int)): a negative id
		// collapses to 0, which is the same "nothing preselected" an absent attribute means.
		$html = do_blocks( '<!-- wp:reservant/booking-widget {"serviceId":-3,"resourceId":-7} /-->' );
		$this->assertStringContainsString( 'data-service=""', $html );
		$this->assertStringContainsString( 'data-staff=""', $html );
		$this->assertStringNotContainsString( 'data-service="3"', $html );
		$this->assertStringNotContainsString( 'data-staff="7"', $html );
	}

	public function test_appearance_attributes_become_inline_custom_properties(): void {
		$html = do_blocks( '<!-- wp:reservant/booking-widget {"accent":"#c00","radius":2} /-->' );
		// Inline on the mount div itself: style.css declares the token defaults on
		// .reservant-widget, and an inline style attribute on that SAME element is what beats
		// them. A wrapper div would lose the specificity fight.
		$this->assertStringContainsString( '--reservant-accent:#c00', $html );
		$this->assertStringContainsString( '--reservant-radius:2px', $html );
	}

	public function test_an_invalid_or_empty_accent_emits_no_accent_property(): void {
		// sanitize_hex_color() answers null for junk and '' for the empty default; both must
		// emit NO custom property at all, so the stylesheet default on .reservant-widget wins.
		// The attribute is user-entered text landing in an HTML attribute - the attempted
		// breakout below must die in sanitisation, not in escaping luck.
		foreach ( array( '', 'red', 'c00', '#c0', '#c00" onmouseover="alert(1)' ) as $accent ) {
			$html = do_blocks(
				'<!-- wp:reservant/booking-widget ' . wp_json_encode( array( 'accent' => $accent ) ) . ' /-->'
			);
			$this->assertStringNotContainsString( '--reservant-accent', $html, "accent: {$accent}" );
			$this->assertStringNotContainsString( 'onmouseover', $html, "accent: {$accent}" );
		}
	}

	public function test_radius_is_clamped_and_the_default_emits_nothing(): void {
		$this->assertStringContainsString(
			'--reservant-radius:32px',
			do_blocks( '<!-- wp:reservant/booking-widget {"radius":999} /-->' )
		);
		$this->assertStringContainsString(
			'--reservant-radius:0px',
			do_blocks( '<!-- wp:reservant/booking-widget {"radius":-5} /-->' )
		);
		// The default (6) emits nothing: the stylesheet already says 6px, the editor never
		// serialises an attribute equal to its default anyway, and an inline 6px would beat a
		// theme's own --reservant-radius override for a value nobody actually chose.
		$this->assertStringNotContainsString(
			'--reservant-radius',
			do_blocks( '<!-- wp:reservant/booking-widget {"radius":6} /-->' )
		);
	}

	public function test_density_travels_as_a_modifier_class_not_a_data_attribute(): void {
		// The Task 9 density decision, pinned: compact is a CLASS on the mount div
		// (reservant-widget--compact), never a data- attribute or an inline property. Density is
		// purely presentational, so it belongs to the stylesheet - style.css defines what compact
		// MEANS (a --reservant-space override), and Task 16 documents it as part of the theming
		// surface. index.tsx deliberately reads no density.
		$html = do_blocks( '<!-- wp:reservant/booking-widget {"density":"compact"} /-->' );
		$this->assertStringContainsString( 'class="reservant-widget reservant-widget--compact"', $html );
		$this->assertStringNotContainsString( 'data-density', $html );

		// comfortable is the default and adds nothing - that is what keeps the parity test honest.
		$this->assertStringNotContainsString(
			'reservant-widget--',
			do_blocks( '<!-- wp:reservant/booking-widget /-->' )
		);
		// Junk collapses to comfortable (block.json's enum drops it, the callback whitelists too).
		$this->assertStringNotContainsString(
			'reservant-widget--',
			do_blocks( '<!-- wp:reservant/booking-widget {"density":"cosy"} /-->' )
		);
	}

	public function test_the_block_declares_no_view_script_and_no_style_of_its_own(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );
		$this->assertInstanceOf( \WP_Block_Type::class, $type );
		$this->assertSame( 3, $type->api_version );

		// Frontend assets have ONE owner: Assets enqueues build/widget.js + build/style-widget.css
		// under the reservant-widget handle (has_block detection, plus the renderer's force() for
		// blocks outside post content). A viewScript/style here would register the same files
		// under second handles - downloaded and executed twice by every visitor's browser, and on
		// classic themes the style would even enqueue SITE-WIDE via
		// wp_enqueue_registered_block_scripts_and_styles(), block present or not.
		$this->assertSame( array(), $type->view_script_handles );
		$this->assertSame( array(), $type->style_handles );

		// The editor surface is the block's own: the panel script and the preview stylesheet.
		$this->assertSame( array( self::EDITOR_HANDLE ), $type->editor_script_handles );
		$this->assertSame( array( self::EDITOR_HANDLE ), $type->editor_style_handles );

		// The five attributes and their defaults are the Task 9 contract (plan line 677).
		$this->assertIsArray( $type->attributes );
		$this->assertSame( 0, $type->attributes['serviceId']['default'] );
		$this->assertSame( 0, $type->attributes['resourceId']['default'] );
		$this->assertSame( '', $type->attributes['accent']['default'] );
		$this->assertSame( 6, $type->attributes['radius']['default'] );
		$this->assertSame( 'comfortable', $type->attributes['density']['default'] );
		$this->assertSame( array( 'comfortable', 'compact' ), $type->attributes['density']['enum'] );
	}

	public function test_the_page_carries_exactly_one_widget_script_and_one_stylesheet(): void {
		// Re-register the block onto THIS test's fresh script/style registries: set_up nulls
		// them, which discards every handle registration made at process boot - and an
		// unregistered handle silently fails to enqueue, which would blind this test to exactly
		// the double-enqueue it exists to catch (proven: with viewScript declared in block.json,
		// the count below stayed at one until this re-registration was added).
		unregister_block_type( self::BLOCK );
		( new Block() )->register();

		$content = '<!-- wp:reservant/booking-widget {"serviceId":3} /-->';
		$this->visitPostWith( $content );
		// Fires detection AND the enqueue_block_assets cascade (where block.json 'style'/'script'
		// handles would enqueue on classic themes)...
		do_action( 'wp_enqueue_scripts' );
		// ...and the render phase is where WP_Block::render() would enqueue viewScript handles.
		do_blocks( $content );

		$this->assertSame(
			array( self::HANDLE ),
			$this->enqueuedHandlesPointingAt( wp_scripts(), 'build/widget.js' ),
			'Exactly one handle may serve build/widget.js - browsers do not deduplicate classic scripts by URL, so a second handle is the bundle executing twice.'
		);
		$this->assertSame(
			array( self::HANDLE ),
			$this->enqueuedHandlesPointingAt( wp_styles(), 'build/style-widget.css' ),
			'Exactly one handle may serve the widget stylesheet.'
		);
	}

	/**
	 * Every enqueued handle whose registered src points at the given build file.
	 *
	 * @return list<string>
	 */
	private function enqueuedHandlesPointingAt( \WP_Dependencies $registry, string $file ): array {
		$handles = array();
		foreach ( $registry->registered as $handle => $dependency ) {
			if (
				is_string( $dependency->src )
				&& str_contains( $dependency->src, $file )
				&& $registry->query( (string) $handle, 'enqueued' )
			) {
				$handles[] = (string) $handle;
			}
		}
		sort( $handles );
		return $handles;
	}

	public function test_the_editor_assets_are_registered_with_translations(): void {
		self::assertFileExists(
			RESERVANT_PATH . 'build/editor.asset.php',
			'run `npm run build` before the integration suite so the registration path under test is real'
		);

		// set_up nulled the registries, discarding the registration made at process boot;
		// register() is idempotent per request (it skips an already-registered block type), so
		// calling it again re-registers the handles without tripping _doing_it_wrong.
		( new Block() )->register();

		$script = wp_scripts()->registered[ self::EDITOR_HANDLE ] ?? null;
		$this->assertNotNull( $script, 'The editor script handle must be registered.' );
		$this->assertStringEndsWith( 'build/editor.js', (string) $script->src );
		$this->assertSame( 'reservant', $script->textdomain, 'wp.i18n needs the textdomain to load the reservant catalog for the editor panel.' );

		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset = include RESERVANT_PATH . 'build/editor.asset.php';
		$this->assertSame( $asset['dependencies'], $script->deps, 'The dependency list must come from build/editor.asset.php, never a hardcoded copy.' );
		$this->assertSame( $asset['version'], $script->ver );

		// The editor previews with the SAME stylesheet visitors get - the token defaults live in
		// style-widget.css, and the block-editor iframe collects registered block-type styles.
		$style = wp_styles()->registered[ self::EDITOR_HANDLE ] ?? null;
		$this->assertNotNull( $style, 'The editor style handle must be registered.' );
		$this->assertStringEndsWith( 'build/style-widget.css', (string) $style->src );
		$this->assertSame( 'replace', wp_styles()->get_data( self::EDITOR_HANDLE, 'rtl' ) );
	}

	public function test_the_block_registers_in_wp_admin_too(): void {
		// Plugin::register() wraps the frontend wiring in `if ( ! is_admin() )`. The block must
		// be registered OUTSIDE that guard: the editor's inserter only lists server-registered
		// block types, and the editor is wp-admin. Every other test here runs with is_admin()
		// false and would stay green with the registration inside the guard - only this one
		// catches it.
		$registry = \WP_Block_Type_Registry::get_instance();
		unregister_block_type( self::BLOCK );
		$this->assertFalse( $registry->is_registered( self::BLOCK ) );

		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin(), 'the simulated editor screen must count as wp-admin' );

		// Re-runs Plugin::register() in an admin context; init has already fired, so
		// Block::register() must take its immediate path rather than waiting for a hook that
		// never comes again. The harness restores the hook table and unsets the screen after
		// the test; the block registry deliberately keeps the re-registration.
		Plugin::boot();

		$this->assertTrue(
			$registry->is_registered( self::BLOCK ),
			'Plugin must register the block outside its is_admin() guard - the editor inserter never sees it otherwise.'
		);
	}
}
