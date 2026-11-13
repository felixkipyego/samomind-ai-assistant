<?php
/**
 * The guest path (start, verify, lookup, end), the code rules, the limits
 * and the answers that must look the same (design sections 3, 4 and 11).
 *
 * @package SAMOMIND
 */

class OrdersFlowTest extends SAMOMIND_Integration_TestCase {

	private function start_body( $order_number, $email, $session = 'a' ) {
		return array(
			'request_id'   => wp_generate_uuid4(),
			'session_ref'  => $this->session_ref( $session ),
			'order_number' => (string) $order_number,
			'email'        => $email,
			'locale'       => 'en',
		);
	}

	private function lookup_found( $order, $session = 'a' ) {
		return $this->call( 'lookup', $this->lookup_body( $order->get_order_number(), $session ) )->get_data();
	}

	public function test_a_full_guest_flow_ends_in_a_lookup() {
		$order = $this->make_order();

		$this->assertFalse( $this->lookup_found( $order )['found'], 'Nothing before a code is checked.' );

		$code   = $this->start_and_get_code( $order );
		$verify = $this->call( 'verify', $this->verify_body( $order, $code ) );
		$this->assertSame( 200, $verify->get_status() );
		$this->assertSame(
			array(
				'verified'         => true,
				'grant_expires_in' => 1800,
			),
			$verify->get_data()
		);

		$found = $this->lookup_found( $order );
		$this->assertTrue( $found['found'] );
		$this->assertSame( (string) $order->get_order_number(), $found['order']['order_number'] );
		$this->assertSame( 'processing', $found['order']['status'] );
	}

