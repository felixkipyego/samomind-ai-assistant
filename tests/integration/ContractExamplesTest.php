<?php
/**
 * The plugin's side of the contract with Samomind: one real example of every
 * answer the shop gives and every request the plugin sends, recorded as JSON
 * for tests/contract/validate.py, which checks them against Samomind's own
 * models. Without SAMOMIND_CONTRACT_WRITE=1 nothing is written; the shapes
 * that matter most are still asserted here.
 *
 * Fake keys and fake order data only.
 *
 * @package SAMOMIND
 */

class ContractExamplesTest extends SAMOMIND_Integration_TestCase {

	const KEY_ID    = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
	const INGEST_ID = '5a1c7e2b-9d3f-4b8a-8c6d-1e2f3a4b5c6d';
	const INGEST    = 'smik_ContractFixtureIngestSecret000000000000';
	const SITE_KEY  = 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA';
	const SOURCE_ID = '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
	const CODE      = 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Examples collected during a test, name => array.
	 *
	 * @var array
	 */
	private static $examples = array();

	/**
	 * Requests the plugin sent to Samomind: array( url, args ).
	 *
	 * @var array
	 */
	private $sent = array();

	protected function setUp(): void {
		parent::setUp();
		$this->sent = array();
		add_filter( 'pre_http_request', array( $this, 'fake_samomind' ), 20, 3 );
		add_filter( 'woocommerce_order_number', array( $this, 'sequential_number' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_samomind' ), 20 );
		remove_filter( 'woocommerce_order_number', array( $this, 'sequential_number' ), 10 );
		WC_Shipment_Tracking_Actions::$items = array();
		foreach ( array( 'samomind_api_base_url', 'samomind_key_id', 'samomind_api_secret', 'samomind_site_key', 'samomind_connection', 'samomind_log', SAMOMIND_Orders_Settings::REPORTED ) as $name ) {
			delete_option( $name );
		}
		SAMOMIND()->scheduler->unschedule_worker();
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

	public static function tearDownAfterClass(): void {
		if ( '1' !== getenv( 'SAMOMIND_CONTRACT_WRITE' ) ) {
			return;
		}
		$dir = dirname( __DIR__ ) . '/contract/examples/plugin-to-samomind';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		foreach ( self::$examples as $name => $example ) {
			file_put_contents( $dir . '/' . $name . '.json', wp_json_encode( $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		}
	}

	// ---- the shop's answers ---------------------------------------------

	/**
	 * Run a signed request through the real handler and the real signing
	 * filter, and keep what the shop would send back.
	 *
	 * @param string $name     File name of the example.
	 * @param string $model    The Samomind model the body must fit, or "" for none.
	 * @param string $action   Endpoint.
	 * @param array  $body     Request body.
	 * @param array  $override Overrides for signed().
	 * @return array status, body (decoded), raw
	 */
	private function answer( $name, $model, $action, array $body, array $override = array() ) {
		$nonce   = substr( strtr( base64_encode( hash( 'sha256', $name, true ) ), '+/', '-_' ), 0, 22 );
		$request = $this->signed( $action, $body, array_merge( array( 'nonce' => $nonce ), $override ) );

		$rest     = new SAMOMIND_Orders_Rest( SAMOMIND()->orders );
		$response = $rest->handle( $action, $request );
		$server   = new class() {
			public $headers = array();
			public function send_header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}
		};
		ob_start();
		$served = $rest->serve_signed( false, $response, $request, $server );
		$raw    = ob_get_clean();
		if ( ! $served ) {
			$raw = 204 === $response->get_status() ? '' : (string) wp_json_encode( $response->get_data() );
		}
		$signature = isset( $server->headers['X-SamoMind-Orders-Signature'] ) ? $server->headers['X-SamoMind-Orders-Signature'] : null;

		self::$examples[ $name ] = array(
			'message'   => $name,
			'direction' => 'shop to Samomind',
			'model'     => $model,
			'status'    => $response->get_status(),
			'nonce'     => $nonce,
			'secret'    => self::SECRET,
			'signature' => $signature,
			'body_raw'  => $raw,
		);
		return array( $response->get_status(), json_decode( $raw, true ), $raw );
	}

	private function limits() {
		return array(
			'code_ttl_minutes'      => 10,
			'code_tries'            => 3,
			'shop_emails_per_hour'  => 20,
			'grant_minutes'         => 30,
			'account_proof_seconds' => 60,
			'resend_gap_seconds'    => 60,
			'sends_per_session_hour' => 3,
		);
	}

	private function request_body( array $extra ) {
		return array_merge(
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( 'contract' ),
			),
			$extra
		);
	}

	private function grant_through_the_code( WC_Order $order, $session ) {
		$code = $this->start_and_get_code( $order, $session );
		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code, $session ) )->get_data()['verified'] );
	}

