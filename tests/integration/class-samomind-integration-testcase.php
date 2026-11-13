<?php
/**
 * Base class for the integration tests: real WordPress, real WooCommerce,
 * HPOS on or off. Tests create their own orders and users and remove them.
 *
 * @package SAMOMIND
 */

use PHPUnit\Framework\TestCase;

require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * A session store other than WordPress' own.
 */
class SAMOMIND_Other_Session_Store extends WP_User_Meta_Session_Tokens {}

abstract class SAMOMIND_Integration_TestCase extends TestCase {

	const SECRET = 'smok_test_secret_for_the_integration_suite';

	/**
	 * Mail captured instead of sent.
	 *
	 * @var array[]
	 */
	protected $mails = array();

	/**
	 * Order ids to delete in tearDown.
	 *
	 * @var int[]
	 */
	private $order_ids = array();

	/**
	 * User ids to delete in tearDown.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		$this->reset_state();

		update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );
		update_option( SAMOMIND_Orders_Settings::SECRET, self::SECRET, false );
		update_option( SAMOMIND_Orders_Settings::SHARE_TRACKING, '1', false );
		update_option( SAMOMIND_Orders_Settings::SHARE_ITEMS, '1', false );
		SAMOMIND()->orders->accept_terms( 1 );
		SAMOMIND_Orders_Rest::$floor_ms = 0;

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'no_http' ), 10, 3 );
		// Mail goes out from a shutdown callback in production; the tests run those themselves.
		add_filter( 'SAMOMIND/orders/finish_function', array( $this, 'finish_function' ) );

		// A real front-end request would have a REST server; make sure routes are registered fresh.
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
	}

	protected function tearDown(): void {
		foreach ( $this->order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->user_ids as $id ) {
			wp_delete_user( $id );
		}
		$this->order_ids = array();
		$this->user_ids  = array();
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'no_http' ), 10 );
		remove_filter( 'SAMOMIND/orders/finish_function', array( $this, 'finish_function' ) );
		remove_all_filters( 'SAMOMIND/orders/finish_function' );
		remove_all_actions( 'shutdown', 50 );
		$this->reset_state();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		parent::tearDown();
	}

	/**
	 * Clear every transient and log row this plugin made.
	 */
	private function reset_state() {
		global $wpdb;
		// phpcs:ignore -- test clean-up of this plugin's transients.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_samomind\\_ord\\_%' OR option_name LIKE '\\_transient\\_timeout\\_samomind\\_ord\\_%'" );
		wp_cache_flush();
		delete_option( SAMOMIND_Log::OPTION );
	}

	public function capture_mail( $pre, $atts ) {
		// WooCommerce mails the admin about every order it saves; only our code emails count.
		if ( isset( $atts['message'] ) && false !== strpos( $atts['message'], 'Your code is ' ) ) {
			$this->mails[] = $atts;
		}
		return true;
	}

