<?php
/**
 * The per-order email cap, the state table and the signed-path checks
 * (T5 findings 3, 4 and 9), against real WordPress and WooCommerce.
 *
 * @package SAMOMIND
 */

class OrdersCapsTest extends SAMOMIND_Integration_TestCase {

	private function start_body( $order_number, $email, $session ) {
		return array(
			'request_id'   => wp_generate_uuid4(),
			'session_ref'  => $this->session_ref( $session ),
			'order_number' => (string) $order_number,
			'email'        => $email,
			'locale'       => 'en',
		);
	}

	private function start( $order_number, $email, $session ) {
		$response = $this->call( 'start', $this->start_body( $order_number, $email, $session ) );
		$this->run_plugin_shutdown();
		return $response;
	}

	public function test_an_order_gets_at_most_three_code_emails_an_hour_whatever_the_session() {
		$order = $this->make_order();
		$id    = $order->get_id();

		$bodies = array();
		// Seven different chat sessions, and the order typed four different ways.
		foreach ( array( (string) $id, '#' . $id, (string) $id, ' ' . $id . ' ', (string) $id, '#' . $id, (string) $id ) as $i => $typed ) {
			$response = $this->start( $typed, $order->get_billing_email(), 'sess' . $i );
			$this->assertSame( 202, $response->get_status(), 'Never 429.' );
			$bodies[] = wp_json_encode( $response->get_data() );
		}

		$this->assertCount( 3, $this->mails, 'The fourth and later code emails for one order are not sent.' );
		$this->assertSame( '{"accepted":true}', $bodies[2] );
		$this->assertSame( '{"accepted":true,"mail_capped":true}', $bodies[3] );
		$this->assertSame( '{"accepted":true,"mail_capped":true}', $bodies[6] );

		$rows = $this->state_rows( 'omail' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '3', $rows[0]['n'] );
		$this->assertSame( SAMOMIND_Orders_State::key( array( (string) $id ) ), $rows[0]['state_key'], 'Keyed on the resolved order id.' );
	}

	public function test_one_order_cannot_fill_the_shop_wide_cap() {
		$order = $this->make_order();
		for ( $i = 0; $i < 8; $i++ ) {
			$this->start( $order->get_id(), $order->get_billing_email(), 'flood' . $i );
		}

		$hour = $this->state_rows( 'hour' );
		$this->assertCount( 1, $hour );
		$this->assertSame( '3', $hour[0]['n'], 'Only the three emails that went out are counted for the shop.' );

		// Other orders still get their code.
		$other = $this->make_order( array( 'billing_email' => 'other@example.com' ) );
		$before = count( $this->mails );
		$this->start( $other->get_id(), 'other@example.com', 'fresh' );
		$this->assertCount( $before + 1, $this->mails );
	}

