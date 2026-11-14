<?php
/**
 * What lookup returns (design sections 6 and 9): only the listed fields,
 * the status vocabulary, order number resolution, tracking, and the shop's
 * own sharing choices. With HPOS on or off.
 *
 * @package SAMOMIND
 */

class WC_Shipment_Tracking_Actions {
	public static $items = array();
	public static function get_instance() {
		return new self();
	}
	public function get_tracking_items( $order_id, $formatted = false ) {
		unset( $order_id, $formatted );
		return self::$items;
	}
}

class OrdersLookupTest extends SAMOMIND_Integration_TestCase {

	protected function tearDown(): void {
		WC_Shipment_Tracking_Actions::$items = array();
		parent::tearDown();
	}

	/**
	 * Grant a session lookups of an order through the guest path.
	 */
	private function grant( WC_Order $order, $session = 'a' ) {
		$code = $this->start_and_get_code( $order, $session );
		$this->assertTrue( $this->call( 'verify', $this->verify_body( $order, $code, $session ) )->get_data()['verified'] );
	}

	private function fetch( WC_Order $order, array $share = array(
		'items'    => true,
		'tracking' => true,
	) ) {
		return $this->call( 'lookup', $this->lookup_body( $order->get_order_number(), 'a', $share ) )->get_data();
	}

