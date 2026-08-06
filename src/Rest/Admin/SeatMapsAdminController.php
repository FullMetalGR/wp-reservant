<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Domain\Seating\SpecParseError;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\TransactionRunner;
use Reservant\Rest\Errors;
use Reservant\Rest\Input;

/**
 * `reservant/v1/admin/seat-maps` CRUD (Task 12): the v1.2 grid builder - a text spec
 * ("rows A-J, 12 per row, aisle after 6"), parsed by `SeatMapSpec::parse()` into row-major seat
 * rows, never a drag-and-drop canvas (AGENTS.md section 9).
 *
 * Unlike an occurrence's soft cancel, DELETE here is a genuine physical delete of the map and every
 * one of its seats: an unclaimed map has no booking history pointing at it, so there is nothing to
 * preserve a row for.
 *
 * Both PUT and DELETE share one guard rail - `SeatMapRepository::hasClaims()` - refused with 409
 * `referenced` the moment any seat on the map is claimed by an active booking, whether or not that
 * claim belongs to the occurrence the caller happens to be thinking about: a map is a reusable
 * template across every occurrence of its seat-mapped service (AGENTS.md section 4), and re-parsing
 * or deleting it would silently renumber or remove seats a live claim still names.
 *
 * The guard is checked twice per write - once outside any transaction for a fast, ordinary refusal,
 * and again as the transaction's first statement immediately before the write - mirroring
 * `ServicesAdminController::destroy()` as fixed in the Task 11 review (fix round 1).
 */
final class SeatMapsAdminController {

	public function __construct( private readonly \wpdb $db ) {}

	/** GET /admin/seat-maps */
	public function index( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Every admin index handler takes the request for signature consistency, even a paramless one.
		$rows = ( new SeatMapRepository( $this->db ) )->all();
		return new \WP_REST_Response( array( 'seat_maps' => $rows ) );
	}

	/** GET /admin/seat-maps/{id} */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new SeatMapRepository( $this->db );
		$map  = $repo->find( (int) $request->get_param( 'id' ) );
		if ( null === $map ) {
			return Errors::notFound();
		}
		return new \WP_REST_Response( self::present( $repo, $map ) );
	}

	/** POST /admin/seat-maps - {name, spec}; a SpecParseError surfaces as 400 with the parser's own message. */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name = sanitize_text_field( Input::text( $request->get_param( 'name' ) ) );
		if ( '' === trim( $name ) ) {
			return Errors::badRequest( __( '"name" is required.', 'reservant' ) );
		}

		$specText = Input::text( $request->get_param( 'spec' ) );
		try {
			$spec = SeatMapSpec::parse( $specText );
		} catch ( SpecParseError $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		$repo = new SeatMapRepository( $this->db );
		$id   = $repo->insert( $name, $specText );
		$repo->insertSeats( $id, $spec->seats() );

		return new \WP_REST_Response( self::present( $repo, (array) $repo->find( $id ) ), 201 );
	}

	/** PUT /admin/seat-maps/{id} - re-parses "spec" and replaces every seat row; refused once any seat is claimed. */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new SeatMapRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		$map  = $repo->find( $id );
		if ( null === $map ) {
			return Errors::notFound();
		}

		$name = $request->has_param( 'name' ) ? sanitize_text_field( Input::text( $request->get_param( 'name' ) ) ) : (string) $map['name'];
		if ( '' === trim( $name ) ) {
			return Errors::badRequest( __( '"name" cannot be blank.', 'reservant' ) );
		}

		$specText = $request->has_param( 'spec' ) ? Input::text( $request->get_param( 'spec' ) ) : (string) $map['spec'];
		try {
			$spec = SeatMapSpec::parse( $specText );
		} catch ( SpecParseError $exception ) {
			return Errors::badRequest( $exception->getMessage() );
		}

		// The fast, non-transactional refusal - see the class docblock.
		if ( $repo->hasClaims( $id ) ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		try {
			( new TransactionRunner( $this->db ) )->run(
				function () use ( $id, $name, $specText, $spec ): void {
					// A fresh repository instance, deliberately not the outer `$repo` - a genuinely
					// new read, not a reuse of the earlier result (Task 11 fix round 1 idiom).
					$repo = new SeatMapRepository( $this->db );
					if ( $repo->hasClaims( $id ) ) {
						throw new \RuntimeException( 'referenced' );
					}
					$repo->updateSpec( $id, $name, $specText );
					$repo->deleteSeats( $id );
					$repo->insertSeats( $id, $spec->seats() );
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		return new \WP_REST_Response( self::present( $repo, (array) $repo->find( $id ) ) );
	}

	/** DELETE /admin/seat-maps/{id} - same claim guard as PUT; removes the map and every one of its seats. */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new SeatMapRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}
		if ( $repo->hasClaims( $id ) ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		try {
			$deleted = ( new TransactionRunner( $this->db ) )->run(
				function () use ( $id ): bool {
					$repo = new SeatMapRepository( $this->db );
					if ( $repo->hasClaims( $id ) ) {
						throw new \RuntimeException( 'referenced' );
					}
					$repo->deleteSeats( $id );
					return $repo->delete( $id );
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		if ( ! $deleted ) {
			return Errors::failure( new \RuntimeException( 'delete_conflict' ) );
		}
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * @param array<string, mixed> $map
	 * @return array<string, mixed>
	 */
	private static function present( SeatMapRepository $repo, array $map ): array {
		return array(
			'id'    => (int) $map['id'],
			'name'  => (string) $map['name'],
			'spec'  => (string) $map['spec'],
			'seats' => $repo->seatsForMap( (int) $map['id'] ),
		);
	}
}
