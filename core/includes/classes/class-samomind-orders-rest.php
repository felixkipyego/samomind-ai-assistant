<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Rest
 *
 * The REST endpoints for order status (docs/ORDERS_CONTRACT.md):
 *
 * - Samomind to the shop, signed with the orders key: start, verify,
 *   account/redeem, list, lookup, end and ping.
 * - The shopper's browser to the shop, same origin: the account-proof
 *   endpoint and the admin-ajax nonce.
 *
 * Every answer to a signed request is itself signed. Failures use one
 * generic body each, so an answer never says whether an order exists.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Rest
 * @since       0.4.0
 */
class SAMOMIND_Orders_Rest {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'samomind/v1';

	/**
	 * Route prefix of every order endpoint.
	 *
	 * @var string
	 */
	const ROUTE_PREFIX = '/samomind/v1/orders/';

	/**
	 * Signed endpoints that must not answer faster for one input than for
	 * another: they all return after at least this many milliseconds.
	 *
	 * @var int
	 */
	public static $floor_ms = 500;

	/**
	 * Settings.
	 *
	 * @var SAMOMIND_Orders_Settings
	 */
	private $settings;

	/**
	 * Signature checks.
	 *
	 * @var SAMOMIND_Orders_Auth
	 */
	private $auth;

	/**
	 * Order reading.
	 *
	 * @var SAMOMIND_Orders_Lookup
	 */
	private $lookup;

	/**
	 * Nonce of the signed request being answered; null for an answer that is
	 * not signed (a failed signature, or the browser endpoints).
	 *
	 * @var string|null
	 */
	private $reply_nonce = null;

	/**
	 * Constructor.
	 *
	 * @since   0.4.0
	 * @param   SAMOMIND_Orders_Settings $settings  Settings.
	 */
	public function __construct( SAMOMIND_Orders_Settings $settings ) {
		$this->settings = $settings;
		$this->auth     = new SAMOMIND_Orders_Auth();
		$this->lookup   = new SAMOMIND_Orders_Lookup();
	}

	/**
	 * Register the hooks.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public function register_hooks() {
		add_action( 'init', array( 'SAMOMIND_Orders_State', 'maybe_install' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_private_headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'drop_cors_handler' ), 5, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_signed' ), 20, 4 );
		add_action( 'wp_ajax_samomind_nonce', array( $this, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_samomind_nonce', array( $this, 'ajax_nonce' ) );
		add_action( SAMOMIND_Orders_Mail::CRON_HOOK, array( $this, 'send_parked_mail' ) );
		add_filter( 'rocket_cache_reject_uri', array( $this, 'exclude_from_rocket' ) );
	}

	/**
	 * Register the routes. Signed routes check the signature in the handler:
	 * WordPress has no user for them, so a permission callback cannot.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public function register_routes() {
		foreach ( array( 'start', 'verify', 'account/redeem', 'list', 'lookup', 'end', 'ping' ) as $action ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/orders/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => function ( $request ) use ( $action ) {
						return $this->handle( $action, $request );
					},
					'permission_callback' => '__return_true', // The signature is checked in handle().
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/account-proof',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'account_proof' ),
				'permission_callback' => '__return_true', // Answers {loggedIn:false} itself; WordPress sets the user from cookie and nonce.
			)
		);
	}

	/**
	 * Whether a request belongs to the order endpoints.
	 *
	 * @since   0.4.0
	 * @param   WP_REST_Request $request  Request.
	 * @return  bool
	 */
	private static function is_orders_route( $request ) {
		return 0 === strpos( (string) $request->get_route(), self::ROUTE_PREFIX );
	}

	/**
	 * Keep every answer out of shared caches.
	 *
	 * @since   0.4.0
	 * @param   WP_HTTP_Response $result   Response.
	 * @param   WP_REST_Server   $server   Server.
	 * @param   WP_REST_Request  $request  Request.
	 * @return  WP_HTTP_Response
	 */
	public function add_private_headers( $result, $server, $request ) {
		if ( self::is_orders_route( $request ) ) {
			$result->header( 'Cache-Control', 'no-store, private' );
			$result->header( 'Vary', 'Cookie' );
		}
		return $result;
	}

