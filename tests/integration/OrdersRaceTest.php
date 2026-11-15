<?php
/**
 * Requests that arrive at the same moment (T5 finding 4): real HTTP calls
 * in parallel from the test container to the wp-env site, at the plugin's
 * own endpoints. The database, not PHP, has to decide who wins.
 *
 * Local wp-env only: the test is skipped where the site cannot be reached.
 *
 * @package SAMOMIND
 */

class OrdersRaceTest extends SAMOMIND_Integration_TestCase {

	/**
	 * Requests sent at once.
	 */
	const PARALLEL = 12;

	/**
	 * Where the web server of the test site answers from the test container.
	 *
	 * @var string
	 */
	private $base = '';

	private $old_structure;

	protected function setUp(): void {
		parent::setUp();

		$this->base = (string) getenv( 'SAMOMIND_RACE_BASE_URL' );
		if ( '' === $this->base ) {
			$this->base = 'http://tests-wordpress';
		}

		// The web server has no rewrite rules, so the REST API is reached through index.php.
		$this->old_structure = get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/index.php/%postname%/' );
		flush_rewrite_rules();

		$probe = $this->post_once( 'ping', '{}', array() );
		if ( 200 !== $probe[0] ) {
			$this->restore_permalinks();
			$this->markTestSkipped( 'The test site cannot be reached over HTTP from here: ' . $this->base . ' (' . $probe[0] . ')' );
		}
		$this->reset_nonces();
	}

	protected function tearDown(): void {
		$this->restore_permalinks();
		parent::tearDown();
	}

	private function restore_permalinks() {
		update_option( 'permalink_structure', $this->old_structure ? $this->old_structure : '/%postname%/' );
		flush_rewrite_rules();
	}

