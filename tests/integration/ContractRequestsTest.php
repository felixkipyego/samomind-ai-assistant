<?php
/**
 * Samomind's side of the contract: the requests in
 * tests/contract/examples/samomind-to-plugin/ were built from Samomind's own
 * request models and signed with Samomind's own signing code (see
 * tests/contract/make_requests.py). The plugin must reproduce each signature
 * and accept each request.
 *
 * @package SAMOMIND
 */

class ContractRequestsTest extends SAMOMIND_Integration_TestCase {

	const ORDER_NUMBER = 'SM-1234';
	const EMAIL        = 'buyer@example.com';

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'woocommerce_order_number', array( $this, 'sequential_number' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'woocommerce_order_number', array( $this, 'sequential_number' ), 10 );
		parent::tearDown();
	}

	/**
	 * What a sequential order numbers plugin does: show the number it keeps in the order's meta.
	 *
	 * @param string   $number Number WooCommerce shows.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	public function sequential_number( $number, $order ) {
		$kept = (string) $order->get_meta( '_order_number', true );
		return '' !== $kept ? $kept : $number;
	}

	/**
	 * Fixtures by name.
	 *
	 * @return array[]
	 */
	private function fixtures() {
		$dir   = dirname( __DIR__ ) . '/contract/examples/samomind-to-plugin';
		$files = glob( $dir . '/*.json' );
		$this->assertNotEmpty( $files, 'No examples found. Run tests/contract/make_requests.py (see tests/contract/README.md).' );
		$found = array();
		foreach ( $files as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			$this->assertIsArray( $data, $file );
			$found[ $data['message'] ] = $data;
		}
		return $found;
	}

	/**
	 * Send a fixture's body to the handler, signed by the test with the
	 * shop's own key and the current time (the fixtures' time has passed).
	 */
	private function accept( array $fixture, $override = array() ) {
		$route = $fixture['route'];
		return $this->send(
			$this->signed(
				substr( $route, strlen( '/samomind/v1/orders/' ) ),
				array(),
				array_merge(
					array(
						'raw_body' => $fixture['body_raw'],
						'key_id'   => $fixture['key_id'],
					),
					$override
				)
			)
		);
	}

	public function test_the_plugin_reproduces_every_signature_samomind_makes() {
		foreach ( $this->fixtures() as $name => $fixture ) {
			$expected = SAMOMIND_Orders_Auth::sign(
				$fixture['secret'],
				SAMOMIND_Orders_Auth::canonical( $fixture['timestamp'], $fixture['key_id'], $fixture['nonce'], $fixture['path'], $fixture['body_raw'] )
			);
			$this->assertSame( $fixture['headers']['X-SamoMind-Orders-Signature'], $expected, $name );
			$this->assertSame( '1', $fixture['headers']['X-SamoMind-Orders-Version'], $name );
		}
	}

	public function test_the_plugin_signs_the_path_the_way_samomind_does() {
		foreach ( $this->fixtures() as $name => $fixture ) {
			$this->assertSame( SAMOMIND_Orders_Auth::request_path( $fixture['route'] ), $fixture['path'], $name );
		}
	}

	public function test_a_ping_from_samomind_is_answered() {
		$fixture  = $this->fixtures()['ping'];
		$response = $this->accept( $fixture );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'orders' ), array_keys( $response->get_data()['capabilities'] ) );
	}

	public function test_a_start_from_samomind_is_accepted() {
		$this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );

		$response = $this->accept( $this->fixtures()['start'] );
		$this->run_plugin_shutdown();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		$this->assertCount( 1, $this->mails, 'The code email goes out.' );
	}

	public function test_a_verify_from_samomind_is_accepted() {
		$fixture = $this->fixtures()['verify'];
		$body    = json_decode( $fixture['body_raw'], true );
		$order   = $this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );
		$store   = new SAMOMIND_Orders_Store( self::SECRET );
		$store->put_code( $body['session_ref'], $order->get_id(), $store->code_hash( $body['session_ref'], $order->get_id(), $body['code'] ), 600 );

		$response = $this->accept( $fixture );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1800, $response->get_data()['grant_expires_in'] );
		$this->assertTrue( $response->get_data()['verified'] );
	}

	public function test_a_redeem_from_samomind_is_accepted() {
		$fixture = $this->fixtures()['redeem'];
		$body    = json_decode( $fixture['body_raw'], true );
		$user    = $this->make_user();
		$this->make_order( array( 'customer_id' => $user, 'number_meta' => self::ORDER_NUMBER ) );

		// The shop's browser answers the challenge Samomind issued; then Samomind redeems it.
		$this->log_in( $user );
		$this->assertTrue( $this->proof( $body['challenge'], wp_create_nonce( 'wp_rest' ) )->get_data()['loggedIn'] );

		$response = $this->accept( $fixture );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['granted'] );
	}

	public function test_lookups_from_samomind_are_answered() {
		$order = $this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );
		$store = new SAMOMIND_Orders_Store( self::SECRET );

		foreach ( array( 'lookup-share-all', 'lookup-share-none' ) as $name ) {
			$fixture = $this->fixtures()[ $name ];
			$body    = json_decode( $fixture['body_raw'], true );
			$store->put_grant( $body['session_ref'], $order->get_id(), 600 );

			$response = $this->accept( $fixture );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $name );
			$this->assertTrue( $data['found'], $name );
			$this->assertSame( self::ORDER_NUMBER, $data['order']['order_number'], $name );
			$this->assertSame( $body['share']['items'], isset( $data['order']['items'] ), $name );
			$this->assertSame( $body['share']['tracking'], array_key_exists( 'tracking', $data['order'] ), $name );
		}
	}

	public function test_an_email_start_from_samomind_is_accepted() {
		$this->accept_history_terms();
		$this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );

		$response = $this->accept( $this->fixtures()['start-email'] );
		$this->run_plugin_shutdown();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		$this->assertCount( 1, $this->mails, 'The code email goes out.' );
	}

	public function test_an_email_verify_from_samomind_is_accepted() {
		$this->accept_history_terms();
		$fixture = $this->fixtures()['verify-email'];
		$body    = json_decode( $fixture['body_raw'], true );
		$this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );
		$store = new SAMOMIND_Orders_Store( self::SECRET );
		$store->put_email_code( $body['session_ref'], $body['email'], $store->email_code_hash( $body['session_ref'], $body['email'], $body['code'] ), 600 );

		$response = $this->accept( $fixture );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1800, $response->get_data()['grant_expires_in'] );
		$this->assertTrue( $response->get_data()['verified'] );
	}

	public function test_a_list_from_samomind_is_answered() {
		$this->accept_history_terms();
		$fixture = $this->fixtures()['list'];
		$body    = json_decode( $fixture['body_raw'], true );
		$this->make_order( array( 'billing_email' => self::EMAIL, 'number_meta' => self::ORDER_NUMBER ) );

		$this->assertSame( array( 'granted' => false ), $this->accept( $fixture )->get_data(), 'No grant yet.' );

		( new SAMOMIND_Orders_Store( self::SECRET ) )->put_email_grant( $body['session_ref'], self::EMAIL, 600 );
		$response = $this->accept( $fixture, array( 'nonce' => 'ANOTHERNONCE1234567890' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['granted'] );
		$this->assertSame( array( self::ORDER_NUMBER ), array_column( $response->get_data()['orders'], 'order_number' ) );
	}

	public function test_an_end_from_samomind_is_accepted() {
		$response = $this->accept( $this->fixtures()['end'] );

		$this->assertSame( 204, $response->get_status() );
	}

	public function test_every_request_is_refused_with_a_wrong_key() {
		foreach ( $this->fixtures() as $name => $fixture ) {
			$response = $this->accept( $fixture, array( 'secret' => $fixture['secret'] ) );

			$this->assertSame( 401, $response->get_status(), $name . ': signed with a key the shop does not have.' );
			$this->assertSame( 'invalid_signature', $response->get_data()['code'], $name );
		}
	}
}
