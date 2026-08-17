<?php
declare( strict_types=1 );

namespace Reservant\Rest\Admin;

use Reservant\Domain\Seating\SeatMapSpec;
use Reservant\Domain\Seating\SpecParseError;
use Reservant\Infrastructure\Db\SeatMapRepository;
use Reservant\Infrastructure\Db\ServiceRepository;
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
 * PUT is guarded by `SeatMapRepository::hasClaims()` alone - refused with 409 `referenced` the
 * moment any seat on the map is claimed by an active booking, whether or not that claim belongs to
 * the occurrence the caller happens to be thinking about: a map is a reusable template across every
 * occurrence of its seat-mapped service (AGENTS.md section 4), and re-parsing it would silently
 * renumber seats a live claim still names.
 *
 * DELETE is guarded by `hasClaims()` OR `ServiceRepository::usesSeatMap()` (review round 1 fix):
 * `hasClaims()` alone does not catch a map that no seat has ever been claimed on but that a live
 * service still points at via `seat_map_id` - deleting it would leave that column dangling rather
 * than fail loudly, per the "no silent divergence" rule this task's brief states for occurrence
 * capacity and this fix extends to the map link itself.
 *
 * Both guards are checked twice per write - once outside any transaction for a fast, ordinary
 * refusal, and again as the transaction's first statement immediately before the write - mirroring
 * `ServicesAdminController::destroy()` as fixed in the Task 11 review (fix round 1). PUT's in-
 * transaction phase additionally re-verifies the row still exists via `SeatMapRepository::
 * lockForUpdate()` (`SELECT ... FOR UPDATE`) before mutating anything (review round 1 fix): a plain
 * `UPDATE`'s own affected-rows count cannot distinguish "the row is gone" from "the row exists but
 * already held the values being written", so existence is proven by a dedicated locking read
 * instead of inferred from the write.
 */
final class SeatMapsAdminController {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * GET /admin/seat-maps - every row carries its own `seats` grid too (the same association
	 * `show()` attaches via `present()`), not just the bare `reservant_seat_maps` columns: the
	 * `SeatMap` shape the SPA's `useSeatMaps()` is typed against declares `seats` as an
	 * always-present, non-optional field, and `SeatMapsScreen` reads it unconditionally the moment a
	 * map loads through this list (`map.seats.length` in the catalog table, and the live
	 * `SeatMapPreview` grid, which is derived from the SELECTED row of this same list).
	 *
	 * This is the identical `index()`-vs-`present()` drift already fixed for `GET /admin/resources`
	 * in `ResourcesAdminController::index()` - both are now expressed as "map every row through the
	 * one shared presenter", so the list and the single-row shape cannot drift apart again.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Every admin index handler takes the request for signature consistency, even a paramless one.
		$repo = new SeatMapRepository( $this->db );
		$rows = $repo->all();
		return new \WP_REST_Response(
			array( 'seat_maps' => array_map( static fn ( array $row ): array => self::present( $repo, $row ), $rows ) )
		);
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
		// Same catch shape update() uses (this class's docblock): insert()/insertSeats() can now both
		// refuse `lock_unavailable` on a DB-level failure, and without this the refusal would escape
		// this REST callback as an uncaught exception instead of the clean 409 every other guarded
		// write on this path answers with.
		try {
			$id = $repo->insert( $name, $specText );
			$repo->insertSeats( $id, $spec->seats() );
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

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
					// Locks the row for the rest of this transaction (review round 1 fix): the
					// authoritative existence recheck, not inferred from `updateSpec()`'s own
					// affected-rows count - see the class docblock for why that count is ambiguous.
					// A concurrent DELETE either already removed the row (this returns false, and
					// nothing below ever runs) or blocks until this transaction commits or rolls
					// back, so `updateSpec()`/`deleteSeats()`/`insertSeats()` can never write against
					// a row that vanishes mid-flight.
					if ( ! $repo->lockForUpdate( $id ) ) {
						throw new \RuntimeException( 'update_conflict' );
					}
					if ( $repo->hasClaims( $id ) ) {
						throw new \RuntimeException( 'referenced' );
					}
					if ( ! $repo->updateSpec( $id, $name, $specText ) ) {
						throw new \RuntimeException( 'update_conflict' );
					}
					$repo->deleteSeats( $id );
					$repo->insertSeats( $id, $spec->seats() );
				}
			);
		} catch ( \RuntimeException $exception ) {
			return Errors::failure( $exception );
		}

		$fresh = $repo->find( $id );
		if ( null === $fresh ) {
			// Defensive only (review round 1 fix): the transaction above already proved the row
			// exists, immediately before writing, via a lock held until COMMIT - this would mean it
			// vanished between that COMMIT and this plain read, which no write path in this codebase
			// can cause. Never presents a garbage row (id 0, empty name) as a false 200.
			return Errors::failure( new \RuntimeException( 'update_conflict' ) );
		}
		return new \WP_REST_Response( self::present( $repo, $fresh ) );
	}

	/**
	 * DELETE /admin/seat-maps/{id} - removes the map and every one of its seats; refused with 409
	 * `referenced` while any seat is claimed, or while any service (any status) still links this map
	 * via `seat_map_id` (review round 1 fix - see the class docblock).
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new SeatMapRepository( $this->db );
		$id   = (int) $request->get_param( 'id' );
		if ( null === $repo->find( $id ) ) {
			return Errors::notFound();
		}
		if ( $repo->hasClaims( $id ) || ( new ServiceRepository( $this->db ) )->usesSeatMap( $id ) ) {
			return Errors::failure( new \RuntimeException( 'referenced' ) );
		}

		try {
			$deleted = ( new TransactionRunner( $this->db ) )->run(
				function () use ( $id ): bool {
					// Fresh instances, deliberately not the outer ones above - genuinely new reads,
					// not a reuse of the earlier result (Task 11 fix round 1 idiom).
					$repo     = new SeatMapRepository( $this->db );
					$services = new ServiceRepository( $this->db );
					if ( $repo->hasClaims( $id ) || $services->usesSeatMap( $id ) ) {
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
	 * The one `SeatMap` shape every route answers with - the map row plus its own seat grid. Shared
	 * by `index()` (the list), `show()`, `create()` and `update()` so a row missing `seats` is
	 * exactly the bug this method exists to make impossible to reintroduce one call site at a time
	 * (the same rule `ResourcesAdminController::attachAssociations()` states for its own three
	 * associations).
	 *
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
