<?php
declare( strict_types=1 );

namespace Reservant\Admin;

use Reservant\Settings;

/**
 * The wp-admin chassis for the React SPA (AGENTS.md P4, "Screens and menu"): a top-level
 * "Reservant" menu with six capability-gated submenus for an owner/manager, or a single
 * "My Calendar" page for a staff-only viewer. Every page callback prints the same empty mount
 * point (`#reservant-admin-root`) with a `data-screen` attribute the SPA reads to pick its screen;
 * the bundle (`build/admin.js`) is enqueued only on these pages, never site-wide.
 *
 * WordPress's own `add_menu_page()` registers into `$menu` unconditionally, regardless of the
 * caller's capability - only whether the page's own render callback gets hooked is capability-
 * gated, not whether the entry exists at all. Which set of pages gets built at all is therefore
 * decided here, in PHP, by capability (`menu()`), rather than left to that later, narrower gate.
 */
final class AdminPage {

	private const TOP_SLUG = 'reservant';
	private const HANDLE   = 'reservant-admin';
	private const ICON     = 'dashicons-calendar-alt';

	/** slug => data-screen value, for every page under the full (manager) menu. */
	private const SUBMENU_SCREENS = array(
		'reservant-services'  => 'services',
		'reservant-staff'     => 'staff',
		'reservant-events'    => 'events',
		'reservant-seat-maps' => 'seat-maps',
		'reservant-settings'  => 'settings',
	);

	private readonly \wpdb $db;