	public function test_the_ping_answer_wraps_the_capabilities_in_orders() {
		$request  = $this->signed( 'ping', array( 'request_id' => wp_generate_uuid4(), 'limits' => $this->limits() ) );
		$response = ( new SAMOMIND_Orders_Rest( SAMOMIND()->orders ) )->handle( 'ping', $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'plugin_version', 'api', 'capabilities', 'server_time' ), array_keys( $data ) );
		// Samomind's PingResponse takes {"capabilities": {"orders": {...}}}, as at check-in, and refuses any other field.
		$this->assertSame( array( 'orders' ), array_keys( $data['capabilities'] ) );
		$this->assertSame( SAMOMIND()->orders->capabilities(), $data['capabilities']['orders'] );
		$this->assertArrayNotHasKey( 'api', $data['capabilities'] );
	}

	public function test_the_ping_answer_and_the_checkin_report_the_same_capabilities() {
		$ping = ( new SAMOMIND_Orders_Rest( SAMOMIND()->orders ) )->handle( 'ping', $this->signed( 'ping' ) )->get_data();

		$this->assertSame( SAMOMIND()->orders->payload_addition()['capabilities'], $ping['capabilities'] );
	}

	public function test_every_answer_the_shop_gives() {
		$order = $this->make_order( array( 'billing_email' => 'buyer@example.com', 'items' => array( 'Blue mug' => 2 ), 'number_meta' => 'SM-1234' ) );
		$limit = $this->limits();

		// ping, opted in and not.
		$this->answer( 'shop-ping', 'PingResponse', 'ping', array( 'request_id' => wp_generate_uuid4(), 'limits' => $limit ) );
		update_option( 'permalink_structure', '' );
		$this->answer( 'shop-ping-plain-permalinks', 'PingResponse', 'ping', array( 'request_id' => wp_generate_uuid4(), 'limits' => $limit ) );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );
		$this->answer( 'shop-ping-not-opted-in', 'PingResponse', 'ping', array( 'request_id' => wp_generate_uuid4(), 'limits' => $limit ) );
		$this->answer( 'shop-403-not-enabled', '', 'start', $this->request_body( array( 'order_number' => 'SM-1234', 'email' => 'buyer@example.com', 'locale' => 'en', 'limits' => $limit ) ) );
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );

		// Failures.
		list( $status, $body ) = $this->answer( 'shop-401-invalid-signature', 'InvalidSignatureBody', 'ping', array( 'request_id' => wp_generate_uuid4(), 'limits' => $limit ), array( 'secret' => 'smok_wrong' ) );
		$this->assertSame( 401, $status );
		$this->assertSame( array( 'code', 'server_time' ), array_keys( $body ) );
		$this->answer( 'shop-400-bad-request', '', 'start', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => 'not-a-ref', 'limits' => $limit ) );

		// start, with and without the shop's mail cap.
		list( $status, $body ) = $this->answer( 'shop-start', 'StartResponse', 'start', $this->request_body( array( 'order_number' => 'SM-1234', 'email' => 'buyer@example.com', 'locale' => 'en', 'limits' => $limit ) ) );
		$this->assertSame( 202, $status );
		$this->assertSame( array( 'accepted' => true ), $body );
		$this->run_plugin_shutdown();
		$code = $this->emailed_code();

		$capped = $limit;
		$capped['shop_emails_per_hour'] = 1;
		list( $status, $body ) = $this->answer(
			'shop-start-mail-capped',
			'StartResponse',
			'start',
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( 'capped' ),
				'order_number' => 'SM-1234',
				'email'       => 'buyer@example.com',
				'locale'      => 'en',
				'limits'      => $capped,
			)
		);
		$this->assertSame( 202, $status );
		$this->assertSame( array( 'accepted' => true, 'mail_capped' => true ), $body );

		// verify, wrong and right.
		list( , $body ) = $this->answer( 'shop-verify-wrong', 'VerifyResponse', 'verify', $this->request_body( array( 'order_number' => 'SM-1234', 'code' => '000000' === $code ? '111111' : '000000', 'limits' => $limit ) ) );
		$this->assertSame( array( 'verified' => false ), $body );
		list( , $body ) = $this->answer( 'shop-verify', 'VerifyResponse', 'verify', $this->request_body( array( 'order_number' => 'SM-1234', 'code' => $code, 'limits' => $limit ) ) );
		$this->assertTrue( $body['verified'] );

		// lookup: everything shared, with a tracking link.
		WC_Shipment_Tracking_Actions::$items = array(
			array(
				'tracking_provider'           => 'dhl',
				'formatted_tracking_provider' => 'DHL',
				'tracking_number'             => 'JD014600',
				'formatted_tracking_link'     => 'https://www.dhl.example/track?id=JD014600',
				'date_shipped'                => strtotime( '2026-11-10 12:00:00' ),
			),
		);
		$share = array( 'items' => true, 'tracking' => true );
		list( , $body ) = $this->answer( 'shop-lookup-full', 'LookupResponse', 'lookup', $this->request_body( array( 'order_number' => 'SM-1234', 'share' => $share ) ) );
		$this->assertTrue( $body['found'] );
		$this->assertSame( 'https://www.dhl.example/track?id=JD014600', $body['order']['tracking']['url'] );

		// Not shared: the request asks for less.
		$none = array( 'items' => false, 'tracking' => false );
		list( , $body ) = $this->answer( 'shop-lookup-not-shared', 'LookupResponse', 'lookup', $this->request_body( array( 'order_number' => 'SM-1234', 'share' => $none ) ) );
		$this->assertArrayNotHasKey( 'items', $body['order'] );
		$this->assertArrayNotHasKey( 'tracking', $body['order'] );

		// Shared, but the carrier gave no usable link, then no tracking at all.
		WC_Shipment_Tracking_Actions::$items[0]['formatted_tracking_link'] = 'http://carrier.example/t';
		list( , $body ) = $this->answer( 'shop-lookup-tracking-without-link', 'LookupResponse', 'lookup', $this->request_body( array( 'order_number' => 'SM-1234', 'share' => $share ) ) );
		$this->assertNull( $body['order']['tracking']['url'] );
		WC_Shipment_Tracking_Actions::$items = array();
		list( , $body ) = $this->answer( 'shop-lookup-no-tracking', 'LookupResponse', 'lookup', $this->request_body( array( 'order_number' => 'SM-1234', 'share' => $share ) ) );
		$this->assertNull( $body['order']['tracking'] );

		// Not found.
		list( , $body ) = $this->answer( 'shop-lookup-not-found', 'LookupResponse', 'lookup', array_merge( $this->request_body( array( 'order_number' => 'SM-1234', 'share' => $share ) ), array( 'session_ref' => $this->session_ref( 'nobody' ) ) ) );
		$this->assertSame( array( 'found' => false ), $body );

		// end.
		list( $status, , $raw ) = $this->answer( 'shop-end', '', 'end', $this->request_body( array() ) );
		$this->assertSame( 204, $status );
		$this->assertSame( '', $raw );
	}

	public function test_the_account_path_answers() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user, 'number_meta' => 'SM-5678' ) );
		$limit = $this->limits();

		$this->log_in( $user );
		$challenge = $this->challenge();
		$response  = $this->proof( $challenge, wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( $response->get_data()['loggedIn'] );
		$proof = $response->get_data()['proof'];
		self::$examples['shop-account-proof'] = array(
			'message'   => 'shop-account-proof',
			'direction' => 'shop to the widget, then Samomind',
			'model'     => 'AccountProof',
			'secret'    => self::SECRET,
			'body_raw'  => wp_json_encode( $proof ),
		);
		$this->assertSame( $challenge, $proof['challenge'] );

		list( , $body ) = $this->answer( 'shop-redeem', 'RedeemResponse', 'account/redeem', $this->request_body( array( 'challenge' => $challenge, 'order_number' => 'SM-5678', 'limits' => $limit ) ) );
		$this->assertTrue( $body['granted'] );
		list( , $body ) = $this->answer( 'shop-redeem-refused', 'RedeemResponse', 'account/redeem', $this->request_body( array( 'challenge' => $this->challenge(), 'order_number' => 'SM-5678', 'limits' => $limit ) ) );
		$this->assertSame( array( 'granted' => false ), $body );
		$this->assertNotEmpty( $order->get_id() );
	}

	public function test_the_checkin_reports_the_reasons_and_values_the_server_accepts() {
		// What the capabilities can say, whatever the shop's state; the validator checks each value.
		$caps = SAMOMIND()->orders->capabilities();
		$this->assertContains( $caps['tracking'], array( 'wc_shipment_tracking', 'ast', 'none' ) );
		$this->assertContains( $caps['mail_dispatch'], array( 'shutdown', 'cron_fallback' ) );
		$this->assertNull( $caps['reason'] );
	}

	// ---- what the plugin sends to Samomind ------------------------------

	public function fake_samomind( $pre, $args, $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 0 !== strpos( $path, '/api/v1/' ) ) {
			return $pre;
		}
		$this->sent[] = array( $url, $args );

		$body   = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$answer = array();
		switch ( $path ) {
			case '/api/v1/plugin/connect':
				$answer = array(
					'site_key'         => self::SITE_KEY,
					'ingest_key_id'    => self::INGEST_ID,
					'ingest_secret'    => self::INGEST,
					'api_base'         => 'https://api.samomind.com',
					'site_name'        => 'Acme Studio',
					'home_url_allowed' => true,
				);
				break;
			case '/api/v1/ingest/connect':
				$answer = array(
					'source_id'                => self::SOURCE_ID,
					'checkin_interval_seconds' => 86400,
					'limits'                   => array(
						'max_documents_per_push' => 100,
						'max_body_bytes'         => 1048576,
						'max_text_chars'         => 50000,
						'max_delete_ids'         => 500,
						'max_reconcile_entries'  => 2000,
					),
					'sync'                     => array( 'state' => 'none', 'cursor' => null ),
				);
				break;
			case '/api/v1/ingest/checkin':
				$answer = array(
					'status'                   => 'active',
					'checkin_interval_seconds' => 86400,
					'supported_api_versions'   => array( '1' ),
				);
				break;
			case '/api/v1/ingest/documents':
				$results = array();
				foreach ( isset( $body['documents'] ) ? (array) $body['documents'] : array() as $document ) {
					$results[] = array( 'external_id' => $document['external_id'], 'status' => 'queued' );
				}
				$answer = array( 'results' => $results );
				break;
			case '/api/v1/ingest/documents/delete':
				$results = array();
				foreach ( (array) $body['external_ids'] as $id ) {
					$results[] = array( 'external_id' => $id, 'status' => 'queued' );
				}
				$answer = array( 'results' => $results );
				break;
			case '/api/v1/ingest/sync':
				$answer = array( 'state' => 'begin' === $body['action'] ? 'in_progress' : 'complete', 'cursor' => null );
				break;
			case '/api/v1/ingest/reconcile':
				$answer = array( 'missing' => array(), 'stale' => array(), 'deleted' => array() );
				break;
			case '/api/v1/ingest/disconnect':
				$answer = array( 'state' => 'disconnected' );
				break;
		}

		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( $answer ),
			'headers'  => array(),
		);
	}

	/**
	 * Keep the requests sent since the last call, by endpoint.
	 *
	 * @param string $label Prefix of the example names.
	 * @return string[] Names saved.
	 */
	private function keep_sent( $label ) {
		$names = array();
		foreach ( $this->sent as $request ) {
			list( $url, $args ) = $request;
			$path               = (string) wp_parse_url( $url, PHP_URL_PATH );
			$base               = $label . '-' . trim( str_replace( array( '/api/v1/', '/' ), array( '', '-' ), $path ), '-' );
			$name               = $base;
			for ( $n = 2; isset( self::$examples[ $name ] ); $n++ ) {
				$name = $base . '-' . $n;
			}
			$headers            = isset( $args['headers'] ) ? $args['headers'] : array();

			self::$examples[ $name ] = array(
				'message'   => $name,
				'direction' => 'plugin to Samomind',
				'path'      => $path,
				'secret'    => self::INGEST,
				'headers'   => $headers,
				'body_raw'  => (string) $args['body'],
			);
			$names[] = $name;
		}
		$this->sent = array();
		return $names;
	}

	public function test_every_request_the_plugin_sends() {
		update_option( 'samomind_api_base_url', 'https://api.samomind.com', false );
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );
		// Send products too, and send a deleted page at the next run instead of after the wait.
		$with_products = function ( $types ) {
			return array_merge( (array) $types, array( 'page', 'post', 'product' ) );
		};
		add_filter( 'SAMOMIND/content/post_types', $with_products );
		add_filter( 'SAMOMIND/sync/debounce_seconds', '__return_zero' );

		$page = wp_insert_post(
			array(
				'post_title'   => 'About us',
				'post_content' => 'We make mugs.',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		$mug  = new WC_Product_Simple();
		$mug->set_name( 'Blue mug' );
		$mug->set_regular_price( '9.50' );
		$mug->set_description( 'A mug.' );
		$mug->set_status( 'publish' );
		$mug->save();

		// Connect with a code, opted out: no capabilities.
		$result = SAMOMIND()->plugin_connect->connect( self::CODE );
		$this->assertIsArray( $result );
		$names = $this->keep_sent( 'plugin-opted-out' );
		$this->assertContains( 'plugin-opted-out-plugin-connect', $names );
		$this->assertContains( 'plugin-opted-out-ingest-connect', $names );
		$this->assertArrayNotHasKey( 'capabilities', json_decode( self::$examples['plugin-opted-out-ingest-connect']['body_raw'], true ) );

		// The first full sync: begin, a batch, complete.
		for ( $i = 0; $i < 30; $i++ ) {
			SAMOMIND()->sync->run();
			$progress = SAMOMIND()->sync->get_progress();
			if ( in_array( $progress['state'], array( 'complete', 'stopped', 'failed' ), true ) ) {
				break;
			}
		}
		$this->assertSame( 'complete', $progress['state'] );
		$this->keep_sent( 'plugin-sync' );

		// A reconcile.
		SAMOMIND()->sync->request_reconcile( 'contract' );
		for ( $i = 0; $i < 10 && SAMOMIND()->sync->has_work(); $i++ ) {
			SAMOMIND()->sync->run();
		}
		$this->keep_sent( 'plugin-reconcile' );

		// A delete.
		wp_delete_post( $page, true );
		for ( $i = 0; $i < 10 && SAMOMIND()->sync->has_work(); $i++ ) {
			SAMOMIND()->sync->run();
		}
		$this->keep_sent( 'plugin-delete' );

		// A check-in with order status on, with its capabilities.
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );
		$this->assertIsArray( SAMOMIND()->connection->checkin( true ) );
		$this->keep_sent( 'plugin-opted-in' );

		// A check-in right after the shop switched it off, with the reason.
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );
		$this->assertIsArray( SAMOMIND()->connection->checkin( true ) );
		$names = $this->keep_sent( 'plugin-switched-off' );
		$this->assertSame( 'not_opted_in', json_decode( self::$examples['plugin-switched-off-ingest-checkin']['body_raw'], true )['capabilities']['orders']['reason'] );
		$this->assertNotEmpty( $names );

		// A connect with order status on.
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );
		$this->assertIsArray( SAMOMIND()->connection->connect() );
		$this->keep_sent( 'plugin-opted-in-reconnect' );
		$this->assertIsArray( SAMOMIND()->connection->checkin( true ) );
		$this->sent = array();

		// Disconnect.
		$this->assertTrue( SAMOMIND()->connection->disconnect( 'admin' ) );
		$this->keep_sent( 'plugin-disconnect' );

		$mug->delete( true );
		remove_filter( 'SAMOMIND/content/post_types', $with_products );
		remove_filter( 'SAMOMIND/sync/debounce_seconds', '__return_zero' );
		$this->assertNotEmpty( self::$examples );
	}
}
