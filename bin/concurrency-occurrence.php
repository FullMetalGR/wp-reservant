#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Concurrency proof #4: an admin occurrence edit racing a customer hold on the same occurrence.
 *
 * `OccurrencesAdminController::update()` re-checks its two guard rails (`referenced` and
 * `capacity`) as the first statement inside its transaction, but those guards are ordinary
 * non-locking SELECTs. Without the occurrence mutex (AGENTS.md section 2.2 - "event items use the
 * occurrence row itself as the mutex") the recheck buys nothing at all: a hold that commits between
 * the guard read and the UPDATE is invisible to the guard and unimpeded by it, and the occurrence
 * lands with more seats sold than it has.
 *
 * The scenario, deterministic rather than hopeful:
 *
 *  1. Setup: a capacity-50 occurrence with 8 seats already permanently blocking.
 *  2. Child process (`wp eval`): drives the real controller for `PUT {capacity: 10}`, with a
 *     `query` filter - WordPress's own wpdb hook, harness-side only - that pauses the process the
 *     moment the occurrence UPDATE is about to be issued. The pause therefore sits exactly in the
 *     TOCTOU window: after the in-transaction guard read, before the write, transaction still open.
 *  3. Driver: at a shared wall-clock instant inside that pause, POSTs a 20-seat hold over the live
 *     REST API - the same rendezvous bin/concurrency-holds.php uses, and for the same reason.
 *  4. Assert, once both have finished: `blockingSeatSum(occurrence) <= capacity`.
 *
 * Without the lock the hold sees 8 + 20 <= 50 and commits, then the admin write lands capacity 10
 * on top of it: 28 seats sold against a capacity of 10, permanently, and `activeBookingCount() > 0`
 * then refuses every further edit so the admin cannot even undo it. With the lock the hold blocks
 * on the occurrence row until the admin transaction commits, re-reads capacity 10, and is refused
 * `capacity` - the occurrence ends at 8 of 10.
 *
 * `paused_at` is reported and asserted rather than trusted: if the child had not reached its pause
 * before the hold fired, the guard read would have seen the hold's own seats and refused the edit
 * for the ordinary reason, and the run would prove nothing. That outcome fails the scenario as
 * `inconclusive` instead of passing quietly.
 *
 * The hold's own outcome is asserted too, not just reported (the anti-pattern
 * bin/concurrency-chains.php's header names, and the same discipline bin/concurrency-holds.php
 * applies to its losers' reason slugs): once raced, this scenario is deterministic - 8 already-
 * blocking seats plus a 20-seat hold exceed the admin's capacity-10 write, so with the occurrence
 * mutex in place the hold MUST come back 409 `capacity`, never merely "not 201". A bare
 * `blocking <= capacity` check on the final row is not enough on its own: if the hold curl call
 * failed for a reason that has nothing to do with the race (connection refused, the 60s timeout, a
 * 500), `blocking` would simply stay at the seeded 8, `8 <= 10` would still hold, and the scenario
 * would report PASS having proven nothing about locking at all.
 *
 * Usage: php bin/concurrency-occurrence.php <base_url> <event_service_id> [cli_container]
 */

/** Run one `wp eval` snippet in the tests container and json_decode its stdout. */
function wp_eval_json( string $cli, string $phpCode ): mixed {
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process     = proc_open(
		array( 'npx', 'wp-env', 'run', $cli, '--env-cwd=wp-content/plugins/reservant', 'wp', 'eval', $phpCode ),
		$descriptors,
		$pipes
	);
	if ( false === $process || ! is_array( $pipes ) ) {
		return null;
	}
	$stdout = (string) stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $process );
	return json_decode( trim( $stdout ), true );
}

/**
 * A fresh capacity-50 occurrence on the given event service, with 8 seats already blocking it.
 *
 * Its own occurrence every run: bin/run-concurrency.sh truncates the booking tables before each
 * run, so the 8 blocking seats are always exactly these 8, and nothing this script does can
 * contest what the other three scenarios booked.
 */
