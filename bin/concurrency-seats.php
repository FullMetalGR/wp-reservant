#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Task 16 concurrency proof #3: N parallel event holds contesting the same single seat on the
 * same occurrence. Exactly one may win; the rest must fail with `reason = seat_taken` (AGENTS.md
 * section 2.2 -- the unique `(occurrence_id, seat_claim)` index is the hard backstop behind the
 * occurrence row lock). No WordPress bootstrap: plain curl against the live REST API.
 *
 * A grid hold requires `seat_ids` whose count matches `seats`; sending a single seat id and
 * omitting `seats` lets the controller default `seats` to `count(seat_ids) === 1` itself.
 *
 * Usage: php bin/concurrency-seats.php <base_url> <occurrence_id> <seat_id>
 */

[ , $base, $occurrenceId, $seatId ] = $argv + array( null, 'http://localhost:8889', '0', '0' );

$payload = json_encode(
	array(
		'customer' => array(
			'name'  => 'Seat',
			'email' => 'seat@example.com',
		),
		'event'    => array(
			'occurrence_id' => (int) $occurrenceId,
			'seat_ids'      => array( (int) $seatId ),
		),
	)
);

$parallel = 6;
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

$codes  = array();
$bodies = array();
foreach ( $handles as $ch ) {
	$codes[]  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$bodies[] = (string) curl_multi_getcontent( $ch );
	curl_multi_remove_handle( $mh, $ch );
	curl_close( $ch );
}
curl_multi_close( $mh );

$wins = count( array_keys( $codes, 201, true ) );
$conf = count( array_keys( $codes, 409, true ) );

$seatTakenReasons = 0;
foreach ( $bodies as $index => $body ) {
	if ( 409 !== $codes[ $index ] ) {
		continue;
	}
	$decoded = json_decode( $body, true );
	if ( is_array( $decoded ) && ( $decoded['message'] ?? null ) === 'seat_taken' ) {
		++$seatTakenReasons;
	}
}

$ok = ( 1 === $wins ) && ( $parallel - 1 === $conf ) && ( $parallel - 1 === $seatTakenReasons );

echo json_encode(
	array(
		'test'       => 'seats',
		'codes'      => $codes,
		'winners'    => $wins,
		'conflicts'  => $conf,
		'seat_taken' => $seatTakenReasons,
		'pass'       => $ok,
	)
), PHP_EOL;
exit( $ok ? 0 : 1 );