	/**
	 * Add the order paths to WP Rocket's list of pages never cached.
	 *
	 * @since   0.4.0
	 * @param   array $uris  Paths, as regular expressions.
	 * @return  array
	 */
	public function exclude_from_rocket( $uris ) {
		$uris   = is_array( $uris ) ? $uris : array();
		$uris[] = '/wp-json/samomind/v1/orders/(.*)';
		return $uris;
	}

	/**
	 * Answers from the order endpoints carry no cross-origin headers: core's
	 * handler would allow any origin to read them with the visitor's cookie,
	 * so for these routes it is taken off before it runs (it is at priority 10).
	 *
	 * @since   0.4.0
	 * @param   bool             $served   Whether another filter served it.
	 * @param   WP_HTTP_Response $result   Response.
	 * @param   WP_REST_Request  $request  Request.
	 * @return  bool
	 */
	public function drop_cors_handler( $served, $result, $request ) {
		if ( self::is_orders_route( $request ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}
		return $served;
	}

	/**
	 * Send an answer to a signed request ourselves, so the signature covers
	 * the exact bytes.
	 *
	 * @since   0.4.0
	 * @param   bool             $served   Whether another filter served it.
	 * @param   WP_HTTP_Response $result   Response.
	 * @param   WP_REST_Request  $request  Request.
	 * @param   WP_REST_Server   $server   Server.
	 * @return  bool
	 */
	public function serve_signed( $served, $result, $request, $server ) {
		if ( $served || ! self::is_orders_route( $request ) ) {
			return $served;
		}

		if ( null === $this->reply_nonce ) {
			return $served;
		}

		$status = (int) $result->get_status();
		$body   = 204 === $status ? '' : (string) wp_json_encode( $result->get_data() );
		$server->send_header( 'X-SamoMind-Orders-Signature', SAMOMIND_Orders_Auth::sign_response( $this->settings->get_secret(), $this->reply_nonce, $status, $body ) );
		$this->reply_nonce = null;

		// This echo is a JSON body made by wp_json_encode() above, signed as it is.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
	}

	/**
	 * ######################
	 * ###
	 * #### SIGNED ENDPOINTS
	 * ###
	 * ######################
	 */

	/**
	 * Common entry for the signed endpoints.
	 *
	 * @since   0.4.0
	 * @param   string          $action   start, verify, account/redeem, list, lookup, end or ping.
	 * @param   WP_REST_Request $request  Request.
	 * @return  WP_REST_Response
	 */
	public function handle( $action, $request ) {
		$started = microtime( true );
		$secret  = $this->settings->get_secret();

		if ( ! $this->auth->verify( $request, $secret ) ) {
			$this->log_once( 'orders/' . $action, 401, 'invalid_signature' );
			return new WP_REST_Response(
				array(
					'code'        => 'invalid_signature',
					'server_time' => time(),
				),
				401
			);
		}
		$this->reply_nonce = (string) $request->get_header( 'X-SamoMind-Orders-Nonce' );

		if ( 'ping' === $action ) {
			$this->remember_proof_seconds( json_decode( (string) $request->get_body(), true ) );
			return $this->ping();
		}
		if ( ! $this->settings->is_enabled() ) {
			$this->log_once( 'orders/' . $action, 403, 'not_enabled' );
			return new WP_REST_Response( array( 'code' => 'not_enabled' ), 403 );
		}

		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'code' => 'bad_request' ), 400 );
		}
		$this->remember_proof_seconds( $data );
		$session_ref = isset( $data['session_ref'] ) && is_string( $data['session_ref'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $data['session_ref'] )
			? $data['session_ref']
			: '';
		if ( '' === $session_ref ) {
			return new WP_REST_Response( array( 'code' => 'bad_request' ), 400 );
		}

		$store = new SAMOMIND_Orders_Store( $secret );
		$limit = SAMOMIND_Orders_Auth::clamp_limits( isset( $data['limits'] ) ? $data['limits'] : null );

		switch ( $action ) {
			case 'start':
				$response = $this->start( $data, $session_ref, $limit, $store );
				$this->wait_for_floor( $started );
				return $response;
			case 'verify':
				$response = $this->verify( $data, $session_ref, $limit, $store );
				$this->wait_for_floor( $started );
				return $response;
			case 'account/redeem':
				$response = $this->redeem( $data, $session_ref, $limit, $store );
				$this->wait_for_floor( $started );
				return $response;
			case 'list':
				return $this->orders_list( $data, $session_ref, $store );
			case 'lookup':
				return $this->lookup( $data, $session_ref, $store );
			default:
				$store->end_session( $session_ref );
				return new WP_REST_Response( null, 204 );
		}
	}

	/**
	 * Answer to ping: the capabilities, for any validly signed request.
	 *
	 * @since   0.4.0
	 * @return  WP_REST_Response
	 */
	private function ping() {
		return new WP_REST_Response(
			array(
				'plugin_version' => SAMOMIND_VERSION,
				'api'            => SAMOMIND_Orders_Settings::API_VERSION,
				'capabilities'   => array( 'orders' => $this->settings->capabilities() ),
				'server_time'    => time(),
			),
			200
		);
	}

	/**
	 * Start: check the order and the billing email, and email a code when
	 * they match. The answer is the same whether or not they do.
	 *
	 * @since   0.4.0
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function start( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		if ( isset( $data['scope'] ) ) {
			return $this->scoped( 'start_email', $data, $session_ref, $limit, $store );
		}
		$order = $this->lookup->find_order( isset( $data['order_number'] ) ? $data['order_number'] : null );
		$typed = isset( $data['email'] ) && is_string( $data['email'] ) ? strtolower( trim( $data['email'] ) ) : '';

		// The same work on every path: a compare and a code, with a dummy address when no order exists.
		$billing = $order ? strtolower( trim( (string) $order->get_billing_email() ) ) : 'nobody@invalid.example';
		$matches = hash_equals( $billing, $typed ) && $order && is_email( $billing );
		$code    = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$hash    = $store->code_hash( $session_ref, $order ? $order->get_id() : 0, $code );

		// Whether the shop's own hourly cap is used up says nothing about this order, so it is
		// reported on every start alike, and the status stays 202.
		$capped = $store->shop_cap_reached( $limit['shop_emails_per_hour'] );

		// An order's own limit is told only to a caller who knew its billing address, so it cannot be used to find out which orders exist.
		$order_id = $matches ? (int) $order->get_id() : 0;
		if ( $matches && $store->order_cap_reached( $order_id ) ) {
			$capped = true;
		}

		if ( $matches && ! $capped && $store->allow_send( $session_ref, $limit['resend_gap_seconds'], $limit['sends_per_session_hour'] ) ) {
			// The order's count comes first, so the shop-wide count only grows for orders still inside their own limit.
			if ( ! $store->allow_order_email( $order_id ) ) {
				$capped = true;
			} elseif ( $store->allow_shop_email( $limit['shop_emails_per_hour'] ) ) {
				$store->put_code( $session_ref, $order_id, $hash, $limit['code_ttl_minutes'] * MINUTE_IN_SECONDS );
				( new SAMOMIND_Orders_Mail( $store ) )->dispatch( $order->get_billing_email(), $code );
			}
		}
		if ( $capped ) {
			$this->log_once( 'orders/start', 202, 'mail_capped' );
			return new WP_REST_Response(
				array(
					'accepted'    => true,
					'mail_capped' => true,
				),
				202
			);
		}

		return new WP_REST_Response( array( 'accepted' => true ), 202 );
	}

	/**
	 * Verify: check the code and, if right, grant lookups of that order for
	 * this chat session.
	 *
	 * @since   0.4.0
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function verify( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		if ( isset( $data['scope'] ) ) {
			return $this->scoped( 'verify_email', $data, $session_ref, $limit, $store );
		}
		$order    = $this->lookup->find_order( isset( $data['order_number'] ) ? $data['order_number'] : null );
		$order_id = $order ? (int) $order->get_id() : 0;
		$typed    = isset( $data['code'] ) && is_string( $data['code'] ) ? substr( $data['code'], 0, 16 ) : '';

		$right = $store->check_code( $session_ref, $order_id, $store->code_hash( $session_ref, $order_id, $typed ), $limit['code_tries'] );
		if ( ! $right || ! $order ) {
			return new WP_REST_Response( array( 'verified' => false ), 200 );
		}

		$ttl = $limit['grant_minutes'] * MINUTE_IN_SECONDS;
		$store->put_grant( $session_ref, $order_id, $ttl );
		return new WP_REST_Response(
			array(
				'verified'         => true,
				'grant_expires_in' => $ttl,
			),
			200
		);
	}

	/**
	 * Redeem the one-time record written for a logged-in browser. The order
	 * must belong to that user.
	 *
	 * @since   0.4.0
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function redeem( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		if ( isset( $data['scope'] ) ) {
			return $this->redeem_account( $data, $session_ref, $limit, $store );
		}
		$challenge = isset( $data['challenge'] ) && is_string( $data['challenge'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/', $data['challenge'] ) ? $data['challenge'] : '';
		$record    = '' !== $challenge ? $store->take_record( $challenge ) : null;
		$order     = $this->lookup->find_order( isset( $data['order_number'] ) ? $data['order_number'] : null );

		$granted = $record
			&& $order
			&& (int) $order->get_customer_id() > 0
			&& (int) $order->get_customer_id() === (int) $record['user_id']
			&& SAMOMIND_Orders_Store::login_session_exists( (int) $record['user_id'], (string) $record['verifier'] );
		if ( ! $granted ) {
			return new WP_REST_Response( array( 'granted' => false ), 200 );
		}

		$ttl = $limit['grant_minutes'] * MINUTE_IN_SECONDS;
		$store->put_grant(
			$session_ref,
			(int) $order->get_id(),
			$ttl,
			array(
				'user_id'  => (int) $record['user_id'],
				'verifier' => (string) $record['verifier'],
			)
		);
		if ( $this->settings->history_enabled() ) {
			// The same proof also lets list and lookup read every order of this customer.
			$store->put_customer_grant( $session_ref, (int) $record['user_id'], (string) $record['verifier'], $ttl );
		}
		return new WP_REST_Response(
			array(
				'granted'          => true,
				'grant_expires_in' => $ttl,
			),
			200
		);
	}

	/**
	 * Redeem for the whole account (scope "account", no order number): the
	 * same one-time record and login check as for one order, then a customer
	 * grant that lets list and lookup read that customer's orders. Anything
	 * else, including a shop that has not accepted the current terms, is
	 * granted false.
	 *
	 * @since   0.4.1
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function redeem_account( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		if ( 'account' !== $data['scope'] ) {
			return new WP_REST_Response( array( 'code' => 'bad_request' ), 400 );
		}
		$refused = new WP_REST_Response( array( 'granted' => false ), 200 );
		if ( ! $this->settings->history_enabled() ) {
			return $refused;
		}

		$challenge = isset( $data['challenge'] ) && is_string( $data['challenge'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/', $data['challenge'] ) ? $data['challenge'] : '';
		$record    = '' !== $challenge ? $store->take_record( $challenge ) : null;
		if ( ! $record || ! SAMOMIND_Orders_Store::login_session_exists( (int) $record['user_id'], (string) $record['verifier'] ) ) {
			return $refused;
		}

		$ttl = $limit['grant_minutes'] * MINUTE_IN_SECONDS;
		$store->put_customer_grant( $session_ref, (int) $record['user_id'], (string) $record['verifier'], $ttl );
		return new WP_REST_Response(
			array(
				'granted'          => true,
				'grant_expires_in' => $ttl,
			),
			200
		);
	}

	/**
	 * Lookup: the order's fields, only for a grant this shop made. No grant,
	 * an expired one, a logged-out account and an unknown order all answer
	 * the same.
	 *
	 * @since   0.4.0
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function lookup( array $data, $session_ref, SAMOMIND_Orders_Store $store ) {
		$order = $this->lookup->find_order( isset( $data['order_number'] ) ? $data['order_number'] : null );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'found' => false ), 200 );
		}

		// A grant for this one order, or, with order history on, the session's email or customer grant.
		$history = $this->settings->history_enabled();
		$covered = null !== $store->get_grant( $session_ref, (int) $order->get_id() )
			|| ( $history && $this->lookup->grant_covers( $order, $store->get_email_grant( $session_ref ), $store->get_customer_grant( $session_ref ) ) );
		if ( ! $covered ) {
			return new WP_REST_Response( array( 'found' => false ), 200 );
		}

		// The shop's own choices decide; a request can only ask for less.
		$share = isset( $data['share'] ) && is_array( $data['share'] ) ? $data['share'] : array();
		$items = $this->settings->shares_items() && ! empty( $share['items'] );
		$track = $this->settings->shares_tracking() && ! empty( $share['tracking'] );

		return new WP_REST_Response(
			array(
				'found' => true,
				'order' => $this->lookup->order_fields( $order, $items, $track, $history ),
			),
			200
		);
	}

	/**
	 * List: the orders of the address or customer this chat session proved,
	 * newest first. No grant, an expired one and order history switched off
	 * all answer the same.
	 *
	 * @since   0.4.1
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function orders_list( array $data, $session_ref, SAMOMIND_Orders_Store $store ) {
		$none = new WP_REST_Response( array( 'granted' => false ), 200 );
		if ( ! $this->settings->history_enabled() ) {
			return $none;
		}
		$email   = $store->get_email_grant( $session_ref );
		$user_id = $store->get_customer_grant( $session_ref );
		if ( '' === $email && $user_id < 1 ) {
			return $none;
		}

		$asked = isset( $data['limit'] ) && ( is_int( $data['limit'] ) || ( is_string( $data['limit'] ) && ctype_digit( $data['limit'] ) ) ) ? (int) $data['limit'] : 20;
		$limit = max( 1, min( SAMOMIND_Orders_Lookup::MAX_LIST, $asked ) );

		// One more than asked for, from each source, tells whether there are more.
		$found = array();
		$all   = array_merge(
			'' !== $email ? $this->lookup->orders_for_email( $email, $limit + 1 ) : array(),
			$user_id > 0 ? $this->lookup->orders_for_customer( $user_id, $limit + 1 ) : array()
		);
		foreach ( $all as $order ) {
			$found[ (int) $order->get_id() ] = $order;
		}
		usort(
			$found,
			static function ( $a, $b ) {
				$left  = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$right = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
				return $right !== $left ? $right <=> $left : (int) $b->get_id() <=> (int) $a->get_id();
			}
		);

		$more  = count( $found ) > $limit;
		$track = $this->settings->shares_tracking();
		$rows  = array();
		foreach ( array_slice( $found, 0, $limit ) as $order ) {
			$rows[] = $this->lookup->list_row( $order, $track );
		}

		return new WP_REST_Response(
			array(
				'granted' => true,
				'orders'  => $rows,
				'more'    => $more,
			),
			200
		);
	}

	/**
	 * Start and verify for an email address (order history). Only scope
	 * "email" exists, and only while the current order terms are accepted.
	 *
	 * @since   0.4.1
	 * @param   string                $method       start_email or verify_email.
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function scoped( $method, array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		if ( 'email' !== $data['scope'] ) {
			return new WP_REST_Response( array( 'code' => 'bad_request' ), 400 );
		}
		if ( ! $this->settings->history_enabled() ) {
			$this->log_once( 'orders/start', 403, 'history_off' );
			return new WP_REST_Response( array( 'code' => 'not_enabled' ), 403 );
		}
		return $this->$method( $data, $session_ref, $limit, $store );
	}

	/**
	 * Start for an address: when at least one order has it, email a code, as
	 * for one order. The answer is the same whether or not it has orders,
	 * and the same when the address is over its own hourly cap. Only the
	 * shop-wide cap shows, as it does for one order.
	 *
	 * @since   0.4.1
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function start_email( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		$typed = SAMOMIND_Orders_Store::normalise_email( isset( $data['email'] ) ? $data['email'] : null );
		$valid = '' !== $typed && false !== is_email( $typed );

		// The same work on every path: one search and one code, with a dummy address when the typed one is no address.
		$email   = $valid ? $typed : 'nobody@invalid.example';
		$matches = $this->lookup->email_has_orders( $email ) && $valid;
		$code    = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$hash    = $store->email_code_hash( $session_ref, $email, $code );

		$capped = $store->shop_cap_reached( $limit['shop_emails_per_hour'] );

		// An address's own cap and a refused resend stay silent: the answer must not tell which addresses have orders.
		if ( $matches && ! $capped && ! $store->email_cap_reached( $email ) && $store->allow_send( $session_ref, $limit['resend_gap_seconds'], $limit['sends_per_session_hour'] ) ) {
			if ( $store->allow_email_mail( $email ) && $store->allow_shop_email( $limit['shop_emails_per_hour'] ) ) {
				$store->put_email_code( $session_ref, $email, $hash, $limit['code_ttl_minutes'] * MINUTE_IN_SECONDS );
				( new SAMOMIND_Orders_Mail( $store ) )->dispatch( $email, $code );
			}
		}
		if ( $capped ) {
			$this->log_once( 'orders/start', 202, 'mail_capped' );
			return new WP_REST_Response(
				array(
					'accepted'    => true,
					'mail_capped' => true,
				),
				202
			);
		}

		return new WP_REST_Response( array( 'accepted' => true ), 202 );
	}

	/**
	 * Verify for an address: check the code and, if right, grant list and
	 * lookup of that address's orders for this chat session.
	 *
	 * @since   0.4.1
	 * @param   array                 $data         Decoded body.
	 * @param   string                $session_ref  Session reference.
	 * @param   array                 $limit        Clamped limits.
	 * @param   SAMOMIND_Orders_Store $store        Store.
	 * @return  WP_REST_Response
	 */
	private function verify_email( array $data, $session_ref, array $limit, SAMOMIND_Orders_Store $store ) {
		$email = SAMOMIND_Orders_Store::normalise_email( isset( $data['email'] ) ? $data['email'] : null );
		$typed = isset( $data['code'] ) && is_string( $data['code'] ) ? substr( $data['code'], 0, 16 ) : '';

		$right = $store->check_email_code( $session_ref, $email, $store->email_code_hash( $session_ref, $email, $typed ), $limit['code_tries'] );
		if ( ! $right ) {
			return new WP_REST_Response( array( 'verified' => false ), 200 );
		}

		$ttl = $limit['grant_minutes'] * MINUTE_IN_SECONDS;
		$store->put_email_grant( $session_ref, $email, $ttl );
		return new WP_REST_Response(
			array(
				'verified'         => true,
				'grant_expires_in' => $ttl,
			),
			200
		);
	}

	/**
	 * Keep account_proof_seconds from a signed request that carries it, so the
	 * browser endpoint, which gets no limits of its own, can use it.
	 *
	 * @since   0.4.0
	 * @param   mixed $data  Decoded body.
	 * @return  void
	 */
	private function remember_proof_seconds( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['limits']['account_proof_seconds'] ) ) {
			return;
		}
		$seconds = SAMOMIND_Orders_Auth::clamp_limits( $data['limits'] )['account_proof_seconds'];
		if ( $seconds !== $this->proof_seconds() ) {
			update_option( SAMOMIND_Orders_Store::PROOF_SECONDS_OPTION, $seconds, false );
		}
	}

	/**
	 * Life of an account proof record: the latest value Samomind sent, held to
	 * the ceiling, or 60 seconds.
	 *
	 * @since   0.4.0
	 * @return  int
	 */
	private function proof_seconds() {
		$limit = SAMOMIND_Orders_Auth::LIMITS['account_proof_seconds'];
		$saved = (int) get_option( SAMOMIND_Orders_Store::PROOF_SECONDS_OPTION, $limit[0] );
		return max( $limit[1], min( $limit[2], $saved ) );
	}

	/**
	 * Wait until the minimum response time has passed. The time is counted
	 * from the start of the request, so a slow path is not made slower.
	 *
	 * @since   0.4.0
	 * @param   float $started  microtime( true ) at the start.
	 * @return  void
	 */
	private function wait_for_floor( $started ) {
		$remaining = ( self::$floor_ms / 1000 ) - ( microtime( true ) - $started );
		if ( $remaining > 0 ) {
			usleep( (int) ( $remaining * 1000000 ) );
		}
	}

	/**
	 * Add a row to the plugin's log: the endpoint, a status and a generic
	 * code. Never the body, an email, a code or anything about an order.
	 * The same row is written at most once a minute, so a caller who
	 * cannot sign cannot fill the log.
	 *
	 * @since   0.4.0
	 * @param   string $endpoint  For example "orders/start".
	 * @param   int    $status    HTTP status.
	 * @param   string $code      Generic result code.
	 * @return  void
	 */
	private function log_once( $endpoint, $status, $code ) {
		$name = SAMOMIND_Orders_Store::PREFIX . 'log_' . substr( hash( 'sha256', $endpoint . $code ), 0, 16 );
		if ( false !== get_transient( $name ) ) {
			return;
		}
		set_transient( $name, '1', MINUTE_IN_SECONDS );
		SAMOMIND()->log->add( $endpoint, $status, $code );
	}

	/**
	 * Cron fallback: send a parked code email.
	 *
	 * @since   0.4.0
	 * @param   string $id  Id of the parked message.
	 * @return  void
	 */
	public function send_parked_mail( $id ) {
		$store = new SAMOMIND_Orders_Store( $this->settings->get_secret() );
		( new SAMOMIND_Orders_Mail( $store ) )->send_parked( $id );
	}

	/**
	 * ######################
	 * ###
	 * #### BROWSER ENDPOINTS (same origin)
	 * ###
	 * ######################
	 */

	/**
	 * Admin-ajax: a REST nonce, fetched when the shopper taps "Check my
	 * order", never put in a page. Never cached.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public function ajax_nonce() {
		$this->mark_private();
		if ( ! $this->settings->is_enabled() ) {
			wp_send_json( array( 'nonce' => null ), 404 );
		}
		wp_send_json( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Account proof (design section 5): for a logged-in browser, sign the
	 * challenge Samomind issued and write the shop's own one-time record.
	 * The answer names no user. A browser that is not logged in, or has no
	 * valid nonce, gets {loggedIn:false}.
	 *
	 * @since   0.4.0
	 * @param   WP_REST_Request $request  Request.
	 * @return  WP_REST_Response
	 */
	public function account_proof( $request ) {
		$this->mark_private();
		if ( ! $this->settings->is_enabled() ) {
			return new WP_REST_Response( array( 'code' => 'not_enabled' ), 404 );
		}

		$params    = $request->get_json_params();
		$challenge = is_array( $params ) && isset( $params['challenge'] ) && is_string( $params['challenge'] ) ? $params['challenge'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $challenge ) ) {
			return new WP_REST_Response( array( 'code' => 'bad_request' ), 400 );
		}

		$user_id  = (int) get_current_user_id();
		$verifier = SAMOMIND_Orders_Store::current_session_verifier();
		if ( $user_id < 1 || '' === $verifier ) {
			return new WP_REST_Response( array( 'loggedIn' => false ), 200 );
		}

		$ttl = $this->proof_seconds();
		$exp = time() + $ttl;
		( new SAMOMIND_Orders_Store( $this->settings->get_secret() ) )->put_record( $challenge, $user_id, $verifier, $ttl );

		return new WP_REST_Response(
			array(
				'loggedIn' => true,
				'proof'    => array(
					'v'         => 1,
					'challenge' => $challenge,
					'exp'       => $exp,
					'sig'       => SAMOMIND_Orders_Auth::sign_proof( $this->settings->get_secret(), $challenge, $exp ),
				),
			),
			200
		);
	}

	/**
	 * Tell caches not to store this response: headers, the constant that
	 * page-cache plugins read, and WordPress' own no-cache headers.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	private function mark_private() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins read.
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private' );
			header( 'Vary: Cookie' );
		}
	}
}