	/** @var list<string> hook suffixes for our own pages - populated by menu(), read by maybeEnqueue(). */
	private array $pageHooks = array();

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				$this->menu();
			}
		);
		add_action(
			'admin_enqueue_scripts',
			function ( string $hookSuffix ): void {
				$this->maybeEnqueue( $hookSuffix );
			}
		);
	}

	/**
	 * Builds exactly one of: the full seven-page manager menu, the single staff my-calendar page,
	 * or nothing at all - see the class docblock for why this has to be decided here rather than
	 * relying on WordPress's own later per-page capability gate.
	 */
	public function menu(): void {
		$this->pageHooks = array();

		if ( current_user_can( 'reservant_manage_bookings' ) ) {
			$this->fullMenu();
			return;
		}
		if ( current_user_can( 'reservant_view_own_calendar' ) ) {
			$this->myCalendarMenu();
		}
	}

	private function fullMenu(): void {
		// Callback deliberately empty here: the very next call re-registers this same slug as its
		// own first submenu item (the standard core idiom for giving that first item a label -
		// "Calendar" - distinct from the top-level menu title "Reservant"), and that call is the
		// one that actually hooks the render callback. Passing a callback to both would register
		// it twice on the same hook and print the mount point twice.
		$this->pageHooks[] = (string) add_menu_page(
			__( 'Reservant', 'reservant' ),
			__( 'Reservant', 'reservant' ),
			'reservant_manage_bookings',
			self::TOP_SLUG,
			'',
			self::ICON
		);

		$this->pageHooks[] = (string) add_submenu_page(
			self::TOP_SLUG,
			__( 'Calendar', 'reservant' ),
			__( 'Calendar', 'reservant' ),
			'reservant_manage_bookings',
			self::TOP_SLUG,
			function (): void {
				$this->render( 'calendar' );
			}
		);

		$bookingsHook = add_submenu_page(
			self::TOP_SLUG,
			__( 'Bookings', 'reservant' ),
			$this->bookingsMenuTitle(),
			'reservant_manage_bookings',
			'reservant-bookings',
			function (): void {
				$this->render( 'bookings' );
			}
		);
		if ( is_string( $bookingsHook ) ) {
			$this->pageHooks[] = $bookingsHook;
		}

		foreach ( self::SUBMENU_SCREENS as $slug => $screen ) {
			$hook = add_submenu_page(
				self::TOP_SLUG,
				self::submenuTitle( $screen ),
				self::submenuTitle( $screen ),
				'reservant_manage_settings',
				$slug,
				function () use ( $screen ): void {
					$this->render( $screen );
				}
			);
			if ( is_string( $hook ) ) {
				$this->pageHooks[] = $hook;
			}
		}
	}

	private function myCalendarMenu(): void {
		$this->pageHooks[] = (string) add_menu_page(
			__( 'My Calendar', 'reservant' ),
			__( 'My Calendar', 'reservant' ),
			'reservant_view_own_calendar',
			'reservant-my-calendar',
			function (): void {
				$this->render( 'my-calendar' );
			},
			self::ICON
		);
	}

	private static function submenuTitle( string $screen ): string {
		return match ( $screen ) {
			'services'  => __( 'Services', 'reservant' ),
			'staff'     => __( 'Staff', 'reservant' ),
			'events'    => __( 'Events', 'reservant' ),
			'seat-maps' => __( 'Seat Maps', 'reservant' ),
			'settings'  => __( 'Settings', 'reservant' ),
			default     => $screen,
		};
	}

	private function render( string $screen ): void {
		echo '<div id="reservant-admin-root" data-screen="' . esc_attr( $screen ) . '"></div>';
	}

	private function maybeEnqueue( string $hookSuffix ): void {
		if ( ! in_array( $hookSuffix, $this->pageHooks, true ) ) {
			return;
		}

		$assetFile = self::pluginPath() . 'build/admin.asset.php';
		if ( ! file_exists( $assetFile ) ) {
			// The bundle has not been built yet (a fresh checkout before `npm run build`, or a CI
			// job that only runs the PHP suites) - nothing to enqueue, not a fatal error.
			return;
		}
		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset = include $assetFile;

		wp_enqueue_script(
			self::HANDLE,
			self::pluginUrl() . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'reservant' );

		// Task 1: the build emits admin.css only once a later task pulls style content into the
		// entry chain - registered when it exists, never assumed.
		$cssFile = self::pluginPath() . 'build/admin.css';
		if ( file_exists( $cssFile ) ) {
			wp_enqueue_style( self::HANDLE, self::pluginUrl() . 'build/admin.css', array(), $asset['version'] );
			// The build emits the RTL twin (admin-rtl.css); 'replace' swaps the whole sheet for
			// RTL admin locales, deriving the URL as s/.css/-rtl.css/ - which matches the
			// emitted name. Same contract as the frontend sheet (Frontend\Assets::enqueue()).
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.reservantAdmin = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * The SPA's boot config (AGENTS.md P4 spec): REST root, nonce, the current user's own
	 * Reservant caps (never the full set - `caps` narrows what the SPA lets that particular user
	 * do, e.g. hiding action buttons for a staff-only viewer), currency, site timezone, and the
	 * slot granularity.
	 *
	 * `emailChoices` is the notification catalog (`Notifications\EmailCatalog::choices()`): the key
	 * and the owner-facing label of every message this build can send, so the Settings screen
	 * renders one checkbox each without a hard-coded list of its own. A list living in TypeScript
	 * could not be compared against the PHP one by any test, and an email added in a later phase
	 * would simply have no switch.
	 *
	 * @return array{restRoot:string,nonce:string,caps:list<string>,currency:string,timezone:string,granularityMin:int,emailChoices:list<array{key:string,label:string}>}
	 */
	private function config(): array {
		$caps = array_values(
			array_filter(
				Capabilities::ALL,
				static fn ( string $cap ): bool => current_user_can( $cap )
			)
		);

		return array(
			'restRoot'       => esc_url_raw( rest_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'caps'           => $caps,
			'currency'       => Settings::make()->currency(),
			'timezone'       => wp_timezone_string(),
			'granularityMin' => self::granularityMin(),
			'emailChoices'   => \Reservant\Notifications\EmailCatalog::choices(),
		);
	}

	private static function granularityMin(): int {
		return max( 1, (int) apply_filters( 'reservant/granularity_min', 5 ) );
	}

	/**
	 * "Bookings", with a pending-approval count bubble appended in the same markup wp-admin's own
	 * menu items use (e.g. the Plugins update count) - computed with exactly one COUNT query, and
	 * only here, so it never runs unless the Bookings submenu is actually being built.
	 */
	private function bookingsMenuTitle(): string {
		$title = __( 'Bookings', 'reservant' );
		$count = $this->pendingApprovalCount();
		if ( $count <= 0 ) {
			return $title;
		}
		return sprintf(
			'%1$s <span class="awaiting-mod count-%2$d"><span class="pending-count">%2$d</span></span>',
			$title,
			$count
		);
	}

	private function pendingApprovalCount(): int {
		$p = $this->db->prefix;
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$p}reservant_bookings WHERE status = %s AND hold_expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL
				'awaiting_approval'
			)
		);
	}

	/**
	 * `reservant.php` defines this before `Plugin` is ever reachable; the `defined()` guard exists
	 * only so static analysis of `src/` in isolation does not flag an unknown constant (mirrors
	 * `Plugin::version()`).
	 */
	private static function pluginPath(): string {
		return defined( 'RESERVANT_PATH' ) ? RESERVANT_PATH : '';
	}

	private static function pluginUrl(): string {
		return defined( 'RESERVANT_URL' ) ? RESERVANT_URL : '';
	}
}
