<?php
/**
 * Request signing and error handling, against docs/API_CONTRACT.md 5.2
 * and 5.4.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * Client with a pinned clock.
 */
class SAMOMIND_Fixed_Clock_Client extends SAMOMIND_Client {

	public $time = 1793620800;

	protected function now() {
		return $this->time;
	}
}

class ClientTest extends SAMOMIND_TestCase {

	// The contract's pinned worked example (5.2). Do not edit.
	const SECRET    = 'smik_EXAMPLE-ONLY-not-a-real-secret-0000000000';
	const KEY_ID    = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
	const TIMESTAMP = '1793620800';
	const IDEM      = 'checkin-2026-11-02-0001';
	const PATH      = '/api/v1/ingest/checkin';
	const BODY      = '{"source_id":"8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f"}';
	const SIGNATURE = 'v1=664538142402a7c58c0b3dba80295e15ecf0b3b6c49727cf290d97044b6d0fe8';

	/**
	 * Responses handed out by the wp_remote_post stub, in order.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Requests the stub received.
	 *
	 * @var array
	 */
	private $requests = array();

	protected function setUp(): void {
		parent::setUp();
		$this->responses = array();
		$this->requests  = array();

		$test = $this;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $test ) {
				return $test->record_request( $url, $args );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $name ) {
				return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
			}
		);
	}

	public function record_request( $url, $args ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array_shift( $this->responses );
	}

	private function queue_response( $status, $body, $headers = array() ) {
		$this->responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	private function queue_error( $status, $code, $message = 'msg' ) {
		$this->queue_response( $status, json_encode( array( 'detail' => array( 'code' => $code, 'message' => $message ) ) ) );
	}

	private function client() {
		return new SAMOMIND_Fixed_Clock_Client( 'https://api.samomind.com', self::KEY_ID, self::SECRET );
	}

	private function vector_canonical() {
		return SAMOMIND_Client::canonical_string( self::TIMESTAMP, self::KEY_ID, self::IDEM, 'POST', self::PATH, self::BODY );
	}

	public function test_signature_reproduces_the_contract_worked_example() {
		$this->assertSame( self::SIGNATURE, SAMOMIND_Client::sign( self::SECRET, $this->vector_canonical() ) );
	}

	public function test_canonical_string_is_exactly_the_documented_layout() {
		$expected = "1793620800\n3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10\ncheckin-2026-11-02-0001\nPOST /api/v1/ingest/checkin\n"
			. '{"source_id":"8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f"}';
		$this->assertSame( $expected, $this->vector_canonical() );
	}

	/**
	 * Every component and the separator take part in the signature.
	 *
	 * @dataProvider mutations
	 */
	public function test_changing_any_component_changes_the_signature( $timestamp, $key_id, $idem, $method, $path, $body ) {
		$canonical = SAMOMIND_Client::canonical_string( $timestamp, $key_id, $idem, $method, $path, $body );
		$this->assertNotSame( self::SIGNATURE, SAMOMIND_Client::sign( self::SECRET, $canonical ) );
	}

	public function mutations() {
		return array(
			'timestamp'      => array( '1793620801', self::KEY_ID, self::IDEM, 'POST', self::PATH, self::BODY ),
			'key id'         => array( self::TIMESTAMP, '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f11', self::IDEM, 'POST', self::PATH, self::BODY ),
			'idempotency'    => array( self::TIMESTAMP, self::KEY_ID, 'checkin-2026-11-02-0002', 'POST', self::PATH, self::BODY ),
			'method'         => array( self::TIMESTAMP, self::KEY_ID, self::IDEM, 'GET', self::PATH, self::BODY ),
			'path'           => array( self::TIMESTAMP, self::KEY_ID, self::IDEM, 'POST', '/api/v1/ingest/disconnect', self::BODY ),
			'trailing slash' => array( self::TIMESTAMP, self::KEY_ID, self::IDEM, 'POST', self::PATH . '/', self::BODY ),
			'body'           => array( self::TIMESTAMP, self::KEY_ID, self::IDEM, 'POST', self::PATH, self::BODY . ' ' ),
		);
	}

	public function test_changing_the_separator_changes_the_signature() {
		$this->assertNotSame( self::SIGNATURE, SAMOMIND_Client::sign( self::SECRET, str_replace( "\n", "\r\n", $this->vector_canonical() ) ) );
		$this->assertNotSame( self::SIGNATURE, SAMOMIND_Client::sign( self::SECRET, self::TIMESTAMP . "\n" . self::KEY_ID . "\n" . self::IDEM . "\nPOST\n" . self::PATH . "\n" . self::BODY ) );
	}

	public function test_wrong_secret_changes_the_signature() {
		$this->assertNotSame( self::SIGNATURE, SAMOMIND_Client::sign( self::SECRET . 'x', $this->vector_canonical() ) );
	}

	public function test_request_sends_the_contract_headers_and_the_signed_bytes() {
		$this->queue_response( 200, '{"status":"active","checkin_interval_seconds":86400,"supported_api_versions":["1"]}' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( 'active', $result['status'] );
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://api.samomind.com/api/v1/ingest/checkin', $request['url'] );
		$this->assertSame( self::BODY, $request['args']['body'] );
		$this->assertSame( 30, $request['args']['timeout'] );
		$this->assertSame(
			array(
				'X-SamoMind-API-Version' => '1',
				'X-SamoMind-Key-Id'      => self::KEY_ID,
				'X-SamoMind-Timestamp'   => self::TIMESTAMP,
				'Idempotency-Key'        => self::IDEM,
				'X-SamoMind-Signature'   => self::SIGNATURE,
				'Content-Type'           => 'application/json',
			),
			$request['args']['headers']
		);
	}

	public function test_secret_is_never_sent() {
		$this->queue_response( 200, '{}' );
		$this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertStringNotContainsString( self::SECRET, json_encode( $this->requests ) );
	}

	public function test_base_url_path_is_part_of_the_signed_path() {
		$client = new SAMOMIND_Fixed_Clock_Client( 'https://staging.example.com/samomind/', self::KEY_ID, self::SECRET );
		$this->assertSame( '/samomind/api/v1/ingest/checkin', $client->request_path( '/checkin' ) );
	}

	public function test_invalid_signature_is_retried_once_then_blocks() {
		$this->queue_error( 401, 'invalid_signature', 'Bad signature.' );
		$this->queue_error( 401, 'invalid_signature', 'Bad signature.' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( self::IDEM, $this->requests[1]['args']['headers']['Idempotency-Key'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
		$this->assertSame( 'Bad signature.', $result->get_error_message() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 'block', SAMOMIND_Client::action_for( $result ) );
	}

	public function test_replay_is_reported_as_already_applied_and_not_retried() {
		$this->queue_error( 409, 'duplicate_request', 'Already applied.' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'duplicate_request', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 'applied', SAMOMIND_Client::action_for( $result ) );
	}

	public function test_server_error_is_retryable() {
		$this->queue_response( 503, '<html>Service Unavailable</html>' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( 'http_503', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( 'retry', SAMOMIND_Client::action_for( $result ) );
	}

	public function test_client_error_is_not_retryable() {
		$this->queue_error( 400, 'invalid_body', 'Field required.' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'invalid_body', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 'stop', SAMOMIND_Client::action_for( $result ) );
	}

	public function test_rate_limit_is_retryable_with_retry_after() {
		$this->queue_response( 429, json_encode( array( 'detail' => array( 'code' => 'rate_limited', 'message' => 'Slow down.' ) ) ), array( 'retry-after' => '17' ) );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 17, $result->get_error_data()['retry_after'] );
		$this->assertSame( 'retry', SAMOMIND_Client::action_for( $result ) );
	}

	public function test_network_failure_is_retryable() {
		$this->responses[] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$result = $this->client()->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( 'network_error', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data()['status'] );
		$this->assertSame( 'retry', SAMOMIND_Client::action_for( $result ) );
	}

	/**
	 * @dataProvider error_actions
	 */
	public function test_error_codes_map_to_contract_actions( $status, $code, $action ) {
		$this->queue_error( $status, $code );
		$this->queue_error( $status, $code );

		$this->assertSame( $action, SAMOMIND_Client::action_for( $this->client()->send( '/documents', '{}', 'wp-abcdefgh' ) ) );
	}

	public function error_actions() {
		return array(
			array( 400, 'unsupported_api_version', 'block' ),
			array( 401, 'stale_timestamp', 'block' ),
			array( 401, 'invalid_key', 'block' ),
			array( 401, 'key_revoked', 'block' ),
			array( 403, 'tenant_suspended', 'block' ),
			array( 404, 'unknown_source', 'reconnect' ),
			array( 409, 'source_disconnected', 'reconnect' ),
			array( 409, 'source_disabled', 'block' ),
			array( 409, 'connect_conflict', 'retry' ),
			array( 413, 'body_too_large', 'split' ),
			array( 400, 'too_many_documents', 'stop' ),
		);
	}

	public function test_unconfigured_client_sends_nothing() {
		$client = new SAMOMIND_Client( 'https://api.samomind.com', self::KEY_ID, '' );

		$result = $client->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( 'not_configured', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_every_attempt_is_logged_without_bodies() {
		$rows   = array();
		$client = new SAMOMIND_Fixed_Clock_Client(
			'https://api.samomind.com',
			self::KEY_ID,
			self::SECRET,
			function ( $endpoint, $status, $code ) use ( &$rows ) {
				$rows[] = func_get_args();
			}
		);
		$this->queue_error( 401, 'invalid_signature' );
		$this->queue_response( 200, '{}' );

		$client->send( '/checkin', self::BODY, self::IDEM );

		$this->assertSame( array( array( '/checkin', 401, 'invalid_signature' ), array( '/checkin', 200, '' ) ), $rows );
	}
}
