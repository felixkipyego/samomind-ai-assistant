<?php
/**
 * WooCommerce data: the plain-text price, hidden products, and the notice
 * for a shop whose products are not ticked for sync.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * The parts of a WooCommerce product the price text reads.
 */
class SAMOMIND_Fake_Price_Product {

	public $types     = array( 'simple' );
	public $price     = '100';
	public $regular   = '100';
	public $on_sale   = false;
	public $suffix    = '';
	public $children  = array();
	public $variation = array();
	public $html      = '<span class="amount">$100.00</span>';

	public function is_type( $type ) {
		return (bool) array_intersect( (array) $type, $this->types );
	}

	public function get_price_html() {
		return $this->html;
	}

	public function get_price() {
		return $this->price;
	}

	public function get_regular_price() {
		return $this->regular;
	}

	public function is_on_sale() {
		return $this->on_sale;
	}

	public function get_price_suffix() {
		return $this->suffix;
	}

	public function get_children() {
		return array_keys( $this->children );
	}

	public function get_variation_prices( $for_display = false ) {
		return $this->variation;
	}
}

class ShopDataTest extends SAMOMIND_TestCase {

	/**
	 * Multiplier wc_get_price_to_display() applies (1.2 = prices shown with 20% tax).
	 *
	 * @var float
	 */
	private $tax = 1.0;

	/**
	 * Grouped children by id.
	 *
	 * @var array
	 */
	private $children = array();

	protected function setUp(): void {
		parent::setUp();

		$this->tax      = 1.0;
		$this->children = array();
		$tax            = &$this->tax;
		$children       = &$this->children;

		// WooCommerce's own markup for an amount, with its entities and the screen-reader-free wrapper.
		Functions\when( 'wc_price' )->alias(
			function ( $amount ) {
				return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>' . number_format( (float) $amount, 2 ) . '</bdi></span>';
			}
		);
		Functions\when( 'wc_get_price_to_display' )->alias(
			function ( $product, $args = array() ) use ( &$tax ) {
				$price = isset( $args['price'] ) ? $args['price'] : $product->get_price();
				return round( (float) $price * $tax, 2 );
			}
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( &$children ) {
				return isset( $children[ $id ] ) ? $children[ $id ] : false;
			}
		);
	}

	private function product( array $props = array() ) {
		$product = new SAMOMIND_Fake_Price_Product();
		foreach ( $props as $name => $value ) {
			$product->$name = $value;
		}
		return $product;
	}

	private function text( SAMOMIND_Fake_Price_Product $product ) {
		return ( new SAMOMIND_Content() )->product_price_text( $product );
	}

	public function test_a_simple_product_is_one_price() {
		$this->assertSame( '$100.00', $this->text( $this->product() ) );
	}

	public function test_a_sale_names_the_old_price() {
		$this->assertSame( '$70.00 (was $100.00)', $this->text( $this->product( array( 'price' => '70', 'on_sale' => true ) ) ) );
	}

	public function test_a_large_sale_arrives_complete_and_clear() {
		$product = $this->product( array( 'price' => '19999', 'regular' => '25000', 'on_sale' => true ) );

		$text = $this->text( $product );

		$this->assertSame( '$19,999.00 (was $25,000.00)', $text );
		$this->assertLessThanOrEqual( SAMOMIND_Content::MAX_PRICE_CHARS, strlen( $text ) );
	}

	public function test_a_sale_price_equal_to_the_regular_price_is_one_price() {
		$this->assertSame( '$100.00', $this->text( $this->product( array( 'on_sale' => true ) ) ) );
	}

	public function test_no_screen_reader_text_and_no_markup_is_sent() {
		$product = $this->product(
			array(
				'price'   => '70',
				'on_sale' => true,
				'html'    => '<del><span class="amount">$100.00</span></del> <span class="screen-reader-text">Original price was: $100.00.</span><ins>$70.00</ins><span class="screen-reader-text">Current price is: $70.00.</span>',
			)
		);

		$text = $this->text( $product );

		$this->assertStringNotContainsString( 'Original price', $text );
		$this->assertStringNotContainsString( 'Current price', $text );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( '&', $text );
	}