	public function test_the_shop_wide_cap_still_stops_a_flood_across_orders() {
		$limits = array( 'limits' => array( 'shop_emails_per_hour' => 2 ) );
		$bodies = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$order  = $this->make_order( array( 'billing_email' => "flood$i@example.com" ) );
			$bodies[] = wp_json_encode( $this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email(), "f$i" ) + $limits )->get_data() );
			$this->run_plugin_shutdown();
		}
		$this->assertCount( 2, $this->mails );
		$this->assertSame( array( '{"accepted":true}', '{"accepted":true}', '{"accepted":true,"mail_capped":true}', '{"accepted":true,"mail_capped":true}' ), $bodies );
	}

	public function test_the_order_cap_is_not_told_to_a_caller_who_does_not_know_the_email() {
		$order = $this->make_order();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->start( $order->get_id(), $order->get_billing_email(), 'owner' . $i );
		}
		$this->assertCount( 3, $this->mails );

		$guess = $this->start( $order->get_id(), 'guess@example.com', 'stranger' );
		$this->assertSame( '{"accepted":true}', wp_json_encode( $guess->get_data() ), 'Same body as for an order that does not exist.' );
		$missing = $this->start( 99999999, 'guess@example.com', 'stranger2' );
		$this->assertSame( wp_json_encode( $missing->get_data() ), wp_json_encode( $guess->get_data() ) );
	}

	public function test_the_state_table_has_a_unique_key_and_is_versioned() {
		global $wpdb;
		$table = SAMOMIND_Orders_State::table();

		$this->assertSame( SAMOMIND_Orders_State::DB_VERSION, get_option( SAMOMIND_Orders_State::DB_OPTION ) );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'kind_key'", ARRAY_A ); // phpcs:ignore
		$this->assertCount( 2, $indexes );
		$this->assertSame( '0', $indexes[0]['Non_unique'] );

		$key = SAMOMIND_Orders_State::key( array( 'x' ) );
		$this->assertSame( 1, SAMOMIND_Orders_State::claim( 'nonce', $key, time() + 60 ) );
		$this->assertSame( 0, SAMOMIND_Orders_State::claim( 'nonce', $key, time() + 60 ) );
	}

	public function test_an_expired_row_can_be_claimed_again_and_the_hourly_clean_up_removes_it() {
		$key = SAMOMIND_Orders_State::key( array( 'y' ) );
		$this->assertSame( 1, SAMOMIND_Orders_State::claim( 'nonce', $key, time() + 60 ) );
		$this->age_state( 'nonce', 3600 );
		$this->assertSame( 1, SAMOMIND_Orders_State::claim( 'nonce', $key, time() + 60 ), 'The old holder had expired.' );

		SAMOMIND_Orders_State::claim( 'nonce', SAMOMIND_Orders_State::key( array( 'z' ) ), time() + 60 );
		$this->age_state( 'nonce', 120 );
		$this->assertTrue( has_action( SAMOMIND_Scheduler::HOURLY_HOOK, array( 'SAMOMIND_Orders_State', 'purge_expired' ) ) !== false );
		SAMOMIND_Orders_State::purge_expired();
		$this->assertSame( array(), $this->state_rows( 'nonce' ), 'Both rows were past their end.' );

		SAMOMIND_Orders_State::claim( 'nonce', $key, time() + 600 );
		SAMOMIND_Orders_State::purge_expired();
		$this->assertCount( 1, $this->state_rows( 'nonce' ), 'A live row stays.' );
	}

	public function test_the_table_is_dropped_by_uninstall() {
		global $wpdb;
		$table = SAMOMIND_Orders_State::table();

		SAMOMIND_Orders_State::drop();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertFalse( get_option( SAMOMIND_Orders_State::DB_OPTION ) );

		SAMOMIND_Orders_State::install();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	/**
	 * Send a signed ping the way the web server would receive it.
	 *
	 * @param string $request_uri  What the server put in REQUEST_URI.
	 * @param string $route        The route that is dispatched.
	 * @return int  The HTTP status.
	 */
	private function ping_received_as( $request_uri, $route ) {
		$had = array_key_exists( 'REQUEST_URI', $_SERVER );
		$was = $had ? $_SERVER['REQUEST_URI'] : null;
		try {
			$_SERVER['REQUEST_URI'] = $request_uri;
			$path                   = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
			$request                = $this->signed( 'ping', array(), array( 'path' => $path ) );
			$request->set_route( $route );
			return $this->send( $request )->get_status();
		} finally {
			if ( $had ) {
				$_SERVER['REQUEST_URI'] = $was;
			} else {
				unset( $_SERVER['REQUEST_URI'] );
			}
		}
	}

	public function test_a_signed_request_with_a_rest_route_parameter_on_the_rest_path_is_refused() {
		// Signed over the path it was sent to, so only the extra parameter is wrong.
		$this->assertSame( 200, $this->ping_received_as( '/wp-json/samomind/v1/orders/ping', '/samomind/v1/orders/ping' ) );
		$this->assertSame( 401, $this->ping_received_as( '/wp-json/samomind/v1/orders/ping?rest_route=/samomind/v1/orders/ping', '/samomind/v1/orders/ping' ) );
		$this->assertSame( 401, $this->ping_received_as( '/blog/wp-json/samomind/v1/orders/ping?x=1&rest_route=/samomind/v1/orders/end', '/samomind/v1/orders/ping' ) );
	}

	public function test_the_matched_route_must_be_the_signed_path() {
		// A request signed for ping and delivered to another route is refused, though the signature is right for its path.
		$this->assertSame( 401, $this->ping_received_as( '/wp-json/samomind/v1/orders/ping', '/samomind/v1/orders/end' ) );
		$this->assertSame( 401, $this->ping_received_as( '/index.php', '/samomind/v1/orders/ping' ) );
		$this->assertSame( 200, $this->ping_received_as( '/blog/wp-json/samomind/v1/orders/ping', '/samomind/v1/orders/ping' ) );
	}
}
