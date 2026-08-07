#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Task 16 concurrency proof #1: N parallel, byte-identical hold requests for the same
 * service/resource/start. Exactly one may win -- the "hold protocol is the only authority on
 * capacity" invariant (AGENTS.md section 2.2). No WordPress bootstrap: this hits the live REST API over
 * HTTP, the same way any real client would.
 *
 * Task 6 adds a second proof in this same file: admin-mode manual booking (`HoldRequest::$admin`)
 * skips lead time and horizon but must still be serialised by the same resource-day lock as an
 * ordinary customer hold. There is no admin REST route yet (that is Task 7's job), so one worker
 * drives the admin-mode hold directly through `HoldBooking` via `wp eval` inside the tests
 * container -- see bin/run-concurrency.sh for the same shell-into-wp-env pattern used to lift the
 * rate limiter -- while the rival worker POSTs an ordinary customer hold over the live REST API
 * for the identical service/resource/start.
 *
 * Spawning the `wp eval` side (`proc_open()`) and then immediately firing the curl side is NOT a
 * race: `proc_open()` returns as soon as the child is forked, and everything that follows --
 * `npx` resolving `wp-env`, `docker compose exec`, a full WordPress bootstrap -- reliably takes
 * over a second (measured: ~1.1-1.6s for a bare `wp eval`), while the curl round trip against an
 * already-running REST server is single-digit milliseconds. Left alone, the customer request
 * always commits first and the admin-wins branch is never exercised, silently defeating the point
 * of the test. The fix is a shared wall-clock rendezvous: the driver picks
 * `$target = time() + 20` and hands it to both sides. The `wp eval` snippet calls
 * `time_sleep_until($target)` immediately before `HoldBooking::execute()`; the driver calls
 * `time_sleep_until($target)` immediately before `curl_exec()`. Both processes therefore enter the
 * locked write protocol within milliseconds of the same instant, regardless of how long getting
 * there took beforehand -- proc_open()/npx/docker overhead only eats into the 20s margin, it does
 * not decide the outcome. Exactly one side may win, and the loser must fail with `overlap` --
 * never a different refusal (a leftover hold, a bad window) that would happen to also carry a
 * 409/failure.
 *
 * Usage: php bin/concurrency-holds.php <base_url> <service_id> <resource_id> <start_utc> [cli_container]
 */

/**
 * The original scenario: 8 byte-identical customer holds racing for one slot.
 *
 * The losers' reason slug is asserted, not just their status code (the anti-pattern
 * bin/concurrency-chains.php's own header names): a 409 proves only that seven requests were
 * refused, not that contention is what refused them. A leftover hold from an earlier run, a start
 * that has drifted off the 5-minute grid or outside the fixture's working hours, or a service that
 * no longer has that resource all produce 1-winner-7-refusals too, and would let a genuinely broken
 * lock protocol pass here. Only `overlap` is the contention answer.
 *
 * @return array{codes: list<int>, reasons: list<string>, pass: bool}
 */
function run_parallel_holds_race( string $base, int $serviceId, int $resourceId, string $startUtc ): array {
	$payload = (string) json_encode(
		array(
			'customer'    => array(
				'name'  => 'Race',
				'email' => 'race@example.com',
			),
			'appointment' => array(
				'start_utc' => $startUtc,
				'segments'  => array(
					array(
						'service_id'  => $serviceId,
						'resource_id' => $resourceId,
					),
				),
			),
		)
	);

	$parallel = 8;
	$mh       = curl_multi_init();
	$handles  = array();
	for ( $i = 0; $i < $parallel; $i++ ) {
		$ch = curl_init( $base . '/?rest_route=/reservant/v1/holds' );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_multi_add_handle( $mh, $ch );
		$handles[] = $ch;
	}
	do {
		curl_multi_exec( $mh, $running );
		curl_multi_select( $mh, 0.05 );
	} while ( $running > 0 );

	$codes   = array();
	$reasons = array();
	foreach ( $handles as $ch ) {
		$code    = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$codes[] = $code;
		if ( 201 !== $code ) {
			$reasons[] = extract_reason( json_decode( (string) curl_multi_getcontent( $ch ), true ) ) ?? 'unparseable';
		}
		curl_multi_remove_handle( $mh, $ch );
		curl_close( $ch );
	}
	curl_multi_close( $mh );

	$wins       = count( array_keys( $codes, 201, true ) );
	$conf       = count( array_keys( $codes, 409, true ) );
	$allOverlap = array( 'overlap' ) === array_values( array_unique( $reasons ) );
	return array(
		'codes'   => $codes,
		'reasons' => $reasons,
		'pass'    => ( 1 === $wins ) && ( $parallel - 1 === $conf ) && $allOverlap,
	);
}

/**
 * PHP source for the `wp eval` side of the admin-vs-customer race: an admin-mode hold for the
 * exact same service/resource/start the rival curl request below also targets. No opening `<?php`
 * tag -- `wp eval` supplies its own, exactly like the mu-plugin snippet in bin/run-concurrency.sh.
 * Prints one line of JSON and nothing else, so the driver can parse it straight off stdout.
 *
 * `time_sleep_until( $target )` runs immediately before `HoldBooking::execute()` -- the rendezvous
 * that makes this a real race against the driver's own `time_sleep_until( $target )` before
 * `curl_exec()` (see the file header).
 */
function build_admin_hold_eval( int $serviceId, int $resourceId, string $startUtc, int $target ): string {
	return sprintf(
		<<<'PHP'
		global $wpdb;
		time_sleep_until( %d );
		try {
			$booking = \Reservant\Application\HoldBooking::make( $wpdb )->execute(
				new \Reservant\Application\Dto\HoldRequest(
					new \Reservant\Application\Dto\Customer( 'AdminRace', 'admin-race@example.com' ),
					new \Reservant\Application\Dto\AppointmentRequest(
						new \DateTimeImmutable( '%s', new \DateTimeZone( 'UTC' ) ),
						array( new \Reservant\Application\Dto\SegmentChoice( %d, %d ) )
					),
					null,
					true
				),
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
			);
			echo json_encode( array( 'ok' => true, 'status' => $booking['status'], 'uuid' => $booking['uuid'] ) );
		} catch ( \Reservant\Application\SlotConflict $e ) {
			echo json_encode( array( 'ok' => false, 'reason' => $e->reason ) );
		} catch ( \Throwable $e ) {
			echo json_encode( array( 'ok' => false, 'reason' => 'exception', 'message' => $e->getMessage() ) );
		}
		PHP,
		$target,
		$startUtc,
		$serviceId,
		$resourceId
	);
}

/**
 * The machine-readable reason slug out of a `/holds` REST error body (Finding 2 fix -- verified
 * against src/Rest/Errors.php, not assumed).
 *
 * `Errors::conflict()` and `Errors::failure()` both construct the `\WP_Error` as
 * `new \WP_Error( $code, $reason, array( 'status' => ..., 'detail' => ... ) )` -- the reason slug
 * is the `\WP_Error` MESSAGE, and WordPress's own `WP_REST_Server::error_to_response()` puts a
 * `\WP_Error`'s message on the response body's top-level `message` key (`data.detail` is only the
 * translated human sentence). Confirmed live: a genuine overlap against this codebase's REST API
 * returns `{"code":"reservant_conflict","message":"overlap","data":{"status":409,"segment":0,
 * "detail":"That time was just taken. Please pick another."}}`. `message` is therefore read
 * first; `data.reason` and a `reservant_`-prefixed `code` are read as fallbacks in case a future
 * error shape adds or renames the primary field, so this does not silently start reading the
 * translated detail sentence as if it were the machine slug.
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
 * Task 6: one admin-mode hold (direct, via `wp eval`) against one ordinary customer hold (REST),
 * same service/resource/start, both released into the locked write protocol at the same shared
 * wall-clock instant (Finding 1 fix -- see the file header for why the naive
 * "spawn-then-immediately-curl" approach never actually raced).
 *
 * @return array{admin: array<string, mixed>, customer_code: int, customer_body: string, winner: ?string, loser_reason: ?string, pass: bool}
 */
function run_admin_customer_race( string $base, string $cli, int $serviceId, int $resourceId, string $startUtc ): array {
	// 20s: comfortably clear of every overhead a `wp eval` round trip has shown in practice
	// (~1.1-1.6s measured for `npx wp-env run ... wp eval`) so the child is certain to reach its
	// own `time_sleep_until( $target )` before $target arrives, whatever load a CI box is under.
	$target  = time() + 20;
	$phpCode = build_admin_hold_eval( $serviceId, $resourceId, $startUtc, $target );

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
		return array(
			'admin'         => array(
				'ok'     => false,
				'reason' => 'spawn_failed',
			),
			'customer_code' => 0,
			'customer_body' => '',
			'winner'        => null,
			'loser_reason'  => null,
			'pass'          => false,
		);
	}

	$customerPayload = (string) json_encode(
		array(
			'customer'    => array(
				'name'  => 'AdminRaceCustomer',
				'email' => 'admin-race-customer@example.com',
			),
			'appointment' => array(
				'start_utc' => $startUtc,
				'segments'  => array(
					array(
						'service_id'  => $serviceId,
						'resource_id' => $resourceId,
					),
				),
			),
		)
	);
	$ch              = curl_init( $base . '/?rest_route=/reservant/v1/holds' );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $customerPayload,
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
		)
	);
	// The rendezvous: both sides enter the protocol within milliseconds of the same wall-clock
	// instant, regardless of how long spawning the child process took beforehand.
	time_sleep_until( $target );
	$customerBody = (string) curl_exec( $ch );
	$customerCode = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	// Reading stdout/stderr to EOF blocks until the child process has finished -- this is also
	// what actually waits for the wp eval side of the race to complete.
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exitCode = proc_close( $process );

	$adminResult = json_decode( trim( $stdout ), true );
	if ( ! is_array( $adminResult ) ) {
		$adminResult = array(
			'ok'     => false,
			'reason' => 'unparseable_output',
			'stdout' => $stdout,
			'stderr' => $stderr,
			'exit'   => $exitCode,
		);
	}

	$adminWon       = true === ( $adminResult['ok'] ?? false );
	$customerWon    = 201 === $customerCode;
	$customerReason = extract_reason( json_decode( $customerBody, true ) );

	$winner      = $adminWon ? 'admin' : ( $customerWon ? 'customer' : null );
	$loserReason = $adminWon ? $customerReason : ( $customerWon ? ( $adminResult['reason'] ?? null ) : null );

	// Exactly one side may win -- neither both (capacity breached) nor neither (a spurious refusal
	// on a slot that should have been free to at least one of them) -- and the loser's reason must
	// be the genuine contention reason, not some other refusal that would also carry a 409/failure.
	// `!==` on two bools IS the XOR: `xor` itself is a trap here, its precedence is lower than `=`,
	// so `$a = $b xor $c` parses as `($a = $b) xor $c` and silently drops $c.
	$exactlyOneWinner = $adminWon !== $customerWon;
	$pass             = $exactlyOneWinner && 'overlap' === $loserReason;

	return array(
		'admin'         => $adminResult,
		'customer_code' => $customerCode,
		// Kept even on success: a both-lose or both-win outcome (a real bug) is otherwise
		// undiagnosable from the emitted JSON alone.
		'customer_body' => $customerBody,
		'winner'        => $winner,
		'loser_reason'  => $loserReason,
		'pass'          => $pass,
	);
}

