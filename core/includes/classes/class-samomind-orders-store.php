<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Store
 *
 * What the shop holds for a verification (design sections 3 and 5): the
 * hashed code with its try counter, the grant that lets a lookup read one
 * order, the one-time account record, and the counters for resends and
 * emails. They live in SAMOMIND_Orders_State, whose statements are atomic.
 * The plain code and the email address are never stored here.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Store
 * @since       0.4.0
 */
class SAMOMIND_Orders_Store {

	/**
	 * Transient prefix of the parked emails; uninstall removes it.
	 *
	 * @var string
	 */
	const PREFIX = 'samomind_ord_';

	/**
	 * Option: account_proof_seconds from the latest signed request that
	 * carried one.
	 *
	 * @var string
	 */
	const PROOF_SECONDS_OPTION = 'samomind_orders_proof_seconds';

	/**
	 * Orders key, the HMAC key for stored codes.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Constructor.
	 *
	 * @since   0.4.0
	 * @param   string $secret  The orders key.
	 */
	public function __construct( $secret ) {
		$this->secret = (string) $secret;
	}

	/**
	 * The stored form of a code: it ties the code to the chat session and
	 * the order, so it is useless anywhere else.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Opaque session reference.
	 * @param   int    $order_id     Order id.
	 * @param   string $code         Six digits.
	 * @return  string
	 */
	public function code_hash( $session_ref, $order_id, $code ) {
		return hash_hmac( 'sha256', $session_ref . '|' . (int) $order_id . '|' . $code, $this->secret );
	}

	/**
	 * Most code emails for one order in an hour, whatever the session or
	 * address the request came from.
	 *
	 * @var int
	 */
	const ORDER_EMAILS_PER_HOUR = 3;

	/**
	 * Whether a new code may be created now, and note the send if so.
	 * A second code inside the gap, or one past the hourly limit, is refused.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $gap          Seconds between two codes, already clamped.
	 * @param   int    $per_hour     Codes an hour, already clamped.
	 * @return  bool
	 */
	public function allow_send( $session_ref, $gap, $per_hour ) {
		$key = SAMOMIND_Orders_State::key( array( $session_ref ) );
		if ( 1 !== SAMOMIND_Orders_State::claim( 'gap', $key, time() + (int) $gap, $session_ref ) ) {
			return false;
		}
		return SAMOMIND_Orders_State::bump( 'sends', $key, (int) $per_hour, time() + HOUR_IN_SECONDS, $session_ref );
	}

	/**
	 * Whether the shop has already sent its cap of code emails this hour.
	 * It only looks; it counts nothing.
	 *
	 * @since   0.4.0
	 * @param   int $cap  Emails per hour, already clamped.
	 * @return  bool
	 */
	public function shop_cap_reached( $cap ) {
		return SAMOMIND_Orders_State::count( 'hour', SAMOMIND_Orders_State::key( array( gmdate( 'Y-m-d-H' ) ) ) ) >= (int) $cap;
	}

	/**
	 * Count a code email for the whole shop and say whether it is within the
	 * cap for this hour. It is a guard against floods: it is only asked
	 * after the order's own count has let the email through, so one order
	 * adds at most ORDER_EMAILS_PER_HOUR to it.
	 *
	 * @since   0.4.0
	 * @param   int $cap  Emails per hour, already clamped.
	 * @return  bool
	 */
	public function allow_shop_email( $cap ) {
		return SAMOMIND_Orders_State::bump( 'hour', SAMOMIND_Orders_State::key( array( gmdate( 'Y-m-d-H' ) ) ), (int) $cap, time() + HOUR_IN_SECONDS );
	}

	/**
	 * Whether an order has used its code emails for this hour. Looks only.
	 * The key is the order's WooCommerce id, never the number as typed.
	 *
	 * @since   0.4.0
	 * @param   int $order_id  Resolved order id.
	 * @return  bool
	 */
	public function order_cap_reached( $order_id ) {
		return SAMOMIND_Orders_State::count( 'omail', SAMOMIND_Orders_State::key( array( (string) (int) $order_id ) ) ) >= self::ORDER_EMAILS_PER_HOUR;
	}

