#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Task 16 concurrency proof #2: opposing-order chains must not deadlock (AGENTS.md section 2.2 --
 * "two chains booking the same pair [of resource-days] in opposite order will deadlock" unless
 * lock acquisition is sorted). No WordPress bootstrap: plain curl against the live REST API.
 *
 * Each round fires two 2-segment chains at once, both touching the *same pair* of resource-days
 * (staff A's day and staff B's day) but declaring their resource ids in opposite order:
 *
 *   Chain A: [ {cut, A}, {colour, B} ]   -- declares resources [A, B]
 *   Chain B: [ {cut, B}, {colour, A} ]   -- declares resources [B, A]
 *
 * A naive, unsorted lock-acquisition path would have chain A grab A-then-B while chain B grabs
 * B-then-A -- the classic AB/BA deadlock. `LockKey::sorted` (AGENTS.md section 2.2 step 1) exists
 * specifically to prevent it by always locking ascending by resource id, for both chains alike.
 *
 * Genuine contention, not just a shared lock: chain A and chain B both start their *cut* segment
 * (duration d) at their own start time, so within a single chain the two segments occupy fixed,
 * non-overlapping windows [0, d) and [d, 2d) relative to that chain's own start (AGENTS.md section 2.4 --
 * "chain segments never overlap in time"). Swapping which resource sits in which window at the
 * *same* start time therefore produces two chains that never actually touch the same resource at
 * the same clock time -- both would legitimately win, which proves decomposition, not locking.
 * To force a real collision, chain B starts `d` minutes after chain A: chain B's cut-on-B window
 * then lands exactly on chain A's colour-on-B window, so only one of the two chains can complete.
 * `d` is read from the live service rather than assumed, so the test survives a fixture change.
 *
 * The loser's failure reason is asserted to be exactly `overlap`, the same discipline
 * concurrency-seats.php applies to `seat_taken`: any 409 used to pass, which would just as
 * happily accept `outside_hours`, `bad_time`, or a leftover hold from a previous run as it would
 * a genuine race loss, silently defeating the point of the test.
 *
 * `$startBase` (the driver's shared "now + ~21 days" start) is deliberately not round 0's start:
 * the holds script (bin/concurrency-holds.php) books staff A on that exact calendar day too, and
 * round 0 would then deterministically lose to that *pre-existing* hold rather than to its actual
 * opponent -- a real bug once (see task-16-report.md finding 1), not a hypothetical. Every round
 * here therefore starts at least one full day after `$startBase`, a day the holds script never
 * touches.
 *
 * Usage: php bin/concurrency-chains.php <base_url> <cut_service> <colour_service> <staff_a> <staff_b> <start_utc>
 */

/** @return array{codes: array<int, int>, bodies: array<int, string>, elapsed: float} */
function fire_pair( string $base, string $payloadA, string $payloadB ): array {
	$mh      = curl_multi_init();
	$handles = array();
	foreach ( array( $payloadA, $payloadB ) as $payload ) {
		$ch = curl_init( $base . '/?rest_route=/reservant/v1/holds' );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
			)
		);
		curl_multi_add_handle( $mh, $ch );
		$handles[] = $ch;
	}

	$started = microtime( true );
	do {
		curl_multi_exec( $mh, $running );
		curl_multi_select( $mh, 0.05 );
	} while ( $running > 0 );
	$elapsed = microtime( true ) - $started;

	$codes  = array_map( static fn ( $ch ): int => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), $handles );
	$bodies = array_map( static fn ( $ch ): string => (string) curl_multi_getcontent( $ch ), $handles );
	foreach ( $handles as $ch ) {
		curl_multi_remove_handle( $mh, $ch );
		curl_close( $ch );
	}
	curl_multi_close( $mh );

	return array(
		'codes'   => $codes,
		'bodies'  => $bodies,
		'elapsed' => $elapsed,
	);
}

function chain_payload( string $startUtc, int $serviceA, int $resourceA, int $serviceB, int $resourceB ): string {
	return (string) json_encode(
		array(
			'customer'    => array(
				'name'  => 'Chain',
				'email' => 'chain@example.com',
			),
			'appointment' => array(
				'start_utc' => $startUtc,
				'segments'  => array(
					array(
						'service_id'  => $serviceA,
						'resource_id' => $resourceA,
					),
					array(
						'service_id'  => $serviceB,
						'resource_id' => $resourceB,
					),
				),
			),
		)
	);
}