	public function no_http() {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '',
			'headers'  => array(),
		);
	}

	public function finish_function() {
		return array( $this, 'flush_marker' );
	}

	/**
	 * Events in the order they happened: "flush", "mail".
	 *
	 * @var string[]
	 */
	protected $events = array();

	public function flush_marker() {
		$this->events[] = 'flush';
	}

	/**
	 * Run the shutdown callbacks this plugin registered, as PHP would after the response.
	 */
	protected function run_plugin_shutdown() {
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
	 * A shop order.
	 *
	 * @param array $args billing_email, status, customer_id, items (name => qty), number_meta.
	 * @return WC_Order
	 */
	protected function make_order( array $args = array() ) {
		$args  = array_merge(
			array(
				'billing_email' => 'buyer@example.com',
				'status'        => 'processing',
				'customer_id'   => 0,
				'items'         => array( 'Blue mug' => 2 ),
			),
			$args
		);
		$order = new WC_Order();
		$order->set_billing_email( $args['billing_email'] );
		$order->set_billing_first_name( 'Private' );
		$order->set_billing_last_name( 'Person' );
		$order->set_billing_phone( '+254700000000' );
		$order->set_billing_address_1( '1 Secret Street' );
		$order->set_customer_note( 'Leave it with the neighbour' );
		$order->set_payment_method_title( 'Card ending 4242' );
		$order->set_customer_id( (int) $args['customer_id'] );
		foreach ( $args['items'] as $name => $qty ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $name );
			$item->set_quantity( $qty );
			$item->set_total( 10 * $qty );
			$order->add_item( $item );
		}
		$order->set_total( 99 );
		$order->set_status( $args['status'] );
		if ( isset( $args['number_meta'] ) ) {
			$order->update_meta_data( '_order_number', $args['number_meta'] );
		}
		$order->save();
		$this->order_ids[] = $order->get_id();
		return $order;
	}

	protected function make_user( $role = 'customer' ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'samomind_t_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'u' . wp_generate_password( 8, false ) . '@example.com',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $id );
		$this->user_ids[] = $id;
		return $id;
	}

	/**
	 * Log a user in the way a browser would: a session token and the cookie
	 * that holds it. Returns the raw session token.
	 */
	protected function log_in( $user_id ) {
		$token                       = WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in', $token );
		wp_set_current_user( $user_id );
		return $token;
	}

	protected function session_ref( $label = 'a' ) {
		return substr( hash( 'sha256', 'session-' . $label ), 0, 32 );
	}

	/**
	 * A request signed the way Samomind signs it.
	 *
	 * @param string $action   start, verify, account/redeem, lookup, end, ping.
	 * @param array  $body     JSON body.
	 * @param array  $override timestamp, nonce, secret, path, key_id, signature, version, raw_body.
	 * @return WP_REST_Request
	 */
	protected function signed( $action, array $body = array(), array $override = array() ) {
		$route     = '/samomind/v1/orders/' . $action;
		$raw       = isset( $override['raw_body'] ) ? $override['raw_body'] : wp_json_encode( $body );
		$timestamp = isset( $override['timestamp'] ) ? (string) $override['timestamp'] : (string) time();
		$nonce     = isset( $override['nonce'] ) ? $override['nonce'] : substr( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), 0, 22 );
		$key_id    = isset( $override['key_id'] ) ? $override['key_id'] : '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
		$secret    = isset( $override['secret'] ) ? $override['secret'] : self::SECRET;
		$path      = isset( $override['path'] ) ? $override['path'] : SAMOMIND_Orders_Auth::request_path( $route );

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-SamoMind-Orders-Key-Id', $key_id );
		$request->set_header( 'X-SamoMind-Orders-Timestamp', $timestamp );
		$request->set_header( 'X-SamoMind-Orders-Nonce', $nonce );
		$request->set_header( 'X-SamoMind-Orders-Version', isset( $override['version'] ) ? $override['version'] : '1' );
		$request->set_header(
			'X-SamoMind-Orders-Signature',
			isset( $override['signature'] ) ? $override['signature'] : SAMOMIND_Orders_Auth::sign( $secret, SAMOMIND_Orders_Auth::canonical( $timestamp, $key_id, $nonce, $path, $raw ) )
		);
		$request->set_body( $raw );
		return $request;
	}

	/**
	 * Dispatch a request the way the REST server does, including the
	 * response filters that add headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	protected function send( WP_REST_Request $request ) {
		$server   = rest_get_server();
		$response = rest_ensure_response( $server->dispatch( $request ) );
		// Only this plugin's response filter: core's others call header(), which a CLI run cannot.
		return ( new SAMOMIND_Orders_Rest( SAMOMIND()->orders ) )->add_private_headers( $response, $server, $request );
	}

	protected function call( $action, array $body = array(), array $override = array() ) {
		return $this->send( $this->signed( $action, $body, $override ) );
	}

	/**
	 * A browser call to the same-origin account-proof endpoint: cookie
	 * authentication and the nonce check run as in a real request.
	 *
	 * @param string      $challenge Challenge.
	 * @param string|null $nonce     X-WP-Nonce, or null to send none.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function proof( $challenge, $nonce ) {
		$request = new WP_REST_Request( 'POST', '/samomind/v1/orders/account-proof' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'challenge' => $challenge ) ) );

		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$errors                         = @rest_cookie_check_errors( null ); // phpcs:ignore -- it sends a header, which a CLI run cannot.
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}
		return $this->send( $request );
	}

	protected function challenge() {
		return substr( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), 0, 22 );
	}

	/**
	 * The code in the last captured email.
	 */
	protected function emailed_code() {
		$this->assertNotEmpty( $this->mails, 'No email was sent.' );
		$mail = end( $this->mails );
		$this->assertSame( 1, preg_match( '/Your code is ([0-9]{6})/', $mail['message'], $m ) );
		return $m[1];
	}

	/**
	 * Start a verification and return the emailed code.
	 */
	protected function start_and_get_code( WC_Order $order, $session = 'a', $email = null ) {
		$response = $this->call(
			'start',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref( $session ),
				'order_number' => (string) $order->get_order_number(),
				'email'        => null === $email ? $order->get_billing_email() : $email,
				'locale'       => 'en',
			)
		);
		$this->assertSame( 202, $response->get_status() );
		$this->run_plugin_shutdown();
		return $this->emailed_code();
	}

	protected function verify_body( WC_Order $order, $code, $session = 'a' ) {
		return array(
			'request_id'   => wp_generate_uuid4(),
			'session_ref'  => $this->session_ref( $session ),
			'order_number' => (string) $order->get_order_number(),
			'code'         => $code,
		);
	}

	protected function lookup_body( $order_number, $session = 'a', array $share = array(
		'items'    => true,
		'tracking' => true,
	) ) {
		return array(
			'request_id'   => wp_generate_uuid4(),
			'session_ref'  => $this->session_ref( $session ),
			'order_number' => (string) $order_number,
			'share'        => $share,
		);
	}
}