	public function test_orders_start_response_identical_for_exists_missing_mismatch() {
		$order = $this->make_order();

		$responses = array(
			'exists'   => $this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email(), 'one' ) ),
			'missing'  => $this->call( 'start', $this->start_body( 99999999, $order->get_billing_email(), 'two' ) ),
			'mismatch' => $this->call( 'start', $this->start_body( $order->get_id(), 'someone.else@example.com', 'three' ) ),
			'badshape' => $this->call( 'start', $this->start_body( 'not a number!', 'x', 'four' ) ),
		);

		$first = null;
		foreach ( $responses as $name => $response ) {
			$snapshot = array( $response->get_status(), wp_json_encode( $response->get_data() ), $response->get_headers() );
			if ( null === $first ) {
				$first = $snapshot;
			}
			$this->assertSame( $first, $snapshot, "The $name answer differs." );
		}
		$this->assertSame( 202, $first[0] );
		$this->assertSame( '{"accepted":true}', $first[1] );
		$this->assertSame( 'no-store, private', $first[2]['Cache-Control'] );
	}

	public function test_orders_start_timing_floor() {
		SAMOMIND_Orders_Rest::$floor_ms = 500;
		$order                          = $this->make_order();

		$timings = array();
		foreach ( array(
			'exists'   => array( $order->get_id(), $order->get_billing_email() ),
			'missing'  => array( 99999999, 'x@example.com' ),
			'mismatch' => array( $order->get_id(), 'other@example.com' ),
		) as $name => $args ) {
			$started          = microtime( true );
			$this->call( 'start', $this->start_body( $args[0], $args[1], $name ) );
			$timings[ $name ] = microtime( true ) - $started;
		}

		foreach ( $timings as $name => $seconds ) {
			$this->assertGreaterThanOrEqual( 0.5, $seconds, "$name came back too fast." );
		}
		$this->assertLessThan( 0.35, max( $timings ) - min( $timings ), 'The three paths should take about the same time.' );
		$this->assertSame( 0, count( $this->mails ), 'No mail goes out before the shutdown callback.' );

		foreach ( array( 'verify', 'account/redeem' ) as $action ) {
			$started = microtime( true );
			$this->call( $action, array( 'session_ref' => $this->session_ref(), 'order_number' => '1', 'code' => '123456', 'challenge' => $this->challenge() ) );
			$this->assertGreaterThanOrEqual( 0.5, microtime( true ) - $started, "$action has the floor too." );
		}
	}

	public function test_code_is_only_sent_to_the_orders_billing_address() {
		$order = $this->make_order( array( 'billing_email' => 'Buyer.Name@Example.com' ) );

		// The caller types the address in other letters; the mail still goes to the order's own address.
		$this->start_and_get_code( $order, 'a', '  buyer.name@EXAMPLE.com ' );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'Buyer.Name@Example.com', $this->mails[0]['to'] );

		// A different address gets nothing, and neither does a missing order.
		$this->call( 'start', $this->start_body( $order->get_id(), 'attacker@example.com', 'b' ) );
		$this->call( 'start', $this->start_body( 424242424, 'attacker@example.com', 'c' ) );
		$this->run_plugin_shutdown();
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_code_email_is_english_from_the_woocommerce_sender_and_holds_nothing_else() {
		update_option( 'woocommerce_email_from_name', 'Mug Shop' );
		update_option( 'woocommerce_email_from_address', 'orders@mugshop.example' );
		$order = $this->make_order();
		$this->start_and_get_code( $order );

		$mail = $this->mails[0];
		$this->assertStringContainsString( 'Your code is ', $mail['message'] );
		$this->assertStringContainsString( 'From: "Mug Shop" <orders@mugshop.example>', implode( "\n", $mail['headers'] ) );
		$this->assertStringNotContainsString( 'Blue mug', $mail['message'] );
		$this->assertStringNotContainsString( (string) $order->get_id() . ' ', $mail['message'] );
		$this->assertStringNotContainsString( 'Secret Street', $mail['message'] );
	}

	public function test_response_is_returned_before_mail_is_sent() {
		$order  = $this->make_order();
		$events = &$this->events;
		add_filter(
			'pre_wp_mail',
			function ( $pre ) use ( &$events ) {
				$events[] = 'mail';
				return $pre;
			},
			5
		);

		$response = $this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email() ) );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array(), $events, 'Nothing is sent while the response is being made.' );

		$this->run_plugin_shutdown();
		$this->assertSame( array( 'flush', 'mail' ), $events, 'The response is flushed first, then the mail goes.' );
	}

	public function test_cron_fallback_only_when_no_finish_function() {
		$order = $this->make_order();

		// With a finish function: a shutdown callback, nothing scheduled.
		$this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email(), 'one' ) );
		$this->assertArrayHasKey( 50, $GLOBALS['wp_filter']['shutdown']->callbacks );
		$this->assertNull( $this->scheduled_mail_event() );
		$this->assertSame( 'shutdown', SAMOMIND_Orders_Mail::dispatch_mode() );
		$this->run_plugin_shutdown();

		// Without one: WP-Cron, and no shutdown callback.
		remove_all_filters( 'SAMOMIND/orders/finish_function' );
		add_filter( 'SAMOMIND/orders/finish_function', '__return_null', 99 );
		$this->assertSame( 'cron_fallback', SAMOMIND_Orders_Mail::dispatch_mode() );
		$this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email(), 'two' ) );
		$this->assertFalse( isset( $GLOBALS['wp_filter']['shutdown']->callbacks[50] ) );
		$event = $this->scheduled_mail_event();
		$this->assertNotNull( $event );

		// The cron callback sends it, once.
		$count = count( $this->mails );
		do_action_ref_array( SAMOMIND_Orders_Mail::CRON_HOOK, $event['args'] );
		do_action_ref_array( SAMOMIND_Orders_Mail::CRON_HOOK, $event['args'] );
		$this->assertCount( $count + 1, $this->mails );
		wp_clear_scheduled_hook( SAMOMIND_Orders_Mail::CRON_HOOK, $event['args'] );
	}

	private function scheduled_mail_event() {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ SAMOMIND_Orders_Mail::CRON_HOOK ] ) ) {
				$entry = reset( $events[ SAMOMIND_Orders_Mail::CRON_HOOK ] );
				return array( 'args' => $entry['args'] );
			}
		}
		return null;
	}

	public function test_code_valid_only_for_its_conversation() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order, 'a' );

		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $code, 'b' ) )->get_data()['verified'] );
		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code, 'a' ) )->get_data()['verified'], 'The right conversation still works.' );
		$this->assertFalse( $this->lookup_found( $order, 'b' )['found'], 'A grant made for one conversation is no use in another.' );
		$this->assertTrue( $this->lookup_found( $order, 'a' )['found'] );
	}

	public function test_used_code_cannot_be_reused() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );

		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'] );
		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'] );
	}

	public function test_confirm_locks_after_three_tries_and_five_failures() {
		// The plugin's part: a code dies at three wrong tries. The five-failure lock is counted in Samomind.
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );
		$wrong = '000000' === $code ? '111111' : '000000';

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $wrong ) )->get_data()['verified'] );
		}
		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'], 'The right code is dead after three wrong tries.' );
		$this->assertFalse( $this->lookup_found( $order )['found'] );
	}

	public function test_a_code_expires() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );
		global $wpdb;
		// Age the stored code past its end, as the clock would.
		$name = $wpdb->get_var( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_samomind\\\\_ord\\\\_code\\\\_%'" ); // phpcs:ignore
		$key  = substr( $name, strlen( '_transient_' ) );
		$data = get_transient( $key );
		$data['exp'] = time() - 1;
		set_transient( $key, $data, 600 );

		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'] );
	}

	public function test_resend_gap_and_cap() {
		$order = $this->make_order();

		$first = $this->start_and_get_code( $order );
		$this->assertCount( 1, $this->mails );

		// Inside the 60 second gap: the same answer, no new mail, the first code still works.
		$again = $this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email() ) );
		$this->assertSame( '{"accepted":true}', wp_json_encode( $again->get_data() ) );
		$this->run_plugin_shutdown();
		$this->assertCount( 1, $this->mails );

		// After the gap a new code replaces the old one.
		$this->age_sends( 'a', 61 );
		$second = $this->start_and_get_code( $order );
		$this->assertCount( 2, $this->mails );
		$this->assertFalse( $this->call( 'verify', $this->verify_body( $order, $first ) )->get_data()['verified'] && $first !== $second, 'The old code stops working.' );

		// Three sends an hour per chat session, then no more.
		$this->age_sends( 'a', 61 );
		$this->start_and_get_code( $order );
		$this->assertCount( 3, $this->mails );
		$this->age_sends( 'a', 61 );
		$this->call( 'start', $this->start_body( $order->get_id(), $order->get_billing_email() ) );
		$this->run_plugin_shutdown();
		$this->assertCount( 3, $this->mails, 'The fourth code in an hour is not sent.' );
	}

	private function age_sends( $session, $seconds ) {
		global $wpdb;
		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_samomind\\\\_ord\\\\_sends\\\\_%'" ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$key   = substr( $row, strlen( '_transient_' ) );
			$state = get_transient( $key );
			$state['last']  -= $seconds;
			$state['first'] -= 0;
			set_transient( $key, $state, HOUR_IN_SECONDS );
		}
		unset( $session );
	}

	public function test_shop_send_limit() {
		$mails = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$order = $this->make_order( array( 'billing_email' => "buyer$i@example.com" ) );
			$this->call(
				'start',
				$this->start_body( $order->get_id(), $order->get_billing_email(), "s$i" ) + array( 'limits' => array( 'shop_emails_per_hour' => 3 ) )
			);
			$this->run_plugin_shutdown();
			$mails[] = count( $this->mails );
		}
		$this->assertSame( array( 1, 2, 3, 3 ), $mails, 'The fourth email in the hour is not sent, and the answer is the same 202.' );
	}

	public function test_grant_expires_absolutely() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );
		$this->call( 'verify', $this->verify_body( $order, $code ) );
		$this->assertTrue( $this->lookup_found( $order )['found'] );

		global $wpdb;
		$name = $wpdb->get_var( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_samomind\\\\_ord\\\\_grant\\\\_%'" ); // phpcs:ignore
		$key   = substr( $name, strlen( '_transient_' ) );
		$grant = get_transient( $key );
		$this->assertEqualsWithDelta( time() + 1800, $grant['exp'], 5 );

		// Looking again never moves the end.
		$this->lookup_found( $order );
		$this->assertSame( $grant['exp'], get_transient( $key )['exp'] );

		// Past the end it is gone, even if the row is still stored.
		$grant['exp'] = time() - 1;
		set_transient( $key, $grant, 600 );
		$this->assertFalse( $this->lookup_found( $order )['found'] );
	}

	public function test_second_order_needs_a_second_verification() {
		$first  = $this->make_order();
		$second = $this->make_order( array( 'billing_email' => $first->get_billing_email() ) );
		$code   = $this->start_and_get_code( $first );
		$this->call( 'verify', $this->verify_body( $first, $code ) );

		$this->assertTrue( $this->lookup_found( $first )['found'] );
		$this->assertFalse( $this->lookup_found( $second )['found'], 'Same email, same chat, other order: still needs its own code.' );
	}

	public function test_end_deletes_the_grant_and_the_pending_code() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );
		$this->call( 'verify', $this->verify_body( $order, $code ) );
		$this->assertTrue( $this->lookup_found( $order )['found'] );

		$end = $this->call( 'end', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $this->session_ref() ) );
		$this->assertSame( 204, $end->get_status() );
		$this->assertNull( $end->get_data() );
		$this->assertFalse( $this->lookup_found( $order )['found'] );

		// An unknown session is fine.
		$this->assertSame( 204, $this->call( 'end', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $this->session_ref( 'never' ) ) )->get_status() );

		// A pending code is deleted too.
		$other = $this->make_order();
		$pending = $this->start_and_get_code( $other, 'z' );
		$this->call( 'end', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $this->session_ref( 'z' ) ) );
		$this->assertFalse( $this->call( 'verify', $this->verify_body( $other, $pending, 'z' ) )->get_data()['verified'] );
	}

	public function test_orders_key_without_a_shop_record_cannot_read_any_order() {
		// Someone holds the key and signs everything correctly, for every order id and for both paths.
		$orders = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$orders[] = $this->make_order( array( 'customer_id' => $this->make_user() ) );
		}
		$ids = array_map(
			function ( $order ) {
				return $order->get_id();
			},
			$orders
		);
		$ids = array_merge( $ids, range( 1, 12 ) );

		foreach ( $ids as $id ) {
			$lookup = $this->call( 'lookup', $this->lookup_body( $id ) );
			$this->assertSame( array( 'found' => false ), $lookup->get_data(), "lookup $id" );

			$redeem = $this->call( 'account/redeem', array( 'session_ref' => $this->session_ref(), 'challenge' => $this->challenge(), 'order_number' => (string) $id ) );
			$this->assertSame( array( 'granted' => false ), $redeem->get_data(), "redeem $id" );

			$verify = $this->call( 'verify', array( 'session_ref' => $this->session_ref(), 'order_number' => (string) $id, 'code' => '123456' ) );
			$this->assertSame( array( 'verified' => false ), $verify->get_data(), "verify $id" );
		}
	}

	public function test_order_lock_does_not_block_account_path() {
		// The plugin has no per-order lock: failed guest tries leave the account path alone.
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$code  = $this->start_and_get_code( $order );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->call( 'verify', $this->verify_body( $order, '000000' === $code ? '111111' : '000000' ) );
		}

		$token     = $this->log_in( $user );
		$challenge = $this->challenge();
		$proof     = $this->proof( $challenge, wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( $proof->get_data()['loggedIn'] );
		$redeem = $this->call( 'account/redeem', array( 'session_ref' => $this->session_ref( 'acc' ), 'challenge' => $challenge, 'order_number' => (string) $order->get_id() ) );
		$this->assertSame( true, $redeem->get_data()['granted'] );
		$this->assertTrue( $this->lookup_found( $order, 'acc' )['found'] );
		unset( $token );
	}

	public function test_log_never_contains_email_or_code() {
		$order = $this->make_order( array( 'billing_email' => 'private.person@example.org' ) );
		$code  = $this->start_and_get_code( $order );
		$this->call( 'verify', $this->verify_body( $order, '000000' === $code ? '111111' : '000000' ) );
		$this->call( 'verify', $this->verify_body( $order, $code ) );
		$this->call( 'lookup', $this->lookup_body( $order->get_order_number() ) );
		$this->call( 'ping', array(), array( 'secret' => 'smok_wrong' ) );
		$this->call( 'start', $this->start_body( $order->get_id(), 'private.person@example.org' ) + array( 'limits' => array( 'shop_emails_per_hour' => 1 ) ) );

		global $wpdb;
		$everything = wp_json_encode( get_option( SAMOMIND_Log::OPTION ) );
		$everything .= implode( '', $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'samomind_log'" ) ); // phpcs:ignore
		foreach ( array( 'private.person', '@example.org', $code, 'Blue mug', 'Private', 'Secret Street', '+254700000000', self::SECRET ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $everything, "The log mentions $needle." );
		}
	}

	public function test_a_failed_mail_is_logged_without_the_address() {
		$order = $this->make_order( array( 'billing_email' => 'private.person@example.org' ) );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false', 5 );
		$this->call( 'start', $this->start_body( $order->get_id(), 'private.person@example.org' ) );
		$this->run_plugin_shutdown();
		remove_filter( 'pre_wp_mail', '__return_false', 5 );

		$rows = SAMOMIND()->log->get_rows();
		$this->assertSame( 'mail_failed', $rows[0]['code'] );
		$this->assertStringNotContainsString( 'private.person', wp_json_encode( $rows ) );
	}
}
