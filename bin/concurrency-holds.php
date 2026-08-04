#!/usr/bin/env php
<?php
declare( strict_types=1 );

/**
 * Task 16 concurrency proof #1: N parallel, byte-identical hold requests for the same
 * service/resource/start. Exactly one may win -- the "hold protocol is the only authority on
 * capacity" invariant (AGENTS.md section 2.2). No WordPress bootstrap: this hits the live REST API over
 * HTTP, the same way any real client would.
 *
 * Usage: php bin/concurrency-holds.php <base_url> <service_id> <resource_id> <start_utc>
 */

[ , $base, $serviceId, $resourceId, $startUtc ] = $argv + array( null, 'http://localhost:8889', '0', '0', '' );

$payload = json_encode(
	array(
		'customer'    => array(
			'name'  => 'Race',
			'email' => 'race@example.com',
		),
		'appointment' => array(
			'start_utc' => $startUtc,
			'segments'  => array(
				array(
					'service_id'  => (int) $serviceId,
					'resource_id' => (int) $resourceId,
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

$codes = array_map( static fn ( $ch ): int => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), $handles );
foreach ( $handles as $ch ) {
	curl_multi_remove_handle( $mh, $ch );
	curl_close( $ch );
}
curl_multi_close( $mh );

$wins = count( array_keys( $codes, 201, true ) );
$conf = count( array_keys( $codes, 409, true ) );
$ok   = ( 1 === $wins ) && ( $parallel - 1 === $conf );

echo json_encode(
	array(
		'test'      => 'holds',
		'codes'     => $codes,
		'winners'   => $wins,
		'conflicts' => $conf,
		'pass'      => $ok,
	)
), PHP_EOL;
exit( $ok ? 0 : 1 );
