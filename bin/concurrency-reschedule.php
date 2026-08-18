#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Concurrency proof #5: `POST /bookings/{uuid}/reschedule` (P5 tasks 4 and 5) under real contention.
 *
 * `RescheduleBooking` releases a booking's slots and claims new ones inside ONE transaction, under the
 * union of the locks guarding both halves (`LockKey::sorted()` over the old items' keys plus the new
 * plan's). Everything it decides on - `overlapCount()`, `blockingSeatSum()`, the status re-read - is a
 * plain SELECT taken after those mutexes are held. Remove the acquisition and every one of those reads
 * runs on a snapshot that predates the rival's commit: two customers are then both told 200 for one
 * slot, with no error surfaced to either of them. That is the failure this file exists to catch, and it
 * is the failure step 5 of the task brief reproduces on purpose - see "WHAT THE RED RUN ACTUALLY LOOKS
 * LIKE" below for the exact shape it took on this stack.
 *
 * Two scenarios, both with a REAL rendezvous rather than a hopeful `sleep` - the pattern
 * bin/concurrency-occurrence.php established: a child `wp eval` drives one side of the race in-process
 * and pauses itself INSIDE its own transaction using core wpdb's `query` filter (a WordPress hook the
 * plugin knows nothing about, so the code under test is unmodified and unaware), while the driver fires
 * the rival over the live REST API at a shared wall-clock instant that falls inside that pause.
 *
 *  A. Two customers reschedule into the same single-capacity slot.
 *     - Child: `POST /bookings/{a1}/reschedule` dispatched with `rest_do_request()` - the full REST
 *       stack, permission callback included - paused at the `DELETE FROM ...booking_items` that
 *       releases its old placement. That is exactly the TOCTOU window: locks held, booking row locked,
 *       old items gone, `assertTargetIsFree()` not yet run, transaction still open.
 *     - Driver: `POST /bookings/{a2}/reschedule` over HTTP for the SAME target, fired inside the pause.
 *     - With the lock: the rival blocks on the shared resource-day row until the child commits, then
 *       re-reads a world containing the child's item and is refused `overlap`. Without it: the rival
 *       reads a free slot and commits, and the child - whose consistent-read snapshot was opened before
 *       the rival ever existed - sees nothing in its way and answers 200 as well. Two 200s for one slot.
 *     - The two bookings start on DIFFERENT days and move to a shared third day, so each side's lock
 *       union is genuinely two keys wide and the contested key is the intersection.
 *
 *  B. A reschedule races a rival hold for the target slot.
 *     - Child: `POST /bookings/{b1}/reschedule` onto a free slot, paused at the same item DELETE.
 *     - Driver: `POST /holds` over HTTP for that same slot, fired inside the pause.
 *     - With the lock the hold waits on the resource-day row the reschedule is holding, then re-reads
 *       and is refused `overlap`. Without it, both commit and the slot ends up with two blocking items.
 *     - Whichever side wins, the booking must be `confirmed` with exactly one item afterwards - at the
 *       target if the reschedule won, at its ORIGINAL start if the hold did. A refused reschedule may
 *       never degrade into a cancellation, and that is asserted rather than assumed.
 *     - `run_hold_race()` documents why the reschedule is the paused side here and the hold is not;
 *       the other way round the scenario passes even with the mutex deleted, for a reason that has
 *       nothing to do with the code under test.
 *
 * WHY THE PASS FORMULA ASSERTS OUTCOMES AND NOT MERELY DIFFERENCE. `$raced && $winners <= 1` is not a
 * test: a harness pointed at a base URL that cannot answer at all produces zero rival winners and would
 * report PASS having proven nothing (a P4 harness shipped in exactly that state and was caught only by
 * aiming it at `http://127.0.0.1:1`). So every leg is pinned: the loser's exact HTTP status, the loser's
 * exact machine reason, the loser's booking still at its ORIGINAL start and still `confirmed`, the
 * winner's booking at the TARGET start, and - the invariant the mutex actually exists for - exactly ONE
 * blocking item covering the contested range afterwards. A dead endpoint fails on the loser's status
 * (0, not 409) before it can reach any of the rest.
 *
 * WHAT THE RED RUN ACTUALLY LOOKS LIKE, because "both bookings land on the same slot" is only one of
 * the shapes the damage takes and a harness that only looked for that one would miss this. With the
 * acquisition commented out and this script run against wp-env's MariaDB, the paused child answers
 * `200` carrying a payload that shows the booking at its ORIGINAL start: its `bumpRev()` UPDATE hits
 * "Record has changed since last read" (MariaDB error 1020) on the resource-day row the rival committed
 * to while it was paused, the whole transaction is rolled back underneath it, and the use case runs on
 * through the audit write and COMMIT reporting success for a move that never happened. Two successes
 * are reported and one of them is fiction. `1 === $winners` catches that, and the committed-state
 * assertions catch it again - which is exactly why this formula reads the database back after the race
 * instead of believing the status codes.
 *
 * `stale_state` IS AN ACCEPTED LOSER REASON, not a harness failure. `RescheduleBooking` recomputes its
 * key set from the locked re-read and refuses `stale_state` (409, retryable) when a rival moved the
 * booking between the pre-lock plan and the transaction, rather than writing outside a mutex it does not
 * hold. That is a legitimate outcome of a genuine race and the engine's documented contract, so it is
 * accepted wherever `overlap` is - and every OTHER assertion still has to hold, including the rollback
 * ones, which is what keeps the allowance from becoming a hole. Neither scenario is expected to produce
 * it (each contests a slot between two DIFFERENT bookings, and nothing moves either booking underneath
 * itself), so the emitted `loser_reason` says which one actually happened.
 *
 * `paused_at` is reported and asserted rather than trusted: a child that had not reached its pause when
 * the rival fired never raced at all, and its scenario fails as un-raced instead of passing quietly.
 *
 * Usage: php bin/concurrency-reschedule.php <base_url> <service_id> <resource_id> <start_utc> [cli_container]
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
 * The machine-readable reason slug out of a REST error body - the same extraction
 * bin/concurrency-holds.php and bin/concurrency-occurrence.php use, and for the same reason:
 * `Errors::conflict()`/`Errors::failure()` put the reason on the `\WP_Error` MESSAGE, which
 * `WP_REST_Server::error_to_response()` surfaces as the body's top-level `message` key (`data.detail`
 * is only the translated sentence). `data.reason` and a `reservant_`-prefixed `code` are fallbacks in
 * case a future error shape renames the primary field.
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

