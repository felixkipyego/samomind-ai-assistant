<?php
/**
 * Connect with a code on real WordPress: the options it writes, the page it
 * renders, who may press the buttons, and that no secret is left anywhere
 * but the one option. HTTP is mocked with pre_http_request; no server is called.
 *
 * @package SAMOMIND
 */

require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

class PluginConnectFlowTest extends SAMOMIND_Integration_TestCase {

	const CODE     = 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	const SITE_KEY = 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA';
	const KEY_ID   = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
	const NEW_SEC  = 'smik_Zk9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua_Gp8eTqWcAxyz';

	/**
	 * Requests seen by the mock: array( url, args ).
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * The mock's answer to the connect call.
	 *
	 * @var array|WP_Error
	 */
	private $reply;

	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();
		$this->reply    = $this->ok();
		foreach ( array( 'samomind_api_base_url', 'samomind_key_id', 'samomind_api_secret', 'samomind_site_key', 'samomind_connection' ) as $name ) {
			delete_option( $name );
		}
		update_option( 'samomind_api_base_url', 'https://api.samomind.com', false );
		// Filters chain, so this must run after the base class's blanket answer to win.
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 20, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 20 );
		foreach ( array( 'samomind_api_base_url', 'samomind_key_id', 'samomind_api_secret', 'samomind_site_key', 'samomind_connection', 'samomind_log' ) as $name ) {
			delete_option( $name );
		}
		SAMOMIND()->scheduler->unschedule_worker();
		$_POST = array();
		unset( $_REQUEST['_wpnonce'], $_GET['new_code'] );
		parent::tearDown();
	}

	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = array( $url, $args );
		if ( false !== strpos( $url, '/api/v1/plugin/connect' ) ) {
			return $this->reply;
		}
		if ( false !== strpos( $url, '/api/v1/ingest/connect' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'source_id' => '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f' ) ),
				'headers'  => array(),
			);
		}
		return $pre;
	}

	private function ok( array $changes = array() ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array_merge(
					array(
						'site_key'         => self::SITE_KEY,
						'ingest_key_id'    => self::KEY_ID,
						'ingest_secret'    => self::NEW_SEC,
						'api_base'         => 'https://api.samomind.com',
						'site_name'        => 'Shop Example',
						'home_url_allowed' => true,
					),
					$changes
				)
			),
			'headers'  => array(),
		);
	}

	private function connect_posts() {
		return array_values(
			array_filter(
				$this->requests,
				function ( $request ) {
					return false !== strpos( $request[0], '/plugin/connect' );
				}
			)
		);
	}

	private function settings_html() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		ob_start();
		( new SAMOMIND_Admin() )->render_settings_page();
		return (string) ob_get_clean();
	}

	private function autoload_of( $option ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore
	}

	// ---- 200 ----------------------------------------------------------

	public function test_a_200_saves_the_keys_in_the_options_manual_setup_uses_and_starts_the_sync() {
		update_option( SAMOMIND_Orders_Settings::SECRET, 'orders-key-before', false );

		$result = SAMOMIND()->plugin_connect->connect( ' ' . strtolower( self::CODE ) . ' ' );
		$this->assertIsArray( $result );

		$this->assertSame( self::KEY_ID, get_option( 'samomind_key_id' ) );
		$this->assertSame( self::NEW_SEC, SAMOMIND()->helpers->get_secret() );
		$this->assertSame( self::SITE_KEY, SAMOMIND()->helpers->get_site_key() );
		$this->assertSame( 'https://api.samomind.com', SAMOMIND()->helpers->get_api_base_url() );
		$this->assertTrue( SAMOMIND()->connection->is_connected() );
		$this->assertSame( 'running', SAMOMIND()->sync->get_progress()['state'], 'the first sync started' );
		$this->assertGreaterThan( 0, SAMOMIND()->scheduler->next_worker_run(), 'the worker is scheduled' );
		$this->assertSame( 'orders-key-before', get_option( SAMOMIND_Orders_Settings::SECRET ), 'the orders key is not touched' );
	}

	public function test_the_request_has_the_contract_body_and_checks_certificates() {
		SAMOMIND()->plugin_connect->connect( self::CODE );

		$posts = $this->connect_posts();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'https://api.samomind.com/api/v1/plugin/connect', $posts[0][0] );
		$this->assertTrue( $posts[0][1]['sslverify'] );
		$this->assertSame( 0, $posts[0][1]['redirection'] );
		$this->assertSame( 15, $posts[0][1]['timeout'] );
		$this->assertSame(
			array(
				'code'           => self::CODE,
				'home_url'       => home_url(),
				'site_url'       => site_url(),
				'plugin_version' => SAMOMIND_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
			),
			json_decode( $posts[0][1]['body'], true )
		);
	}

	public function test_the_secret_is_stored_once_and_never_autoloaded() {
		global $wpdb;
		SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertNotContains( $this->autoload_of( 'samomind_api_secret' ), array( 'yes', 'on', 'auto', 'auto-on' ) );
		$this->assertArrayNotHasKey( 'samomind_api_secret', wp_load_alloptions() );

		$holders = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::NEW_SEC ) . '%' ) ); // phpcs:ignore
		$this->assertSame( array( 'samomind_api_secret' ), $holders, 'no transient, log row or notice holds the secret' );

		$code_holders = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( substr( self::CODE, 4 ) ) . '%' ) ); // phpcs:ignore
		$this->assertSame( array(), $code_holders, 'the code is kept nowhere' );
	}

	public function test_a_200_with_an_http_api_base_is_refused_and_saves_nothing() {
		update_option( 'samomind_key_id', '11111111-2222-4333-8444-555555555555', false );
		update_option( 'samomind_api_secret', 'smik_manual_before', false );
		$this->reply = $this->ok( array( 'api_base' => 'http://api.samomind.com' ) );

		$result = SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '11111111-2222-4333-8444-555555555555', get_option( 'samomind_key_id' ) );
		$this->assertSame( 'smik_manual_before', get_option( 'samomind_api_secret' ) );
		$this->assertSame( 'https://api.samomind.com', get_option( 'samomind_api_base_url' ) );
		$this->assertSame( '', get_option( 'samomind_site_key', '' ) );
		$this->assertFalse( SAMOMIND()->connection->is_connected() );
	}

	// ---- Hosts ----------------------------------------------------------

	public function test_a_non_https_api_host_is_refused_before_anything_is_sent() {
		update_option( 'samomind_api_base_url', 'http://api.samomind.com', false );

		$result = SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertSame( 'unsafe_host', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_loopback_host_may_use_http() {
		update_option( 'samomind_api_base_url', 'http://127.0.0.1:8000', false );
		$this->reply = $this->ok( array( 'api_base' => 'http://127.0.0.1:8000' ) );

		$this->assertIsArray( SAMOMIND()->plugin_connect->connect( self::CODE ) );
		$this->assertSame( 'http://127.0.0.1:8000/api/v1/plugin/connect', $this->connect_posts()[0][0] );
		$this->assertSame( 'http://127.0.0.1:8000', get_option( 'samomind_api_base_url' ) );
	}

	public function test_the_settings_field_refuses_a_non_https_address() {
		$this->assertSame( 'https://api.samomind.com', ( new SAMOMIND_Admin() )->sanitize_api_base_url( 'http://api.samomind.com' ), 'the sanitiser kept the old value' );
	}

	// ---- Answers --------------------------------------------------------

	/**
	 * @dataProvider refusals
	 */
	public function test_every_answer_gives_its_message( $status, $error, $headers, $message ) {
		$this->reply = array(
			'response' => array( 'code' => $status ),
			'body'     => wp_json_encode(
				array(
					'error'   => $error,
					'message' => 'server text',
				)
			),
			'headers'  => $headers,
		);

		$result = SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( '', get_option( 'samomind_key_id', '' ) );
		$this->assertSame( '', get_option( 'samomind_api_secret', '' ) );
	}

	public function refusals() {
		return array(
			'not available' => array( 404, 'not_found', array(), "Connecting with a code isn't available for your account yet. Use Advanced: enter keys manually." ),
			'invalid code'  => array( 400, 'invalid_code', array(), "That code isn't right. Copy it again from your Samomind dashboard." ),
			'invalid req'   => array( 400, 'invalid_request', array(), 'Something went wrong sending the code. Update the plugin and try again.' ),
			'used'          => array( 409, 'code_used', array(), 'That code has expired or was already used. Create a new one in your Samomind dashboard (Install > WordPress).' ),
			'expired'       => array( 410, 'code_expired', array(), 'That code has expired or was already used. Create a new one in your Samomind dashboard (Install > WordPress).' ),
			'rate limited'  => array( 429, 'rate_limited', array( 'retry-after' => '30' ), 'Too many tries. Wait 30 seconds and try again.' ),
			'unavailable'   => array( 503, 'unavailable', array(), "Samomind couldn't finish the connection. Nothing changed. Try again in a minute." ),
			'other'         => array( 502, '', array(), 'Could not reach Samomind. Try again in a minute.' ),
		);
	}

	public function test_a_network_error_gives_the_plain_message() {
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 6 for ' . self::CODE );

		$result = SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertStringStartsWith( 'Could not reach Samomind.', $result->get_error_message() );
		$this->assertStringNotContainsString( self::CODE, $result->get_error_message() );
	}

	public function test_nothing_is_logged_with_a_secret() {
		SAMOMIND()->plugin_connect->connect( self::CODE );
		$this->reply = array(
			'response' => array( 'code' => 410 ),
			'body'     => wp_json_encode( array( 'error' => 'code_expired', 'message' => self::CODE ) ),
			'headers'  => array(),
		);
		SAMOMIND()->plugin_connect->connect( self::CODE );

		$log = wp_json_encode( SAMOMIND()->log->get_rows() );
		$this->assertStringNotContainsString( self::NEW_SEC, $log );
		$this->assertStringNotContainsString( substr( self::CODE, 4 ), $log );
		$this->assertStringContainsString( 'code_expired', $log );
	}

	// ---- The page ---------------------------------------------------------

	public function test_the_empty_box_asks_for_a_code_and_the_manual_fields_sit_under_a_fold() {
		$html = $this->settings_html();

		$this->assertStringContainsString( 'Connect to Samomind', $html );
		$this->assertStringContainsString( 'name="samomind_connect_code"', $html );
		$this->assertStringContainsString( 'value="samomind_plugin_connect"', $html );
		$this->assertMatchesRegularExpression( '#<details[^>]*>\s*<summary>Advanced: enter keys manually</summary>.*samomind_api_secret.*samomind_key_id.*samomind_api_base_url.*</details>#s', $html );
		$this->assertStringContainsString( 'name="samomind_post_types[offered][]"', $html, 'the rest of the settings form is still there' );
	}

	public function test_the_connected_box_names_the_site_and_never_prints_a_secret() {
		SAMOMIND()->plugin_connect->connect( self::CODE );

		$html = $this->settings_html();

		$this->assertStringContainsString( 'Connected to Shop Example', $html );
		$this->assertStringContainsString( 'value="samomind_plugin_disconnect"', $html );
		$this->assertStringContainsString( 'Use a new code', $html );
		$this->assertStringContainsString( 'To stop these keys working everywhere, create a new code in your Samomind dashboard.', $html );
		$this->assertStringNotContainsString( 'Allow next to your website address', $html );
		$this->assertStringNotContainsString( self::NEW_SEC, $html );
		$this->assertStringNotContainsString( self::CODE, $html );
		$this->assertStringContainsString( 'A secret is saved', $html );
	}

	public function test_a_new_code_field_shows_when_asked_for() {
		SAMOMIND()->plugin_connect->connect( self::CODE );
		$_GET['new_code'] = '1';

		$this->assertStringContainsString( 'name="samomind_connect_code"', $this->settings_html() );
	}

	public function test_the_box_says_when_the_website_address_still_needs_allowing() {
		$this->reply = $this->ok( array( 'home_url_allowed' => false ) );
		SAMOMIND()->plugin_connect->connect( self::CODE );

		$this->assertStringContainsString( 'Finish in your Samomind dashboard: click Allow next to your website address.', $this->settings_html() );
	}

	// ---- Buttons --------------------------------------------------------

	private function press( $action ) {
		$thrown = null;
		add_filter(
			'wp_redirect',
			function () {
				throw new RuntimeException( 'redirected' );
			}
		);
		add_filter(
			'wp_die_handler',
			function () {
				return function () {
					throw new RuntimeException( 'died' );
				};
			}
		);
		try {
			do_action( 'admin_post_samomind_' . $action );
		} catch ( RuntimeException $e ) {
			$thrown = $e->getMessage();
		}
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		return $thrown;
	}

	public function test_a_subscriber_cannot_connect() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		$_POST['samomind_connect_code']   = self::CODE;
		$_REQUEST['_wpnonce']             = wp_create_nonce( 'samomind_plugin_connect' );

		$this->assertSame( 'died', $this->press( 'plugin_connect' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_administrator_without_a_nonce_cannot_connect() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_POST['samomind_connect_code'] = self::CODE;
		unset( $_REQUEST['_wpnonce'] );

		$this->assertSame( 'died', $this->press( 'plugin_connect' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_nonce_for_another_action_does_not_work() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_POST['samomind_connect_code'] = self::CODE;
		$_REQUEST['_wpnonce']           = wp_create_nonce( 'samomind_connect' );

		$this->assertSame( 'died', $this->press( 'plugin_connect' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_administrator_with_a_nonce_connects() {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		$_POST['samomind_connect_code'] = self::CODE;
		$_REQUEST['_wpnonce']           = wp_create_nonce( 'samomind_plugin_connect' );

		$this->assertSame( 'redirected', $this->press( 'plugin_connect' ) );
		$this->assertSame( self::KEY_ID, get_option( 'samomind_key_id' ) );
		$notice = get_transient( SAMOMIND_Admin::NOTICE_TRANSIENT . $admin );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringNotContainsString( self::NEW_SEC, wp_json_encode( $notice ) );
		delete_transient( SAMOMIND_Admin::NOTICE_TRANSIENT . $admin );
	}

	public function test_disconnect_clears_the_keys_here_and_sends_nothing() {
		SAMOMIND()->plugin_connect->connect( self::CODE );
		$this->requests = array();
		$admin          = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'samomind_plugin_disconnect' );

		$this->assertSame( 'redirected', $this->press( 'plugin_disconnect' ) );

		$this->assertSame( '', get_option( 'samomind_key_id' ) );
		$this->assertSame( '', get_option( 'samomind_api_secret' ) );
		$this->assertFalse( SAMOMIND()->connection->is_connected() );
		$this->assertSame( array(), $this->requests );
		delete_transient( SAMOMIND_Admin::NOTICE_TRANSIENT . $admin );
	}

	// ---- Manual setup is unchanged ------------------------------------------

	public function test_manual_keys_still_connect_the_old_way() {
		update_option( 'samomind_key_id', self::KEY_ID );
		update_option( 'samomind_api_secret', 'smik_manual_key_for_the_test', false );

		$this->assertTrue( SAMOMIND()->helpers->has_credentials() );
		$result = SAMOMIND()->connection->connect();

		$this->assertIsArray( $result );
		$this->assertTrue( SAMOMIND()->connection->is_connected() );
		$this->assertSame( array(), $this->connect_posts(), 'no code was involved' );
		$this->assertStringContainsString( 'Connected to Samomind', $this->settings_html() );
	}
}
