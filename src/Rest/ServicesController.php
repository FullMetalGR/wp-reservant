<?php
declare( strict_types=1 );

namespace Reservant\Rest;

use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * GET /services - the public catalog the booking widget is built from.
 *
 * Deliberately narrower than the admin catalog: this endpoint is unauthenticated, so each row
 * carries only what a customer needs to choose. `resources` is id and name, never the email,
 * `wp_user_id` or working rules that `GET /admin/resources` returns to a capability holder -
 * enforced in `ResourceRepository::publicSummaryForService()`'s own SQL projection, not by
 * unsetting columns here.
 */
final class ServicesController {

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /services */
	public function index(): \WP_REST_Response {
		$services  = new ServiceRepository( $this->db );
		$resources = new ResourceRepository( $this->db );

		$rows = array();
		foreach ( $services->allActive() as $service ) {
			$rows[] = array(
				'id'                => (int) $service['id'],
				'name'              => (string) $service['name'],
				'description'       => (string) $service['description'],
				'type'              => (string) $service['type'],
				'duration_min'      => (int) $service['duration_min'],
				'price_minor'       => (int) $service['price_minor'],
				'currency'          => (string) $service['currency'],
				'requires_approval' => (bool) $service['requires_approval'],
				'resources'         => $resources->publicSummaryForService( (int) $service['id'] ),
			);
		}
		return new \WP_REST_Response( $rows );
	}
}
