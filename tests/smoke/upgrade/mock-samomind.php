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

$GLOBALS['smoke_requests']  = array();

// The documents the plugin sent: external id => fields, in the order sent.
$GLOBALS['smoke_documents'] = array();

// Code emails are counted, never sent. WooCommerce's own mails about a new order are not ours and are not counted.
$GLOBALS['smoke_mails'] = array();
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) {
		if ( isset( $atts['message'] ) && false !== strpos( (string) $atts['message'], 'Your code is ' ) ) {
			$GLOBALS['smoke_mails'][] = (string) $atts['message'];
		}
		return true;
	},
	1,
	2
);

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
					$GLOBALS['smoke_documents'][ (string) $document['external_id'] ] = array(
						'kind'   => $document['kind'],
						'fields' => isset( $document['fields'] ) ? $document['fields'] : null,
					);
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

// Code emails go out from a shutdown callback in production; the update test runs those itself.
add_filter(
	'SAMOMIND/orders/finish_function',
	function () {
		return function () {};
	}
);

/**
 * Run the shutdown callbacks this plugin registered, as PHP would after the response.
 */
function smoke_run_plugin_shutdown() {
	if ( ! isset( $GLOBALS['wp_filter']['shutdown']->callbacks[50] ) ) {
		return;
	}
	$callbacks = $GLOBALS['wp_filter']['shutdown']->callbacks[50];
	remove_all_actions( 'shutdown', 50 );
	foreach ( $callbacks as $callback ) {
		call_user_func( $callback['function'] );
	}
}

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
	return smoke_signed( 'ping', array(), $secret )->get_status();
}

/**
 * Any signed order request, as Samomind sends it. Returns the response.
 */
function smoke_signed( $action, array $body = array(), $secret = SMOKE_ORDERS_SECRET ) {
	$route     = '/samomind/v1/orders/' . $action;
	$raw       = empty( $body ) ? '{}' : wp_json_encode( $body );
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
	return rest_get_server()->dispatch( $request );
}

/**
 * The same ping, signed with a key the site does not have.
 */
function smoke_signed_ping_with_wrong_key() {
	return smoke_signed_ping( 'smok_not_the_key_of_this_site' );
}
