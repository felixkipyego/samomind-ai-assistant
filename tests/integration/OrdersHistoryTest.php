<?php
/**
 * Order history (0.4.1, SamoMind docs/design/F-3-order-status.md section 15):
 * the email scope on start and verify, list, lookup with an email or customer
 * grant, the totals and item images, and the rules that keep one customer's
 * orders from another. With HPOS on or off.
 *
 * @package SAMOMIND
 */

class OrdersHistoryTest extends SAMOMIND_Integration_TestCase {

	const EMAIL = 'buyer@example.com';

	/**
	 * Image address by attachment id, for the image_downsize filter.
	 *
	 * @var array
	 */
	private $images = array();

	protected function setUp(): void {
		parent::setUp();
		$this->accept_history_terms();
		$this->images = array();
		add_filter( 'image_downsize', array( $this, 'image_for' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'image_downsize', array( $this, 'image_for' ), 10 );
		parent::tearDown();
	}

	public function image_for( $out, $id ) {
		return isset( $this->images[ $id ] ) ? array( $this->images[ $id ], 300, 300, true ) : $out;
	}

	// ---- Helpers --------------------------------------------------------------

	private function start_body( $email, $session = 'a', array $extra = array() ) {
		return array_merge(
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( $session ),
				'scope'       => 'email',
				'email'       => $email,
				'locale'      => 'en',
			),
			$extra
		);
	}

	private function start( $email, $session = 'a', array $extra = array() ) {
		$response = $this->call( 'start', $this->start_body( $email, $session, $extra ) );
		$this->run_plugin_shutdown();
		return $response;
	}