	public function test_only_the_listed_fields_leave_the_shop() {
		$order = $this->make_order( array( 'items' => array( 'Blue mug' => 2 ) ) );
		$order->set_date_paid( strtotime( '2026-11-08 10:00:00' ) );
		$order->save();
		$this->grant( $order );

		$found = $this->fetch( $order );
		$this->assertTrue( $found['found'] );
		$this->assertSame(
			array( 'order_number', 'status', 'status_label', 'created', 'paid', 'shipped', 'completed', 'items', 'tracking' ),
			array_keys( $found['order'] )
		);
		$this->assertSame( array( array( 'name' => 'Blue mug', 'qty' => 2 ) ), $found['order']['items'] );
		$this->assertSame( '2026-11-08', $found['order']['paid'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $found['order']['created'] );
		$this->assertNull( $found['order']['completed'] );
		$this->assertNull( $found['order']['tracking'] );

		$json = wp_json_encode( $found );
		foreach ( array( 'buyer@example.com', 'Private', 'Person', '+254700000000', 'Secret Street', 'Leave it with the neighbour', 'Card ending', '99' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, "The lookup leaks $secret." );
		}
	}

	public function test_status_vocabulary() {
		$expected = array(
			'pending'    => array( 'pending', 'Pending payment' ),
			'on-hold'    => array( 'on-hold', 'On hold' ),
			'processing' => array( 'processing', 'Processing' ),
			'completed'  => array( 'completed', 'Completed' ),
			'cancelled'  => array( 'cancelled', 'Cancelled' ),
			'refunded'   => array( 'refunded', 'Refunded' ),
			'failed'     => array( 'failed', 'Failed' ),
		);
		$lookup = new SAMOMIND_Orders_Lookup();
		foreach ( $expected as $wc_status => $pair ) {
			$order  = $this->make_order( array( 'status' => $wc_status ) );
			$fields = $lookup->order_fields( $order, false, false );
			$this->assertSame( $pair, array( $fields['status'], $fields['status_label'] ), $wc_status );
		}
	}

	public function test_a_custom_status_is_other_with_a_clean_label() {
		register_post_status(
			'wc-samomind-test',
			array(
				'label'  => 'Packing <b>now</b> ' . "\u{202E}" . 'evil',
				'public' => false,
			)
		);
		add_filter(
			'wc_order_statuses',
			function ( $statuses ) {
				$statuses['wc-samomind-test'] = 'Packing <b>now</b> ' . "\u{202E}" . 'evil';
				return $statuses;
			}
		);
		$order  = $this->make_order( array( 'status' => 'samomind-test' ) );
		$fields = ( new SAMOMIND_Orders_Lookup() )->order_fields( $order, false, false );
		remove_all_filters( 'wc_order_statuses' );

		$this->assertSame( 'other', $fields['status'] );
		$this->assertSame( 'Packing now evil', $fields['status_label'] );
	}

	public function test_unshared_fields_are_omitted_even_if_requested() {
		$order = $this->make_order();
		$this->grant( $order );
		WC_Shipment_Tracking_Actions::$items = array( $this->tracking_item() );

		update_option( SAMOMIND_Orders_Settings::SHARE_ITEMS, '0', false );
		update_option( SAMOMIND_Orders_Settings::SHARE_TRACKING, '0', false );
		$found = $this->fetch( $order, array( 'items' => true, 'tracking' => true ) );
		$this->assertArrayNotHasKey( 'items', $found['order'] );
		$this->assertArrayNotHasKey( 'tracking', $found['order'] );
		$this->assertNull( $found['order']['shipped'], 'The shipped date comes from the tracking add-on, so it is not shared either.' );

		// The shop allows both but the request asks for less: the request wins.
		update_option( SAMOMIND_Orders_Settings::SHARE_ITEMS, '1', false );
		update_option( SAMOMIND_Orders_Settings::SHARE_TRACKING, '1', false );
		$found = $this->fetch( $order, array( 'items' => false, 'tracking' => true ) );
		$this->assertArrayNotHasKey( 'items', $found['order'] );
		$this->assertArrayHasKey( 'tracking', $found['order'] );
		$found = $this->fetch( $order, array() );
		$this->assertArrayNotHasKey( 'items', $found['order'] );
		$this->assertArrayNotHasKey( 'tracking', $found['order'] );
	}

	public function test_defaults_are_tracking_on_and_item_names_off() {
		delete_option( SAMOMIND_Orders_Settings::SHARE_TRACKING );
		delete_option( SAMOMIND_Orders_Settings::SHARE_ITEMS );
		$this->assertTrue( SAMOMIND()->orders->shares_tracking() );
		$this->assertFalse( SAMOMIND()->orders->shares_items() );
	}

	private function tracking_item( array $override = array() ) {
		return array_merge(
			array(
				'tracking_provider'         => 'dhl',
				'formatted_tracking_provider' => 'DHL',
				'tracking_number'           => 'JD014600',
				'formatted_tracking_link'   => 'https://www.dhl.example/track?id=JD014600',
				'date_shipped'              => strtotime( '2026-11-10 12:00:00' ),
			),
			$override
		);
	}

	public function test_tracking_from_woocommerce_shipment_tracking_first_item_only() {
		$order = $this->make_order();
		$this->grant( $order );
		WC_Shipment_Tracking_Actions::$items = array(
			$this->tracking_item(),
			$this->tracking_item( array( 'tracking_number' => 'SECOND', 'formatted_tracking_provider' => 'UPS' ) ),
		);

		$this->assertSame( 'wc_shipment_tracking', SAMOMIND_Orders_Lookup::tracking_provider() );
		$found = $this->fetch( $order );
		$this->assertSame(
			array(
				'carrier' => 'DHL',
				'number'  => 'JD014600',
				'url'     => 'https://www.dhl.example/track?id=JD014600',
			),
			$found['order']['tracking']
		);
		$this->assertSame( '2026-11-10', $found['order']['shipped'] );
	}

	/**
	 * @dataProvider bad_tracking_urls
	 */
	public function test_tracking_links_that_are_not_https_are_dropped( $url ) {
		$this->assertNull( SAMOMIND_Orders_Lookup::safe_https_url( $url ) );
	}

	public function bad_tracking_urls() {
		return array(
			'http'            => array( 'http://carrier.example/t?1' ),
			'javascript'      => array( 'javascript:alert(1)' ),
			'credentials'     => array( 'https://user:pass@carrier.example/t' ),
			'user only'       => array( 'https://user@carrier.example/t' ),
			'ipv4 host'       => array( 'https://203.0.113.9/t' ),
			'ipv6 host'       => array( 'https://[2001:db8::1]/t' ),
			'no dot in host'  => array( 'https://localhost/t' ),
			'too long'        => array( 'https://carrier.example/' . str_repeat( 'a', 2100 ) ),
			'with a space'    => array( 'https://carrier.example/a b' ),
			'empty'           => array( '' ),
			'scheme relative' => array( '//carrier.example/t' ),
		);
	}

	public function test_a_good_tracking_link_passes() {
		$this->assertSame( 'https://carrier.example/t?id=1', SAMOMIND_Orders_Lookup::safe_https_url( 'https://carrier.example/t?id=1' ) );
	}

	public function test_tracking_with_a_bad_link_keeps_carrier_and_number_and_drops_the_link() {
		$order = $this->make_order();
		$this->grant( $order );
		WC_Shipment_Tracking_Actions::$items = array( $this->tracking_item( array( 'formatted_tracking_link' => 'http://carrier.example/t' ) ) );

		$tracking = $this->fetch( $order )['order']['tracking'];
		$this->assertSame( 'DHL', $tracking['carrier'] );
		$this->assertNull( $tracking['url'] );
	}

	public function test_order_number_resolution_by_id_and_by_a_sequential_number_plugin() {
		$plain = $this->make_order();
		$seq   = $this->make_order( array( 'number_meta' => 'SM-70123' ) );
		$lookup = new SAMOMIND_Orders_Lookup();

		$this->assertSame( $plain->get_id(), $lookup->find_order( (string) $plain->get_id() )->get_id() );
		$this->assertSame( $plain->get_id(), $lookup->find_order( '#' . $plain->get_id() )->get_id() );
		$this->assertSame( $seq->get_id(), $lookup->find_order( 'SM-70123' )->get_id(), 'By the number a sequential plugin shows.' );
		$this->assertNull( $lookup->find_order( 'SM-99999' ) );
		$this->assertNull( $lookup->find_order( '0' ) );
		$this->assertNull( $lookup->find_order( '1; DROP TABLE' ) );
		$this->assertNull( $lookup->find_order( str_repeat( '9', 21 ) ) );
		$this->assertNull( $lookup->find_order( array( '1' ) ) );
		$this->assertNull( $lookup->find_order( null ) );
	}

	public function test_a_refund_or_a_product_id_is_never_an_order() {
		$order  = $this->make_order();
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1, 'reason' => 'x' ) );
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->save();

