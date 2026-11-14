<?php
/**
 * The signature, replay and key-separation rows of the threat table
 * (design section 11), against real WordPress and WooCommerce.
 *
 * @package SAMOMIND
 */

class OrdersSignatureTest extends SAMOMIND_Integration_TestCase {

	public function test_replayed_nonce_is_refused() {
		$request = $this->signed( 'ping' );
		$this->assertSame( 200, $this->send( $request )->get_status() );

		// The very same signed request again, within the window.
		$again = $this->send( $request );
		$this->assertSame( 401, $again->get_status() );
		$this->assertSame( 'invalid_signature', $again->get_data()['code'] );
	}

	public function test_stale_timestamp_refused() {
		foreach ( array( -121, 121, -3600 ) as $skew ) {
			$response = $this->call( 'ping', array(), array( 'timestamp' => time() + $skew ) );
			$this->assertSame( 401, $response->get_status(), "skew $skew" );
		}
		$this->assertSame( 200, $this->call( 'ping', array(), array( 'timestamp' => time() - 100 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'ping', array(), array( 'timestamp' => time() + 100 ) )->get_status() );
	}

	public function test_signature_covers_the_full_path_of_a_sub_directory_install() {
		$had = array_key_exists( 'REQUEST_URI', $_SERVER );
		$was = $had ? $_SERVER['REQUEST_URI'] : null;

		try {
			// The shop is at https://example.com/blog, so WordPress received the whole path, query string and all.
			$_SERVER['REQUEST_URI'] = '/blog/wp-json/samomind/v1/orders/ping?x=1';

			$full = $this->call( 'ping', array(), array( 'path' => '/blog/wp-json/samomind/v1/orders/ping' ) );
			$this->assertSame( 200, $full->get_status() );

			// A signature over the route without the folder is refused.
			$short = $this->call( 'ping', array(), array( 'path' => '/wp-json/samomind/v1/orders/ping' ) );
			$this->assertSame( 401, $short->get_status() );

			// So is one that leaves out the REST prefix, as a bare route would.
			$bare = $this->call( 'ping', array(), array( 'path' => '/samomind/v1/orders/ping' ) );
			$this->assertSame( 401, $bare->get_status() );
		} finally {
			if ( $had ) {
				$_SERVER['REQUEST_URI'] = $was;
			} else {
				unset( $_SERVER['REQUEST_URI'] );
			}
		}
	}

	public function test_signature_not_valid_on_another_path() {
		// Signed for /orders/ping, sent to /orders/lookup.
		$request = $this->signed( 'lookup', $this->lookup_body( '1' ), array( 'path' => SAMOMIND_Orders_Auth::request_path( '/samomind/v1/orders/ping' ) ) );
		$this->assertSame( 401, $this->send( $request )->get_status() );
	}

	public function test_a_wrong_key_a_wrong_body_and_a_missing_header_are_one_generic_failure() {
		$bodies = array();

		$bodies[] = $this->call( 'ping', array(), array( 'secret' => 'smok_someone_elses_key' ) );
		$tamper   = $this->signed( 'lookup', $this->lookup_body( '5' ) );
		$tamper->set_body( wp_json_encode( $this->lookup_body( '6' ) ) );
		$bodies[] = $this->send( $tamper );
		$bodies[] = $this->call( 'ping', array(), array( 'version' => '2' ) );
		$bodies[] = $this->call( 'ping', array(), array( 'signature' => 'v1=' . str_repeat( 'a', 64 ) ) );
		$bodies[] = $this->call( 'ping', array(), array( 'nonce' => 'short' ) );
		$unsigned = new WP_REST_Request( 'POST', '/samomind/v1/orders/ping' );
		$bodies[] = $this->send( $unsigned );

		foreach ( $bodies as $response ) {
			$this->assertSame( 401, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame( array( 'code', 'server_time' ), array_keys( $data ) );
			$this->assertSame( 'invalid_signature', $data['code'] );
			$this->assertEqualsWithDelta( time(), $data['server_time'], 5 );
		}
	}

	public function test_with_no_orders_key_saved_nothing_is_valid() {
		delete_option( SAMOMIND_Orders_Settings::SECRET );
		$this->assertSame( 401, $this->call( 'ping', array(), array( 'secret' => '' ) )->get_status() );
		$this->assertSame( 401, $this->call( 'ping' )->get_status() );
	}

	public function test_ingest_signature_is_not_valid_on_the_orders_channel() {
		$secret  = self::SECRET;
		$ts      = (string) time();
		$nonce   = 'AAAAAAAAAAAAAAAAAAAAAA';
		$route   = '/samomind/v1/orders/ping';
		$path    = SAMOMIND_Orders_Auth::request_path( $route );
		$key_id  = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';

		// An ingest-style signature (the ingest message, with the same secret value) over the same request.
		$ingest = SAMOMIND_Client::sign( $secret, SAMOMIND_Client::canonical_string( $ts, $key_id, $nonce, 'POST', $path, '' ) );
		$this->assertSame( 401, $this->send( $this->signed( 'ping', array(), array(
			'timestamp' => $ts,
			'nonce'     => $nonce,
			'raw_body'  => '',
			'signature' => $ingest,
		) ) )->get_status() );

		// And the other way: an orders signature is not an ingest signature.
		$orders = SAMOMIND_Orders_Auth::sign( $secret, SAMOMIND_Orders_Auth::canonical( $ts, $key_id, $nonce, $path, '' ) );
		$this->assertNotSame( $ingest, $orders );
		$this->assertNotSame(
			$orders,
			SAMOMIND_Client::sign( $secret, SAMOMIND_Client::canonical_string( $ts, $key_id, $nonce, 'POST', $path, '' ) )
		);
	}

	public function test_nonce_storage_that_fails_refuses_the_request() {
		// A store that takes the write but cannot give it back: the first look finds nothing, the read-back finds nothing.
		$key   = 'samomind_ord_n_' . hash( 'sha256', 'BBBBBBBBBBBBBBBBBBBBBB' );
		$name  = 'pre_transient_' . $key;
		$calls = 0;
		$block = function () use ( &$calls, $key ) {
			++$calls;
			if ( 2 === $calls ) {
				delete_option( '_transient_' . $key );
				delete_option( '_transient_timeout_' . $key );
			}
			return false;
		};
		add_filter( $name, $block );
		$response = $this->call( 'ping', array(), array( 'nonce' => 'BBBBBBBBBBBBBBBBBBBBBB' ) );
		remove_filter( $name, $block );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 2, $calls, 'It stored the nonce and read it back.' );
	}

	public function test_limits_are_clamped_to_the_ceilings() {
		$clamped = SAMOMIND_Orders_Auth::clamp_limits(
			array(
				'code_ttl_minutes'      => 9999,
				'code_tries'            => 500,
				'shop_emails_per_hour'  => 100000,
				'grant_minutes'         => 100000,
				'account_proof_seconds' => 100000,
				'resend_gap_seconds'    => 1,
				'sends_per_session_hour' => 100000,
			)
		);
		$this->assertSame(
			array(
				'code_ttl_minutes'      => 30,
				'code_tries'            => 10,
				'shop_emails_per_hour'  => 100,
				'grant_minutes'         => 120,
				'account_proof_seconds' => 120,
				'resend_gap_seconds'    => 30,
				'sends_per_session_hour' => 10,
			),
			$clamped
		);

		$floor = SAMOMIND_Orders_Auth::clamp_limits( array( 'code_ttl_minutes' => 0, 'code_tries' => -5, 'grant_minutes' => 'abc', 'shop_emails_per_hour' => 1.5 ) );
		$this->assertSame( 1, $floor['code_ttl_minutes'] );
		$this->assertSame( 1, $floor['code_tries'] );
		$this->assertSame( 30, $floor['grant_minutes'], 'A value that is not a whole number falls back to the default.' );
		$this->assertSame( 20, $floor['shop_emails_per_hour'] );
		$this->assertSame( 60, $floor['account_proof_seconds'], 'A missing value is the default.' );
		$this->assertSame( 60, $floor['resend_gap_seconds'] );
		$this->assertSame( 3, $floor['sends_per_session_hour'] );
	}

	public function test_a_hostile_try_limit_is_held_to_ten() {
		$order = $this->make_order();
		$body  = array(
			'request_id'   => wp_generate_uuid4(),
			'session_ref'  => $this->session_ref(),
			'order_number' => (string) $order->get_id(),
			'email'        => $order->get_billing_email(),
			'limits'       => array( 'code_tries' => 100000 ),
		);
		$this->call( 'start', $body );
		$this->run_plugin_shutdown();
		$code  = $this->emailed_code();
		$wrong = '000000' === $code ? '111111' : '000000';

		for ( $i = 0; $i < 10; $i++ ) {
			$this->call( 'verify', $this->verify_body( $order, $wrong ) + array( 'limits' => array( 'code_tries' => 100000 ) ) );
		}
		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'], 'The code died at the ceiling.' );
	}

	public function test_a_hostile_code_life_is_held_to_thirty_minutes() {
		$order = $this->make_order();
		$this->call(
			'start',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref(),
				'order_number' => (string) $order->get_id(),
				'email'        => $order->get_billing_email(),
				'limits'       => array( 'code_ttl_minutes' => 100000 ),
			)
		);

		global $wpdb;
		$timeouts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_samomind\\_ord\\_code\\_%'" ); // phpcs:ignore
		$this->assertNotEmpty( $timeouts );
		$this->assertLessThanOrEqual( 30 * MINUTE_IN_SECONDS + 5, (int) max( $timeouts ) - time() );
	}

