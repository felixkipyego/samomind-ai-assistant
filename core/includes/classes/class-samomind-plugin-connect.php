<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Plugin_Connect
 *
 * "Connect with a code" (Samomind docs/api/plugin-connect.md). The owner
 * pastes a one-time code from the dashboard; the plugin trades it for the
 * site key, the ingest key id and a new ingest secret, saves them in the
 * options manual setup already uses, and starts the first sync.
 *
 * The code, the answer and the secret are never logged and never stored
 * anywhere but the options below. Only a status code and the API's "error"
 * field reach the request log.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Plugin_Connect
 * @since       0.4.0
 */
class SAMOMIND_Plugin_Connect {

	/**
	 * Path of the connect call, under the API base URL.
	 *
	 * @var string
	 */
	const PATH = '/api/v1/plugin/connect';

	/**
	 * Seconds to wait for the answer.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * A connection code: smc_ and 32 base32 characters.
	 *
	 * @var string
	 */
	const CODE_PATTERN = '/^smc_[A-Z2-7]{32}$/';

	/**
	 * The code as the owner typed it, cleaned for sending: spaces trimmed and
	 * the part after smc_ in capitals. Empty when it is not a code.
	 *
	 * @since   0.4.0
	 * @param   mixed $typed  Whatever was in the field.
	 * @return  string
	 */
	public static function normalise_code( $typed ) {
		if ( ! is_string( $typed ) ) {
			return '';
		}
		$typed = trim( $typed );
		if ( 0 !== strpos( $typed, 'smc_' ) ) {
			return '';
		}
		$code = 'smc_' . strtoupper( substr( $typed, 4 ) );
		return 1 === preg_match( self::CODE_PATTERN, $code ) ? $code : '';
	}

	/**
	 * Trade a code for keys, save them and start the first sync.
	 *
	 * @since   0.4.0
	 * @param   mixed $typed  The code as typed.
	 * @return  array|WP_Error  array( site_name, home_url_allowed ), or an error whose message is for the owner.
	 */
	public function connect( $typed ) {
		$code = self::normalise_code( $typed );
		if ( '' === $code ) {
			return new WP_Error( 'bad_format', __( 'That is not a connection code. It starts with smc_ and has 32 more letters and numbers. Copy it again from your Samomind dashboard.', 'samomind-ai-assistant' ) );
		}

		$helpers = SAMOMIND()->helpers;
		if ( $helpers->secret_is_constant() ) {
			return new WP_Error( 'secret_constant', __( 'The secret is set in wp-config.php (SAMOMIND_API_SECRET), so a code cannot replace it. Remove that line, or use Advanced: enter keys manually.', 'samomind-ai-assistant' ) );
		}

		// The code goes only to an https address (or a loopback one for local tests).
		$base = SAMOMIND_Helpers::clean_api_base_url( $helpers->get_api_base_url() );
		if ( '' === $base ) {
			return new WP_Error( 'unsafe_host', __( 'The API address on this site is not https, so the code was not sent. Set the API base URL to an https address under Advanced, then try again.', 'samomind-ai-assistant' ) );
		}

		$answer = $this->send( $base, $code );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$keys = $this->keys_from( $answer );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		$this->save( $keys );
		$info = array(
			'site_name'        => $keys['site_name'],
			'home_url_allowed' => $keys['home_url_allowed'],
		);
		SAMOMIND()->connection->update_state( array( 'code_connect' => $info ) );
		SAMOMIND()->connection->clear_block();

		// The same first connection and sync the Connect button on the Status page runs.
		$connected = SAMOMIND()->connection->connect();
		if ( is_wp_error( $connected ) ) {
			/* translators: %s: the reason, from Samomind */
			return new WP_Error( 'sync_not_started', sprintf( __( 'Your keys were saved, but the first connection did not finish: %s Click Connect on the Status page to try again.', 'samomind-ai-assistant' ), $connected->get_error_message() ) );
		}

		return $info;
	}

