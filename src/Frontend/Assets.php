<?php
declare( strict_types=1 );

namespace Reservant\Frontend;

use Reservant\Settings;

/**
 * Enqueues the public widget bundle - and, far more importantly, refuses to.
 *
 * The widget is ~100 KB of script plus a stylesheet, and the second test in ShortcodeTest is the
 * reason this is its own class: those bytes load ONLY on pages that actually carry the widget.
 * Detection runs on `wp_enqueue_scripts` and looks for either registered shortcode or the block
 * in the queried singular post's content. Two surfaces detection cannot see are covered by
 * `force()`: the magic-link manage route (no post exists at all - Task 10 calls `force()` on
 * `template_redirect`, before its `get_header()` fires `wp_enqueue_scripts`), and a shortcode
 * rendered from outside post content (a template part, a text widget, a page builder module),
 * where `Shortcode::mount()` calls `force()` at render time and WordPress prints the script in
 * the footer and the stylesheet via `print_late_styles()`. The late path trades a flash of
 * unstyled widget for not shipping a dead mount point; head-time detection stays the primary
 * route exactly to keep the stylesheet in `<head>`.
 *
 * `force()` deliberately keeps NO instance state: "enqueue this request" is recorded by adding a
 * one-shot `wp_enqueue_scripts` action (or enqueuing immediately when the hook already fired).
 * The hook table is request-scoped in production and backed up/restored per test by the WP
 * harness, so the decision can never leak across tests the way a latched boolean on a shared
 * instance would.
 *
 * The script/style URLs name the files the build REALLY emits, which the plan's Task 7 interface
 * line got wrong twice (see assets/src/public/index.tsx): the stylesheet is
 * `build/style-widget.css`, not `widget.css` - wp-scripts collects any source `style.css` into a
 * `style-<entry>` chunk - and `build/widget.asset.php` lists exactly three handles
 * (react-jsx-runtime, wp-element, wp-i18n; react and react-dom arrive transitively through
 * wp-element). The asset file is read dynamically, never hardcoded, mirroring Admin\AdminPage.
 */
final class Assets {

	private const HANDLE = 'reservant-widget';

	/** Task 9 registers this block; detection already covers it so Task 9 adds no enqueue code. */
	private const BLOCK = 'reservant/booking-widget';

	public function register(): void {
		add_action(
			'wp_enqueue_scripts',
			function (): void {
				if ( $this->pageCarriesWidget() ) {
					$this->enqueue();
				}
			}
		);
	}

	/**
	 * Enqueue on this request regardless of what content detection sees. Callable from any point
	 * in the request: before `wp_enqueue_scripts` it defers to that hook (so the stylesheet still
	 * lands in `<head>`); after it, it enqueues on the spot and WordPress's footer pass prints
	 * both assets. `enqueue()` is idempotent, so calling this any number of times - or alongside
	 * a successful detection - adds nothing twice.
	 */
	public function force(): void {
		if ( did_action( 'wp_enqueue_scripts' ) > 0 ) {
			$this->enqueue();
			return;
		}
		add_action(
			'wp_enqueue_scripts',
			function (): void {
				$this->enqueue();
			}
		);
	}

	private function pageCarriesWidget(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		// Both shortcodes, not just the booking one: a page carrying only [reservant_manage]
		// renders a mount point too, and without the bundle it would be a dead div.
		return has_shortcode( $post->post_content, Shortcode::BOOKING )
			|| has_shortcode( $post->post_content, Shortcode::MANAGE )
			|| has_block( self::BLOCK, $post );
	}

	private function enqueue(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			// Already done this request - detection and force() may both fire, and the inline
			// bootstrap script below must not be attached twice.
			return;
		}

		$assetFile = self::pluginPath() . 'build/widget.asset.php';
		if ( ! file_exists( $assetFile ) ) {
			// The bundle has not been built (a fresh checkout before `npm run build`) - nothing
			// to enqueue, not a fatal error. Mirrors Admin\AdminPage.
			return;
		}
		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset = include $assetFile;

		wp_enqueue_script(
			self::HANDLE,
			self::pluginUrl() . 'build/widget.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'reservant' );
		wp_add_inline_script(
			self::HANDLE,
			'window.reservantWidget = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);

		wp_enqueue_style(
			self::HANDLE,
			self::pluginUrl() . 'build/style-widget.css',
			array(),
			$asset['version']
		);
		// The build emits the RTL twin (style-widget-rtl.css); 'replace' swaps the whole sheet
		// for RTL locales, deriving the URL as s/.css/-rtl.css/ - which matches the emitted name.
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * The widget's boot object, consumed by the Task 11 API client as `window.reservantWidget`.
	 *
	 * `nonce` is EMPTY for a logged-out visitor, on purpose, and the client must not send an
	 * X-WP-Nonce header when it is. This page is public and routinely served from a full-page
	 * cache for days, while a `wp_rest` nonce dies in 12-24 hours - and WordPress treats a
	 * supplied-but-invalid nonce as a hard 403 (`rest_cookie_invalid_nonce`) on EVERY route,
	 * public ones included, where the same request with no nonce at all simply proceeds
	 * unauthenticated, which is exactly what the guest booking routes are designed for. A cached
	 * nonce would therefore convert requests that succeed into requests that fail. Logged-in
	 * pageviews bypass page caches by convention, so there the nonce is safe and useful: it lets
	 * WordPress recognise the user it already knows on every REST call the widget makes.
	 *
	 * @return array{restRoot:string,nonce:string,currency:string,timezone:string,granularityMin:int,checkoutTtlMin:int}
	 */
	private function config(): array {
		$settings = Settings::make();

		return array(
			'restRoot'       => esc_url_raw( rest_url() ),
			'nonce'          => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'currency'       => $settings->currency(),
			'timezone'       => wp_timezone_string(),
			'granularityMin' => max( 1, (int) apply_filters( 'reservant/granularity_min', 5 ) ),
			'checkoutTtlMin' => $settings->checkoutTtlMin(),
		);
	}

	/**
	 * `reservant.php` defines these before `Plugin` is ever reachable; the `defined()` guard
	 * exists only so static analysis of `src/` in isolation does not flag an unknown constant
	 * (mirrors `Plugin::version()` and `Admin\AdminPage`).
	 */
	private static function pluginPath(): string {
		return defined( 'RESERVANT_PATH' ) ? RESERVANT_PATH : '';
	}

	private static function pluginUrl(): string {
		return defined( 'RESERVANT_URL' ) ? RESERVANT_URL : '';
	}
}
