<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Client
 *
 * The only class that talks to the SamoMind ingest API. Signs every
 * request as docs/API_CONTRACT.md section 5.2 specifies and turns error
 * responses into WP_Error objects.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Client
 * @since       0.1.0
 */
class SAMOMIND_Client {

	/**
	 * Pinned API version (contract 5.1 and 9).
	 *
	 * @var string
	 */
	const API_VERSION = '1';

	/**
	 * Path of the ingest endpoints, below the base URL.
	 *
	 * @var string
	 */
	const PATH_PREFIX = '/api/v1/ingest';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Codes that mean the site cannot do anything useful until an admin
	 * acts (contract 5.4). Signature and clock errors land here after the
	 * one retry the client already made.
	 *
	 * @var string[]
	 */
	const BLOCKING_CODES = array(
		'not_configured',
		'unsupported_api_version',
		'invalid_signature',
		'stale_timestamp',
		'invalid_key',
		'key_revoked',
		'tenant_suspended',
		'source_disabled',
	);

	/**
	 * API base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Ingest key id.
	 *
	 * @var string
	 */
	private $key_id;

	/**
	 * Ingest secret, used only as the HMAC key.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Called after every HTTP attempt with ( endpoint, status, code ).
	 *
	 * @var callable|null
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @since   0.1.0
	 * @param   string        $base_url  API base URL.
	 * @param   string        $key_id    Ingest key id.
	 * @param   string        $secret    Ingest secret.
	 * @param   callable|null $logger    Receives ( endpoint, status, code ).
	 */
	public function __construct( $base_url, $key_id, $secret, $logger = null ) {
		$this->base_url = rtrim( (string) $base_url, '/' );
		$this->key_id   = (string) $key_id;
		$this->secret   = (string) $secret;
		$this->logger   = $logger;
	}

	/**
	 * Build a client from the saved settings.
	 *
	 * @since   0.1.0
	 * @return  SAMOMIND_Client
	 */
	public static function from_settings() {
		$helpers = SAMOMIND()->helpers;
		return new self(
			$helpers->get_api_base_url(),
			$helpers->get_key_id(),
			$helpers->get_secret(),
			array( SAMOMIND()->log, 'add' )
		);
	}

	/**
	 * The contract 5.2 message: five lines joined by "\n", the fourth being
	 * METHOD + " " + path, the body last and unterminated.
	 *
	 * @since   0.1.0
	 * @param   string $timestamp        Unix seconds.
	 * @param   string $key_id           Key id.
	 * @param   string $idempotency_key  Idempotency-Key header value.
	 * @param   string $method           HTTP method.
	 * @param   string $path             Request path, no host or query.
	 * @param   string $body             Exact body bytes.
	 * @return  string
	 */
	public static function canonical_string( $timestamp, $key_id, $idempotency_key, $method, $path, $body ) {
		return $timestamp . "\n" . $key_id . "\n" . $idempotency_key . "\n" . $method . ' ' . $path . "\n" . $body;
	}

