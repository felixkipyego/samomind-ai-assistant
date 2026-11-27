<?php
/**
 * Unit tests for the order status pieces that need no WordPress: the
 * capability payload, the opt-in rules, signing, limits and the mail.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

class OrdersTest extends SAMOMIND_TestCase {

	/**
	 * Fake options.
	 *
	 * @var array
	 */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	private function settings() {
		return new SAMOMIND_Orders_Settings();
	}

	public function test_a_site_that_never_opted_in_sends_nothing_new() {
		$this->assertSame( array(), $this->settings()->payload_addition() );
		$this->assertArrayNotHasKey( 'samomind_orders_reported', $this->options );
	}

	public function test_connect_and_checkin_report_the_capabilities_once_opted_in_and_the_switch_off_once() {
		$settings = $this->settings();

		$this->options[ SAMOMIND_Orders_Settings::OPT_IN ] = '1';
		$payload = $settings->payload_addition();
		$this->assertSame(
			array( 'api', 'enabled', 'terms_accepted_version', 'share_items', 'share_tracking', 'tracking', 'hpos', 'woocommerce', 'mail_dispatch', 'reason', 'history' ),
			array_keys( $payload['capabilities']['orders'] )
		);
		$this->assertSame( 1, $payload['capabilities']['orders']['api'] );
		$this->assertFalse( $payload['capabilities']['orders']['enabled'], 'No WooCommerce, terms or key in this test.' );
		$this->assertFalse( $payload['capabilities']['orders']['share_items'] );
		$this->assertTrue( $payload['capabilities']['orders']['share_tracking'] );

		// Switched off again: one more report says so, then nothing.
		$this->options[ SAMOMIND_Orders_Settings::OPT_IN ] = '0';
		$this->options[ SAMOMIND_Orders_Settings::REPORTED ] = '1';
		$this->assertArrayHasKey( 'capabilities', $settings->payload_addition() );
		$this->assertSame( array(), $settings->payload_addition() );
	}

	public function test_multisite_is_refused_with_its_reason() {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->assertSame( 'multisite', $this->settings()->disabled_reason() );
		$this->assertFalse( $this->settings()->is_enabled() );
	}

	public function test_without_woocommerce_the_reason_says_so() {
		// The unit bootstrap has no WooCommerce class.
		$this->assertSame( 'woocommerce_missing', $this->settings()->disabled_reason() );
	}

	public function test_terms_record_version_user_and_time() {
		$settings = $this->settings();
		$this->assertFalse( $settings->terms_are_accepted() );

		$settings->accept_terms( 7 );
		$terms = $settings->get_terms();
		$this->assertSame( SAMOMIND_Orders_Settings::TERMS_VERSION, $terms['version'] );
		$this->assertSame( 7, $terms['user_id'] );
		$this->assertEqualsWithDelta( time(), $terms['time'], 5 );
		$this->assertTrue( $settings->terms_are_accepted() );

		// A version this plugin does not know is not accepted.
		$this->options[ SAMOMIND_Orders_Settings::TERMS ]['version'] = '2025-01';
		$this->assertFalse( $settings->terms_are_accepted() );
		$this->assertFalse( $settings->history_terms_are_accepted() );
	}

	public function test_the_terms_are_version_2026_11_26_and_the_earlier_one_still_switches_order_status_on() {
		$settings = $this->settings();
		$this->assertSame( '2026-11-26', SAMOMIND_Orders_Settings::TERMS_VERSION );
		$this->assertSame( '2026-11', SAMOMIND_Orders_Settings::PREVIOUS_TERMS_VERSION );

		$this->options[ SAMOMIND_Orders_Settings::TERMS ] = array(
			'version' => '2026-11',
			'user_id' => 3,
			'time'    => 1,
		);
		$this->assertTrue( $settings->terms_are_accepted(), 'The bump never switches order status off.' );
		$this->assertFalse( $settings->history_terms_are_accepted(), 'History needs the new terms.' );
		$this->assertTrue( $settings->needs_updated_terms() );

		$this->options[ SAMOMIND_Orders_Settings::TERMS ]['version'] = '2026-11-26';
		$this->assertTrue( $settings->terms_are_accepted() );
		$this->assertTrue( $settings->history_terms_are_accepted() );
		$this->assertFalse( $settings->needs_updated_terms() );
	}

	public function test_accepting_records_the_new_version() {
		$settings = $this->settings();
		$settings->accept_terms( 9 );
		$this->assertSame( '2026-11-26', $settings->get_terms()['version'] );
		$this->assertTrue( $settings->history_terms_are_accepted() );
	}

	public function test_history_is_reported_false_until_the_new_terms_are_accepted() {
		$settings = $this->settings();
		$this->assertFalse( $settings->capabilities()['history'] );

		// Even with the new terms the feature stays off here: this test has no WooCommerce.
		$settings->accept_terms( 1 );
		$this->assertFalse( $settings->capabilities()['history'] );
		$this->assertFalse( $settings->history_enabled() );
	}

	public function test_email_addresses_normalise_to_one_form_and_a_plus_address_is_another() {
		$this->assertSame( 'buyer@example.com', SAMOMIND_Orders_Store::normalise_email( "  Buyer@Example.COM \n" ) );
		$this->assertNotSame( SAMOMIND_Orders_Store::normalise_email( 'buyer+a@example.com' ), SAMOMIND_Orders_Store::normalise_email( 'buyer@example.com' ) );
		$this->assertSame( '', SAMOMIND_Orders_Store::normalise_email( null ) );
		$this->assertSame( '', SAMOMIND_Orders_Store::normalise_email( array( 'a@b.c' ) ) );
	}

	public function test_an_email_code_hash_ties_a_code_to_its_session_and_address() {
		$store = new SAMOMIND_Orders_Store( 'secret' );
		$base  = $store->email_code_hash( 'sessionA', 'buyer@example.com', '123456' );
		$this->assertNotSame( $base, $store->email_code_hash( 'sessionB', 'buyer@example.com', '123456' ) );
		$this->assertNotSame( $base, $store->email_code_hash( 'sessionA', 'buyer+a@example.com', '123456' ) );
		$this->assertNotSame( $base, $store->email_code_hash( 'sessionA', 'buyer@example.com', '123457' ) );
		$this->assertNotSame( $base, ( new SAMOMIND_Orders_Store( 'other' ) )->email_code_hash( 'sessionA', 'buyer@example.com', '123456' ) );
		$this->assertNotSame( $base, $store->code_hash( 'sessionA', 0, '123456' ), 'Never the same as a one-order code.' );
		$this->assertStringNotContainsString( '123456', $base );
		$this->assertStringNotContainsString( 'buyer', $base );
	}

	public function test_sharing_defaults() {
		$settings = $this->settings();
		$this->assertTrue( $settings->shares_tracking() );
		$this->assertFalse( $settings->shares_items() );
	}

	public function test_the_orders_key_is_not_the_ingest_key() {
		$this->options['samomind_api_secret'] = 'smik_ingest';
		$this->assertSame( '', $this->settings()->get_secret() );
		$this->options[ SAMOMIND_Orders_Settings::SECRET ] = 'smok_orders';
		$this->assertSame( 'smok_orders', $this->settings()->get_secret() );
	}

	public function test_canonical_message_has_the_orders_label_and_the_path() {
		$message = SAMOMIND_Orders_Auth::canonical( '1731412345', 'kid', 'NONCE', '/wp-json/samomind/v1/orders/start', '{"a":1}' );
		$this->assertSame( "samomind-orders-v1\n1731412345\nkid\nNONCE\nPOST /wp-json/samomind/v1/orders/start\n{\"a\":1}", $message );
	}

	public function test_the_ingest_message_and_the_orders_message_never_match() {
		$orders = SAMOMIND_Orders_Auth::canonical( '1', 'k', 'n', '/p', '' );
		$ingest = SAMOMIND_Client::canonical_string( '1', 'k', 'n', 'POST', '/p', '' );
		$this->assertNotSame( $orders, $ingest );
		$this->assertNotSame( SAMOMIND_Client::sign( 'same-secret', $ingest ), SAMOMIND_Orders_Auth::sign( 'same-secret', $orders ) );
	}

	public function test_response_message_is_bound_to_the_request_nonce_status_and_body() {
		$a = SAMOMIND_Orders_Auth::sign_response( 's', 'N1', 200, '{"x":1}' );
		$this->assertNotSame( $a, SAMOMIND_Orders_Auth::sign_response( 's', 'N2', 200, '{"x":1}' ) );
		$this->assertNotSame( $a, SAMOMIND_Orders_Auth::sign_response( 's', 'N1', 202, '{"x":1}' ) );
		$this->assertNotSame( $a, SAMOMIND_Orders_Auth::sign_response( 's', 'N1', 200, '{"x":2}' ) );
		$this->assertMatchesRegularExpression( '/^v1=[0-9a-f]{64}$/', $a );
	}

	public function test_the_code_hash_ties_a_code_to_its_session_and_order() {
		$store = new SAMOMIND_Orders_Store( 'secret' );
		$base  = $store->code_hash( 'sessionA', 5, '123456' );
		$this->assertNotSame( $base, $store->code_hash( 'sessionB', 5, '123456' ) );
		$this->assertNotSame( $base, $store->code_hash( 'sessionA', 6, '123456' ) );
		$this->assertNotSame( $base, $store->code_hash( 'sessionA', 5, '123457' ) );
		$this->assertNotSame( $base, ( new SAMOMIND_Orders_Store( 'other' ) )->code_hash( 'sessionA', 5, '123456' ) );
		$this->assertStringNotContainsString( '123456', $base );
	}

	public function test_tracking_text_is_cleaned() {
		$this->assertSame( 'Hello world', SAMOMIND_Orders_Lookup::clean_text( "  <b>Hello</b>\n\t world\u{202E}  ", 50 ) );
		$this->assertSame( 'abc', SAMOMIND_Orders_Lookup::clean_text( 'abcdef', 3 ) );
		$this->assertSame( 'Tom & Jerry', SAMOMIND_Orders_Lookup::clean_text( 'Tom &amp; Jerry', 50 ) );
	}

	public function test_mail_is_english_and_holds_only_the_code() {
		Functions\when( 'get_bloginfo' )->justReturn( 'Mug Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
		$mail = new SAMOMIND_Orders_Mail( new SAMOMIND_Orders_Store( 's' ) );

		$this->assertSame( 'Your order check code for Mug Shop', $mail->subject() );
		$this->assertStringContainsString( 'Your code is 042517', $mail->body( '042517' ) );
		$this->assertStringContainsString( 'ignore this email', $mail->body( '042517' ) );
	}

	public function test_mail_sender_is_the_woocommerce_one_and_cannot_inject_headers() {
		Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
		$mail = new SAMOMIND_Orders_Mail( new SAMOMIND_Orders_Store( 's' ) );

		$this->options['woocommerce_email_from_name']    = "Shop\r\nBcc: spy@example.com";
		$this->options['woocommerce_email_from_address'] = 'orders@shop.example';
		$headers = $mail->headers();
		$this->assertSame( 'Content-Type: text/plain; charset=UTF-8', $headers[0] );
		$this->assertCount( 2, $headers );
		$this->assertStringNotContainsString( "\n", $headers[1] );
		$this->assertStringNotContainsString( "\r", $headers[1] );
		$this->assertStringEndsWith( '<orders@shop.example>', $headers[1] );

		$this->options['woocommerce_email_from_address'] = 'not an address';
		$this->assertCount( 1, $mail->headers(), 'No usable sender: leave it to WordPress.' );
	}

	public function test_the_cron_fallback_is_reported_when_nothing_can_flush() {
		Filters\expectApplied( 'SAMOMIND/orders/finish_function' )->andReturn( null );
		$this->assertSame( 'cron_fallback', SAMOMIND_Orders_Mail::dispatch_mode() );
	}

	/**
	 * Order fields as lookup builds them: n items with a name and an image address.
	 */
	private function fields( $count, $name, $image = null, array $extra = array() ) {
		$items = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = array(
				'name'      => $name,
				'qty'       => 1,
				'image_url' => $image,
			);
		}
		return array_merge(
			array(
				'order_number' => 'SM-1234',
				'status'       => 'other',
				'status_label' => str_repeat( "\u{1F600}", 40 ),
				'created'      => '2026-11-08',
				'paid'         => '2026-11-08',
				'shipped'      => '2026-11-10',
				'completed'    => null,
				'items'        => $items,
				'tracking'     => array(
					'carrier' => str_repeat( "\u{1F600}", 80 ),
					'number'  => str_repeat( "\u{1F600}", 80 ),
					'url'     => 'https://t.example/' . str_repeat( 'a', 2030 ),
				),
				'total'        => str_repeat( '9', 32 ),
			),
			$extra
		);
	}

	private function body_size( array $fields ) {
		return strlen( json_encode( array( 'found' => true, 'order' => $fields ) ) );
	}

	public function test_the_worst_lookup_answer_fits_under_the_limit_with_names_shortened_and_nothing_dropped() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$emoji = str_repeat( "\u{1F600}", 200 );
		$image = 'https://shop.example/' . str_repeat( 'a', 2048 - 21 );

		foreach ( array( 'version 2, with images' => $image, 'version 1, without' => null ) as $label => $url ) {
			$fields = $this->fields( 50, $emoji, $url );
			if ( null === $url ) {
				foreach ( $fields['items'] as $i => $item ) {
					unset( $fields['items'][ $i ]['image_url'] );
				}
				unset( $fields['total'] );
			}
			$this->assertGreaterThan( 100000, $this->body_size( $fields ), $label . ' starts far over the limit.' );

			$fitted = ( new SAMOMIND_Orders_Lookup() )->fit_size( $fields );

			$this->assertLessThanOrEqual( SAMOMIND_Orders_Lookup::MAX_ANSWER_BYTES, $this->body_size( $fitted ), $label );
			$this->assertCount( 50, $fitted['items'], $label . ': no item is dropped.' );
			foreach ( $fitted['items'] as $item ) {
				$this->assertSame( '…', mb_substr( $item['name'], -1 ), $label . ': a shortened name ends with an ellipsis.' );
				$this->assertSame( 0, strpos( $item['name'], "\u{1F600}" ), $label . ': the start is kept.' );
				$this->assertGreaterThanOrEqual( 2, mb_strlen( $item['name'] ) );
			}
			$this->assertSame( $fields['tracking'], $fitted['tracking'], $label . ': tracking is never cut.' );
			$this->assertSame( $fields['status_label'], $fitted['status_label'], $label );
		}
	}

	public function test_names_are_shortened_longest_first_and_short_ones_stay() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$fields                       = $this->fields( 50, str_repeat( "\u{1F600}", 200 ) );
		$fields['items'][7]['name']   = 'Blue mug';
		$fields['items'][8]['name']   = str_repeat( "\u{1F600}", 20 );

		$fitted = ( new SAMOMIND_Orders_Lookup() )->fit_size( $fields );

		$this->assertLessThanOrEqual( SAMOMIND_Orders_Lookup::MAX_ANSWER_BYTES, $this->body_size( $fitted ) );
		$this->assertSame( 'Blue mug', $fitted['items'][7]['name'] );
		$this->assertSame( $fields['items'][8]['name'], $fitted['items'][8]['name'] );
	}

	public function test_images_go_before_names_and_from_the_last_item_back() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$image  = 'https://shop.example/' . str_repeat( 'a', 2048 - 21 );
		$fields = $this->fields( 50, 'A name of ordinary length', $image );

		$fitted = ( new SAMOMIND_Orders_Lookup() )->fit_size( $fields );

		$this->assertLessThanOrEqual( SAMOMIND_Orders_Lookup::MAX_ANSWER_BYTES, $this->body_size( $fitted ) );
		$this->assertSame( array_column( $fields['items'], 'name' ), array_column( $fitted['items'], 'name' ), 'No name is touched while dropping images is enough.' );
		$seen_null = false;
		foreach ( $fitted['items'] as $item ) {
			if ( null === $item['image_url'] ) {
				$seen_null = true;
			} else {
				$this->assertFalse( $seen_null );
			}
		}
		$this->assertTrue( $seen_null );
	}

	public function test_an_answer_that_already_fits_is_returned_unchanged() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$fields = $this->fields( 3, str_repeat( "\u{1F600}", 200 ), 'https://shop.example/a.jpg' );

		$this->assertSame( $fields, ( new SAMOMIND_Orders_Lookup() )->fit_size( $fields ) );
	}
}
