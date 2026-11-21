<?php
/**
 * Stands in for the Samomind API in the update test: answers the ingest
 * endpoints the way the contract says and blocks every other request, so
 * nothing leaves the test site.
 *
 * Fixed fake values only. No real key, secret or code is used or printed.
 */

define( 'SMOKE_KEY_ID', '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10' );
define( 'SMOKE_SECRET', 'smik_UpdateTestFakeSecret0000000000000000000000' );
define( 'SMOKE_ORDERS_SECRET', 'smok_UpdateTestFakeOrdersKey000000000000000' );
define( 'SMOKE_SITE_KEY', 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA' );
define( 'SMOKE_API_BASE', 'https://api.samomind.com' );

$GLOBALS['smoke_requests'] = array();

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$GLOBALS['smoke_requests'][] = $path;
		if ( 0 !== strpos( $path, '/api/v1/ingest/' ) ) {
			return new WP_Error( 'smoke_blocked', 'The update test allows only the Samomind ingest endpoints.' );
		}

		$body   = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$answer = array();
		switch ( substr( $path, strlen( '/api/v1/ingest' ) ) ) {
			case '/connect':
				$answer = array(
					'source_id'                => '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
					'checkin_interval_seconds' => 86400,
				);
				break;
			case '/checkin':
				$answer = array(
					'status'                   => 'active',
					'checkin_interval_seconds' => 86400,
				);
				break;
			case '/documents':
				$results = array();
				foreach ( isset( $body['documents'] ) ? (array) $body['documents'] : array() as $document ) {
					$results[] = array( 'status' => 'queued' );
				}
				$answer = array( 'results' => $results );
				break;
			case '/reconcile':
				$answer = array(
					'missing' => array(),
					'stale'   => array(),
					'deleted' => array(),
				);
				break;
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $answer ),
			'headers'  => array(),
		);
	},
	1,
	3
);

/**
 * Run the sync worker until the full sync is over, as the scheduler would.
 */
function smoke_run_sync_to_the_end() {
	for ( $i = 0; $i < 60; $i++ ) {
		SAMOMIND()->sync->run();
		$progress = SAMOMIND()->sync->get_progress();
		if ( in_array( $progress['state'], array( 'complete', 'stopped', 'failed' ), true ) ) {
			break;
		}
	}
	return SAMOMIND()->sync->get_progress();
}

$GLOBALS['smoke_failed'] = false;

function smoke_check( $label, $condition ) {
	echo ( $condition ? 'ok    ' : 'FAIL  ' ) . $label . "\n";
	if ( ! $condition ) {
		$GLOBALS['smoke_failed'] = true;
	}
}

/**
 * A signed order status ping, as Samomind sends it. Returns the HTTP status.
 */
function smoke_signed_ping( $secret = SMOKE_ORDERS_SECRET ) {
	$route     = '/samomind/v1/orders/ping';
	$raw       = '{}';
	$timestamp = (string) time();
	$nonce     = substr( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), 0, 22 );
	$path      = SAMOMIND_Orders_Auth::request_path( $route );

	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'X-SamoMind-Orders-Key-Id', SMOKE_KEY_ID );
	$request->set_header( 'X-SamoMind-Orders-Timestamp', $timestamp );
	$request->set_header( 'X-SamoMind-Orders-Nonce', $nonce );
	$request->set_header( 'X-SamoMind-Orders-Version', '1' );
	$request->set_header( 'X-SamoMind-Orders-Signature', SAMOMIND_Orders_Auth::sign( $secret, SAMOMIND_Orders_Auth::canonical( $timestamp, SMOKE_KEY_ID, $nonce, $path, $raw ) ) );
	$request->set_body( $raw );

	$GLOBALS['wp_rest_server'] = null;
	return rest_get_server()->dispatch( $request )->get_status();
}

/**
 * The same ping, signed with a key the site does not have.
 */
function smoke_signed_ping_with_wrong_key() {
	return smoke_signed_ping( 'smok_not_the_key_of_this_site' );
}