/**
 * Three confirmed appointment bookings on the given service/resource, one per scenario side.
 *
 * Seeded in admin mode (`HoldRequest::$admin`), which lands straight on `confirmed` with no hold TTL -
 * one round trip instead of hold-then-confirm, and `confirmed` is the status the invariants below
 * assert the losers still hold. The plaintext `manage_token` comes back exactly once, from
 * `HoldBooking::execute()`'s snapshot, and is the guest credential `/reschedule` requires.
 *
 * Their own days every run: bin/run-concurrency.sh truncates the booking tables before each run, and
 * these five days sit clear of every other scenario's (holds: +0 and +30; chains: +1..+10; occurrence:
 * its own freshly seeded occurrence), so nothing here can contest what another script booked.
 */
function build_seed_eval( int $serviceId, int $resourceId, string $startA1, string $startA2, string $startB1 ): string {
	return sprintf(
		<<<'PHP'
		global $wpdb;
		$make = static function ( $label, $email, $startUtc ) use ( $wpdb ) {
			$booking = \Reservant\Application\HoldBooking::make( $wpdb )->execute(
				new \Reservant\Application\Dto\HoldRequest(
					new \Reservant\Application\Dto\Customer( $label, $email ),
					new \Reservant\Application\Dto\AppointmentRequest(
						new \DateTimeImmutable( $startUtc, new \DateTimeZone( 'UTC' ) ),
						array( new \Reservant\Application\Dto\SegmentChoice( %1$d, %2$d ) )
					),
					null,
					true
				),
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
			);
			return array(
				'uuid'   => (string) $booking['uuid'],
				'token'  => (string) $booking['manage_token'],
				'start'  => (string) $booking['items'][0]['start_utc'],
				'status' => (string) $booking['status'],
			);
		};
		try {
			echo json_encode(
				array(
					'a1' => $make( 'ReschedA1', 'resched-a1@example.com', '%3$s' ),
					'a2' => $make( 'ReschedA2', 'resched-a2@example.com', '%4$s' ),
					'b1' => $make( 'ReschedB1', 'resched-b1@example.com', '%5$s' ),
				)
			);
		} catch ( \Throwable $e ) {
			echo json_encode( array( 'error' => $e->getMessage() ) );
		}
		PHP,
		$serviceId,
		$resourceId,
		$startA1,
		$startA2,
		$startB1
	);
}