	public function test_prices_follow_the_shop_tax_display() {
		$this->tax = 1.2;

		$this->assertSame( '$120.00', $this->text( $this->product() ) );
		$this->assertSame( '$84.00 (was $120.00)', $this->text( $this->product( array( 'price' => '70', 'on_sale' => true ) ) ) );
	}

	public function test_a_price_suffix_is_added_as_plain_text() {
		$product = $this->product( array( 'suffix' => ' <small class="woocommerce-price-suffix">incl. VAT</small>' ) );

		$this->assertSame( '$100.00 incl. VAT', $this->text( $product ) );
	}

	public function test_a_very_long_suffix_never_cuts_the_price() {
		$product = $this->product( array( 'suffix' => str_repeat( 'long ', 30 ) ) );

		$text = $this->text( $product );

		$this->assertSame( SAMOMIND_Content::MAX_PRICE_CHARS, strlen( $text ) );
		$this->assertStringStartsWith( '$100.00 long', $text );
	}

	public function test_the_currency_symbol_entity_and_no_break_space_become_plain_text() {
		Functions\when( 'wc_price' )->justReturn( '<span class="amount"><bdi>100,00&nbsp;<span class="symbol">&euro;</span></bdi></span>' );

		$this->assertSame( '100,00 €', $this->text( $this->product() ) );
	}

	public function test_a_variable_product_is_a_range() {
		$variable = $this->product(
			array(
				'types'     => array( 'variable' ),
				'variation' => array(
					'price'         => array( 11 => '30', 12 => '50' ),
					'regular_price' => array( 11 => '30', 12 => '50' ),
				),
			)
		);

		$this->assertSame( '$30.00 to $50.00', $this->text( $variable ) );
	}

	public function test_a_variable_product_with_one_price_shows_it_once() {
		$variable = $this->product(
			array(
				'types'     => array( 'variable' ),
				'variation' => array(
					'price'         => array( 11 => '50', 12 => '50' ),
					'regular_price' => array( 11 => '50', 12 => '50' ),
				),
			)
		);

		$this->assertSame( '$50.00', $this->text( $variable ) );
	}

	public function test_a_variable_range_says_when_some_variations_are_on_sale() {
		$variable = $this->product(
			array(
				'types'     => array( 'variable' ),
				'on_sale'   => true,
				'variation' => array(
					'price'         => array( 11 => '30', 12 => '50' ),
					'regular_price' => array( 11 => '50', 12 => '50' ),
				),
			)
		);

		$this->assertSame( '$30.00 to $50.00 (some on sale)', $this->text( $variable ) );
	}

	public function test_a_variable_product_with_one_price_on_sale_names_the_old_price() {
		$variable = $this->product(
			array(
				'types'     => array( 'variable' ),
				'on_sale'   => true,
				'variation' => array(
					'price'         => array( 11 => '40', 12 => '40' ),
					'regular_price' => array( 11 => '50', 12 => '50' ),
				),
			)
		);

		$this->assertSame( '$40.00 (was $50.00)', $this->text( $variable ) );
	}

	public function test_a_grouped_product_is_the_range_of_its_children() {
		$this->children = array(
			21 => $this->product( array( 'price' => '10' ) ),
			22 => $this->product( array( 'price' => '25' ) ),
			23 => $this->product( array( 'price' => '' ) ),
		);
		$grouped        = $this->product( array( 'types' => array( 'grouped' ), 'price' => '', 'children' => $this->children ) );
		$grouped->html  = '<span>$10.00 &ndash; $25.00</span>';

		$this->assertSame( '$10.00 to $25.00', $this->text( $grouped ) );
	}

	public function test_an_external_product_reads_like_a_simple_one() {
		$this->assertSame( '$100.00', $this->text( $this->product( array( 'types' => array( 'external' ) ) ) ) );
	}

	public function test_no_price_sends_nothing() {
		$this->assertSame( '', $this->text( $this->product( array( 'price' => '', 'html' => '' ) ) ) );
		$this->assertSame( '', $this->text( $this->product( array( 'types' => array( 'variable' ), 'variation' => array( 'price' => array() ) ) ) ) );
	}

	public function test_a_price_a_shop_hides_from_guests_is_not_sent() {
		$this->assertSame( '', $this->text( $this->product( array( 'html' => '' ) ) ) );
	}