function seed_occurrence( int $serviceId ): string {
	return sprintf(
		<<<'PHP'
		global $wpdb;
		$start = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '+31 days' )->setTime( 18, 0 );
		$occurrence = ( new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb ) )->insert(
			array(
				'service_id' => %d,
				'start_utc'  => $start->format( 'Y-m-d H:i:s' ),
				'end_utc'    => $start->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ),
				'capacity'   => 50,
			)
		);
		try {
			\Reservant\Application\HoldBooking::make( $wpdb )->execute(
				new \Reservant\Application\Dto\HoldRequest(
					new \Reservant\Application\Dto\Customer( 'OccSeed', 'occ-seed@example.com' ),
					null,
					new \Reservant\Application\Dto\EventRequest( $occurrence, 8 ),
					true
				),
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
			);
			echo json_encode( array( 'occurrence' => $occurrence ) );
		} catch ( \Throwable $e ) {
			echo json_encode( array( 'occurrence' => 0, 'error' => $e->getMessage() ) );
		}
		PHP,
		$serviceId
	);
}

/**
 * The admin `PUT {capacity: 10}`, driven through the real controller, paused inside its own
 * transaction at the instant the occurrence UPDATE is about to run.
 *
 * `add_filter( 'query', ... )` is core wpdb's own hook (`wpdb::query()` applies it to every
 * statement); nothing in the plugin knows this filter exists, so the code under test is unmodified
 * and unaware. Matching on `UPDATE ...reservant_occurrences` and not on the guard SELECTs is what
 * puts the pause on the correct side of the read.
 */
function build_paused_put_eval( int $occurrenceId, float $resumeAt ): string {
	return sprintf(
		<<<'PHP'
		global $wpdb;
		$pausedAt = 0.0;
		add_filter(
			'query',
			static function ( $query ) use ( &$pausedAt ) {
				if ( 0.0 === $pausedAt && 1 === preg_match( '/^\s*UPDATE\s+\S*reservant_occurrences/i', (string) $query ) ) {
					$pausedAt = microtime( true );
					time_sleep_until( %2$F );
				}
				return $query;
			}
		);
		$request = new \WP_REST_Request( 'PUT', '/reservant/v1/admin/occurrences/%1$d' );
		$request->set_param( 'id', %1$d );
		$request->set_param( 'capacity', 10 );
		try {
			$response = ( new \Reservant\Rest\Admin\OccurrencesAdminController( $wpdb ) )->update( $request );
			echo json_encode(
				array(
					'paused_at' => $pausedAt,
					'status'    => $response instanceof \WP_Error ? 409 : $response->get_status(),
					'reason'    => $response instanceof \WP_Error ? $response->get_error_message() : null,
				)
			);
		} catch ( \Throwable $e ) {
			echo json_encode( array( 'paused_at' => $pausedAt, 'status' => 0, 'reason' => $e->getMessage() ) );
		}
		PHP,
		$occurrenceId,
		$resumeAt
	);
}

/**
 * The machine-readable reason slug out of a `/holds` REST error body - the same extraction
 * bin/concurrency-holds.php uses and for the same reason (see that file's `extract_reason()`):
 * `Errors::conflict()` puts the `SlotConflict` reason on the `\WP_Error` message, which
 * `WP_REST_Server::error_to_response()` surfaces as the response body's top-level `message` key.
 *
 * @param mixed $decoded json_decode() of the response body, or null/scalar if it did not decode.
 */
function extract_reason( mixed $decoded ): ?string {
	if ( ! is_array( $decoded ) ) {
		return null;
	}
	$data = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array();
	if ( is_string( $data['reason'] ?? null ) ) {
		return $data['reason'];
	}
	if ( is_string( $decoded['message'] ?? null ) ) {
		return $decoded['message'];
	}
	$code = $decoded['code'] ?? null;
	if ( is_string( $code ) && str_starts_with( $code, 'reservant_' ) ) {
		return substr( $code, strlen( 'reservant_' ) );
	}
	return null;
}

/** Final state of the contested occurrence, read fresh after both sides have finished. */
function build_final_state_eval( int $occurrenceId ): string {
	return sprintf(
		<<<'PHP'
		global $wpdb;
		$repo = new \Reservant\Infrastructure\Db\OccurrenceRepository( $wpdb );
		$row  = $repo->find( %1$d );
		echo json_encode(
			array(
				'capacity' => null === $row ? 0 : (int) $row['capacity'],
				'blocking' => $repo->blockingSeatSum( %1$d ),
			)
		);
		PHP,
		$occurrenceId
	);
}