	/**
	 * Sign a canonical string.
	 *
	 * @since   0.1.0
	 * @param   string $secret     Ingest secret.
	 * @param   string $canonical  Output of canonical_string().
	 * @return  string  "v1=" followed by lowercase hex HMAC-SHA256.
	 */
	public static function sign( $secret, $canonical ) {
		return 'v1=' . hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Encode a payload exactly once; these bytes are both signed and sent.
	 *
	 * @since   0.1.0
	 * @param   array $payload  Request body.
	 * @return  string
	 */
	public static function encode( array $payload ) {
		return (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Idempotency key for background work, derived from the event so a
	 * re-run of the same work produces the same key (docs/ARCHITECTURE.md A4).
	 *
	 * @since   0.1.0
	 * @param   string   $scope  Short label, for example "push" or "sync".
	 * @param   string[] $parts  What identifies the event.
	 * @return  string  43 visible ASCII characters.
	 */
	public static function event_key( $scope, array $parts ) {
		return 'wp-' . substr( hash( 'sha256', $scope . "\n" . implode( "\n", $parts ) ), 0, 40 );
	}

	/**
	 * Idempotency key for a one-off user action.
	 *
	 * @since   0.1.0
	 * @param   string $scope  Short label, for example "connect".
	 * @return  string
	 */
	public static function fresh_key( $scope ) {
		return $scope . '-' . wp_generate_uuid4();
	}

	/**
	 * Whether a status should be retried later with the same key (5.7).
	 *
	 * @since   0.1.0
	 * @param   int $status  HTTP status, 0 for a network failure.
	 * @return  bool
	 */
	public static function is_retryable( $status ) {
		$status = (int) $status;
		return 0 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * What the caller should do about a failed request (contract 5.4).
	 *
	 * @since   0.1.0
	 * @param   WP_Error $error  Error returned by post().
	 * @return  string  retry, applied, detached, split, block or stop.
	 */
	public static function action_for( $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
			return 'retry';
		}
		if ( 'duplicate_request' === $code ) {
			return 'applied';
		}
		if ( 'connect_conflict' === $code ) {
			return 'retry';
		}
		// The dashboard wins: never reconnect on our own (Felix, 2026-11-03;
		// contract 5.4 says "call connect again").
		if ( 'unknown_source' === $code || 'source_disconnected' === $code ) {
			return 'detached';
		}
		if ( 'body_too_large' === $code ) {
			return 'split';
		}
		if ( in_array( $code, self::BLOCKING_CODES, true ) ) {
			return 'block';
		}
		return 'stop';
	}

	/**
	 * Whether the base URL, key id and secret are usable.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function is_configured() {
		return '' !== $this->key_id
			&& '' !== $this->secret
			&& 1 === preg_match( '#^https?://[^/?\#]+#i', $this->base_url );
	}

	/**
	 * Request path for an endpoint, as signed and sent.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint  For example "/checkin".
	 * @return  string
	 */
	public function request_path( $endpoint ) {
		$base_path = (string) wp_parse_url( $this->base_url, PHP_URL_PATH );
		return rtrim( $base_path, '/' ) . self::PATH_PREFIX . $endpoint;
	}

	/**
	 * POST a payload to an endpoint.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint         For example "/checkin".
	 * @param   array  $payload          Request body.
	 * @param   string $idempotency_key  8 to 128 visible ASCII characters.
	 * @return  array|WP_Error  Decoded response, or the error with data
	 *                          array( status, retryable, retry_after ).
	 */
	public function post( $endpoint, array $payload, $idempotency_key ) {
		return $this->send( $endpoint, self::encode( $payload ), $idempotency_key );
	}

	/**
	 * POST an already encoded body.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint         For example "/checkin".
	 * @param   string $body             JSON body.
	 * @param   string $idempotency_key  Idempotency key.
	 * @return  array|WP_Error
	 */
	public function send( $endpoint, $body, $idempotency_key ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Enter the API base URL, key id and secret first.', 'samomind-ai-assistant' ),
				array(
					'status'      => 0,
					'retryable'   => false,
					'retry_after' => 0,
				)
			);
		}

		$result = $this->send_once( $endpoint, $body, $idempotency_key );

		// Contract 5.4: a signature or clock refusal is retried once with a
		// fresh timestamp. The key was not spent (5.3), so it is reused.
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'invalid_signature', 'stale_timestamp' ), true ) ) {
			$result = $this->send_once( $endpoint, $body, $idempotency_key );
		}

		return $result;
	}

	/**
	 * Sign and send one attempt.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint         Endpoint.
	 * @param   string $body             JSON body.
	 * @param   string $idempotency_key  Idempotency key.
	 * @return  array|WP_Error
	 */
	private function send_once( $endpoint, $body, $idempotency_key ) {
		$timestamp = (string) $this->now();
		$path      = $this->request_path( $endpoint );
		$signature = self::sign( $this->secret, self::canonical_string( $timestamp, $this->key_id, $idempotency_key, 'POST', $path, $body ) );

		$response = wp_remote_post(
			$this->base_url . self::PATH_PREFIX . $endpoint,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'X-SamoMind-API-Version' => self::API_VERSION,
					'X-SamoMind-Key-Id'      => $this->key_id,
					'X-SamoMind-Timestamp'   => $timestamp,
					'Idempotency-Key'        => $idempotency_key,
					'X-SamoMind-Signature'   => $signature,
					'Content-Type'           => 'application/json',
				),
				'body'        => $body,
			)
		);

		return $this->parse_response( $endpoint, $response );
	}

	/**
	 * Turn a wp_remote_post() result into data or a WP_Error, and log it.
	 *
	 * @since   0.1.0
	 * @param   string         $endpoint  Endpoint, for the log.
	 * @param   array|WP_Error $response  HTTP API response.
	 * @return  array|WP_Error
	 */
	public function parse_response( $endpoint, $response ) {
		if ( is_wp_error( $response ) ) {
			$this->log( $endpoint, 0, 'network_error' );
			return new WP_Error(
				'network_error',
				$response->get_error_message(),
				array(
					'status'      => 0,
					'retryable'   => true,
					'retry_after' => 0,
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			$this->log( $endpoint, $status, '' );
			return is_array( $data ) ? $data : array();
		}

		$code    = 'http_' . $status;
		$message = sprintf(
			/* translators: %d: HTTP status code */
			__( 'Unexpected response from SamoMind (HTTP %d).', 'samomind-ai-assistant' ),
			$status
		);
		if ( isset( $data['detail']['code'] ) && is_string( $data['detail']['code'] ) ) {
			$code    = $data['detail']['code'];
			$message = isset( $data['detail']['message'] ) ? (string) $data['detail']['message'] : $code;
		}

		$this->log( $endpoint, $status, $code );

		return new WP_Error(
			$code,
			$message,
			array(
				'status'      => $status,
				'retryable'   => self::is_retryable( $status ),
				'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
			)
		);
	}

	/**
	 * Current Unix time. Separate so tests can pin it.
	 *
	 * @since   0.1.0
	 * @return  int
	 */
	protected function now() {
		return time();
	}

	/**
	 * Pass one request row to the logger. Never a body or a header.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint  Endpoint.
	 * @param   int    $status    HTTP status, 0 for network.
	 * @param   string $code      Error code, empty on success.
	 * @return  void
	 */
	private function log( $endpoint, $status, $code ) {
		if ( is_callable( $this->logger ) ) {
			call_user_func( $this->logger, $endpoint, $status, $code );
		}
	}
}
