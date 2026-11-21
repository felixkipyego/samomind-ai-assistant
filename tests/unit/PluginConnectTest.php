<?php
/**
 * Connect with a code: what is sent, what each answer says, what is saved,
 * and that nothing secret is logged. HTTP is stubbed; no server is called.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * Stand-in for SAMOMIND_Connection: records what the connect class asks.
 */
class SAMOMIND_Fake_Connection {

	public $state      = array();
	public $cleared    = 0;
	public $connects   = 0;
	public $forgotten  = 0;
	public $connect_to = array( 'source_id' => 'abc' );
	public $connected  = false;

	public function update_state( array $changes ) {
		$this->state = array_merge( $this->state, $changes );
	}

	public function get_state() {
		return $this->state;
	}

	public function is_connected() {
		return $this->connected;
	}

	public function clear_block() {
		++$this->cleared;
	}

	public function connect() {
		++$this->connects;
		return $this->connect_to;
	}

	public function forget() {
		++$this->forgotten;
	}
}

/**
 * Stand-in for SAMOMIND_Log.
 */
class SAMOMIND_Fake_Log {

	public $rows = array();

	public function add( $endpoint, $status, $code ) {
		$this->rows[] = array( $endpoint, $status, $code );
	}
}

class PluginConnectTest extends SAMOMIND_TestCase {

	const CODE     = 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	const SITE_KEY = 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA';
	const KEY_ID   = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
	const SECRET   = 'smik_Zk9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua_Gp8eTqWcAxyz';

	/**
	 * Options as stored: name => array( value, autoload ).
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Every wp_remote_post call: array( url, args ).
	 *
	 * @var array
	 */
	private $posts = array();

	/**
	 * What the next wp_remote_post returns.
	 *
	 * @var array|WP_Error
	 */
	private $reply;

	/**
	 * Everything error_log() would have received.
	 *
	 * @var string
	 */
	private $error_log_file;

	/**
	 * @var SAMOMIND_Fake_Connection
	 */
	private $connection;

	/**
	 * @var SAMOMIND_Fake_Log
	 */
	private $log;

	protected function setUp(): void {
		parent::setUp();

		$this->options    = array(
			'samomind_api_base_url' => array( 'https://api.samomind.com', false ),
			'samomind_orders_secret' => array( 'orders-key-untouched', false ),
		);
		$this->posts      = array();
		$this->reply      = self::answer( 200, $this->good_body() );
		$this->connection = new SAMOMIND_Fake_Connection();
		$this->log        = new SAMOMIND_Fake_Log();

		$this->error_log_file = tempnam( sys_get_temp_dir(), 'samomind-log' );
		ini_set( 'error_log', $this->error_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return isset( $this->options[ $name ] ) ? $this->options[ $name ][0] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( $url, $args );
				return $this->reply;
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

		$GLOBALS['samomind_test_plugin'] = (object) array(
			'helpers'        => new SAMOMIND_Helpers(),
			'connection'     => $this->connection,
			'log'            => $this->log,
			'plugin_connect' => new SAMOMIND_Plugin_Connect(),
		);
	}

	protected function tearDown(): void {
		if ( is_string( $this->error_log_file ) && file_exists( $this->error_log_file ) ) {
			unlink( $this->error_log_file );
		}
		parent::tearDown();
	}

	private static function json( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper, no WordPress here.
	}

	private static function answer( $status, $body, array $headers = array() ) {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => is_array( $body ) ? self::json( $body ) : $body,
			'headers'  => $headers,
		);
	}

	private function good_body( array $changes = array() ) {
		return array_merge(
			array(
				'site_key'         => self::SITE_KEY,
				'ingest_key_id'    => self::KEY_ID,
				'ingest_secret'    => self::SECRET,
				'api_base'         => 'https://api.samomind.com',
				'site_name'        => 'Shop Example',
				'home_url_allowed' => true,
			),
			$changes
		);
	}

	private function connect( $typed = self::CODE ) {
		return $GLOBALS['samomind_test_plugin']->plugin_connect->connect( $typed );
	}

	private function saved( $name ) {
		return isset( $this->options[ $name ] ) ? $this->options[ $name ][0] : null;
	}

	// ---- The code ----------------------------------------------------

