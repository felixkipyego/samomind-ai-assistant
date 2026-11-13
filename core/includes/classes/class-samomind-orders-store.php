<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Store
 *
 * What the shop holds for a verification (design sections 3 and 5), all in
 * short-lived transients: the hashed code with its try counter, the grant
 * that lets a lookup read one order, the one-time account record, and the
 * counters for resends and emails. The plain code and the email address are
 * never stored here.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Store
 * @since       0.4.0
 */
class SAMOMIND_Orders_Store {

	/**
	 * Transient prefix of every record in this class; uninstall removes it.
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
	 * Transient name for a kind of record and what identifies it.
	 *
	 * @since   0.4.0
	 * @param   string   $kind   code, grant, index, sends, mail, record or hour.
	 * @param   string[] $parts  What identifies it.
	 * @return  string
	 */
	private static function name( $kind, array $parts ) {
		return self::PREFIX . $kind . '_' . substr( hash( 'sha256', implode( '|', $parts ) ), 0, 40 );
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
	 * Whether a new code may be created now, and note the send if so.
	 * A second code inside the gap, or a fourth in an hour, is refused.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $gap          Seconds between two codes, already clamped.
	 * @param   int    $per_hour     Codes an hour, already clamped.
	 * @return  bool
	 */
	public function allow_send( $session_ref, $gap, $per_hour ) {
		$name  = self::name( 'sends', array( $session_ref ) );
		$state = get_transient( $name );
		$now   = time();
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['first'], $state['last'] ) || $now - (int) $state['first'] >= HOUR_IN_SECONDS ) {
			$state = array(
				'count' => 0,
				'first' => $now,
				'last'  => 0,
			);
		}
		if ( $now - (int) $state['last'] < (int) $gap || (int) $state['count'] >= (int) $per_hour ) {
			return false;
		}
		$state['count'] = (int) $state['count'] + 1;
		$state['last']  = $now;
		return set_transient( $name, $state, HOUR_IN_SECONDS );
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
		return (int) get_transient( self::name( 'hour', array( gmdate( 'Y-m-d-H' ) ) ) ) >= (int) $cap;
	}

	/**
	 * Count a code email for the whole shop and say whether it is within the
	 * cap for this hour.
	 *
	 * @since   0.4.0
	 * @param   int $cap  Emails per hour, already clamped.
	 * @return  bool
	 */
	public function allow_shop_email( $cap ) {
		$name  = self::name( 'hour', array( gmdate( 'Y-m-d-H' ) ) );
		$count = (int) get_transient( $name );
		if ( $count >= (int) $cap ) {
			return false;
		}
		return set_transient( $name, $count + 1, HOUR_IN_SECONDS );
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
		set_transient(
			self::name( 'code', array( $session_ref, (string) (int) $order_id ) ),
			array(
				'hash'  => $hash,
				'tries' => 0,
				'exp'   => time() + (int) $ttl,
			),
			(int) $ttl
		);
		$this->index( $session_ref, $order_id, HOUR_IN_SECONDS );
	}

	/**
	 * Check a code. Every wrong try is counted and the code dies at the
	 * limit; a right one is spent at once, so it works once.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id, 0 when the order was not found.
	 * @param   string $candidate_hash  code_hash() of what was typed.
	 * @param   int    $max_tries    Wrong tries allowed.
	 * @return  bool
	 */
	public function check_code( $session_ref, $order_id, $candidate_hash, $max_tries ) {
		$name   = self::name( 'code', array( $session_ref, (string) (int) $order_id ) );
		$record = get_transient( $name );
		if ( ! is_array( $record ) || empty( $record['hash'] ) || (int) $record['exp'] < time() ) {
			delete_transient( $name );
			return false;
		}
		if ( hash_equals( (string) $record['hash'], $candidate_hash ) ) {
			delete_transient( $name );
			return true;
		}
		$record['tries'] = (int) $record['tries'] + 1;
		if ( $record['tries'] >= (int) $max_tries ) {
			delete_transient( $name );
		} else {
			set_transient( $name, $record, max( 1, (int) $record['exp'] - time() ) );
		}
		return false;
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
		set_transient(
			self::name( 'grant', array( $session_ref, (string) (int) $order_id ) ),
			array(
				'order'    => (int) $order_id,
				'user_id'  => isset( $account['user_id'] ) ? (int) $account['user_id'] : 0,
				'verifier' => isset( $account['verifier'] ) ? (string) $account['verifier'] : '',
				'exp'      => time() + (int) $ttl,
			),
			(int) $ttl
		);
		$this->index( $session_ref, $order_id, (int) $ttl );
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
		$name  = self::name( 'grant', array( $session_ref, (string) (int) $order_id ) );
		$grant = get_transient( $name );
		if ( ! is_array( $grant ) || ! isset( $grant['exp'] ) || (int) $grant['exp'] < time() ) {
			return null;
		}
		if ( (int) $grant['user_id'] > 0 && ! self::login_session_exists( (int) $grant['user_id'], (string) $grant['verifier'] ) ) {
			delete_transient( $name );
			return null;
		}
		return $grant;
	}

	/**
	 * Delete every code and grant of a session. Unknown sessions are fine.
	 * A one-time account record is keyed by its challenge, which the shop
	 * does not tie to a session until it is redeemed; it ends on its own
	 * within a minute.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @return  void
	 */
	public function end_session( $session_ref ) {
		$index = get_transient( self::name( 'index', array( $session_ref ) ) );
		if ( is_array( $index ) ) {
			foreach ( $index as $order_id ) {
				delete_transient( self::name( 'code', array( $session_ref, (string) (int) $order_id ) ) );
				delete_transient( self::name( 'grant', array( $session_ref, (string) (int) $order_id ) ) );
			}
		}
		delete_transient( self::name( 'index', array( $session_ref ) ) );
		delete_transient( self::name( 'sends', array( $session_ref ) ) );
	}

	/**
	 * Remember that a session has a code or a grant for an order, so that
	 * end_session() can find them.
	 *
	 * @since   0.4.0
	 * @param   string $session_ref  Session reference.
	 * @param   int    $order_id     Order id.
	 * @param   int    $ttl          Seconds to keep the index.
	 * @return  void
	 */
	private function index( $session_ref, $order_id, $ttl ) {
		$name  = self::name( 'index', array( $session_ref ) );
		$index = get_transient( $name );
		$index = is_array( $index ) ? $index : array();
		if ( ! in_array( (int) $order_id, $index, true ) ) {
			$index[] = (int) $order_id;
		}
		set_transient( $name, $index, max( (int) $ttl, HOUR_IN_SECONDS ) );
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
		set_transient(
			self::name( 'record', array( $challenge ) ),
			array(
				'user_id'  => (int) $user_id,
				'verifier' => (string) $verifier,
				'exp'      => time() + (int) $ttl,
			),
			(int) $ttl
		);
	}

	/**
	 * Take the record for a challenge. It is deleted on the first look, so
	 * it can be redeemed once, right or wrong.
	 *
	 * @since   0.4.0
	 * @param   string $challenge  The challenge.
	 * @return  array|null  The record when it exists and has not expired.
	 */
	public function take_record( $challenge ) {
		$name   = self::name( 'record', array( $challenge ) );
		$record = get_transient( $name );
		delete_transient( $name );
		if ( ! is_array( $record ) || (int) $record['exp'] < time() || (int) $record['user_id'] < 1 ) {
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
	 * Park a code email until the response has left (see SAMOMIND_Orders_Mail).
	 *
	 * @since   0.4.0
	 * @param   string $id       Random id.
	 * @param   array  $message  to and code.
	 * @return  void
	 */
	public function park_mail( $id, array $message ) {
		set_transient( self::name( 'mail', array( $id ) ), $message, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Take a parked code email; it can be sent once.
	 *
	 * @since   0.4.0
	 * @param   string $id  Random id.
	 * @return  array|null
	 */
	public function take_mail( $id ) {
		$name    = self::name( 'mail', array( $id ) );
		$message = get_transient( $name );
		delete_transient( $name );
		return is_array( $message ) ? $message : null;
	}
}