	/**
	 * Clear the keys on this site. Nothing is sent: Samomind is not told, and
	 * the secret keeps working anywhere else it was copied to.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public function disconnect() {
		update_option( 'samomind_key_id', '', false );
		update_option( 'samomind_api_secret', '', false );
		update_option( 'samomind_site_key', '' );
		SAMOMIND()->connection->forget();
	}

	/**
	 * Post the code. Certificates are always checked and redirects are not
	 * followed, so the code cannot be passed on to another address.
	 *
	 * @since   0.4.0
	 * @param   string $base  Clean API base URL.
	 * @param   string $code  Normalised code.
	 * @return  array|WP_Error  The decoded answer on 200.
	 */
	private function send( $base, $code ) {
		$response = wp_remote_post(
			$base . self::PATH,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'code'           => $code,
						'home_url'       => home_url(),
						'site_url'       => site_url(),
						'plugin_version' => SAMOMIND_VERSION,
						'wp_version'     => get_bloginfo( 'version' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// The error code only; its message can carry the address.
			SAMOMIND()->log->add( self::PATH, 0, sanitize_key( $response->get_error_code() ) );
			return new WP_Error( 'unreachable', __( 'Could not reach Samomind. Check that this site can make outgoing requests, then try again.', 'samomind-ai-assistant' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status ) {
			SAMOMIND()->log->add( self::PATH, 200, '' );
			return is_array( $data ) ? $data : new WP_Error( 'bad_answer', self::bad_answer_message() );
		}

		$error = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? substr( preg_replace( '/[^a-z_]/', '', strtolower( $data['error'] ) ), 0, 40 ) : '';
		SAMOMIND()->log->add( self::PATH, $status, $error );
		return new WP_Error( 'refused', $this->message_for( $status, $error, (string) wp_remote_retrieve_header( $response, 'retry-after' ) ) );
	}

	/**
	 * What to tell the owner for each refusal.
	 *
	 * @since   0.4.0
	 * @param   int    $status       HTTP status.
	 * @param   string $error        The "error" field, cleaned.
	 * @param   string $retry_after  The Retry-After header.
	 * @return  string
	 */
	private function message_for( $status, $error, $retry_after ) {
		if ( 404 === $status ) {
			return __( 'Connecting with a code isn\'t available for your account yet. Use Advanced: enter keys manually.', 'samomind-ai-assistant' );
		}
		if ( 400 === $status && 'invalid_code' === $error ) {
			return __( 'That code isn\'t right. Copy it again from your Samomind dashboard.', 'samomind-ai-assistant' );
		}
		if ( 400 === $status && 'invalid_request' === $error ) {
			return __( 'Something went wrong sending the code. Update the plugin and try again.', 'samomind-ai-assistant' );
		}
		if ( 409 === $status || 410 === $status ) {
			return __( 'That code has expired or was already used. Create a new one in your Samomind dashboard (Install > WordPress).', 'samomind-ai-assistant' );
		}
		if ( 429 === $status ) {
			$seconds = ctype_digit( $retry_after ) ? min( 3600, max( 1, (int) $retry_after ) ) : 60;
			/* translators: %d: seconds to wait */
			return sprintf( __( 'Too many tries. Wait %d seconds and try again.', 'samomind-ai-assistant' ), $seconds );
		}
		if ( 503 === $status ) {
			return __( 'Samomind couldn\'t finish the connection. Nothing changed. Try again in a minute.', 'samomind-ai-assistant' );
		}
		return __( 'Could not reach Samomind. Try again in a minute.', 'samomind-ai-assistant' );
	}

	/**
	 * The one message for an answer this plugin cannot use.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	private static function bad_answer_message() {
		return __( 'Samomind sent an answer this plugin cannot use, so nothing was saved. Create a new code in your Samomind dashboard and try again.', 'samomind-ai-assistant' );
	}

	/**
	 * Check every field of a 200 before anything is saved.
	 *
	 * @since   0.4.0
	 * @param   array $answer  Decoded answer.
	 * @return  array|WP_Error  Checked values.
	 */
	private function keys_from( array $answer ) {
		$base = isset( $answer['api_base'] ) ? SAMOMIND_Helpers::clean_api_base_url( $answer['api_base'] ) : '';
		if ( '' === $base ) {
			return new WP_Error( 'unsafe_api_base', __( 'Samomind returned an API address that is not https, so nothing was saved. Create a new code in your Samomind dashboard and try again.', 'samomind-ai-assistant' ) );
		}

		$site_key = isset( $answer['site_key'] ) ? $answer['site_key'] : '';
		$key_id   = isset( $answer['ingest_key_id'] ) && is_string( $answer['ingest_key_id'] ) ? strtolower( trim( $answer['ingest_key_id'] ) ) : '';
		$secret   = isset( $answer['ingest_secret'] ) && is_string( $answer['ingest_secret'] ) ? trim( $answer['ingest_secret'] ) : '';
		$valid    = SAMOMIND_Embed::is_valid_site_key( $site_key )
			&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key_id )
			&& 1 === preg_match( '/^smik_[A-Za-z0-9_-]+$/', $secret );
		if ( ! $valid ) {
			return new WP_Error( 'bad_answer', self::bad_answer_message() );
		}

		$name = isset( $answer['site_name'] ) && is_string( $answer['site_name'] ) ? sanitize_text_field( $answer['site_name'] ) : '';
		return array(
			'api_base'         => $base,
			'site_key'         => $site_key,
			'key_id'           => $key_id,
			'secret'           => $secret,
			'site_name'        => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100, 'UTF-8' ) : substr( $name, 0, 100 ),
			'home_url_allowed' => ! ( isset( $answer['home_url_allowed'] ) && false === $answer['home_url_allowed'] ),
		);
	}

	/**
	 * Save the keys in the options manual setup uses. The secret is kept out
	 * of autoload, so it is not read on every page load.
	 *
	 * @since   0.4.0
	 * @param   array $keys  Checked values from keys_from().
	 * @return  void
	 */
	private function save( array $keys ) {
		update_option( 'samomind_api_base_url', $keys['api_base'], false );
		update_option( 'samomind_key_id', $keys['key_id'], false );
		update_option( 'samomind_api_secret', $keys['secret'], false );
		update_option( 'samomind_site_key', $keys['site_key'] );
	}
}