	private function verify( $email, $code, $session = 'a' ) {
		return $this->call(
			'verify',
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( $session ),
				'scope'       => 'email',
				'email'       => $email,
				'code'        => $code,
			)
		);
	}

	/**
	 * Prove an email address for a session: start, read the emailed code, verify.
	 */
	private function grant_email( $email = self::EMAIL, $session = 'a' ) {
		$before = count( $this->mails );
		$this->assertSame( 202, $this->start( $email, $session )->get_status() );
		$this->assertCount( $before + 1, $this->mails, 'A code is emailed.' );
		$response = $this->verify( $email, $this->emailed_code(), $session );
		$this->assertTrue( $response->get_data()['verified'] );
	}

	private function list_orders( $session = 'a', $limit = 20 ) {
		return $this->call(
			'list',
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( $session ),
				'limit'       => $limit,
			)
		);
	}

	private function lookup( $order, $session = 'a' ) {
		return $this->call( 'lookup', $this->lookup_body( $order->get_order_number(), $session ) );
	}

	private function numbers( array $list ) {
		return array_column( $list['orders'], 'order_number' );
	}

	/**
	 * Prove a signed-in customer for a session, through the account proof as a browser does.
	 */
	private function grant_customer( $user, WC_Order $order, $session = 'a' ) {
		$this->log_in( $user );
		$challenge = $this->challenge();
		$this->assertTrue( $this->proof( $challenge, wp_create_nonce( 'wp_rest' ) )->get_data()['loggedIn'] );
		$response = $this->call(
			'account/redeem',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref( $session ),
				'challenge'    => $challenge,
				'order_number' => (string) $order->get_order_number(),
			)
		);
		$this->assertTrue( $response->get_data()['granted'] );
		wp_set_current_user( 0 );
	}

	// ---- Terms and capabilities ------------------------------------------------

	public function test_history_is_reported_only_with_the_new_terms() {
		$this->assertTrue( SAMOMIND()->orders->capabilities()['history'] );
		$this->assertSame( '2026-11-26', SAMOMIND()->orders->capabilities()['terms_accepted_version'] );

		$ping = $this->call( 'ping' )->get_data();
		$this->assertTrue( $ping['capabilities']['orders']['history'] );

		update_option( SAMOMIND_Orders_Settings::TERMS, array( 'version' => '2026-11', 'user_id' => 1, 'time' => time() ), false );
		$this->assertFalse( SAMOMIND()->orders->capabilities()['history'] );
		$this->assertTrue( SAMOMIND()->orders->capabilities()['enabled'], 'Order status itself stays on with the earlier terms.' );
		$this->assertFalse( $this->call( 'ping' )->get_data()['capabilities']['orders']['history'] );

		$addition = SAMOMIND()->orders->payload_addition();
		$this->assertFalse( $addition['capabilities']['orders']['history'], 'The check-in says it too.' );
	}

	public function test_the_earlier_terms_keep_order_status_working_and_history_off() {
		update_option( SAMOMIND_Orders_Settings::TERMS, array( 'version' => '2026-11', 'user_id' => 1, 'time' => time() ), false );
		$order = $this->make_order( array( 'billing_email' => self::EMAIL ) );

		// One order, one code, as in 0.4.0.
		$code = $this->start_and_get_code( $order );
		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'] );
		$found = $this->lookup( $order )->get_data();
		$this->assertTrue( $found['found'] );
		$this->assertArrayNotHasKey( 'total', $found['order'], 'A version 1 answer has no total.' );

		// History is refused: start and verify with the email scope, and list.
		$mails = count( $this->mails );
		$response = $this->start( self::EMAIL, 'b' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'code' => 'not_enabled' ), $response->get_data() );
		$this->assertCount( $mails, $this->mails );
		$this->assertSame( 403, $this->verify( self::EMAIL, '123456', 'b' )->get_status() );

		// Even a grant row that should not exist (as an older state might leave) opens nothing.
		( new SAMOMIND_Orders_Store( self::SECRET ) )->put_email_grant( $this->session_ref( 'c' ), self::EMAIL, 600 );
		$this->assertSame( array( 'granted' => false ), $this->list_orders( 'c' )->get_data() );
		$other = $this->make_order( array( 'billing_email' => self::EMAIL ) );
		$this->assertSame( array( 'found' => false ), $this->lookup( $other, 'c' )->get_data() );
	}

	public function test_accepting_the_new_terms_turns_history_on_without_touching_the_keys() {
		update_option( SAMOMIND_Orders_Settings::TERMS, array( 'version' => '2026-11', 'user_id' => 1, 'time' => time() ), false );
		$this->assertTrue( SAMOMIND()->orders->needs_updated_terms() );

		SAMOMIND()->orders->accept_terms( 4 );

		$this->assertTrue( SAMOMIND()->orders->history_enabled() );
		$this->assertSame( self::SECRET, SAMOMIND()->orders->get_secret() );
		$this->assertTrue( SAMOMIND()->orders->is_opted_in() );
	}

	// ---- Start ---------------------------------------------------------------

	public function test_start_for_an_email_with_orders_emails_a_code_to_the_normalised_address() {
		$this->make_order( array( 'billing_email' => 'Buyer@Example.com' ) );

		$response = $this->start( "  BUYER@example.COM \n" );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'buyer@example.com', $this->mails[0]['to'] );
		$this->assertCount( 1, $this->state_rows( 'code' ) );
		$this->assertStringNotContainsString( self::EMAIL, wp_json_encode( $this->state_rows( 'code' ) ), 'The address is not part of the code row.' );
	}

	public function test_the_answer_to_start_is_identical_with_and_without_orders_and_when_capped() {
		SAMOMIND_Orders_Rest::$floor_ms = 250;
		$this->make_order( array( 'billing_email' => self::EMAIL ) );
		$trashed = $this->make_order( array( 'billing_email' => 'gone@example.com' ) );
		$trashed->delete( false );

		$answers = array();
		$timings = array();
		foreach ( array(
			'has orders'       => self::EMAIL,
			'no orders'        => 'nobody-ever@example.com',
			'only a trashed'   => 'gone@example.com',
			'not an address'   => 'not an address',
			'empty'            => '',
		) as $name => $email ) {
			$started          = microtime( true );
			$response         = $this->start( $email, 's-' . $name );
			$timings[ $name ] = microtime( true ) - $started;
			$answers[ $name ] = array( $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		$this->assertCount( 1, $this->mails, 'Only the address with an order got a code.' );

		// The same address once its own cap is used up: three more sessions, then a fourth.
		$this->start( self::EMAIL, 'cap-1' );
		$this->start( self::EMAIL, 'cap-2' );
		$this->assertCount( 3, $this->mails );
		$started                = microtime( true );
		$response               = $this->start( self::EMAIL, 'cap-3' );
		$timings['email capped'] = microtime( true ) - $started;
		$answers['email capped'] = array( $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 3, $this->mails, 'The fourth code is not sent.' );

		foreach ( $answers as $name => $answer ) {
			$this->assertSame( $answers['has orders'], $answer, "$name answers exactly like an address with orders." );
		}
		$this->assertSame( '{"accepted":true}', $answers['no orders'][1] );
		foreach ( $timings as $name => $seconds ) {
			$this->assertGreaterThanOrEqual( 0.24, $seconds, "$name waits for the floor." );
		}
	}

	public function test_a_per_email_cap_never_sets_mail_capped_and_the_shop_cap_still_does() {
		$this->make_order( array( 'billing_email' => self::EMAIL ) );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->start( self::EMAIL, 'e' . $i );
		}
		$this->assertCount( 3, $this->mails );

		$capped = $this->start( self::EMAIL, 'e4' );
		$this->assertArrayNotHasKey( 'mail_capped', $capped->get_data() );

		// The shop-wide cap: every start alike says so, with or without orders.
		$store = new SAMOMIND_Orders_Store( self::SECRET );
		for ( $i = 0; $i < 20; $i++ ) {
			$store->allow_shop_email( 20 );
		}
		foreach ( array( self::EMAIL, 'nobody@example.com' ) as $email ) {
			$response = $this->start( $email, 'shop-' . $email );
			$this->assertSame( 202, $response->get_status() );
			$this->assertSame( array( 'accepted' => true, 'mail_capped' => true ), $response->get_data(), $email );
		}
	}

	public function test_the_per_email_cap_counts_sent_mails_only() {
		// Starts that send nothing do not count: no orders, an invalid address, a resend inside the gap.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->start( 'nobody@example.com', 'n' . $i );
		}
		$this->start( 'not an address', 'bad' );
		$this->assertCount( 0, $this->state_rows( 'omail' ) );

		$this->make_order( array( 'billing_email' => self::EMAIL ) );
		$this->start( self::EMAIL, 'a' );
		$this->start( self::EMAIL, 'a' );
		$this->assertCount( 1, $this->mails, 'The second start of one session is inside the gap.' );
		$rows = $this->state_rows( 'omail' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, (int) $rows[0]['n'], 'One mail sent, one counted.' );

		$this->start( self::EMAIL, 'b' );
		$this->start( self::EMAIL, 'c' );
		$this->start( self::EMAIL, 'd' );
		$this->assertCount( 3, $this->mails );
		$this->assertSame( 3, (int) $this->state_rows( 'omail' )[0]['n'] );
		$this->assertSame( 3, (int) $this->state_rows( 'hour' )[0]['n'], 'The shop counts the three sent.' );
	}

	public function test_a_plus_address_is_a_different_email_with_its_own_cap_and_no_orders() {
		$this->make_order( array( 'billing_email' => self::EMAIL ) );

		$this->start( 'buyer+tag@example.com', 'p' );
		$this->assertCount( 0, $this->mails, 'The plus-address has no orders.' );

		$this->start( self::EMAIL, 'q' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_start_with_an_unknown_scope_is_a_bad_request() {
		$response = $this->start( self::EMAIL, 'a', array( 'scope' => 'phone' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->mails );
	}

	public function test_orders_in_excluded_statuses_do_not_make_an_email_known() {
		$order = $this->make_order( array( 'billing_email' => 'draft@example.com', 'status' => 'pending' ) );
		$this->start( 'draft@example.com', 'a' );
		$this->assertCount( 1, $this->mails, 'A pending order counts.' );

		$order->set_status( 'checkout-draft' );
		$order->save();
		$this->start( 'draft@example.com', 'b' );
		$this->assertCount( 1, $this->mails, 'A checkout draft does not.' );

		$order->set_status( 'processing' );
		$order->save();
		$order->delete( false );
		$this->start( 'draft@example.com', 'c' );
		$this->assertCount( 1, $this->mails, 'A trashed order does not.' );
	}

	// ---- Verify and list -------------------------------------------------------

	public function test_a_right_code_opens_the_orders_of_that_email_newest_first() {
		$old    = $this->make_order( array( 'date' => strtotime( '2026-01-05 10:00:00' ) ) );
		$newest = $this->make_order( array( 'date' => strtotime( '2026-03-05 10:00:00' ) ) );
		$middle = $this->make_order( array( 'date' => strtotime( '2026-02-05 10:00:00' ) ) );
		$this->make_order( array( 'billing_email' => 'other@example.com', 'date' => strtotime( '2026-04-05 10:00:00' ) ) );

		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data(), 'Nothing before the code.' );
		$this->grant_email();

		$response = $this->list_orders();
		$list     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $list['granted'] );
		$this->assertFalse( $list['more'] );
		$this->assertSame(
			array( (string) $newest->get_order_number(), (string) $middle->get_order_number(), (string) $old->get_order_number() ),
			$this->numbers( $list )
		);
	}

	public function test_a_wrong_or_replayed_code_gives_no_grant() {
		$this->make_order();
		$this->start( self::EMAIL );
		$code = $this->emailed_code();

		$this->assertSame( array( 'verified' => false ), $this->verify( self::EMAIL, '000000' === $code ? '000001' : '000000' )->get_data() );
		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );

		$this->assertTrue( $this->verify( self::EMAIL, $code )->get_data()['verified'] );
		$this->assertSame( array( 'verified' => false ), $this->verify( self::EMAIL, $code )->get_data(), 'A code works once.' );
	}

	public function test_a_code_is_for_one_email_and_one_session() {
		$this->make_order();
		$this->make_order( array( 'billing_email' => 'other@example.com' ) );
		$this->start( self::EMAIL, 'a' );
		$code = $this->emailed_code();

		$this->assertFalse( $this->verify( 'other@example.com', $code, 'a' )->get_data()['verified'] );
		$this->assertFalse( $this->verify( self::EMAIL, $code, 'b' )->get_data()['verified'] );
		$this->assertTrue( $this->verify( self::EMAIL, $code, 'a' )->get_data()['verified'] );
	}

	public function test_the_code_of_one_order_does_not_open_an_email() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );

		$this->assertFalse( $this->verify( self::EMAIL, $code )->get_data()['verified'] );
	}

	public function test_a_verify_for_an_email_works_with_case_and_spaces_changed() {
		$this->make_order();
		$this->start( self::EMAIL );

		$this->assertTrue( $this->verify( '  Buyer@EXAMPLE.com ', $this->emailed_code() )->get_data()['verified'] );
	}

	public function test_an_email_grant_never_returns_another_emails_orders() {
		$mine   = $this->make_order( array( 'billing_email' => self::EMAIL ) );
		$mixed  = $this->make_order( array( 'billing_email' => 'Buyer@Example.COM' ) );
		$other  = $this->make_order( array( 'billing_email' => 'other@example.com' ) );
		$plus   = $this->make_order( array( 'billing_email' => 'buyer+shoes@example.com' ) );
		$domain = $this->make_order( array( 'billing_email' => 'buyer@example.com.au' ) );
		$this->grant_email( '  BUYER@Example.com ' );

		$list = $this->list_orders()->get_data();
		$this->assertEqualsCanonicalizing( array( (string) $mine->get_order_number(), (string) $mixed->get_order_number() ), $this->numbers( $list ) );

		$this->assertTrue( $this->lookup( $mine )->get_data()['found'] );
		$this->assertTrue( $this->lookup( $mixed )->get_data()['found'], 'Case and spaces make the same email.' );
		foreach ( array( 'another address' => $other, 'a plus-address' => $plus, 'a longer domain' => $domain ) as $name => $order ) {
			$this->assertSame( array( 'found' => false ), $this->lookup( $order )->get_data(), "$name is not covered." );
		}
		$this->assertSame( array( 'found' => false ), $this->call( 'lookup', $this->lookup_body( '99999999' ) )->get_data() );
	}

	public function test_another_session_has_no_access() {
		$this->make_order();
		$this->grant_email( self::EMAIL, 'a' );

		$this->assertTrue( $this->list_orders( 'a' )->get_data()['granted'] );
		$this->assertSame( array( 'granted' => false ), $this->list_orders( 'b' )->get_data() );
	}

	public function test_an_expired_grant_answers_granted_false_and_found_false() {
		$order = $this->make_order();
		$this->grant_email();
		$this->assertTrue( $this->list_orders()->get_data()['granted'] );

		$this->age_state( 'egrant', 3 * HOUR_IN_SECONDS );

		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order )->get_data() );
	}

	public function test_the_grant_lasts_as_long_as_the_limits_say_and_never_moves() {
		$this->make_order();
		$this->start( self::EMAIL, 'a' );
		$response = $this->call(
			'verify',
			array(
				'request_id'  => wp_generate_uuid4(),
				'session_ref' => $this->session_ref( 'a' ),
				'scope'       => 'email',
				'email'       => self::EMAIL,
				'code'        => $this->emailed_code(),
				'limits'      => array( 'grant_minutes' => 5 ),
			)
		);
		$this->assertSame( 300, $response->get_data()['grant_expires_in'] );

		$rows = $this->state_rows( 'egrant' );
		$this->assertCount( 1, $rows );
		$this->assertEqualsWithDelta( time() + 300, (int) $rows[0]['expires'], 3 );
		$this->list_orders();
		$this->assertSame( (int) $rows[0]['expires'], (int) $this->state_rows( 'egrant' )[0]['expires'], 'Using it does not extend it.' );
	}

	public function test_the_limit_is_clamped_and_more_says_whether_there_are_further_orders() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->make_order( array( 'date' => strtotime( "2026-02-0{$i} 10:00:00" ) ) );
		}
		$this->grant_email();

		$one = $this->list_orders( 'a', 1 )->get_data();
		$this->assertCount( 1, $one['orders'] );
		$this->assertTrue( $one['more'] );

		foreach ( array( 0, -7, 'abc', null ) as $limit ) {
			$clamped = $this->list_orders( 'a', $limit )->get_data();
			$this->assertGreaterThanOrEqual( 1, count( $clamped['orders'] ), 'A limit of ' . wp_json_encode( $limit ) );
		}
		$this->assertCount( 1, $this->list_orders( 'a', 0 )->get_data()['orders'], 'Zero becomes one.' );
		$this->assertCount( 1, $this->list_orders( 'a', -7 )->get_data()['orders'], 'A negative limit becomes one.' );

		$all = $this->list_orders( 'a', 1000 )->get_data();
		$this->assertCount( 3, $all['orders'] );
		$this->assertFalse( $all['more'] );

		$exact = $this->list_orders( 'a', 3 )->get_data();
		$this->assertFalse( $exact['more'], 'Exactly as many as asked for is not more.' );
		$this->assertTrue( $this->list_orders( 'a', 2 )->get_data()['more'] );
	}

	public function test_a_list_of_fifty_stays_far_under_the_size_the_server_accepts() {
		for ( $i = 0; $i < 52; $i++ ) {
			$this->make_order( array( 'items' => array( 'Item ' . $i => 1 ), 'date' => strtotime( '2026-01-01 00:00:00' ) + $i * 60 ) );
		}
		$this->grant_email();

		$response = $this->list_orders( 'a', 1000 );
		$data     = $response->get_data();
		$this->assertCount( 50, $data['orders'], 'The most that can be asked for.' );
		$this->assertTrue( $data['more'] );
		$this->assertLessThan( 60000, strlen( (string) wp_json_encode( $data ) ), 'Under the 64 KB the server accepts, with margin.' );
	}

	public function test_excluded_statuses_are_never_listed_or_looked_up() {
		$visible = $this->make_order();
		$draft   = $this->make_order( array( 'status' => 'pending' ) );
		$draft->set_status( 'checkout-draft' );
		$draft->save();
		$trashed = $this->make_order();
		$trashed->delete( false );
		$this->grant_email( self::EMAIL );

		$this->assertSame( array( (string) $visible->get_order_number() ), $this->numbers( $this->list_orders()->get_data() ) );
		$this->assertSame( array( 'found' => false ), $this->lookup( $draft )->get_data() );
		$this->assertSame( array( 'found' => false ), $this->lookup( $trashed )->get_data() );
	}

	public function test_every_status_a_customer_can_have_is_listed_with_its_label() {
		$expected = array(
			'pending'    => 'Pending payment',
			'on-hold'    => 'On hold',
			'processing' => 'Processing',
			'completed'  => 'Completed',
			'cancelled'  => 'Cancelled',
			'refunded'   => 'Refunded',
			'failed'     => 'Failed',
		);
		foreach ( $expected as $status => $label ) {
			$this->make_order( array( 'status' => $status ) );
		}
		$this->grant_email();

		$rows = array_column( $this->list_orders()->get_data()['orders'], 'status_label', 'status' );
		$this->assertEquals( $expected, array_intersect_key( $rows, $expected ) );
		$this->assertCount( 7, $rows );
	}

	// ---- What a row and a lookup hold ------------------------------------------

	public function test_a_list_row_holds_exactly_the_listed_fields_and_nothing_private() {
		$this->make_order( array( 'items' => array( 'Blue mug' => 2, 'Red mug' => 3 ), 'total' => 129, 'currency' => 'USD' ) );
		$this->grant_email();

		$list = $this->list_orders()->get_data();
		$row  = $list['orders'][0];

		$this->assertSame( array( 'granted', 'orders', 'more' ), array_keys( $list ) );
		$this->assertSame( array( 'order_number', 'status', 'status_label', 'created', 'paid', 'shipped', 'completed', 'total', 'item_count' ), array_keys( $row ) );
		$this->assertSame( 5, $row['item_count'], 'The sum of the quantities.' );
		$this->assertSame( '$129.00', $row['total'] );
		$this->assertLessThanOrEqual( 32, strlen( $row['total'] ) );
		$this->assertSame( 'processing', $row['status'] );

		$this->assertNoPrivateData( wp_json_encode( $list ) );
	}

	public function test_a_lookup_holds_the_total_and_nothing_private() {
		$order = $this->make_order( array( 'items' => array( 'Blue mug' => 2 ), 'total' => 129 ) );
		$this->grant_email();

		$found = $this->lookup( $order )->get_data();

		$this->assertTrue( $found['found'] );
		$this->assertSame( array( 'order_number', 'status', 'status_label', 'created', 'paid', 'shipped', 'completed', 'items', 'tracking', 'total' ), array_keys( $found['order'] ) );
		$this->assertSame( '$129.00', $found['order']['total'] );
		$this->assertSame( array( 'name', 'qty', 'image_url' ), array_keys( $found['order']['items'][0] ) );
		$this->assertNull( $found['order']['items'][0]['image_url'] );
		$this->assertNoPrivateData( wp_json_encode( $found ) );
	}

	public function test_a_lookup_without_shared_items_has_no_items_and_no_images() {
		$order = $this->make_order();
		$this->grant_email();

		$found = $this->call( 'lookup', $this->lookup_body( $order->get_order_number(), 'a', array( 'items' => false, 'tracking' => false ) ) )->get_data();

		$this->assertArrayNotHasKey( 'items', $found['order'] );
		$this->assertArrayHasKey( 'total', $found['order'] );
		update_option( SAMOMIND_Orders_Settings::SHARE_ITEMS, '0', false );
		$this->assertArrayNotHasKey( 'items', $this->lookup( $order )->get_data()['order'], 'The shop\'s own choice wins.' );
	}

	public function test_the_total_is_in_the_orders_own_currency() {
		$order = $this->make_order( array( 'total' => 1500, 'currency' => 'KES' ) );
		$this->grant_email();

		$total = $this->list_orders()->get_data()['orders'][0]['total'];
		$this->assertStringContainsString( '1,500.00', $total );
		$this->assertStringNotContainsString( '<', $total );
		$this->assertStringNotContainsString( '&', $total );
		$this->assertSame( $total, $this->lookup( $order )->get_data()['order']['total'] );
		$this->assertLessThanOrEqual( 32, mb_strlen( $total ) );
	}

	private function assertNoPrivateData( $json ) {
		foreach ( array( 'buyer@example.com', 'Private', 'Person', '+254700000000', 'Secret Street', 'Hidden Road', 'Shipville', 'Leave it with the neighbour', 'Card ending', '203.0.113.9' ) as $secret ) {
			$this->assertStringNotContainsStringIgnoringCase( $secret, $json, "The answer leaks $secret." );
		}
		foreach ( array( 'billing', 'shipping', 'phone', 'email', 'payment', 'note', 'address' ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/"[a-z_]*' . $key . '[a-z_]*":/i', $json, "A key about $key is in the answer." );
		}
	}

	// ---- Customer grant -------------------------------------------------------

	public function test_a_customer_grant_opens_every_order_of_that_customer_and_no_one_elses() {
		$user    = $this->make_user();
		$other   = $this->make_user();
		$first   = $this->make_order( array( 'customer_id' => $user, 'billing_email' => 'one@example.com', 'date' => strtotime( '2026-01-01 10:00:00' ) ) );
		$second  = $this->make_order( array( 'customer_id' => $user, 'billing_email' => 'two@example.com', 'date' => strtotime( '2026-02-01 10:00:00' ) ) );
		$theirs  = $this->make_order( array( 'customer_id' => $other, 'billing_email' => 'one@example.com' ) );
		$guest   = $this->make_order( array( 'billing_email' => 'one@example.com' ) );

		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );
		$this->grant_customer( $user, $first );

		$list = $this->list_orders()->get_data();
		$this->assertSame( array( (string) $second->get_order_number(), (string) $first->get_order_number() ), $this->numbers( $list ) );
		$this->assertTrue( $this->lookup( $second )->get_data()['found'], 'Any order of the customer, not only the one used to sign in.' );
		$this->assertSame( array( 'found' => false ), $this->lookup( $theirs )->get_data(), 'Another customer\'s order, though it has the same billing email.' );
		$this->assertSame( array( 'found' => false ), $this->lookup( $guest )->get_data() );
	}

	public function test_a_customer_grant_ends_when_the_login_does() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$this->grant_customer( $user, $order );
		$this->assertTrue( $this->list_orders()->get_data()['granted'] );

		WP_Session_Tokens::get_instance( $user )->destroy_all();

		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order )->get_data() );
		$this->assertCount( 0, $this->state_rows( 'cgrant' ), 'The dead grant is removed.' );
	}

	public function test_a_customer_grant_is_not_made_when_history_is_off() {
		update_option( SAMOMIND_Orders_Settings::TERMS, array( 'version' => '2026-11', 'user_id' => 1, 'time' => time() ), false );
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );

		$this->grant_customer( $user, $order );

		$this->assertCount( 0, $this->state_rows( 'cgrant' ) );
		$this->assertTrue( $this->lookup( $order )->get_data()['found'], 'The one order still works, as in version 1.' );
		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );
	}

	public function test_an_email_and_a_customer_grant_in_one_session_list_both_once() {
		$user   = $this->make_user();
		$signed = $this->make_order( array( 'customer_id' => $user, 'billing_email' => 'account@example.com', 'date' => strtotime( '2026-01-01 10:00:00' ) ) );
		$both   = $this->make_order( array( 'customer_id' => $user, 'billing_email' => self::EMAIL, 'date' => strtotime( '2026-02-01 10:00:00' ) ) );
		$guest  = $this->make_order( array( 'billing_email' => self::EMAIL, 'date' => strtotime( '2026-03-01 10:00:00' ) ) );
		$this->grant_customer( $user, $signed );
		$this->grant_email();

		$numbers = $this->numbers( $this->list_orders()->get_data() );

		$this->assertSame( array( (string) $guest->get_order_number(), (string) $both->get_order_number(), (string) $signed->get_order_number() ), $numbers );
	}

	// ---- Redeem for the whole account ------------------------------------------

	private function redeem_account( $session, $challenge, array $extra = array() ) {
		return $this->call(
			'account/redeem',
			array_merge(
				array(
					'request_id'  => wp_generate_uuid4(),
					'session_ref' => $this->session_ref( $session ),
					'scope'       => 'account',
					'challenge'   => $challenge,
				),
				$extra
			)
		);
	}

	private function fresh_proof( $user ) {
		$this->log_in( $user );
		$challenge = $this->challenge();
		$this->assertTrue( $this->proof( $challenge, wp_create_nonce( 'wp_rest' ) )->get_data()['loggedIn'] );
		return $challenge;
	}

	public function test_an_account_redeem_without_an_order_number_lists_that_users_orders_only() {
		$user  = $this->make_user();
		$other = $this->make_user();
		$mine  = $this->make_order( array( 'customer_id' => $user, 'billing_email' => 'a@example.com' ) );
		$this->make_order( array( 'customer_id' => $other, 'billing_email' => 'a@example.com' ) );
		$theirs = $this->make_order( array( 'customer_id' => $other ) );

		$response = $this->redeem_account( 'a', $this->fresh_proof( $user ) );
		wp_set_current_user( 0 );

		$this->assertSame( array( 'granted' => true, 'grant_expires_in' => 1800 ), $response->get_data() );
		$this->assertSame( array( (string) $mine->get_order_number() ), $this->numbers( $this->list_orders()->get_data() ) );
		$this->assertTrue( $this->lookup( $mine )->get_data()['found'] );
		$this->assertSame( array( 'found' => false ), $this->lookup( $theirs )->get_data() );
		$this->assertCount( 0, $this->state_rows( 'grant' ), 'No grant for one order is made.' );
	}

	public function test_an_account_redeem_is_refused_for_a_logged_out_session_a_used_or_bad_challenge_and_old_terms() {
		$user = $this->make_user();
		$this->make_order( array( 'customer_id' => $user ) );

		$challenge = $this->fresh_proof( $user );
		WP_Session_Tokens::get_instance( $user )->destroy_all();
		$this->assertSame( array( 'granted' => false ), $this->redeem_account( 'a', $challenge )->get_data(), 'Logged out.' );

		$challenge = $this->fresh_proof( $user );
		$this->assertTrue( $this->redeem_account( 'b', $challenge )->get_data()['granted'] );
		$this->assertSame( array( 'granted' => false ), $this->redeem_account( 'c', $challenge )->get_data(), 'A record is used once.' );

		foreach ( array( 'short', str_repeat( '!', 22 ), '', null, 5 ) as $bad ) {
			$this->assertSame( array( 'granted' => false ), $this->redeem_account( 'd', $bad )->get_data(), 'Challenge ' . wp_json_encode( $bad ) );
		}
		$this->assertSame( array( 'granted' => false ), $this->redeem_account( 'd', $this->challenge() )->get_data(), 'No record.' );

		$challenge = $this->fresh_proof( $user );
		update_option( SAMOMIND_Orders_Settings::TERMS, array( 'version' => '2026-11', 'user_id' => 1, 'time' => time() ), false );
		$this->assertSame( array( 'granted' => false ), $this->redeem_account( 'e', $challenge )->get_data(), 'Earlier terms.' );
		$this->assertCount( 1, $this->state_rows( 'cgrant' ), 'Only the one good redeem made a grant.' );
	}

	public function test_a_logout_ends_an_account_grant_at_once_for_list_and_lookup() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$this->assertTrue( $this->redeem_account( 'a', $this->fresh_proof( $user ) )->get_data()['granted'] );
		wp_set_current_user( 0 );
		$this->assertTrue( $this->list_orders()->get_data()['granted'] );

		WP_Session_Tokens::get_instance( $user )->destroy_all();

		$this->assertSame( array( 'granted' => false ), $this->list_orders()->get_data() );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order )->get_data() );
	}

	public function test_a_redeem_with_an_unknown_scope_is_a_bad_request_and_version_1_is_unchanged() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$this->assertSame( 400, $this->redeem_account( 'a', $this->challenge(), array( 'scope' => 'everything' ) )->get_status() );

		$response = $this->call(
			'account/redeem',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref( 'v1' ),
				'challenge'    => $this->fresh_proof( $user ),
				'order_number' => (string) $order->get_order_number(),
			)
		);
		$this->assertTrue( $response->get_data()['granted'] );
		$this->assertCount( 1, $this->state_rows( 'grant' ), 'The grant for the one order.' );
	}

	// ---- End ------------------------------------------------------------------

	public function test_end_removes_the_email_and_customer_grants_of_the_session_only() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$this->grant_customer( $user, $order, 'a' );
		$this->grant_email( self::EMAIL, 'a' );
		$this->grant_email( self::EMAIL, 'b' );
		$this->assertCount( 1, $this->state_rows( 'cgrant' ) );
		$this->assertCount( 2, $this->state_rows( 'egrant' ) );

		$response = $this->call( 'end', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $this->session_ref( 'a' ) ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( array( 'granted' => false ), $this->list_orders( 'a' )->get_data() );
		$this->assertSame( array( 'found' => false ), $this->lookup( $order, 'a' )->get_data() );
		$this->assertCount( 0, $this->state_rows( 'cgrant' ) );
		$this->assertCount( 1, $this->state_rows( 'egrant' ), 'The other session keeps its own.' );
		$this->assertTrue( $this->list_orders( 'b' )->get_data()['granted'] );
	}

	// ---- Signature rules on the new route --------------------------------------

	public function test_list_refuses_a_bad_signature_a_replayed_nonce_and_a_missing_key() {
		$this->make_order();
		$this->grant_email();
		$body = array(
			'request_id'  => wp_generate_uuid4(),
			'session_ref' => $this->session_ref( 'a' ),
			'limit'       => 5,
		);

		$bad = $this->call( 'list', $body, array( 'signature' => 'v1=' . str_repeat( '0', 64 ) ) );
		$this->assertSame( 401, $bad->get_status() );
		$this->assertSame( 'invalid_signature', $bad->get_data()['code'] );

		$wrong_key = $this->call( 'list', $body, array( 'secret' => 'smok_someone_elses_key' ) );
		$this->assertSame( 401, $wrong_key->get_status() );

		$this->assertSame( 200, $this->call( 'list', $body, array( 'nonce' => 'REPLAYEDNONCE123456789' ) )->get_status() );
		$this->assertSame( 401, $this->call( 'list', $body, array( 'nonce' => 'REPLAYEDNONCE123456789' ) )->get_status(), 'A nonce works once.' );

		$this->assertSame( 401, $this->call( 'list', $body, array( 'timestamp' => time() - 3600 ) )->get_status(), 'A stale timestamp.' );

		delete_option( SAMOMIND_Orders_Settings::SECRET );
		$this->assertSame( 401, $this->call( 'list', $body )->get_status(), 'With the key revoked, nothing is answered.' );
	}

	public function test_list_is_refused_when_order_status_is_off() {
		update_option( SAMOMIND_Orders_Settings::OPT_IN, '0', false );

		$response = $this->list_orders();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'code' => 'not_enabled' ), $response->get_data() );
	}

	public function test_list_is_a_signed_no_store_answer_without_cors_headers() {
		$this->make_order();
		$this->grant_email();

		$response = $this->list_orders();

		$this->assertStringContainsString( 'no-store', (string) $response->get_headers()['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $response->get_headers() );
		$this->assertTrue( $this->route_registered( 'list' ) );
	}

	private function route_registered( $action ) {
		return isset( rest_get_server()->get_routes()[ '/samomind/v1/orders/' . $action ] );
	}

	// ---- Items and their images -----------------------------------------------

	private function product( $name, $image_id = 0 ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10' );
		if ( $image_id ) {
			$product->set_image_id( $image_id );
		}
		$product->save();
		return $product;
	}

	private function order_with( array $products ) {
		$order = $this->make_order( array( 'items' => array() ) );
		foreach ( $products as $product ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_name( $product->get_name() );
			$item->set_quantity( 1 );
			$item->set_total( 10 );
			$order->add_item( $item );
		}
		$order->save();
		return $order;
	}

	public function test_item_images_come_from_the_product_and_are_null_when_there_is_none() {
		$this->images[ 8001 ] = 'https://shop.example/wp-content/uploads/mug-300x300.jpg';
		$with                 = $this->product( 'Mug', 8001 );
		$without              = $this->product( 'Plain' );
		$order                = $this->order_with( array( $with, $without ) );
		$this->grant_email();

		$items = $this->lookup( $order )->get_data()['order']['items'];

		$this->assertSame( 'https://shop.example/wp-content/uploads/mug-300x300.jpg', $items[0]['image_url'] );
		$this->assertNull( $items[1]['image_url'], 'No image is null, never the placeholder.' );
		$with->delete( true );
		$without->delete( true );
	}

	public function test_a_variation_uses_its_own_image_and_else_the_parents() {
		$this->images[ 8010 ] = 'https://shop.example/parent-300x300.jpg';
		$this->images[ 8011 ] = 'https://shop.example/variation-300x300.jpg';
		$parent               = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$parent->set_image_id( 8010 );
		$parent->save();
		$plain = new WC_Product_Variation();
		$plain->set_parent_id( $parent->get_id() );
		$plain->set_regular_price( '10' );
		$plain->save();
		$own = new WC_Product_Variation();
		$own->set_parent_id( $parent->get_id() );
		$own->set_regular_price( '10' );
		$own->set_image_id( 8011 );
		$own->save();
		$order = $this->order_with( array( $plain, $own ) );
		$this->grant_email();

		$items = $this->lookup( $order )->get_data()['order']['items'];

		$this->assertSame( 'https://shop.example/parent-300x300.jpg', $items[0]['image_url'] );
		$this->assertSame( 'https://shop.example/variation-300x300.jpg', $items[1]['image_url'] );
		$parent->delete( true );
	}

	public function test_a_bad_item_image_address_is_null_and_the_answer_stays_valid() {
		$this->images[ 8020 ] = 'http://shop.example/insecure.jpg';
		$this->images[ 8021 ] = 'https://user:pass@shop.example/creds.jpg';
		$this->images[ 8022 ] = 'https://shop.example/' . str_repeat( 'a', 2040 );
		$this->images[ 8023 ] = 'https://shop.example/with space.jpg';
		$products             = array();
		foreach ( array( 8020, 8021, 8022, 8023 ) as $id ) {
			$products[] = $this->product( 'P' . $id, $id );
		}
		$order = $this->order_with( $products );
		$this->grant_email();

		$items = $this->lookup( $order )->get_data()['order']['items'];

		$this->assertCount( 4, $items );
		foreach ( $items as $item ) {
			$this->assertNull( $item['image_url'], $item['name'] );
		}
		foreach ( $products as $product ) {
			$product->delete( true );
		}
	}

	public function test_fifty_items_with_the_longest_image_addresses_stay_under_the_size_limit() {
		$long = 'https://shop.example/' . str_repeat( 'a', 2048 - strlen( 'https://shop.example/' ) );
		$this->assertSame( 2048, strlen( $long ) );
		$this->images[ 8030 ] = $long;
		$product              = $this->product( 'Item with a very long image address', 8030 );
		$order                = $this->order_with( array_fill( 0, 50, $product ) );
		$this->grant_email();

		$response = $this->lookup( $order );
		$data     = $response->get_data();
		$items    = $data['order']['items'];

		$this->assertTrue( $data['found'] );
		$this->assertCount( 50, $items, 'Items are never dropped.' );
		$this->assertLessThanOrEqual( SAMOMIND_Orders_Lookup::MAX_ANSWER_BYTES, strlen( (string) wp_json_encode( $data ) ), 'Under 60 KB.' );
		$this->assertLessThan( 64 * 1024, strlen( (string) wp_json_encode( $data ) ) );

		$kept = 0;
		$seen_null = false;
		foreach ( $items as $item ) {
			$this->assertSame( 'Item with a very long image address', $item['name'], 'Text is never cut.' );
			if ( null === $item['image_url'] ) {
				$seen_null = true;
			} else {
				$this->assertFalse( $seen_null, 'Addresses are dropped from the last item back, so none follows a null.' );
				$this->assertSame( $long, $item['image_url'], 'An address that is kept is whole.' );
				++$kept;
			}
		}
		$this->assertGreaterThan( 10, $kept, 'The first items keep their image.' );
		$this->assertLessThan( 50, $kept );
		$product->delete( true );
	}

	public function test_fifty_items_with_short_addresses_keep_every_image() {
		$this->images[ 8040 ] = 'https://shop.example/a.jpg';
		$product              = $this->product( 'Short', 8040 );
		$order                = $this->order_with( array_fill( 0, 50, $product ) );
		$this->grant_email();

		$items = $this->lookup( $order )->get_data()['order']['items'];

		$this->assertCount( 50, array_filter( array_column( $items, 'image_url' ) ) );
		$product->delete( true );
	}

	public function test_a_one_order_grant_of_version_1_gets_the_new_fields_when_history_is_on() {
		$order = $this->make_order();
		$code  = $this->start_and_get_code( $order );
		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code ) )->get_data()['verified'] );

		$found = $this->lookup( $order )->get_data();

		$this->assertTrue( $found['found'] );
		$this->assertArrayHasKey( 'total', $found['order'] );
	}
}
