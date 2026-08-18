<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Rest;

use Reservant\Application\SlotConflict;
use Reservant\Rest\Errors;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * `Errors` holds two lists that must agree - the `KNOWN_REASONS` allow-list and `detail()`'s
 * sentence map - and nothing but care kept them in step. They drifted: `stale_state`, `not_held`
 * and `currency_mismatch` were allow-listed, so they reached the wire with the right machine reason
 * and the right status, and fell through to the generic sentence. A caller that renders
 * `data.detail` - which the widget does, and which `shared/errors.ts` makes the preferred text -
 * told the visitor "that request could not be completed" about a currency clash, a lost race and a
 * booking that is no longer a hold, three situations with three different remedies.
 *
 * The reason is a bare string crossing the seam, so the compiler cannot pair the lists. This test
 * pairs them instead: it reads `KNOWN_REASONS` off the class rather than restating it, so a reason
 * added without a sentence fails here on the next run instead of surfacing as a shrug to a
 * customer. Making the refusal a closed type would let the language do this; until then, this is
 * the gate.
 */
final class ErrorsExhaustivenessTest extends ReservantTestCase {

	/** The arm that must never be reachable for a known reason. */
	private const GENERIC = 'That request could not be completed.';

	/**
	 * @return list<string>
	 * @throws \ReflectionException Never in practice - the constant is declared on the class.
	 */
	private static function knownReasons(): array {
		/** @var list<string> $reasons */
		$reasons = ( new \ReflectionClass( Errors::class ) )->getConstant( 'KNOWN_REASONS' );
		return $reasons;
	}

	public function test_the_allow_list_is_not_empty_so_an_empty_read_cannot_pass_this_file(): void {
		// Guards the reflection itself: a renamed or removed constant would otherwise turn every
		// assertion below into a vacuous pass over an empty list.
		self::assertGreaterThan( 20, count( self::knownReasons() ) );
	}

	public function test_every_known_reason_has_a_sentence_of_its_own(): void {
		$generic = array();
		foreach ( self::knownReasons() as $reason ) {
			$data = Errors::failure( new \RuntimeException( $reason ) )->get_error_data();
			self::assertIsArray( $data );
			if ( self::GENERIC === ( $data['detail'] ?? '' ) ) {
				$generic[] = $reason;
			}
		}
		self::assertSame(
			array(),
			$generic,
			'These reasons reach the wire with the generic sentence: ' . implode( ', ', $generic )
		);
	}

	public function test_every_known_reason_keeps_its_machine_name_as_the_message(): void {
		// Clients switch on this, so a sentence must never take its place: `data.detail` is for
		// people, `message` is for code (AGENTS.md section 5).
		foreach ( self::knownReasons() as $reason ) {
			self::assertSame( $reason, Errors::failure( new \RuntimeException( $reason ) )->get_error_message() );
		}
	}

	public function test_no_known_reason_is_answered_as_a_server_fault(): void {
		// Every entry on this list is an answer, not a bug - the whole point of the allow-list is
		// that anything outside it becomes the opaque 500 instead.
		foreach ( self::knownReasons() as $reason ) {
			$data = Errors::failure( new \RuntimeException( $reason ) )->get_error_data();
			self::assertIsArray( $data );
			$status = (int) ( $data['status'] ?? 0 );
			self::assertGreaterThanOrEqual( 400, $status, $reason . ' must be a client-facing status.' );
			self::assertLessThan( 500, $status, $reason . ' must not be answered as a server fault.' );
		}
	}

	public function test_an_unknown_reason_is_an_opaque_500_and_never_echoes_its_message(): void {
		// The other half of the contract: `RuntimeException` is also how the repositories report a
		// failed write, so a message outside the list must not reach an anonymous caller. This is
		// what stops `$wpdb->last_error`'s table and index names going out on a deadlock.
		$leaky = Errors::failure( new \RuntimeException( "booking_insert_failed: Duplicate entry 'x' for key 'occ_seat'" ) );
		$data  = $leaky->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 500, $data['status'] );
		self::assertStringNotContainsString( 'occ_seat', (string) $leaky->get_error_message() );
		self::assertStringNotContainsString( 'occ_seat', (string) ( $data['detail'] ?? '' ) );
	}

	public function test_every_slot_conflict_reason_documented_on_the_class_is_allow_listed(): void {
		// `SlotConflict`'s docblock is the contract for the hold protocol's refusals, and
		// `Errors::conflict()` does NOT gate on `KNOWN_REASONS` - it maps the reason straight to the
		// wire. So a code documented there but missing from the allow-list would answer correctly
		// through `conflict()` and become a 500 through `failure()`, for the same string.
		$documented = array( 'overlap', 'seat_taken', 'capacity', 'no_staff', 'not_found', 'bad_time', 'lead_time', 'horizon', 'outside_hours', 'bad_seat', 'not_reschedulable' );
		foreach ( $documented as $reason ) {
			self::assertContains( $reason, self::knownReasons(), $reason . ' is documented on SlotConflict but not allow-listed.' );
			$data = Errors::conflict( new SlotConflict( $reason, 2 ) )->get_error_data();
			self::assertIsArray( $data );
			self::assertNotSame( self::GENERIC, $data['detail'] ?? '' );
			self::assertSame( 2, $data['segment'] );
		}
	}
}