		$lookup = new SAMOMIND_Orders_Lookup();
		$this->assertNull( $lookup->find_order( (string) $refund->get_id() ) );
		$this->assertNull( $lookup->find_order( (string) $product->get_id() ) );
		$product->delete( true );
	}

	public function test_a_lookup_works_by_a_sequential_number_through_the_whole_flow() {
		$order = $this->make_order( array( 'number_meta' => 'SM-70555' ) );
		$code  = $this->start_and_get_code( $this->with_number( $order, 'SM-70555' ) );
		$verify = $this->verify_body( $order, $code );
		$verify['order_number'] = 'SM-70555';
		$this->assertTrue( $this->call( 'verify', $verify )->get_data()['verified'] );

		$found = $this->call( 'lookup', $this->lookup_body( 'SM-70555' ) )->get_data();
		$this->assertTrue( $found['found'] );
	}

	private function with_number( WC_Order $order, $number ) {
		// The request carries the typed number, not the id.
		$response = $this->call(
			'start',
			array(
				'request_id'   => wp_generate_uuid4(),
				'session_ref'  => $this->session_ref(),
				'order_number' => $number,
				'email'        => $order->get_billing_email(),
			)
		);
		$this->assertSame( 202, $response->get_status() );
		$this->run_plugin_shutdown();
		return $order;
	}

	public function test_an_unknown_order_and_a_missing_grant_look_the_same() {
		$order = $this->make_order();
		$a     = $this->call( 'lookup', $this->lookup_body( $order->get_order_number() ) );
		$b     = $this->call( 'lookup', $this->lookup_body( '999999999' ) );
		$this->assertSame( wp_json_encode( $a->get_data() ), wp_json_encode( $b->get_data() ) );
		$this->assertSame( '{"found":false}', wp_json_encode( $a->get_data() ) );
	}
}