[ , $base, $serviceId, $cli ] = $argv + array( null, 'http://localhost:8889', '0', 'tests-cli' );

$serviceId = (int) $serviceId;
$cli       = ( is_string( $cli ) && '' !== $cli ) ? $cli : 'tests-cli';

$seeded       = wp_eval_json( $cli, seed_occurrence( $serviceId ) );
$occurrenceId = is_array( $seeded ) ? (int) ( $seeded['occurrence'] ?? 0 ) : 0;
if ( 0 === $occurrenceId ) {
	echo json_encode(
		array(
			'test'  => 'occurrence_edit',
			'error' => 'seed_failed',
			'seed'  => $seeded,
			'pass'  => false,
		)
	), PHP_EOL;
	exit( 1 );
}

// 20s to the rendezvous - the margin bin/concurrency-holds.php measured for a `wp eval` round trip
// (npx, docker exec, a full WordPress bootstrap) with room to spare on a loaded CI box. The child
// then stays paused 6s past it, so the hold runs squarely inside the window either way.
$target   = time() + 20;
$resumeAt = (float) ( $target + 6 );

$descriptors = array(
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$process     = proc_open(
	array( 'npx', 'wp-env', 'run', $cli, '--env-cwd=wp-content/plugins/reservant', 'wp', 'eval', build_paused_put_eval( $occurrenceId, $resumeAt ) ),
	$descriptors,
	$pipes
);
if ( false === $process || ! is_array( $pipes ) ) {
	echo json_encode(
		array(
			'test'  => 'occurrence_edit',
			'error' => 'spawn_failed',
			'pass'  => false,
		)
	), PHP_EOL;
	exit( 1 );
}

$holdPayload = (string) json_encode(
	array(
		'customer' => array(
			'name'  => 'OccRace',
			'email' => 'occ-race@example.com',
		),
		'event'    => array(
			'occurrence_id' => $occurrenceId,
			'seats'         => 20,
		),
	)
);
$ch          = curl_init( $base . '/?rest_route=/reservant/v1/holds' );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $holdPayload,
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
		CURLOPT_RETURNTRANSFER => true,
		// Generous: with the fix in place this request BLOCKS on the occurrence row for the whole
		// remainder of the admin transaction's pause before it gets to answer at all.
		CURLOPT_TIMEOUT        => 60,
	)
);
time_sleep_until( $target );
$holdBody = (string) curl_exec( $ch );
$holdCode = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
curl_close( $ch );

$stdout = (string) stream_get_contents( $pipes[1] );
$stderr = (string) stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
proc_close( $process );

$put = json_decode( trim( $stdout ), true );
if ( ! is_array( $put ) ) {
	$put = array(
		'paused_at' => 0.0,
		'status'    => 0,
		'reason'    => 'unparseable_output',
		'stdout'    => $stdout,
		'stderr'    => $stderr,
	);
}

$final    = wp_eval_json( $cli, build_final_state_eval( $occurrenceId ) );
$capacity = is_array( $final ) ? (int) ( $final['capacity'] ?? 0 ) : 0;
$blocking = is_array( $final ) ? (int) ( $final['blocking'] ?? 0 ) : 0;

// The child must have been sitting in the window when the hold fired, or the race never happened
// and a "pass" would mean nothing.
$raced      = ( (float) ( $put['paused_at'] ?? 0.0 ) ) > 0.0 && ( (float) $put['paused_at'] ) <= (float) $target;
$holdReason = extract_reason( json_decode( $holdBody, true ) );
// Deterministic once raced: 8 seeded + 20 requested exceeds the admin's capacity-10 write, so the
// hold must be refused `capacity` specifically - a 409 with any other reason (or no 409 at all)
// means the scenario did not prove what it claims to.
$pass = $raced && $capacity > 0 && $blocking <= $capacity && 409 === $holdCode && 'capacity' === $holdReason;

echo json_encode(
	array(
		'test'        => 'occurrence_edit',
		'occurrence'  => $occurrenceId,
		'put'         => $put,
		'hold_code'   => $holdCode,
		'hold_body'   => $holdBody,
		'hold_reason' => $holdReason,
		'capacity'    => $capacity,
		'blocking'    => $blocking,
		'raced'       => $raced,
		'pass'        => $pass,
	)
), PHP_EOL;
exit( $pass ? 0 : 1 );