/**
 * The child side of BOTH scenarios: a real `POST /bookings/{uuid}/reschedule`, dispatched in-process by
 * `rest_do_request()` so the `query` filter can pause it, and paused inside its own transaction at the
 * instant the old items are being released.
 *
 * `add_filter( 'query', ... )` is core wpdb's own hook (`wpdb::query()` applies it to every statement),
 * so nothing in the plugin knows the pause exists. Matching the item DELETE rather than the lock's
 * `SELECT ... FOR UPDATE` is deliberate: the DELETE is issued whether or not the locks were acquired, so
 * a sabotaged build still reaches the pause and still races - it fails on the OUTCOME (two winners)
 * rather than on a rendezvous that quietly never happened.
 *
 * `rest_do_request()` runs the permission callback too, so the guest token below is genuinely checked.
 */
function build_paused_reschedule_eval( string $uuid, string $token, string $targetStart, float $resumeAt ): string {
	return sprintf(
		<<<'PHP'
		$pausedAt = 0.0;
		add_filter(
			'query',
			static function ( $query ) use ( &$pausedAt ) {
				if ( 0.0 === $pausedAt && 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*reservant_booking_items/i', (string) $query ) ) {
					$pausedAt = microtime( true );
					time_sleep_until( %4$F );
				}
				return $query;
			}
		);
		$request = new \WP_REST_Request( 'POST', '/reservant/v1/bookings/%1$s/reschedule' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body( json_encode( array( 'token' => '%2$s', 'start_utc' => '%3$s' ) ) );
		try {
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			echo json_encode(
				array(
					'paused_at' => $pausedAt,
					'status'    => $response->get_status(),
					'reason'    => ( is_array( $data ) && is_string( $data['message'] ?? null ) ) ? $data['message'] : null,
					// What the 200 CLAIMED, to compare against what the database ends up holding. A
					// transaction rolled back underneath the request - which is what an unordered write
					// gets on this stack - still answers from the payload it built before COMMIT.
					'start'     => ( is_array( $data ) && isset( $data['items'][0]['start_utc'] ) ) ? (string) $data['items'][0]['start_utc'] : null,
				)
			);
		} catch ( \Throwable $e ) {
			echo json_encode( array( 'paused_at' => $pausedAt, 'status' => 0, 'reason' => $e->getMessage() ) );
		}
		PHP,
		$uuid,
		$token,
		$targetStart,
		$resumeAt
	);
}

/**
 * Committed state after a race: each booking's status and first-item start, plus the number of blocking
 * items covering the contested range on that resource.
 *
 * The blocking count is the invariant the mutex exists for and the one no HTTP status can fake - two
 * bookings sold the same 30 minutes read as `2` here no matter how encouraging the response codes were.
 *
 * @param list<string> $uuids
 */
function build_state_eval( array $uuids, int $resourceId, string $blockStart, string $blockEnd ): string {
	$quoted = array();
	foreach ( $uuids as $uuid ) {
		// The uuid comes from this script's own seed round trip, but it is being interpolated into PHP
		// source: refuse anything that is not the shape `Routes::UUID` accepts rather than trust it.
		if ( 1 === preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			$quoted[] = "'" . $uuid . "'";
		}
	}
	return sprintf(
		<<<'PHP'
		global $wpdb;
		$repo = new \Reservant\Infrastructure\Db\BookingRepository( $wpdb );
		$out  = array( 'bookings' => array() );
		foreach ( array( %1$s ) as $uuid ) {
			$row = $repo->findByUuid( $uuid );
			$out['bookings'][ $uuid ] = null === $row
				? null
				: array(
					'status' => (string) $row['status'],
					'start'  => isset( $row['items'][0] ) ? (string) $row['items'][0]['start_utc'] : null,
					'items'  => count( (array) $row['items'] ),
				);
		}
		$out['blocking_at_target'] = $repo->overlapCount( %2$d, '%3$s', '%4$s' );
		echo json_encode( $out );
		PHP,
		implode( ', ', $quoted ),
		$resourceId,
		$blockStart,
		$blockEnd
	);
}

/**
 * Spawn a `wp eval` child and hand back its handle and pipes, without waiting for it.
 *
 * @return array{proc: resource, pipes: array<int, resource>}|null
 */
function spawn_wp_eval( string $cli, string $phpCode ): ?array {
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
	return array(
		'proc'  => $process,
		'pipes' => $pipes,
	);
}

/**
 * Read a spawned child to EOF - which is also what waits for it - and decode its one JSON line.
 *
 * @param array{proc: resource, pipes: array<int, resource>} $child
 * @return array<string, mixed>
 */
function finish_wp_eval( array $child ): array {
	$stdout = (string) stream_get_contents( $child['pipes'][1] );
	$stderr = (string) stream_get_contents( $child['pipes'][2] );
	fclose( $child['pipes'][1] );
	fclose( $child['pipes'][2] );
	proc_close( $child['proc'] );

	$decoded = json_decode( trim( $stdout ), true );
	if ( ! is_array( $decoded ) ) {
		return array(
			'paused_at' => 0.0,
			'status'    => 0,
			'reason'    => 'unparseable_output',
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}
	return $decoded;
}

/**
 * One JSON POST over the live REST API, fired at the shared rendezvous instant.
 *
 * The timeout is generous on purpose: with the locks in place this request BLOCKS on the contested
 * resource-day row for the whole remainder of the child's pause before it gets to answer at all.
 *
 * @param array<string, mixed> $payload
 * @return array{code: int, body: string}
 */
function post_json_at( string $url, array $payload, int $target ): array {
	$handle = curl_init( $url );
	curl_setopt_array(
		$handle,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => (string) json_encode( $payload ),
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
		)
	);
	time_sleep_until( $target );
	$body = (string) curl_exec( $handle );
	$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	curl_close( $handle );
	return array(
		'code' => $code,
		'body' => $body,
	);
}

/**
 * Scenario A: two customers, two different origins, one target slot.
 *
 * @param array{uuid: string, token: string, start: string} $a1
 * @param array{uuid: string, token: string, start: string} $a2
 * @return array<string, mixed>
 */
function run_reschedule_race( string $base, string $cli, array $a1, array $a2, int $resourceId, string $target, string $targetEnd ): array {
	// 20s to the rendezvous - the margin bin/concurrency-holds.php measured for a `wp eval` round trip
	// (npx, docker exec, a full WordPress bootstrap) with room to spare on a loaded CI box. The child
	// then stays paused 6s past it, so the rival is certain to arrive inside the window.
	$meetAt   = time() + 20;
	$resumeAt = (float) ( $meetAt + 6 );

	$child = spawn_wp_eval( $cli, build_paused_reschedule_eval( $a1['uuid'], $a1['token'], $target, $resumeAt ) );
	if ( null === $child ) {
		return array(
			'test'  => 'reschedule',
			'error' => 'spawn_failed',
			'pass'  => false,
		);
	}

	$rival = post_json_at(
		$base . '/?rest_route=/reservant/v1/bookings/' . $a2['uuid'] . '/reschedule',
		array(
			'token'     => $a2['token'],
			'start_utc' => $target,
		),
		$meetAt
	);
	$child = finish_wp_eval( $child );

	$state    = wp_eval_json( $cli, build_state_eval( array( $a1['uuid'], $a2['uuid'] ), $resourceId, $target, $targetEnd ) );
	$bookings = ( is_array( $state ) && is_array( $state['bookings'] ?? null ) ) ? $state['bookings'] : array();
	$blocking = is_array( $state ) ? (int) ( $state['blocking_at_target'] ?? -1 ) : -1;

	$childCode  = (int) ( $child['status'] ?? 0 );
	$rivalCode  = $rival['code'];
	$childWon   = 200 === $childCode;
	$rivalWon   = 200 === $rivalCode;
	$winners    = (int) $childWon + (int) $rivalWon;
	$rivalState = is_array( $bookings[ $a2['uuid'] ] ?? null ) ? $bookings[ $a2['uuid'] ] : array();
	$childState = is_array( $bookings[ $a1['uuid'] ] ?? null ) ? $bookings[ $a1['uuid'] ] : array();

	// Whoever did not answer 200 is the loser, and every claim below is about that side specifically.
	$loserCode     = $childWon ? $rivalCode : $childCode;
	$loserReason   = $childWon ? extract_reason( json_decode( $rival['body'], true ) ) : ( is_string( $child['reason'] ?? null ) ? $child['reason'] : null );
	$loserOriginal = $childWon ? $a2['start'] : $a1['start'];
	$loserState    = $childWon ? $rivalState : $childState;
	$winnerState   = $childWon ? $childState : $rivalState;

	// The child must have been sitting in its pause when the rival fired, or the race never happened
	// and a "pass" would mean nothing.
	$raced = ( (float) ( $child['paused_at'] ?? 0.0 ) ) > 0.0 && ( (float) $child['paused_at'] ) <= (float) $meetAt;

	// Read back out of the database, not out of either response: a rolled-back transaction can still
	// answer 200 from the payload it built before COMMIT (see the file header).
	$loserStart   = isset( $loserState['start'] ) ? (string) $loserState['start'] : null;
	$loserStatus  = isset( $loserState['status'] ) ? (string) $loserState['status'] : null;
	$winnerStart  = isset( $winnerState['start'] ) ? (string) $winnerState['start'] : null;
	$winnerStatus = isset( $winnerState['status'] ) ? (string) $winnerState['status'] : null;

	$pass = $raced
		&& 1 === $winners
		&& 409 === $loserCode
		&& ( 'overlap' === $loserReason || 'stale_state' === $loserReason )
		&& $loserOriginal === $loserStart
		&& 'confirmed' === $loserStatus
		&& $target === $winnerStart
		&& 'confirmed' === $winnerStatus
		&& 1 === $blocking;

	return array(
		'test'               => 'reschedule',
		'target'             => $target,
		'child'              => $child,
		'rival_code'         => $rivalCode,
		'rival_body'         => $rival['body'],
		'winner'             => $childWon ? 'child' : ( $rivalWon ? 'rival' : null ),
		'winners'            => $winners,
		'loser_code'         => $loserCode,
		'loser_reason'       => $loserReason,
		'loser_original'     => $loserOriginal,
		'bookings'           => $bookings,
		'blocking_at_target' => $blocking,
		'raced'              => $raced,
		'pass'               => $pass,
	);
}

/**
 * Scenario B: a reschedule onto a slot a rival hold is taking at the same instant.
 *
 * THE PAUSED SIDE IS THE RESCHEDULE, NOT THE HOLD, AND THAT IS NOT ARBITRARY. Pausing the hold
 * instead - hold in the child, reschedule over HTTP - produces a scenario that passes green even with
 * `RescheduleBooking`'s mutex deleted, because `ResourceDayRepository::ensure()` is an `INSERT IGNORE`
 * and InnoDB takes a SHARED lock on the duplicate row when it hits the existing key. A paused hold
 * holding that row `FOR UPDATE` therefore blocks the rival's `ensure()` before the rival's own
 * acquisition would ever have been reached, and the serialisation the scenario appeared to prove would
 * have belonged to the wrong statement entirely. That version was written, run under the sabotage of
 * step 5, and observed to stay green - which is exactly what step 5 exists to catch. With the
 * reschedule paused, its `ensure()` has already autocommitted and released, so nothing but the mutex
 * under test can hold the rival hold back.
 *
 * @param array{uuid: string, token: string, start: string} $b1
 * @return array<string, mixed>
 */
function run_hold_race( string $base, string $cli, array $b1, int $serviceId, int $resourceId, string $target, string $targetEnd ): array {
	$meetAt   = time() + 20;
	$resumeAt = (float) ( $meetAt + 6 );

	$child = spawn_wp_eval( $cli, build_paused_reschedule_eval( $b1['uuid'], $b1['token'], $target, $resumeAt ) );
	if ( null === $child ) {
		return array(
			'test'  => 'reschedule_hold',
			'error' => 'spawn_failed',
			'pass'  => false,
		);
	}

	$rival = post_json_at(
		$base . '/?rest_route=/reservant/v1/holds',
		array(
			'customer'    => array(
				'name'  => 'ReschedHoldRival',
				'email' => 'resched-hold-rival@example.com',
			),
			'appointment' => array(
				'start_utc' => $target,
				'segments'  => array(
					array(
						'service_id'  => $serviceId,
						'resource_id' => $resourceId,
					),
				),
			),
		),
		$meetAt
	);
	$child = finish_wp_eval( $child );

	$state    = wp_eval_json( $cli, build_state_eval( array( $b1['uuid'] ), $resourceId, $target, $targetEnd ) );
	$bookings = ( is_array( $state ) && is_array( $state['bookings'] ?? null ) ) ? $state['bookings'] : array();
	$blocking = is_array( $state ) ? (int) ( $state['blocking_at_target'] ?? -1 ) : -1;
	$booking  = is_array( $bookings[ $b1['uuid'] ] ?? null ) ? $bookings[ $b1['uuid'] ] : array();

	$rescheduleCode   = (int) ( $child['status'] ?? 0 );
	$rescheduleReason = is_string( $child['reason'] ?? null ) ? $child['reason'] : null;
	$holdCode         = $rival['code'];
	$holdReason       = extract_reason( json_decode( $rival['body'], true ) );
	$holdWon          = 201 === $holdCode;
	$rescheduleWon    = 200 === $rescheduleCode;
	$winners          = (int) $holdWon + (int) $rescheduleWon;

	$raced = ( (float) ( $child['paused_at'] ?? 0.0 ) ) > 0.0 && ( (float) $child['paused_at'] ) <= (float) $meetAt;

	// Read back out of the database, not out of either response - same reason as scenario A.
	$bookingStart  = isset( $booking['start'] ) ? (string) $booking['start'] : null;
	$bookingStatus = isset( $booking['status'] ) ? (string) $booking['status'] : null;

	// Either side may legitimately win, and each outcome has its own full set of consequences - the
	// booking is at the target and the hold was refused `overlap`, or the booking never moved, is still
	// `confirmed`, and the reschedule was refused. What is NOT allowed is both, neither, or a winner
	// whose loser cannot say why it lost.
	$outcome = $holdWon
		? ( 409 === $rescheduleCode
			&& ( 'overlap' === $rescheduleReason || 'stale_state' === $rescheduleReason )
			&& $b1['start'] === $bookingStart )
		: ( 409 === $holdCode
			&& 'overlap' === $holdReason
			&& $target === $bookingStart );

	$pass = $raced
		&& 1 === $winners
		&& 'confirmed' === $bookingStatus
		&& 1 === (int) ( $booking['items'] ?? 0 )
		&& 1 === $blocking
		&& $outcome;

	return array(
		'test'               => 'reschedule_hold',
		'target'             => $target,
		'reschedule'         => $child,
		'hold_code'          => $holdCode,
		'hold_body'          => $rival['body'],
		'hold_reason'        => $holdReason,
		'winner'             => $holdWon ? 'hold' : ( $rescheduleWon ? 'reschedule' : null ),
		'winners'            => $winners,
		'booking_original'   => $b1['start'],
		'booking_after'      => $booking,
		'blocking_at_target' => $blocking,
		'raced'              => $raced,
		'pass'               => $pass,
	);
}

/** `$startUtc` shifted by whole days, keeping the wire format the REST layer validates. */
function day_offset( string $startUtc, int $days, string $timeOfDay ): string {
	return ( new DateTimeImmutable( $startUtc, new DateTimeZone( 'UTC' ) ) )
		->modify( '+' . $days . ' days' )
		->format( 'Y-m-d ' ) . $timeOfDay;
}

[ , $base, $serviceId, $resourceId, $startUtc, $cli ] = $argv + array( null, 'http://localhost:8889', '0', '0', '', 'tests-cli' );

$serviceId  = (int) $serviceId;
$resourceId = (int) $resourceId;
$cli        = ( is_string( $cli ) && '' !== $cli ) ? $cli : 'tests-cli';

// Five days of this script's own, all inside the fixture's 09:00-17:00 hours, on the 5-minute grid and
// comfortably inside the 60-day horizon. Scenario A's two bookings start on different days so their
// lock unions are two keys wide and overlap only on the target day.
$startA1 = day_offset( $startUtc, 12, '10:00:00' );
$startA2 = day_offset( $startUtc, 13, '10:00:00' );
$targetA = day_offset( $startUtc, 14, '13:00:00' );
$startB1 = day_offset( $startUtc, 15, '10:00:00' );
$targetB = day_offset( $startUtc, 16, '13:00:00' );

$seeded = wp_eval_json( $cli, build_seed_eval( $serviceId, $resourceId, $startA1, $startA2, $startB1 ) );
if ( ! is_array( $seeded ) || ! is_array( $seeded['a1'] ?? null ) || ! is_array( $seeded['a2'] ?? null ) || ! is_array( $seeded['b1'] ?? null ) ) {
	echo json_encode(
		array(
			'test'  => 'reschedule',
			'error' => 'seed_failed',
			'seed'  => $seeded,
			'pass'  => false,
		)
	), PHP_EOL;
	exit( 1 );
}

// The contested block range: the fixture's "Cut" is 30 minutes with no buffers, so the target start
// plus 30 minutes is exactly the span both sides are fighting over.
$targetAEnd = ( new DateTimeImmutable( $targetA, new DateTimeZone( 'UTC' ) ) )->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' );
$targetBEnd = ( new DateTimeImmutable( $targetB, new DateTimeZone( 'UTC' ) ) )->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' );

$raceResult = run_reschedule_race( $base, $cli, $seeded['a1'], $seeded['a2'], $resourceId, $targetA, $targetAEnd );
$holdResult = run_hold_race( $base, $cli, $seeded['b1'], $serviceId, $resourceId, $targetB, $targetBEnd );

echo json_encode( $raceResult ), PHP_EOL;
echo json_encode( $holdResult ), PHP_EOL;

exit( ( true === $raceResult['pass'] && true === $holdResult['pass'] ) ? 0 : 1 );
