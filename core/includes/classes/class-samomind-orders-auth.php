<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Auth
 *
 * Checks the signature on a request from Samomind and signs the answer
 * (design section 2). The label "samomind-orders-v1" is different from the
 * ingest channel's message, so a signature is never valid on both.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Auth
 * @since       0.4.0
 */
class SAMOMIND_Orders_Auth {

	/**
	 * First line of the signed request message.
	 *
	 * @var string
	 */
	const REQUEST_LABEL = 'samomind-orders-v1';

	/**
	 * First line of the signed response message.
	 *
	 * @var string
	 */
	const RESPONSE_LABEL = 'samomind-orders-v1-response';

	/**
	 * First line of the message signed inside an account proof.
	 *
	 * @var string
	 */
	const PROOF_LABEL = 'samomind-orders-v1-proof';

	/**
	 * Accepted clock difference in seconds, either way.
	 *
	 * @var int
	 */
	const WINDOW = 120;

	/**
	 * How long an accepted nonce is remembered: twice the window.
	 *
	 * @var int
	 */
	const NONCE_TTL = 300;

	/**
	 * Transient prefix for remembered nonces.
	 *
	 * @var string
	 */
	const NONCE_PREFIX = 'samomind_ord_n_';

	/**
	 * Limits Samomind sends: default, lowest and hard ceiling for each
	 * (design decision 18). The shop holds every value to its ceiling. The
	 * resend gap is never shorter than 30 seconds and a session never gets
	 * more than 10 code emails an hour.
	 *
	 * @var array
	 */
	const LIMITS = array(
		'code_ttl_minutes'       => array( 10, 1, 30 ),
		'code_tries'             => array( 3, 1, 10 ),
		'shop_emails_per_hour'   => array( 20, 1, 100 ),
		'grant_minutes'          => array( 30, 1, 120 ),
		'account_proof_seconds'  => array( 60, 5, 120 ),
		'resend_gap_seconds'     => array( 60, 30, 3600 ),
		'sends_per_session_hour' => array( 3, 1, 10 ),
	);

	/**
	 * The message that is signed.
	 *
	 * @since   0.4.0
	 * @param   string $timestamp  Unix seconds, as sent.
	 * @param   string $key_id     Key id, as sent.
	 * @param   string $nonce      Nonce, as sent.
	 * @param   string $path       The shop's own path.
	 * @param   string $body       Raw body bytes.
	 * @return  string
	 */
	public static function canonical( $timestamp, $key_id, $nonce, $path, $body ) {
		return self::REQUEST_LABEL . "\n" . $timestamp . "\n" . $key_id . "\n" . $nonce . "\nPOST " . $path . "\n" . $body;
	}

	/**
	 * "v1=" and the hex HMAC-SHA256 of a message.
	 *
	 * @since   0.4.0
	 * @param   string $secret   The orders key.
	 * @param   string $message  Message to sign.
	 * @return  string
	 */
	public static function sign( $secret, $message ) {
		return 'v1=' . hash_hmac( 'sha256', $message, $secret );
	}

	/**
	 * The signature header value for an answer.
	 *
	 * @since   0.4.0
	 * @param   string $secret  The orders key.
	 * @param   string $nonce   The request's nonce.
	 * @param   int    $status  HTTP status.
	 * @param   string $body    Exact body bytes.
	 * @return  string
	 */
	public static function sign_response( $secret, $nonce, $status, $body ) {
		return self::sign( $secret, self::RESPONSE_LABEL . "\n" . $nonce . "\n" . (int) $status . "\n" . $body );
	}

	/**
	 * The signature inside an account proof: bare hex HMAC-SHA256 over the
	 * label, the proof version, the challenge and the expiry. The design
	 * names the proof's fields but not this formula (a question for the
	 * backend, docs/ORDERS_CONTRACT.md).
	 *
	 * @since   0.4.0
	 * @param   string $secret     The orders key.
	 * @param   string $challenge  Challenge Samomind issued.
	 * @param   int    $exp        Expiry, Unix seconds.
	 * @return  string
	 */
	public static function sign_proof( $secret, $challenge, $exp ) {
		return hash_hmac( 'sha256', self::PROOF_LABEL . "\n1\n" . $challenge . "\n" . (int) $exp, $secret );
	}

	/**
	 * The path Samomind signs: the shop's own REST path, with a sub-directory
	 * install's folder in front.
	 *
	 * @since   0.4.0
	 * @param   string $route  Route such as "/samomind/v1/orders/start".
	 * @return  string
	 */
	public static function request_path( $route ) {
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		return rtrim( $home_path, '/' ) . '/' . rest_get_url_prefix() . $route;
	}

	/**
	 * Check method, headers, clock, signature and replay, in that order.
	 * The nonce is stored only after the signature has passed, so a stranger
	 * cannot fill the store, and a failure to store it refuses the request.
	 *
	 * @since   0.4.0
	 * @param   WP_REST_Request $request  The request.
	 * @param   string          $secret   The orders key; empty refuses.
	 * @return  bool
	 */
	public function verify( $request, $secret ) {
		if ( '' === $secret || 'POST' !== $request->get_method() ) {
			return false;
		}

		$timestamp = (string) $request->get_header( 'X-SamoMind-Orders-Timestamp' );
		$key_id    = (string) $request->get_header( 'X-SamoMind-Orders-Key-Id' );
		$nonce     = (string) $request->get_header( 'X-SamoMind-Orders-Nonce' );
		$given     = (string) $request->get_header( 'X-SamoMind-Orders-Signature' );

		if ( '1' !== (string) $request->get_header( 'X-SamoMind-Orders-Version' )
			|| 1 !== preg_match( '/^[0-9]{9,12}$/', $timestamp )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $nonce )
			|| 1 !== preg_match( '/^[A-Za-z0-9-]{1,64}$/', $key_id )
			|| 1 !== preg_match( '/^v1=[0-9a-f]{64}$/', $given )
		) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::WINDOW ) {
			return false;
		}

		$expected = self::sign(
			$secret,
			self::canonical( $timestamp, $key_id, $nonce, self::request_path( $request->get_route() ), (string) $request->get_body() )
		);
		if ( ! hash_equals( $expected, $given ) ) {
			return false;
		}

		return $this->remember_nonce( $nonce );
	}

	/**
	 * Store a nonce for five minutes. False when it was seen before or when
	 * it could not be stored and read back (fail closed).
	 *
	 * @since   0.4.0
	 * @param   string $nonce  The nonce.
	 * @return  bool
	 */
	private function remember_nonce( $nonce ) {
		$name = self::NONCE_PREFIX . hash( 'sha256', $nonce );
		if ( false !== get_transient( $name ) ) {
			return false;
		}
		if ( ! set_transient( $name, '1', self::NONCE_TTL ) ) {
			return false;
		}
		return false !== get_transient( $name );
	}

	/**
	 * Hold the limits Samomind sent to the ceilings in the plugin. A value
	 * that is missing, not a whole number or out of range falls back to the
	 * default or to the nearest bound.
	 *
	 * @since   0.4.0
	 * @param   mixed $raw  The "limits" object of a request.
	 * @return  array  Every limit, as an integer.
	 */
	public static function clamp_limits( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$result = array();
		foreach ( self::LIMITS as $name => $range ) {
			$value = isset( $raw[ $name ] ) && ( is_int( $raw[ $name ] ) || ( is_string( $raw[ $name ] ) && ctype_digit( $raw[ $name ] ) ) )
				? (int) $raw[ $name ]
				: $range[0];

			$result[ $name ] = max( $range[1], min( $range[2], $value ) );
		}
		return $result;
	}
}