/** Read the cut service's own duration -- the offset that makes chain B's cut collide with chain A's colour. */
function cut_duration_min( string $base, int $cutId ): int {
	$ch = curl_init( $base . '/?rest_route=/reservant/v1/services/' . $cutId );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
		)
	);
	$body = curl_exec( $ch );
	curl_close( $ch );
	$data = is_string( $body ) ? json_decode( $body, true ) : null;
	if ( ! is_array( $data ) || ! isset( $data['duration_min'] ) ) {
		fwrite( STDERR, "concurrency-chains: could not read duration_min for service {$cutId} from {$base}\n" );
		exit( 1 );
	}
	return (int) $data['duration_min'];
}

[ , $base, $cutId, $colourId, $staffA, $staffB, $startBase ] = $argv + array( null, 'http://localhost:8889', '0', '0', '0', '0', '' );

$cutId    = (int) $cutId;
$colourId = (int) $colourId;
$staffA   = (int) $staffA;
$staffB   = (int) $staffB;

$offsetMin = cut_duration_min( $base, $cutId );
// +1 day: $startBase's own day is the holds script's day (staff A already has a live hold there
// by the time this runs) -- see the file header. Every round below lands on a day neither the
// holds script nor any earlier round in *this* run has touched.
$baseStart = ( new DateTimeImmutable( $startBase, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' );

// One round per calendar day -- day is the resource-day mutex's own granularity (AGENTS.md section 4), so
// this guarantees every round is a clean slate with zero risk of bleeding into working-hours limits
// the way ten same-day hourly offsets would (0900-1700 doesn't hold ten 90-minute chain footprints).
$rounds    = 10;
$results   = array();
$overallOk = true;

for ( $round = 0; $round < $rounds; $round++ ) {
	$roundDay = $baseStart->modify( "+{$round} days" );
	$startA   = $roundDay->format( 'Y-m-d H:i:s' );
	$startB   = $roundDay->modify( "+{$offsetMin} minutes" )->format( 'Y-m-d H:i:s' );

	$payloadA = chain_payload( $startA, $cutId, $staffA, $colourId, $staffB );
	$payloadB = chain_payload( $startB, $cutId, $staffB, $colourId, $staffA );

	$fired  = fire_pair( $base, $payloadA, $payloadB );
	$codes  = $fired['codes'];
	$bodies = $fired['bodies'];

	$wins    = count( array_keys( $codes, 201, true ) );
	$no5xx   = array() === array_filter( $codes, static fn ( int $c ): bool => $c >= 500 );
	$noStall = $fired['elapsed'] < 15.0; // InnoDB's own lock-wait timeout is 50s; a deadlock stalls there.
	$oneWin  = 1 === $wins;

	// The loser must have lost to genuine contention, not to some other refusal (outside_hours,
	// bad_time, a stale hold) that would happen to also carry a 409 -- exactly the gap that let
	// round 0's old holds-script collision pass silently before this check existed.
	$loserReason = null;
	if ( $oneWin ) {
		$loserIndex  = array_search( 409, $codes, true );
		$decoded     = false === $loserIndex ? null : json_decode( $bodies[ $loserIndex ], true );
		$loserReason = is_array( $decoded ) ? ( $decoded['message'] ?? null ) : null;
	}
	$reasonOk = 'overlap' === $loserReason;
	$roundOk  = $no5xx && $noStall && $oneWin && $reasonOk;

	$overallOk = $overallOk && $roundOk;
	$results[] = array(
		'round'        => $round,
		'codes'        => $codes,
		'loser_reason' => $loserReason,
		'elapsed'      => round( $fired['elapsed'], 3 ),
		'pass'         => $roundOk,
	);
}

echo json_encode(
	array(
		'test'   => 'chains',
		'rounds' => $results,
		'pass'   => $overallOk,
	)
), PHP_EOL;
exit( $overallOk ? 0 : 1 );