	/**
	 * Count a code email for one order and say whether it is within its
	 * hourly limit.
	 *
	 * @since   0.4.0
	 * @param   int $order_id  Resolved order id.
	 * @return  bool
	 */
	public function allow_order_email( $order_id ) {
		return SAMOMIND_Orders_State::bump( 'omail', SAMOMIND_Orders_State::key( array( (string) (int) $order_id ) ), self::ORDER_EMAILS_PER_HOUR, time() + HOUR_IN_SECONDS );
	}

	/**
	 * Store a code, replacing any earlier one for the same session and
	 * order (a resend invalidates the previous code).
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id.
	 * @param   string $hash         Output of code_hash().
	 * @param   int    $ttl          Seconds the code works.
	 * @return  void
	 */
	public function put_code( $session_ref, $order_id, $hash, $ttl ) {
		SAMOMIND_Orders_State::put( 'code', SAMOMIND_Orders_State::key( array( $session_ref, (string) (int) $order_id ) ), time() + (int) $ttl, array( 'hash' => $hash ), $session_ref );
	}

	/**
	 * Check a code. Every try is counted before it is compared, by a
	 * conditional UPDATE, so parallel guesses cannot get more than the
	 * allowed number; a right one is spent at once and works once.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id, 0 when the order was not found.
	 * @param   string $candidate_hash  code_hash() of what was typed.
	 * @param   int    $max_tries    Tries allowed.
	 * @return  bool
	 */
	public function check_code( $session_ref, $order_id, $candidate_hash, $max_tries ) {
		$row = SAMOMIND_Orders_State::get( 'code', SAMOMIND_Orders_State::key( array( $session_ref, (string) (int) $order_id ) ) );
		if ( null === $row || empty( $row['data']['hash'] ) ) {
			return false;
		}
		if ( ! SAMOMIND_Orders_State::try_once( $row['id'], $max_tries ) ) {
			return false;
		}
		return hash_equals( (string) $row['data']['hash'], $candidate_hash ) && SAMOMIND_Orders_State::spend( $row['id'] );
	}

	/**
	 * Create a grant that lets lookup read one order for one chat session.
	 * Its end is fixed now and never moves.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id.
	 * @param   int    $ttl          Seconds the grant lasts.
	 * @param   array  $account      For an account grant: user_id and verifier.
	 * @return  void
	 */
	public function put_grant( $session_ref, $order_id, $ttl, array $account = array() ) {
		SAMOMIND_Orders_State::put(
			'grant',
			SAMOMIND_Orders_State::key( array( $session_ref, (string) (int) $order_id ) ),
			time() + (int) $ttl,
			array(
				'order'    => (int) $order_id,
				'user_id'  => isset( $account['user_id'] ) ? (int) $account['user_id'] : 0,
				'verifier' => isset( $account['verifier'] ) ? (string) $account['verifier'] : '',
			),
			$session_ref
		);
	}