[ , $base, $serviceId, $resourceId, $startUtc, $cli ] = $argv + array( null, 'http://localhost:8889', '0', '0', '', 'tests-cli' );

$serviceId  = (int) $serviceId;
$resourceId = (int) $resourceId;
$cli        = ( is_string( $cli ) && '' !== $cli ) ? $cli : 'tests-cli';

$holdsResult = run_parallel_holds_race( $base, $serviceId, $resourceId, $startUtc );

// A day neither the race above nor bin/concurrency-chains.php's rounds ever touch (chains.php
// runs on $startUtc + 1 .. + 10 days) -- bin/run-concurrency.sh truncates the booking tables before
// every run regardless, but a day of its own keeps this scenario correct even if that changes.
$adminRaceStart = ( new DateTimeImmutable( $startUtc, new DateTimeZone( 'UTC' ) ) )->modify( '+30 days' )->format( 'Y-m-d H:i:s' );
$adminResult    = run_admin_customer_race( $base, $cli, $serviceId, $resourceId, $adminRaceStart );

echo json_encode(
	array(
		'test'      => 'holds',
		'codes'     => $holdsResult['codes'],
		'winners'   => count( array_keys( $holdsResult['codes'], 201, true ) ),
		'conflicts' => count( array_keys( $holdsResult['codes'], 409, true ) ),
		'reasons'   => $holdsResult['reasons'],
		'pass'      => $holdsResult['pass'],
	)
), PHP_EOL;

echo json_encode(
	array(
		'test'          => 'admin_race',
		'winner'        => $adminResult['winner'],
		'loser_reason'  => $adminResult['loser_reason'],
		'admin'         => $adminResult['admin'],
		'customer_code' => $adminResult['customer_code'],
		'customer_body' => $adminResult['customer_body'],
		'pass'          => $adminResult['pass'],
	)
), PHP_EOL;

exit( ( $holdsResult['pass'] && $adminResult['pass'] ) ? 0 : 1 );
