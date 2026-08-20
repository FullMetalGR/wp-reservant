<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Licensing\LicenseStatus;
use Reservant\Licensing\Providers;
use Reservant\Rest\Input;

/**
 * `reservant/v1/admin/license` (AGENTS.md section 5): read the license, activate a key, deactivate.
 *
 * **These three routes are gated on the capability alone and NEVER on the license.** They are the
 * way back: a site whose license has lapsed reaches `configureSite()`'s refusal on every
 * configuration write, and the only thing that can clear it is `POST` here. Guarding this endpoint
 * on an active license would make an unlicensed site permanently unlicensable, which is not a
 * stricter policy - it is a broken one.
 *
 * Every method answers with the SAME payload, the license as `LicensePayload` renders it, because
 * every `LicenseManager` method returns the resulting `LicenseStatus` for exactly that reason (see
 * the interface): the caller never has to write and then read back to find out what happened, and
 * the two halves of that pair never get the chance to disagree.
 *
 * The clock is an argument all the way down - `LicenseManager` takes `$nowUtc` on all four methods -
 * so this controller resolves the instant once per request, the way `BookingsAdminController` does.
 * A status read and an activation inside one request must not straddle a grace deadline.
 */
final class LicenseAdminController {

	private ?\DateTimeImmutable $now = null;

	/** GET /admin/license */
	public function index(): \WP_REST_Response|\WP_Error {
		try {
			$status = Providers::get()->status( $this->now() );
		} catch ( \Throwable $e ) {
			return self::unavailable( $e );
		}
		return self::answer( $status );
	}

	/**
	 * POST /admin/license - bind a key to this site.
	 *
	 * An empty `key` is not an error and not an activation: `LicenseManager::activate()` defines it
	 * as a no-op that reports what is already stored, precisely so a form posted with a blank field
	 * cannot cost a site the license it paid for. Unbinding is `destroy()`'s job alone. So there is
	 * no 400 arm here - the manager's own contract already covers the empty case, and duplicating
	 * the rule at the boundary would give it two homes.
	 *
	 * Trimming is the implementation's job too (a pasted key arrives with whitespace on both ends
	 * more often than not); `Input::text()` is here to refuse an array or an object posted where a
	 * string belongs, which is what the boundary exists for.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$key = Input::text( $request->get_param( 'key' ) );
		try {
			$status = Providers::get()->activate( $key, $this->now() );
		} catch ( \Throwable $e ) {
			return self::unavailable( $e );
		}
		return self::answer( $status );
	}

	/**
	 * DELETE /admin/license - unbind this site so the seat can be used somewhere else.
	 *
	 * Always 200 and always `inactive`, even when the license was already invalid or bound
	 * elsewhere: "stop claiming to be licensed" has no failure mode worth reporting, and an owner
	 * who cannot deactivate is an owner who cannot move their own site (see the interface).
	 */
	public function destroy(): \WP_REST_Response|\WP_Error {
		try {
			$status = Providers::get()->deactivate( $this->now() );
		} catch ( \Throwable $e ) {
			return self::unavailable( $e );
		}
		return self::answer( $status );
	}

	private static function answer( LicenseStatus $status ): \WP_REST_Response {
		return new \WP_REST_Response( LicensePayload::of( $status ) );
	}

	/**
	 * A `LicenseManager` that threw.
	 *
	 * The shipped `LocalKeyLicense` never does, but `reservant/license_manager` is a documented seam
	 * and whatever comes through it is third-party code - the same reason
	 * `Infrastructure\Scheduler\Jobs::licenseRecheck()` catches `\Throwable` rather than
	 * `\RuntimeException`, and the same reason `ServicesAdminController::mirror()` does around the
	 * payment provider. Without this, a remote validator's TypeError is a white screen on the
	 * Settings page instead of a message.
	 *
	 * 503 rather than 500: nothing is wrong with this request, the thing that answers it could not,
	 * and repeating it in a minute is the right move. The failure is announced on `reservant/error`
	 * so a site can log it - that channel is what the plugin has instead of choosing a sink.
	 */
	private static function unavailable( \Throwable $e ): \WP_Error {
		do_action( 'reservant/error', $e );
		return new \WP_Error(
			'reservant_license_unavailable',
			'license_unavailable',
			array(
				'status' => 503,
				'detail' => __( 'The licensing service could not be reached. Please try again in a few minutes.', 'reservant' ),
			)
		);
	}

	private function now(): \DateTimeImmutable {
		$this->now ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $this->now;
	}
}