	public function test_code_is_trimmed_and_the_part_after_the_prefix_is_upper_cased() {
		$lower = 'smc_' . strtolower( substr( self::CODE, 4 ) );

		$this->assertSame( self::CODE, SAMOMIND_Plugin_Connect::normalise_code( "  $lower \n" ) );
		$this->assertSame( self::CODE, SAMOMIND_Plugin_Connect::normalise_code( self::CODE ) );
	}

	/**
	 * @dataProvider bad_codes
	 */
	public function test_a_wrong_format_is_refused_before_anything_is_sent( $typed ) {
		$result = $this->connect( $typed );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bad_format', $result->get_error_code() );
		$this->assertSame( array(), $this->posts );
	}

	public function bad_codes() {
		return array(
			'empty'            => array( '' ),
			'wrong prefix'     => array( 'smk_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ),
			'prefix in caps'   => array( 'SMC_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ),
			'no prefix'        => array( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ),
			'too short'        => array( 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ23456' ),
			'too long'         => array( 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ2345678' ),
			'digit 1 not base32' => array( 'smc_1BCDEFGHIJKLMNOPQRSTUVWXYZ234567' ),
			'space inside'     => array( 'smc_ABCDEFGHIJKLMNOP QRSTUVWXYZ234567' ),
			'not a string'     => array( array( self::CODE ) ),
		);
	}

	// ---- What is sent ------------------------------------------------

	public function test_the_request_carries_the_contract_body_and_checks_certificates() {
		$this->connect( '  smc_' . strtolower( substr( self::CODE, 4 ) ) . '  ' );

		$this->assertCount( 1, $this->posts );
		list( $url, $args ) = $this->posts[0];
		$this->assertSame( 'https://api.samomind.com/api/v1/plugin/connect', $url );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 15, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame(
			array(
				'code'           => self::CODE,
				'home_url'       => 'https://shop.example.com',
				'site_url'       => 'https://shop.example.com',
				'plugin_version' => SAMOMIND_VERSION,
				'wp_version'     => '6.8.1',
			),
			json_decode( $args['body'], true )
		);
		$this->assertLessThan( 4096, strlen( $args['body'] ) );
	}

	public function test_a_non_https_api_address_is_refused_before_sending() {
		$this->options['samomind_api_base_url'] = array( 'http://api.samomind.com', false );

		$result = $this->connect();

		$this->assertSame( 'unsafe_host', $result->get_error_code() );
		$this->assertSame( array(), $this->posts );
		$this->assertSame( 0, $this->connection->connects );
	}

	/**
	 * @dataProvider loopback_hosts
	 */
	public function test_loopback_hosts_may_use_http( $base ) {
		$this->options['samomind_api_base_url'] = array( $base, false );
		$this->reply                            = self::answer( 200, $this->good_body( array( 'api_base' => $base ) ) );

		$result = $this->connect();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( $base . '/api/v1/plugin/connect', $this->posts[0][0] );
		$this->assertTrue( $this->posts[0][1]['sslverify'] );
	}

	public function loopback_hosts() {
		return array(
			'localhost' => array( 'http://localhost:8000' ),
			'127.0.0.1' => array( 'http://127.0.0.1:8000' ),
			'ipv6'      => array( 'http://[::1]:8000' ),
		);
	}

	/**
	 * @dataProvider unsafe_hosts
	 */
	public function test_other_http_hosts_are_not_loopback( $base ) {
		$this->options['samomind_api_base_url'] = array( $base, false );

		$this->assertSame( 'unsafe_host', $this->connect()->get_error_code() );
		$this->assertSame( array(), $this->posts );
	}

	public function unsafe_hosts() {
		return array(
			'lan address'        => array( 'http://192.168.1.5' ),
			'a .test host'       => array( 'http://samomind.test' ),
			'localhost lookalike' => array( 'http://localhost.evil.com' ),
			'ftp'                => array( 'ftp://api.samomind.com' ),
			'credentials'        => array( 'https://user:pass@api.samomind.com' ),
		);
	}

	public function test_a_secret_set_in_wp_config_is_not_replaced() {
		$GLOBALS['samomind_test_plugin']->helpers = new class() extends SAMOMIND_Helpers {
			public function secret_is_constant() {
				return true;
			}
		};

		$this->assertSame( 'secret_constant', $this->connect()->get_error_code() );
		$this->assertSame( array(), $this->posts );
	}

	// ---- What each answer says ---------------------------------------

	/**
	 * @dataProvider refusals
	 */
	public function test_every_refusal_gives_its_message_and_saves_nothing( $status, $error, $headers, $expected ) {
		$this->reply = self::answer( $status, array( 'error' => $error, 'message' => 'server text ' . self::SECRET ), $headers );

		$result = $this->connect();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_message() );
		$this->assertArrayNotHasKey( 'samomind_key_id', $this->options );
		$this->assertArrayNotHasKey( 'samomind_api_secret', $this->options );
		$this->assertSame( 0, $this->connection->connects );
	}

	public function refusals() {
		return array(
			'not available'   => array( 404, 'not_found', array(), "Connecting with a code isn't available for your account yet. Use Advanced: enter keys manually." ),
			'invalid code'    => array( 400, 'invalid_code', array(), "That code isn't right. Copy it again from your Samomind dashboard." ),
			'invalid request' => array( 400, 'invalid_request', array(), 'Something went wrong sending the code. Update the plugin and try again.' ),
			'used'            => array( 409, 'code_used', array(), 'That code has expired or was already used. Create a new one in your Samomind dashboard (Install > WordPress).' ),
			'expired'         => array( 410, 'code_expired', array(), 'That code has expired or was already used. Create a new one in your Samomind dashboard (Install > WordPress).' ),
			'rate limited'    => array( 429, 'rate_limited', array( 'retry-after' => '42' ), 'Too many tries. Wait 42 seconds and try again.' ),
			'rate no header'  => array( 429, 'rate_limited', array(), 'Too many tries. Wait 60 seconds and try again.' ),
			'unavailable'     => array( 503, 'unavailable', array(), "Samomind couldn't finish the connection. Nothing changed. Try again in a minute." ),
			'anything else'   => array( 500, 'boom', array(), 'Could not reach Samomind. Try again in a minute.' ),
			'a 400 we do not know' => array( 400, 'something_new', array(), 'Could not reach Samomind. Try again in a minute.' ),
		);
	}

	public function test_a_network_error_is_a_plain_could_not_reach_message() {
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 6: could not resolve host api.samomind.com' );

		$result = $this->connect();

		$this->assertSame( 'Could not reach Samomind. Check that this site can make outgoing requests, then try again.', $result->get_error_message() );
		$this->assertSame( array(), array_intersect_key( $this->options, array( 'samomind_key_id' => 1 ) ) );
	}

	public function test_an_answer_that_is_not_json_saves_nothing() {
		$this->reply = self::answer( 200, '<html>proxy page</html>' );

		$this->assertSame( 'bad_answer', $this->connect()->get_error_code() );
		$this->assertArrayNotHasKey( 'samomind_key_id', $this->options );
	}

	/**
	 * @dataProvider unusable_answers
	 */
	public function test_a_200_with_a_bad_field_saves_nothing( array $changes ) {
		$this->reply = self::answer( 200, $this->good_body( $changes ) );

		$result = $this->connect();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'https://api.samomind.com', $this->saved( 'samomind_api_base_url' ) );
		$this->assertArrayNotHasKey( 'samomind_key_id', $this->options );
		$this->assertArrayNotHasKey( 'samomind_api_secret', $this->options );
		$this->assertArrayNotHasKey( 'samomind_site_key', $this->options );
		$this->assertSame( array(), $this->connection->state );
		$this->assertSame( 0, $this->connection->connects );
	}

	public function unusable_answers() {
		return array(
			'http api_base'       => array( array( 'api_base' => 'http://api.samomind.com' ) ),
			'lan api_base'        => array( array( 'api_base' => 'http://192.168.0.9' ) ),
			'javascript api_base' => array( array( 'api_base' => 'javascript:alert(1)' ) ),
			'no api_base'         => array( array( 'api_base' => '' ) ),
			'bad site key'        => array( array( 'site_key' => '<script>' ) ),
			'bad key id'          => array( array( 'ingest_key_id' => 'not-a-uuid' ) ),
			'bad secret'          => array( array( 'ingest_secret' => 'nope' ) ),
			'secret not a string' => array( array( 'ingest_secret' => array( self::SECRET ) ) ),
		);
	}

	public function test_the_message_for_a_non_https_api_base_says_so() {
		$this->reply = self::answer( 200, $this->good_body( array( 'api_base' => 'http://evil.example.com' ) ) );

		$this->assertStringContainsString( 'not https', $this->connect()->get_error_message() );
	}

	// ---- A 200 ---------------------------------------------------------

	public function test_a_200_saves_the_keys_in_the_existing_options_and_starts_the_first_sync() {
		$this->reply = self::answer( 200, $this->good_body( array( 'api_base' => 'https://api2.samomind.com/' ) ) );

		$result = $this->connect();

		$this->assertSame(
			array(
				'site_name'        => 'Shop Example',
				'home_url_allowed' => true,
			),
			$result
		);
		$this->assertSame( self::KEY_ID, $this->saved( 'samomind_key_id' ) );
		$this->assertSame( self::SECRET, $this->saved( 'samomind_api_secret' ) );
		$this->assertSame( self::SITE_KEY, $this->saved( 'samomind_site_key' ) );
		$this->assertSame( 'https://api2.samomind.com', $this->saved( 'samomind_api_base_url' ) );
		$this->assertSame( 1, $this->connection->connects, 'the first connection and sync start once' );
		$this->assertSame( 1, $this->connection->cleared );
		$this->assertSame( 'Shop Example', $this->connection->state['code_connect']['site_name'] );
	}

	public function test_the_secret_is_not_autoloaded_and_the_orders_key_is_not_touched() {
		$this->connect();

		$this->assertFalse( $this->options['samomind_api_secret'][1] );
		$this->assertFalse( $this->options['samomind_key_id'][1] );
		$this->assertSame( array( 'orders-key-untouched', false ), $this->options['samomind_orders_secret'] );
		$this->assertArrayNotHasKey( 'samomind_orders_enabled', $this->options );
		$this->assertArrayNotHasKey( 'samomind_orders_terms', $this->options );
	}

	public function test_home_url_not_allowed_is_passed_on() {
		$this->reply = self::answer( 200, $this->good_body( array( 'home_url_allowed' => false ) ) );

		$this->assertFalse( $this->connect()['home_url_allowed'] );
		$this->assertFalse( $this->connection->state['code_connect']['home_url_allowed'] );
	}

	public function test_a_first_connection_that_fails_keeps_the_keys_and_says_what_to_do() {
		$this->connection->connect_to = new WP_Error( 'unauthorized', 'The key was refused.' );

		$result = $this->connect();

		$this->assertSame( 'sync_not_started', $result->get_error_code() );
		$this->assertStringContainsString( 'keys were saved', $result->get_error_message() );
		$this->assertSame( self::SECRET, $this->saved( 'samomind_api_secret' ), 'the secret was shown once, so it is kept' );
	}

	// ---- Nothing secret is logged ---------------------------------------

	public function test_the_log_and_error_log_never_hold_the_code_the_body_or_the_secret() {
		$this->connect();
		$this->reply = self::answer( 410, array( 'error' => 'code_expired', 'message' => 'for ' . self::CODE ) );
		$this->connect();
		$this->reply = new WP_Error( 'http_request_failed', 'failed for ' . self::CODE );
		$this->connect();

		$logged = self::json( $this->log->rows ) . (string) file_get_contents( $this->error_log_file );
		foreach ( array( self::CODE, self::SECRET, substr( self::CODE, 4 ), 'Shop Example' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $logged );
		}
		$this->assertSame(
			array(
				array( '/api/v1/plugin/connect', 200, '' ),
				array( '/api/v1/plugin/connect', 410, 'code_expired' ),
				array( '/api/v1/plugin/connect', 0, 'http_request_failed' ),
			),
			$this->log->rows
		);
	}

	public function test_nothing_is_kept_in_a_transient() {
		$stored = array();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) use ( &$stored ) {
				$stored[] = array( $name, $value );
				return true;
			}
		);

		$this->connect();

		$this->assertSame( array(), $stored );
	}

	// ---- Disconnect ---------------------------------------------------

	public function test_disconnect_clears_the_keys_here_and_sends_nothing() {
		$this->connect();
		$this->posts = array();

		$GLOBALS['samomind_test_plugin']->plugin_connect->disconnect();

		$this->assertSame( '', $this->saved( 'samomind_key_id' ) );
		$this->assertSame( '', $this->saved( 'samomind_api_secret' ) );
		$this->assertSame( '', $this->saved( 'samomind_site_key' ) );
		$this->assertSame( 1, $this->connection->forgotten );
		$this->assertSame( array(), $this->posts );
		$this->assertSame( 'orders-key-untouched', $this->saved( 'samomind_orders_secret' ) );
	}
}
