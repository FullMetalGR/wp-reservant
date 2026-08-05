<?php
declare( strict_types=1 );

namespace Reservant\Admin;

use Reservant\Cli\FixtureCommand;
use Reservant\Rest\Routes;

/**
 * Tools > Reservant Demo Data - the `wp reservant fixture` seeder for sites without shell access.
 *
 * Until the admin SPA (P4) ships, the engine is invisible from wp-admin: it only speaks REST, and
 * a fresh install has nothing to answer with. This page runs the same idempotent seeder the CLI
 * and the concurrency scripts use, then links straight to live REST calls so the result can be
 * seen in a browser. Gated behind `reservant_manage_settings` and a nonce; seeding twice is safe
 * by design.
 */
final class DemoDataPage {

	private const SLUG   = 'reservant-demo-data';
	private const ACTION = 'reservant_seed_demo';

	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				$this->menu();
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( $this, 'seed' ) );
	}

	/** @return string|false the page hook suffix, or false when the user may not see the page. */
	public function menu(): string|false {
		return add_management_page(
			__( 'Reservant Demo Data', 'reservant' ),
			__( 'Reservant Demo Data', 'reservant' ),
			'reservant_manage_settings',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function seed(): void {
		if ( ! current_user_can( 'reservant_manage_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to seed demo data.', 'reservant' ) );
		}
		check_admin_referer( self::ACTION );
		global $wpdb;
		FixtureCommand::ensure( $wpdb );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'seeded' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function render(): void {
		global $wpdb;
		$ids = FixtureCommand::stored( $wpdb );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reservant Demo Data', 'reservant' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['seeded'] ) && null !== $ids ) {
			echo '<div class="notice notice-success"><p>'
				. esc_html__( 'Demo data is ready.', 'reservant' )
				. '</p></div>';
		}

		echo '<p>' . esc_html__( 'Seeds a small demo dataset: a salon with two staff members (Alex and Bella, open 09:00-17:00 every day), a Cut and a Colour appointment service, a Seminar event, and a seated GridShow event. Running it again is safe; existing rows are reused.', 'reservant' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"/>';
		wp_nonce_field( self::ACTION );
		submit_button(
			null === $ids
				? __( 'Seed demo data', 'reservant' )
				: __( 'Re-check demo data', 'reservant' )
		);
		echo '</form>';

		if ( null !== $ids ) {
			$this->results( $ids );
		}

		echo '</div>';
	}

	/** @param array<string, mixed> $ids */
	private function results( array $ids ): void {
		echo '<h2>' . esc_html__( 'Seeded ids', 'reservant' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width: 480px">';
		echo '<thead><tr><th>' . esc_html__( 'What', 'reservant' ) . '</th><th>' . esc_html__( 'Id', 'reservant' ) . '</th></tr></thead><tbody>';
		$rows = array(
			'cut'         => __( 'Cut (appointment service)', 'reservant' ),
			'colour'      => __( 'Colour (appointment with processing gap)', 'reservant' ),
			'staff_a'     => __( 'Alex (staff)', 'reservant' ),
			'staff_b'     => __( 'Bella (staff)', 'reservant' ),
			'seminar_occ' => __( 'Seminar occurrence', 'reservant' ),
			'grid_occ'    => __( 'GridShow occurrence (seated)', 'reservant' ),
		);
		foreach ( $rows as $key => $label ) {
			if ( ! isset( $ids[ $key ] ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $ids[ $key ] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Try it', 'reservant' ) . '</h2>';
		echo '<p>' . esc_html__( 'These are live REST calls against this site. Open them in a new tab:', 'reservant' ) . '</p>';
		echo '<ul>';
		$this->tryLink(
			__( 'Free Cut slots for the next week', 'reservant' ),
			$this->availabilityUrl( (int) $ids['cut'] )
		);
		if ( isset( $ids['grid_occ'] ) ) {
			$this->tryLink(
				__( 'Seat map of the GridShow occurrence', 'reservant' ),
				rest_url( Routes::NS . '/occurrences/' . (int) $ids['grid_occ'] . '/seats' )
			);
		}
		if ( isset( $ids['cut'] ) ) {
			$this->tryLink(
				__( 'The Cut service itself', 'reservant' ),
				rest_url( Routes::NS . '/services/' . (int) $ids['cut'] )
			);
		}
		echo '</ul>';
	}

	private function tryLink( string $label, string $url ): void {
		echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a><br/><code>' . esc_html( $url ) . '</code></li>';
	}

	private function availabilityUrl( int $serviceId ): string {
		$items = (string) wp_json_encode( array( array( 'service_id' => $serviceId ) ) );
		return add_query_arg(
			array(
				'items' => rawurlencode( $items ),
				'from'  => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				'to'    => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
			),
			rest_url( Routes::NS . '/availability' )
		);
	}
}
