<?php
/**
 * The logged-in path (design section 5): the same-origin proof endpoint, the
 * shop's one-time record, redemption, and the end of access at logout.
 *
 * @package SAMOMIND
 */

class OrdersAccountTest extends SAMOMIND_Integration_TestCase {

	private function redeem( $challenge, $order, $session = 'acc' ) {
		return $this->call(
			'account/redeem',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref( $session ),
				'challenge'    => $challenge,
				'order_number' => (string) $order->get_order_number(),
			)
		);
	}

	private function lookup( $order, $session = 'acc' ) {
		return $this->call( 'lookup', $this->lookup_body( $order->get_order_number(), $session ) )->get_data();
	}

	/**
	 * A logged-in browser answers a challenge; returns the challenge and the proof.
	 */
	private function logged_in_proof( $user_id ) {
		$token     = $this->log_in( $user_id );
		$challenge = $this->challenge();
		$response  = $this->proof( $challenge, wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['loggedIn'] );
		return array( $challenge, $response->get_data()['proof'], $token );
	}

	public function test_an_account_flow_ends_in_a_lookup() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );

		list( $challenge, $proof ) = $this->logged_in_proof( $user );
		$this->assertSame( array( 'v', 'challenge', 'exp', 'sig' ), array_keys( $proof ) );
		$this->assertSame( 1, $proof['v'] );
		$this->assertSame( $challenge, $proof['challenge'] );
		$this->assertEqualsWithDelta( time() + 60, $proof['exp'], 5 );
		$this->assertSame( SAMOMIND_Orders_Auth::sign_proof( self::SECRET, $challenge, $proof['exp'] ), $proof['sig'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $proof['sig'] );

		$this->assertSame( array( 'granted' => true, 'grant_expires_in' => 1800 ), $this->redeem( $challenge, $order )->get_data() );
		$found = $this->lookup( $order );
		$this->assertTrue( $found['found'] );
		$this->assertSame( 'processing', $found['order']['status'] );
	}

	public function test_the_proof_names_no_one() {
		$user = $this->make_user();
		list( , $proof ) = $this->logged_in_proof( $user );
		$json = wp_json_encode( $proof );
		$data = get_userdata( $user );
		foreach ( array( (string) $user, $data->user_login, $data->user_email, $data->display_name ) as $needle ) {
			if ( ctype_digit( $needle ) ) {
				$this->assertDoesNotMatchRegularExpression( '/"(user|uid|id)[a-z_]*":' . $needle . '/i', $json );
			} else {
				$this->assertStringNotContainsString( $needle, $json );
			}
		}
	}

	public function test_proof_endpoint_is_no_store_and_requires_cookie_and_nonce() {
		$user = $this->make_user();
		$this->log_in( $user );
		$good = wp_create_nonce( 'wp_rest' );

		$ok = $this->proof( $this->challenge(), $good );
		$this->assertTrue( $ok->get_data()['loggedIn'] );
		$headers = $ok->get_headers();
		$this->assertSame( 'no-store, private', $headers['Cache-Control'] );
		$this->assertSame( 'Cookie', $headers['Vary'] );

		// A nonce that is wrong is refused by WordPress itself.
		$bad = $this->proof( $this->challenge(), 'not-a-nonce' );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 'rest_cookie_invalid_nonce', $bad->get_error_code() );

		// No cookie at all: logged out, whatever the nonce says.
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$anon = $this->proof( $this->challenge(), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( array( 'loggedIn' => false ), $anon->get_data() );
		$this->assertSame( 'no-store, private', $anon->get_headers()['Cache-Control'] );

		// No record is written for a logged-out browser.
		$this->assertSame( 0, $this->count_records() - 1 );
	}

	public function test_missing_nonce_is_treated_as_logged_out() {
		$user = $this->make_user();
		$this->log_in( $user );
		$before = $this->count_records();

		$response = $this->proof( $this->challenge(), null );
		$this->assertSame( array( 'loggedIn' => false ), $response->get_data() );
		$this->assertSame( $before, $this->count_records(), 'Nothing is stored for it.' );
	}

	private function count_records() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_samomind\\\\_ord\\\\_record\\\\_%'" ); // phpcs:ignore
	}

	/**
	 * Run the admin-ajax nonce handler and return what it printed.
	 */
	private function run_ajax_nonce() {
		$rest = new SAMOMIND_Orders_Rest( SAMOMIND()->orders );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new RuntimeException( 'die' );
				};
			}
		);
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			$rest->ajax_nonce();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		return $out;
	}

	public function test_the_nonce_endpoint_gives_a_rest_nonce_and_marks_the_page_uncacheable() {
		$out = $this->run_ajax_nonce();

		$this->assertSame( 1, preg_match( '/^\{"nonce":"([a-f0-9]{10})"\}$/', $out, $m ), $out );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'wp_rest' ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	public function test_the_nonce_endpoint_gives_nothing_when_order_status_is_off() {
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );
		$this->assertSame( '{"nonce":null}', $this->run_ajax_nonce() );
	}

	public function test_proof_replay_is_refused() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		list( $challenge ) = $this->logged_in_proof( $user );

		$this->assertTrue( $this->redeem( $challenge, $order )->get_data()['granted'] );
		$this->assertSame( array( 'granted' => false ), $this->redeem( $challenge, $order, 'other' )->get_data(), 'The same challenge cannot be redeemed twice.' );
	}

	public function test_shop_record_is_single_use_and_expires() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );

		// Expired: age the stored record.
		list( $challenge ) = $this->logged_in_proof( $user );
		global $wpdb;
		$name   = $wpdb->get_var( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_samomind\\\\_ord\\\\_record\\\\_%'" ); // phpcs:ignore
		$key    = substr( $name, strlen( '_transient_' ) );
		$record = get_transient( $key );
		$this->assertEqualsWithDelta( time() + 60, $record['exp'], 5 );
		$record['exp'] = time() - 1;
		set_transient( $key, $record, 600 );
		$this->assertSame( array( 'granted' => false ), $this->redeem( $challenge, $order )->get_data() );

		// Wrong order for the user burns the record too: a second try with the right order fails.
		$other = $this->make_order( array( 'customer_id' => $this->make_user() ) );
		list( $challenge2 ) = $this->logged_in_proof( $user );
		$this->assertFalse( $this->redeem( $challenge2, $other )->get_data()['granted'] );
		$this->assertFalse( $this->redeem( $challenge2, $order )->get_data()['granted'] );
	}

	public function test_proof_for_another_conversation_is_refused() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		list( $challenge ) = $this->logged_in_proof( $user );
		$this->redeem( $challenge, $order, 'mine' );

		$this->assertTrue( $this->lookup( $order, 'mine' )['found'] );
		$this->assertFalse( $this->lookup( $order, 'someone-elses' )['found'] );
	}

	public function test_account_grant_requires_a_shop_issued_record() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );

		// A challenge no browser ever answered.
		$this->assertSame( array( 'granted' => false ), $this->redeem( $this->challenge(), $order )->get_data() );
		$this->assertFalse( $this->lookup( $order )['found'] );

		// A guest order, an unknown order and another customer's order.
		list( $challenge ) = $this->logged_in_proof( $user );
		$guest = $this->make_order( array( 'customer_id' => 0 ) );
		$this->assertFalse( $this->redeem( $challenge, $guest )->get_data()['granted'] );
		list( $challenge ) = $this->logged_in_proof( $user );
		$theirs = $this->make_order( array( 'customer_id' => $this->make_user() ) );
		$this->assertFalse( $this->redeem( $challenge, $theirs )->get_data()['granted'] );
		list( $challenge ) = $this->logged_in_proof( $user );
		$this->assertFalse( $this->redeem( $challenge, new class() {
			public function get_order_number() {
				return '987654321';
			}
		} )->get_data()['granted'] );
	}

	public function test_logout_ends_an_account_grant() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		list( $challenge, , $token ) = $this->logged_in_proof( $user );
		$this->assertTrue( $this->redeem( $challenge, $order )->get_data()['granted'] );
		$this->assertTrue( $this->lookup( $order )['found'] );

		// The shopper logs out of the shop: the same signed lookup now finds nothing.
		WP_Session_Tokens::get_instance( $user )->destroy( $token );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order ) );

		// And the grant is deleted, not just hidden: a new login does not bring it back.
		$this->log_in( $user );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order ) );
	}

	public function test_destroyed_session_ends_an_account_grant() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		list( $challenge ) = $this->logged_in_proof( $user );
		$this->redeem( $challenge, $order );
		$this->assertTrue( $this->lookup( $order )['found'] );

		// A password change or "log out everywhere".
		WP_Session_Tokens::get_instance( $user )->destroy_all();
		$this->assertSame( array( 'found' => false ), $this->lookup( $order ) );
	}

	public function test_an_expired_login_session_ends_an_account_grant() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		list( $challenge, , $token ) = $this->logged_in_proof( $user );
		$this->redeem( $challenge, $order );

		$sessions = get_user_meta( $user, 'session_tokens', true );
		$verifier = hash( 'sha256', $token );
		$this->assertArrayHasKey( $verifier, $sessions );
		$sessions[ $verifier ]['expiration'] = time() - 1;
		update_user_meta( $user, 'session_tokens', $sessions );

		$this->assertFalse( $this->lookup( $order )['found'] );
	}

	public function test_a_site_that_cannot_expose_the_session_token_offers_no_account_proof() {
		$user = $this->make_user();
		$this->log_in( $user );
		add_filter(
			'session_token_manager',
			function () {
				return 'SAMOMIND_Other_Session_Store';
			}
		);
		$response = $this->proof( $this->challenge(), wp_create_nonce( 'wp_rest' ) );
		remove_all_filters( 'session_token_manager' );

		$this->assertSame( array( 'loggedIn' => false ), $response->get_data() );
	}
}