	public function test_the_document_carries_the_clean_price() {
		$document = SAMOMIND_Content::build_document(
			array(
				'id'           => 7,
				'post_type'    => 'product',
				'url'          => 'https://www.example.com/p/',
				'title'        => 'Mug',
				'content_html' => '<p>A mug.</p>',
				'modified_gmt' => '2026-11-02 08:30:00',
				'product'      => array(
					'price'        => '$19,999.00 (was $25,000.00)',
					'stock_status' => 'outofstock',
				),
			),
			200000
		);

		$this->assertSame( '$19,999.00 (was $25,000.00)', $document['fields']['price'] );
		$this->assertSame( 'outofstock', $document['fields']['stock_status'] );
	}

	/**
	 * Catalog visibility: hidden is never sent; Shop only, Search results
	 * only and Shop and search results are.
	 */
	public function visibility() {
		return array(
			'hidden'              => array( 'hidden', false ),
			'search results only' => array( 'search', true ),
			'shop only'           => array( 'catalog', true ),
			'shop and search'     => array( 'visible', true ),
		);
	}

	/**
	 * @dataProvider visibility
	 */
	public function test_catalog_visibility_decides_whether_a_product_is_sent( $visibility, $sent ) {
		$data = array(
			'id'        => 5,
			'post_type' => 'product',
			'status'    => 'publish',
			'password'  => '',
			'product'   => array( 'catalog_visibility' => $visibility ),
		);

		$this->assertSame( $sent, SAMOMIND_Content::is_eligible_data( $data, array( 'product' ), array() ) );
	}

	public function test_the_notice_shows_when_woocommerce_products_are_not_ticked() {
		$this->assertTrue( $this->content( true, array( 'post', 'page', 'product' ), array( 'post', 'page' ) )->shop_products_not_synced() );
	}

	public function test_the_notice_is_silent_when_products_are_ticked_or_woocommerce_is_off() {
		$this->assertFalse( $this->content( true, array( 'post', 'page', 'product' ), array( 'post', 'product' ) )->shop_products_not_synced() );
		$this->assertFalse( $this->content( false, array( 'post', 'page' ), array( 'post', 'page' ) )->shop_products_not_synced() );
		// Products not offered at all (for example a filter removed them): nothing to tick.
		$this->assertFalse( $this->content( true, array( 'post', 'page' ), array( 'post', 'page' ) )->shop_products_not_synced() );
	}

	private function content( $woocommerce, array $available, array $selected ) {
		$content = $this->getMockBuilder( SAMOMIND_Content::class )->onlyMethods( array( 'woocommerce_active', 'get_available_post_types', 'get_selected_post_types' ) )->getMock();
		$content->method( 'woocommerce_active' )->willReturn( $woocommerce );
		$content->method( 'get_available_post_types' )->willReturn( $available );
		$content->method( 'get_selected_post_types' )->willReturn( $selected );
		return $content;
	}

	/**
	 * Render the notice the way the Status and Settings screens do.
	 */
	private function render_notice( $can, $not_synced ) {
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'esc_html__' )->alias(
			function ( $text ) {
				return htmlspecialchars( $text, ENT_NOQUOTES );
			}
		);
		$content = $this->content( true, $not_synced ? array( 'product' ) : array( 'product' ), $not_synced ? array() : array( 'product' ) );
		$GLOBALS['samomind_test_plugin'] = (object) array( 'content' => $content );

		$admin  = ( new ReflectionClass( SAMOMIND_Admin::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( SAMOMIND_Admin::class, 'render_shop_notice' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		ob_start();
		$method->invoke( $admin );
		return ob_get_clean();
	}

	public function test_the_notice_says_what_to_tick_and_can_be_dismissed() {
		$html = $this->render_notice( true, true );

		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'WooCommerce is active, but products are not synced. Tick Products under Samomind &gt; Settings &gt; What is synced.', $html );
	}

	public function test_the_notice_is_for_administrators_and_only_when_needed() {
		$this->assertSame( '', $this->render_notice( false, true ) );
		$this->assertSame( '', $this->render_notice( true, false ) );
	}
}
