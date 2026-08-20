<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Admin;

use Reservant\Admin\AdminPage;
use Reservant\Admin\Capabilities;
use Reservant\Application\Dto\AppointmentRequest;
use Reservant\Application\Dto\Customer;
use Reservant\Application\Dto\HoldRequest;
use Reservant\Application\Dto\SegmentChoice;
use Reservant\Application\HoldBooking;
use Reservant\Domain\Availability\AvailabilityRule;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `Admin\AdminPage` (AGENTS.md P4, Task 13): menu registration per role, the shared mount-point
 * page callback, the enqueue gate (only our own pages), the inline boot config, and the Bookings
 * submenu's pending-approval bubble.
 */
final class AdminPageTest extends ReservantTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		Capabilities::sync();

		// WordPress's admin-menu globals are plain superglobals, not part of the hook system the
		// test scaffolding backs up/restores between tests - reset them here so one test's
		// add_menu_page()/add_submenu_page() calls never leak into the next.
		$GLOBALS['menu']               = array();
		$GLOBALS['submenu']            = array();
		$GLOBALS['admin_page_hooks']   = array();
		$GLOBALS['_registered_pages']  = array();
		$GLOBALS['_parent_pages']      = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();

		// `wp_scripts()` is a global singleton the test scaffolding does not reset either - an
		// earlier test's enqueue (and its inline script data) would otherwise still be sitting on
		// the 'reservant-admin' handle when this test enqueues it again.
		wp_deregister_script( 'reservant-admin' );
	}

	private function asAdmin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/** The `reservant_staff` role, exactly as `Capabilities::sync()` builds it (view_own_calendar + approve_bookings). */
	private function asStaff(): int {
		$id = self::factory()->user->create( array( 'role' => 'reservant_staff' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function asSubscriber(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );
		return $id;
	}

	// ---------------------------------------------------------------- menu registration

	public function testAdministratorSeesAllSevenPagesUnderTheTopLevelMenu(): void {
		$this->asAdmin();
		( new AdminPage() )->menu();

		self::assertCount(
			7,
			$GLOBALS['submenu']['reservant'] ?? array(),
			'Calendar, Bookings, Services, Staff, Events, Seat Maps, Settings'
		);
		self::assertArrayNotHasKey( 'reservant-my-calendar', array_flip( array_column( $GLOBALS['menu'], 2 ) ) );
	}

	public function testAdministratorTopLevelMenuUsesTheBookingsCapabilityAndIcon(): void {
		$this->asAdmin();
		( new AdminPage() )->menu();

		$top = null;
		foreach ( $GLOBALS['menu'] as $entry ) {
			if ( 'reservant' === $entry[2] ) {
				$top = $entry;
			}
		}
		self::assertNotNull( $top, 'the top-level "reservant" menu must be registered' );
		self::assertSame( 'reservant_manage_bookings', $top[1] );
		self::assertSame( 'dashicons-calendar-alt', $top[6] );
	}

	public function testStaffOnlyUserSeesExactlyMyCalendar(): void {
		$this->asStaff();
		( new AdminPage() )->menu();

		self::assertArrayNotHasKey( 'reservant', $GLOBALS['submenu'] );
		$slugs = array_column( $GLOBALS['menu'], 2 );
		self::assertSame( array( 'reservant-my-calendar' ), $slugs );
	}

	public function testSubscriberSeesNoMenuAtAll(): void {
		$this->asSubscriber();
		( new AdminPage() )->menu();

		self::assertArrayNotHasKey( 'reservant', $GLOBALS['submenu'] );
		self::assertSame( array(), $GLOBALS['menu'] );
	}

	// ---------------------------------------------------------------- page callbacks

	public function testEachPageCallbackPrintsTheMountPointWithItsOwnScreen(): void {
		$this->asAdmin();
		( new AdminPage() )->menu();

		ob_start();
		do_action( get_plugin_page_hookname( 'reservant', '' ) );
		$calendar = (string) ob_get_clean();
		self::assertStringContainsString( 'id="reservant-admin-root"', $calendar );
		self::assertStringContainsString( 'data-screen="calendar"', $calendar );

		ob_start();
		do_action( get_plugin_page_hookname( 'reservant-bookings', 'reservant' ) );
		$bookings = (string) ob_get_clean();
		self::assertStringContainsString( 'data-screen="bookings"', $bookings );

		ob_start();
		do_action( get_plugin_page_hookname( 'reservant-settings', 'reservant' ) );
		$settings = (string) ob_get_clean();
		self::assertStringContainsString( 'data-screen="settings"', $settings );
	}

	public function testMyCalendarPageCallbackPrintsItsOwnScreen(): void {
		$this->asStaff();
		( new AdminPage() )->menu();

		ob_start();
		do_action( get_plugin_page_hookname( 'reservant-my-calendar', '' ) );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="reservant-admin-root"', $html );
		self::assertStringContainsString( 'data-screen="my-calendar"', $html );
	}

	// ---------------------------------------------------------------- enqueue

	public function testAssetsEnqueueOnlyOnOurOwnPages(): void {
		self::assertFileExists(
			RESERVANT_PATH . 'build/admin.asset.php',
			'run `npm run build` before the integration suite so the enqueue path under test is real'
		);

		$this->asAdmin();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );

		set_current_screen( get_plugin_page_hookname( 'reservant', '' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant', '' ) );
		self::assertTrue( wp_script_is( 'reservant-admin', 'enqueued' ), 'our own page must enqueue the bundle' );

		wp_dequeue_script( 'reservant-admin' );
		wp_deregister_script( 'reservant-admin' );

		set_current_screen( 'edit.php' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		self::assertFalse( wp_script_is( 'reservant-admin', 'enqueued' ), 'an unrelated wp-admin page must not enqueue it' );
	}

	public function testTheAdminStylesheetDeclaresItsRtlTwin(): void {
		self::assertFileExists(
			RESERVANT_PATH . 'build/admin.asset.php',
			'run `npm run build` before the integration suite so the enqueue path under test is real'
		);

		$this->asAdmin();
		// set_up() resets only the script handle; the style handle would otherwise carry an
		// earlier test's registration (and its data) into this one.
		wp_dequeue_style( 'reservant-admin' );
		wp_deregister_style( 'reservant-admin' );
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant', '' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant', '' ) );

		self::assertTrue( wp_style_is( 'reservant-admin', 'enqueued' ), 'our own page must enqueue the admin stylesheet' );
		// The build emits an RTL twin (build/admin-rtl.css); declaring it via style_add_data is
		// what makes WordPress swap the whole sheet for RTL admin locales. 'replace' derives the
		// URL as s/.css/-rtl.css/, so the twin's on-disk name must match that derivation - the
		// same contract the frontend sheet already declares (Frontend\Assets, pinned by
		// ShortcodeTest). Without it an RTL admin locale is served the LTR sheet even though the
		// correct one shipped.
		self::assertSame( 'replace', wp_styles()->get_data( 'reservant-admin', 'rtl' ) );
		self::assertFileExists( RESERVANT_PATH . 'build/admin-rtl.css' );
	}

	public function testInlineConfigCarriesCapsAndRestRoot(): void {
		self::assertFileExists( RESERVANT_PATH . 'build/admin.asset.php' );

		$this->asAdmin();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant', '' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant', '' ) );

		$config = $this->inlineConfig();
		self::assertSame( untrailingslashit( rest_url() ), untrailingslashit( $config['restRoot'] ) );
		self::assertNotEmpty( $config['nonce'] );
		self::assertSame(
			array( 'reservant_manage_bookings', 'reservant_approve_bookings', 'reservant_manage_settings', 'reservant_view_own_calendar' ),
			$config['caps']
		);
		self::assertSame( 'EUR', $config['currency'] );
		self::assertSame( 5, $config['granularityMin'] );
	}

	/**
	 * P8.3's Settings screen reads the license from the bootstrap rather than fetching it, because
	 * an unlicensed site has every configuration screen to draw as read-only and a round trip first
	 * is a screen that renders once wrongly and then corrects itself. The shape is
	 * `Rest\Admin\LicensePayload`'s - the same one `GET /admin/license` answers with - so the SPA
	 * parses one shape whether the status arrived with the page or came back from an activation.
	 */
	public function testInlineConfigCarriesTheLicenseInTheSameShapeTheRestRouteAnswersWith(): void {
		self::assertFileExists( RESERVANT_PATH . 'build/admin.asset.php' );
		$this->licenseThisSite();

		$this->asAdmin();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant-settings', 'reservant' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant-settings', 'reservant' ) );

		$license = $this->inlineConfig()['license'];
		self::assertIsArray( $license );
		self::assertSame(
			array( 'state', 'active', 'masked_key', 'domain', 'last_checked_at', 'grace_ends_at' ),
			array_keys( $license )
		);
		self::assertSame( 'active', $license['state'] );
		self::assertTrue( $license['active'] );
		self::assertSame( '********0001', $license['masked_key'] );
	}

	/** The plaintext key is a credential. It is stored, and it never leaves the server. */
	public function testInlineConfigNeverCarriesThePlaintextLicenseKey(): void {
		self::assertFileExists( RESERVANT_PATH . 'build/admin.asset.php' );
		$this->licenseThisSite();

		$this->asAdmin();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant-settings', 'reservant' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant-settings', 'reservant' ) );

		self::assertStringNotContainsString( 'RSVT-TEST-0000-0001', (string) wp_json_encode( $this->inlineConfig() ) );
	}

	/**
	 * The `caps` narrowing, one field over: a staff-only viewer's My Calendar page has no licensing
	 * section, no configuration screen to grey out and no way to act on the answer, so the bound
	 * domain and the check history - the operator's business - do not go to them.
	 */
	public function testInlineConfigWithholdsTheLicenseFromAViewerWhoCannotConfigureTheSite(): void {
		self::assertFileExists( RESERVANT_PATH . 'build/admin.asset.php' );
		$this->licenseThisSite();

		$this->asStaff();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant-my-calendar', '' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant-my-calendar', '' ) );

		self::assertNull( $this->inlineConfig()['license'] );
	}

	public function testInlineConfigOmitsCapsTheUserDoesNotHold(): void {
		self::assertFileExists( RESERVANT_PATH . 'build/admin.asset.php' );

		$this->asStaff();
		$page = new AdminPage();
		$page->register();
		do_action( 'admin_menu' );
		set_current_screen( get_plugin_page_hookname( 'reservant-my-calendar', '' ) );
		do_action( 'admin_enqueue_scripts', get_plugin_page_hookname( 'reservant-my-calendar', '' ) );

		$config = $this->inlineConfig();
		self::assertSame( array( 'reservant_approve_bookings', 'reservant_view_own_calendar' ), $config['caps'] );
	}

	/**
	 * @return array{restRoot:string,nonce:string,caps:list<string>,currency:string,timezone:string,granularityMin:int,license:array<string, mixed>|null}
	 */
	private function inlineConfig(): array {
		$inline = wp_scripts()->get_data( 'reservant-admin', 'before' );
		self::assertIsArray( $inline );

		// `WP_Dependencies::add_inline_script()` casts `get_data()`'s own `false` ("nothing queued
		// yet") to `(array) false`, which in PHP is the one-element list `[false]`, not `[]` - so a
		// handle's very first inline script always shares its array with that leading `false`
		// placeholder. Filtering it out is the correct way to read this data, not a workaround for
		// a bug on either side.
		$scripts = array_values( array_filter( $inline, static fn ( $entry ): bool => is_string( $entry ) && '' !== $entry ) );
		self::assertCount( 1, $scripts, 'exactly one real inline script must have been added to this handle' );

		$js = $scripts[0];
		self::assertStringStartsWith( 'window.reservantAdmin = ', $js );
		$json = substr( $js, strlen( 'window.reservantAdmin = ' ), -1 );

		$config = json_decode( $json, true );
		self::assertIsArray( $config );
		/** @var array{restRoot:string,nonce:string,caps:list<string>,currency:string,timezone:string,granularityMin:int,license:array<string, mixed>|null} $config */
		return $config;
	}

	// ---------------------------------------------------------------- pending-approval bubble

	public function testBookingsSubmenuBubblesThePendingApprovalCount(): void {
		global $wpdb;
		$this->asAdmin();

		self::assertStringNotContainsString(
			'pending-count',
			$this->bookingsSubmenuTitle(),
			'no bubble while nothing is awaiting approval'
		);

		$this->holdAwaitingApproval( $wpdb );

		$title = $this->bookingsSubmenuTitle();
		self::assertStringContainsString( 'pending-count', $title );
		self::assertStringContainsString( '>1<', $title );
	}

	private function bookingsSubmenuTitle(): string {
		$GLOBALS['submenu'] = array();
		$GLOBALS['menu']    = array();
		( new AdminPage() )->menu();
		foreach ( $GLOBALS['submenu']['reservant'] as $entry ) {
			if ( 'reservant-bookings' === $entry[2] ) {
				return (string) $entry[0];
			}
		}
		self::fail( 'the Bookings submenu entry must exist' );
	}

	/** Holds an approval-required appointment service so a real `awaiting_approval` row exists. */
	private function holdAwaitingApproval( \wpdb $wpdb ): void {
		$services  = new ServiceRepository( $wpdb );
		$resources = new ResourceRepository( $wpdb );
		$avail     = new AvailabilityRepository( $wpdb );

		$serviceId = $services->insert(
			array(
				'name'                => 'Consult',
				'type'                => 'appointment',
				'duration_min'        => 30,
				'price_minor'         => 1000,
				'payment_mode'        => 'onsite',
				'requires_approval'   => 1,
				'approval_hold_hours' => 48,
			)
		);
		$staff = $resources->insert( array( 'name' => 'Alex' ) );
		$resources->linkService( $serviceId, $staff );
		foreach ( range( 1, 7 ) as $weekday ) {
			$avail->insertRule( $staff, new AvailabilityRule( $weekday, '09:00', '17:00' ) );
		}

		HoldBooking::make( $wpdb )->execute(
			new HoldRequest(
				new Customer( 'Maria', 'maria@example.com' ),
				new AppointmentRequest( $this->utc( 1, '09:00' ), array( new SegmentChoice( $serviceId ) ) )
			),
			$this->utc( 0 )
		);
	}
}