	/**
	 * Headers and body of a request signed the way Samomind signs it.
	 *
	 * @param string $action  Endpoint.
	 * @param string $raw     JSON body.
	 * @param array  $options nonce.
	 * @return array  array( url, headers, body )
	 */
	private function build( $action, $raw, array $options ) {
		$path      = '/index.php/wp-json/samomind/v1/orders/' . $action;
		$timestamp = (string) time();
		$nonce     = isset( $options['nonce'] ) ? $options['nonce'] : substr( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), 0, 22 );
		$key_id    = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';
		$headers   = array(
			'Host: ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ),
			'Content-Type: application/json',
			'X-SamoMind-Orders-Key-Id: ' . $key_id,
			'X-SamoMind-Orders-Timestamp: ' . $timestamp,
			'X-SamoMind-Orders-Nonce: ' . $nonce,
			'X-SamoMind-Orders-Version: 1',
			'X-SamoMind-Orders-Signature: ' . SAMOMIND_Orders_Auth::sign( self::SECRET, SAMOMIND_Orders_Auth::canonical( $timestamp, $key_id, $nonce, $path, $raw ) ),
		);
		return array( $this->base . $path, $headers, $raw );
	}

	private function post_once( $action, $raw, array $options ) {
		return $this->post_all( array( $this->build( $action, $raw, $options ) ) )[0];
	}

	/**
	 * Send requests at the same moment and return array( status, decoded body ) for each.
	 *
	 * @param array[] $requests Output of build().
	 * @return array[]
	 */
	private function post_all( array $requests ) {
		$multi   = curl_multi_init();
		$handles = array();
		foreach ( $requests as $i => $request ) {
			$handle = curl_init( $request[0] );
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $request[2],
					CURLOPT_HTTPHEADER     => $request[1],
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 60,
				)
			);
			curl_multi_add_handle( $multi, $handle );
			$handles[ $i ] = $handle;
		}
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( $running ) {
				curl_multi_select( $multi, 0.2 );
			}
		} while ( $running && CURLM_OK === $status );

		$results = array();
		foreach ( $handles as $i => $handle ) {
			$results[ $i ] = array( (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE ), json_decode( (string) curl_multi_getcontent( $handle ), true ) );
			curl_multi_remove_handle( $multi, $handle );
			curl_close( $handle );
		}
		curl_multi_close( $multi );
		return $results;
	}

	public function test_parallel_copies_of_one_signed_request_get_exactly_one_answer() {
		foreach ( array( 'ping', 'end' ) as $action ) {
			$raw      = 'end' === $action ? wp_json_encode( array( 'session_ref' => $this->session_ref() ) ) : '{}';
			$request  = $this->build( $action, $raw, array() );
			$results  = $this->post_all( array_fill( 0, self::PARALLEL, $request ) );
			$statuses = wp_list_pluck( $results, 0 );

			$accepted = array_filter(
				$statuses,
				function ( $status ) {
					return 401 !== $status;
				}
			);
			$this->assertCount( 1, $accepted, "$action: exactly one copy is accepted, got " . implode( ',', $statuses ) );
			$this->assertSame( self::PARALLEL - 1, count( array_keys( $statuses, 401, true ) ), "$action: every other copy is a replay." );
			$this->assertCount( 1, $this->state_rows( 'nonce' ), 'The nonce is stored once.' );
			$this->reset_nonces();
		}
	}

	private function reset_nonces() {
		global $wpdb;
		$table = SAMOMIND_Orders_State::table();
		$wpdb->query( "DELETE FROM {$table} WHERE kind = 'nonce'" ); // phpcs:ignore
	}

	public function test_parallel_wrong_codes_are_counted_three_at_most() {
		$order = $this->make_order();
		$store = new SAMOMIND_Orders_Store( self::SECRET );
		$right = '424242';
		$store->put_code( $this->session_ref(), $order->get_id(), $store->code_hash( $this->session_ref(), $order->get_id(), $right ), 600 );

		$requests = array();
		for ( $i = 0; $i < self::PARALLEL; $i++ ) {
			$wrong      = str_pad( (string) ( 100000 + $i ), 6, '0', STR_PAD_LEFT );
			$requests[] = $this->build( 'verify', wp_json_encode( $this->verify_body( $order, $wrong ) ), array() );
		}
		$results = $this->post_all( $requests );

		foreach ( $results as $i => $result ) {
			$this->assertSame( 200, $result[0], "Request $i" );
			$this->assertFalse( $result[1]['verified'], "Request $i" );
		}

		$rows = $this->state_rows( 'code' );
		$this->assertCount( 1, $rows );
		$this->assertLessThanOrEqual( 3, (int) $rows[0]['n'], 'No more than three tries are counted.' );
		$this->assertSame( 3, (int) $rows[0]['n'] );

		// The right code, sent now, is refused: the record is locked.
		$late = $this->post_once( 'verify', wp_json_encode( $this->verify_body( $order, $right ) ), array() );
		$this->assertFalse( $late[1]['verified'] );
		$this->assertSame( 3, (int) $this->state_rows( 'code' )[0]['n'] );
	}

	public function test_parallel_right_codes_are_spent_once() {
		$order = $this->make_order();
		$store = new SAMOMIND_Orders_Store( self::SECRET );
		$right = '515151';
		$store->put_code( $this->session_ref(), $order->get_id(), $store->code_hash( $this->session_ref(), $order->get_id(), $right ), 600 );

		$requests = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$requests[] = $this->build( 'verify', wp_json_encode( $this->verify_body( $order, $right ) ), array() );
		}
		$verified = array_filter( array_map( function ( $result ) {
			return ! empty( $result[1]['verified'] );
		}, $this->post_all( $requests ) ) );

		$this->assertCount( 1, $verified, 'A code works once, even when it is sent three times at once.' );
	}

	public function test_a_one_time_account_record_is_taken_by_one_request() {
		$user  = $this->make_user();
		$order = $this->make_order( array( 'customer_id' => $user ) );
		$token = WP_Session_Tokens::get_instance( $user )->create( time() + DAY_IN_SECONDS );

		$challenge = $this->challenge();
		( new SAMOMIND_Orders_Store( self::SECRET ) )->put_record( $challenge, $user, hash( 'sha256', $token ), 60 );

		$requests = array();
		for ( $i = 0; $i < self::PARALLEL; $i++ ) {
			$requests[] = $this->build(
				'account/redeem',
				wp_json_encode(
					array(
						'request_id'   => wp_generate_uuid4(),
						'session_ref'  => $this->session_ref( 'r' . $i ),
						'order_number' => (string) $order->get_id(),
						'challenge'    => $challenge,
					)
				),
				array()
			);
		}
		$granted = array_filter(
			array_map(
				function ( $result ) {
					return ! empty( $result[1]['granted'] );
				},
				$this->post_all( $requests )
			)
		);

		$this->assertCount( 1, $granted, 'Of twelve redeems of one record, one wins.' );
		$this->assertCount( 0, $this->state_rows( 'record' ) );
	}

	public function test_parallel_starts_for_one_order_send_at_most_three_codes() {
		$order    = $this->make_order();
		$requests = array();
		for ( $i = 0; $i < self::PARALLEL; $i++ ) {
			$requests[] = $this->build(
				'start',
				wp_json_encode(
					array(
						'request_id'   => wp_generate_uuid4(),
						'session_ref'  => $this->session_ref( 's' . $i ),
						'order_number' => (string) $order->get_id(),
						'email'        => $order->get_billing_email(),
						'locale'       => 'en',
					)
				),
				array()
			);
		}
		$results = $this->post_all( $requests );
		foreach ( $results as $i => $result ) {
			$this->assertSame( 202, $result[0], "Request $i" );
		}

		$omail = $this->state_rows( 'omail' );
		$this->assertCount( 1, $omail );
		$this->assertSame( 3, (int) $omail[0]['n'], 'Three emails for the order, never a fourth.' );
		$this->assertCount( 3, $this->state_rows( 'code' ) );
		$this->assertSame( 3, (int) $this->state_rows( 'hour' )[0]['n'], 'Only those three are counted for the shop.' );

		$capped = array_filter(
			$results,
			function ( $result ) {
				return ! empty( $result[1]['mail_capped'] );
			}
		);
		$this->assertCount( self::PARALLEL - 3, $capped, 'The rest are told the mail was capped.' );
	}
}