	/**
	 * The live grant for a session and order, or null. An account grant
	 * counts only while the WordPress login it came from still exists; when
	 * that is gone the grant is deleted.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id.
	 * @return  array|null
	 */
	public function get_grant( $session_ref, $order_id ) {
		$row = SAMOMIND_Orders_State::get( 'grant', SAMOMIND_Orders_State::key( array( $session_ref, (string) (int) $order_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$grant = $row['data'] + array(
			'order'    => (int) $order_id,
			'user_id'  => 0,
			'verifier' => '',
		);
		if ( (int) $grant['user_id'] > 0 && ! self::login_session_exists( (int) $grant['user_id'], (string) $grant['verifier'] ) ) {
			SAMOMIND_Orders_State::spend( $row['id'] );
			return null;
		}
		$grant['exp'] = $row['expires'];
		return $grant;
	}

	/**
	 * Delete every code, grant and send counter of a session. Unknown
	 * sessions are fine. A one-time account record is keyed by its
	 * challenge, which the shop does not tie to a session until it is
	 * redeemed; it ends on its own within a minute.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @return  void
	 */
	public function end_session( $session_ref ) {
		SAMOMIND_Orders_State::delete_session( $session_ref, array( 'code', 'grant', 'gap', 'sends' ) );
	}

	/**
	 * Store the one-time record written when an account proof is issued.
	 *
	 * @since   0.4.0
	 * @param   string $challenge  The challenge Samomind issued.
	 * @param   int    $user_id    The logged-in user.
	 * @param   string $verifier   Hash of the login session token.
	 * @param   int    $ttl        Seconds it lives.
	 * @return  void
	 */
	public function put_record( $challenge, $user_id, $verifier, $ttl ) {
		SAMOMIND_Orders_State::put(
			'record',
			SAMOMIND_Orders_State::key( array( $challenge ) ),
			time() + (int) $ttl,
			array(
				'user_id'  => (int) $user_id,
				'verifier' => (string) $verifier,
			)
		);
	}

	/**
	 * Take the record for a challenge. It is deleted on the first look and
	 * only the request whose DELETE changed the row gets it, so it can be
	 * redeemed once, right or wrong, even by two requests at the same time.
	 *
	 * @since   0.4.0
	 * @param   string $challenge  The challenge.
	 * @return  array|null  The record when it exists and has not expired.
	 */
	public function take_record( $challenge ) {
		$record = SAMOMIND_Orders_State::take( 'record', SAMOMIND_Orders_State::key( array( $challenge ) ) );
		if ( null === $record || (int) $record['user_id'] < 1 ) {
			return null;
		}
		return $record;
	}

	/**
	 * The stored form of the current login session: the hash WordPress
	 * itself uses to index it. Empty when this request has no session token
	 * or the site swaps the session store for one that cannot be checked.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public static function current_session_verifier() {
		if ( ! self::session_store_is_default() ) {
			return '';
		}
		$token = wp_get_session_token();
		return '' === $token ? '' : hash( 'sha256', $token );
	}

	/**
	 * Whether WordPress keeps login sessions in user meta, the only store
	 * this plugin can check without the raw token.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public static function session_store_is_default() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		return 'WP_User_Meta_Session_Tokens' === apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' );
	}

	/**
	 * Whether the login session with this hash still exists and has not
	 * expired. Logging out, a password change and "log out everywhere" all
	 * remove it.
	 *
	 * @since   0.4.0
	 * @param   int    $user_id   User id.
	 * @param   string $verifier  Output of current_session_verifier().
	 * @return  bool
	 */
	public static function login_session_exists( $user_id, $verifier ) {
		if ( '' === $verifier || ! self::session_store_is_default() ) {
			return false;
		}
		$sessions = get_user_meta( (int) $user_id, 'session_tokens', true );
		return is_array( $sessions ) && isset( $sessions[ $verifier ]['expiration'] ) && (int) $sessions[ $verifier ]['expiration'] > time();
	}

	/**
	 * Transient name of a parked email.
	 *
	 * @since   0.4.0
	 * @param   string $id  Random id.
	 * @return  string
	 */
	private static function mail_name( $id ) {
		return self::PREFIX . 'mail_' . substr( hash( 'sha256', (string) $id ), 0, 40 );
	}

	/**
	 * Park a code email until the response has left (see SAMOMIND_Orders_Mail).
	 *
	 * @since   0.4.0
	 * @param   string $id       Random id.
	 * @param   array  $message  to and code.
	 * @return  void
	 */
	public function park_mail( $id, array $message ) {
		set_transient( self::mail_name( $id ), $message, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Take a parked code email; it can be sent once.
	 *
	 * @since   0.4.0
	 * @param   string $id  Random id.
	 * @return  array|null
	 */
	public function take_mail( $id ) {
		$name    = self::mail_name( $id );
		$message = get_transient( $name );
		delete_transient( $name );
		return is_array( $message ) ? $message : null;
	}
}