	public function test_a_response_is_signed_over_the_exact_bytes_and_the_request_nonce() {
		$request = $this->signed( 'ping' );
		$response = $this->send( $request );

		$server = new class() {
			public $headers = array();
			public function send_header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}
		};
		$rest = new SAMOMIND_Orders_Rest( SAMOMIND()->orders );

		// Run the real handler so it remembers the nonce, then the real serve filter.
		$handled = $rest->handle( 'ping', $this->signed( 'ping', array(), array( 'nonce' => 'CCCCCCCCCCCCCCCCCCCCCC' ) ) );
		$this->assertSame( 200, $handled->get_status() );
		ob_start();
		$served = $rest->serve_signed( false, $handled, $this->signed( 'ping' ), $server );
		$body   = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( wp_json_encode( $handled->get_data() ), $body );
		$this->assertSame(
			SAMOMIND_Orders_Auth::sign_response( self::SECRET, 'CCCCCCCCCCCCCCCCCCCCCC', 200, $body ),
			$server->headers['X-SamoMind-Orders-Signature']
		);
		$this->assertNotSame( $response, $handled );
	}

	public function test_a_failed_signature_answer_is_not_signed() {
		$rest   = new SAMOMIND_Orders_Rest( SAMOMIND()->orders );
		$server = new class() {
			public $headers = array();
			public function send_header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}
		};
		$bad = $rest->handle( 'ping', $this->signed( 'ping', array(), array( 'secret' => 'smok_wrong' ) ) );
		$this->assertSame( 401, $bad->get_status() );
		$this->assertFalse( $rest->serve_signed( false, $bad, $this->signed( 'ping' ), $server ) );
		$this->assertSame( array(), $server->headers );
	}

	public function test_core_cors_handler_is_removed_for_the_order_routes_only() {
		$rest = new SAMOMIND_Orders_Rest( SAMOMIND()->orders );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );

		$rest->drop_cors_handler( false, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'Other routes keep core behaviour.' );

		$rest->drop_cors_handler( false, null, new WP_REST_Request( 'POST', '/samomind/v1/orders/account-proof' ) );
		$this->assertFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );

		add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	}

	public function test_orders_routes_refuse_when_not_opted_in() {
		foreach ( array( 'start', 'verify', 'account/redeem', 'lookup', 'end' ) as $action ) {
			update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );
			$response = $this->call( $action, array( 'session_ref' => $this->session_ref() ) );
			$this->assertSame( 403, $response->get_status(), $action );
			$this->assertSame( array( 'code' => 'not_enabled' ), $response->get_data(), $action );
		}

		// Ping still answers a validly signed request, and says why the feature is off.
		$ping = $this->call( 'ping' );
		$this->assertSame( 200, $ping->get_status() );
		$this->assertFalse( $ping->get_data()['capabilities']['enabled'] );
		$this->assertSame( 'not_opted_in', $ping->get_data()['capabilities']['reason'] );

		update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );
		delete_option( SAMOMIND_Orders_Settings::TERMS );
		$this->assertSame( 403, $this->call( 'start', array( 'session_ref' => $this->session_ref() ) )->get_status(), 'Terms not accepted.' );
	}

	public function test_old_woocommerce_reports_its_own_reason() {
		$wc      = WC();
		$version = $wc->version;
		$wc->version = '8.1.0';
		$ping    = $this->call( 'ping' )->get_data();
		$wc->version = $version;

		$this->assertFalse( $ping['capabilities']['enabled'] );
		$this->assertSame( 'woocommerce_too_old', $ping['capabilities']['reason'] );
	}

	public function test_ping_reports_the_capabilities() {
		$data = $this->call( 'ping', array( 'request_id' => wp_generate_uuid4() ) )->get_data();

		$this->assertSame( SAMOMIND_VERSION, $data['plugin_version'] );
		$this->assertSame( 1, $data['api'] );
		$caps = $data['capabilities'];
		$this->assertSame( 1, $caps['api'] );
		$this->assertTrue( $caps['enabled'] );
		$this->assertSame( SAMOMIND_Orders_Settings::TERMS_VERSION, $caps['terms_accepted_version'] );
		$this->assertTrue( $caps['share_items'] );
		$this->assertTrue( $caps['share_tracking'] );
		$this->assertSame( SAMOMIND_Orders_Lookup::tracking_provider(), $caps['tracking'] );
		$this->assertSame( '1' === getenv( 'SAMOMIND_HPOS' ), $caps['hpos'] );
		$this->assertSame( WC_VERSION, $caps['woocommerce'] );
		$this->assertContains( $caps['mail_dispatch'], array( 'shutdown', 'cron_fallback' ) );
		$this->assertNull( $caps['reason'] );
		$this->assertEqualsWithDelta( time(), $data['server_time'], 5 );
		$this->assertSame( 0, count( $this->mails ), 'Ping sends no mail.' );
	}
}
