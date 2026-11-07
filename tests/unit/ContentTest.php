<?php
/**
 * What is sent and how a post maps to a PushDocument (contract section 4).
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Content with fixed database answers, to test the counts.
 */
class SAMOMIND_Counting_Content extends SAMOMIND_Content {

	public $candidates = 0;
	public $among      = 0;
	public $among_ids  = null;

	public function count_candidates() {
		return $this->candidates;
	}

	public function count_candidates_among( array $ids ) {
		$this->among_ids = $ids;
		return $this->among;
	}
}

/**
 * Content with a fixed collected post, to test get_document().
 */
class SAMOMIND_Doc_Content extends SAMOMIND_Content {

	public $data = array();

	public function is_eligible( $post ) {
		return true;
	}

	public function collect( $post ) {
		return $this->data;
	}
}

/**
 * Records what the quality check was told.
 */
class SAMOMIND_Recording_Builders {

	public $noted = array();

	public function note_quality( $post_id, $builder, array $reasons ) {
		$this->noted[] = array( $post_id, $builder, $reasons );
	}
}

class ContentTest extends SAMOMIND_TestCase {

	/**
	 * Value of the samomind_post_types option; null while nothing is saved.
	 *
	 * @var string[]|null
	 */
	private $selected_types = null;

	/**
	 * Value of the old samomind_excluded_post_types option; null when absent.
	 *
	 * @var string[]|null
	 */
	private $legacy_excluded = null;

	/**
	 * Options written with add_option(), by name.
	 *
	 * @var array
	 */
	private $added = array();

	protected function setUp(): void {
		parent::setUp();

		$this->selected_types  = null;
		$this->legacy_excluded = null;
		$this->added           = array();
		$test                  = $this;
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $test ) {
				$values = $test->options();
				return array_key_exists( $name, $values ) && null !== $values[ $name ] ? $values[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) use ( $test ) {
				$test->record_added( $name, $value );
				return true;
			}
		);
		$GLOBALS['samomind_test_plugin'] = (object) array( 'helpers' => new SAMOMIND_Helpers() );
	}

	public function options() {
		return array(
			'samomind_post_types'          => $this->selected_types,
			'samomind_excluded_post_types' => $this->legacy_excluded,
		);
	}

	public function record_added( $name, $value ) {
		$this->added[ $name ] = $value;
	}

	private function page_data( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 42,
				'post_type'    => 'page',
				'status'       => 'publish',
				'password'     => '',
				'url'          => 'https://www.example.com/about-us/',
				'title'        => 'About &amp; Contact',
				'content_html' => "<h2>Who we are</h2><p>We build <strong>software</strong>&nbsp;in Nairobi.</p>\n<ul><li>One</li><li>Two</li></ul><script>alert(1)</script>",
				'excerpt'      => 'Short summary.',
				'modified_gmt' => '2026-11-02 08:30:00',
				'terms'        => array( 'Company', 'Company', 'Kenya' ),
			),
			$overrides
		);
	}

	public function test_page_maps_to_the_contract_document() {
		$document = SAMOMIND_Content::build_document( $this->page_data(), 200000 );

		$this->assertSame(
			array(
				'external_id' => '42',
				'url'         => 'https://www.example.com/about-us/',
				'kind'        => 'page',
				'title'       => 'About & Contact',
				'text'        => "Short summary.\n\nWho we are\nWe build software in Nairobi.\n\nOne\nTwo",
				'modified_at' => '2026-11-02T08:30:00+00:00',
				'fields'      => array( 'categories' => array( 'Company', 'Kenya' ) ),
			),
			$document
		);
	}

	public function test_product_adds_price_stock_and_short_description() {
		$data = $this->page_data(
			array(
				'id'           => 7,
				'post_type'    => 'product',
				'content_html' => '<p>Long description.</p>',
				'excerpt'      => '',
				'terms'        => array( 'Shoes' ),
				'product'      => array(
					'short_description_html' => '<p>Short description.</p>',
					'price'                  => '<span class="amount"><bdi>KSh&nbsp;1,500.00</bdi></span>',
					'stock_status'           => 'instock',
					'catalog_visibility'     => 'visible',
				),
			)
		);

		$document = SAMOMIND_Content::build_document( $data, 200000 );

		$this->assertSame( 'product', $document['kind'] );
		$this->assertSame( "Long description.\n\nShort description.", $document['text'] );
		$this->assertSame(
			array(
				'price'        => 'KSh 1,500.00',
				'stock_status' => 'instock',
				'categories'   => array( 'Shoes' ),
			),
			$document['fields']
		);
	}

	public function test_custom_post_type_is_kind_custom_and_without_terms_has_no_fields() {
		$document = SAMOMIND_Content::build_document( $this->page_data( array( 'post_type' => 'event', 'terms' => array() ) ), 200000 );

		$this->assertSame( 'custom', $document['kind'] );
		$this->assertArrayNotHasKey( 'fields', $document );
	}

	public function test_document_holds_only_contract_fields() {
		$data = $this->page_data(
			array(
				'author'   => 'admin@example.com',
				'comments' => array( 'Great post' ),
				'password' => '',
			)
		);

		$document = SAMOMIND_Content::build_document( $data, 200000 );

		$this->assertSame( array( 'external_id', 'url', 'kind', 'title', 'text', 'modified_at', 'fields' ), array_keys( $document ) );
		$this->assertStringNotContainsString( 'admin@example.com', json_encode( $document ) );
		$this->assertStringNotContainsString( 'Great post', json_encode( $document ) );
	}

	public function test_limits_are_applied() {
		$data = $this->page_data(
			array(
				'title'        => str_repeat( 'T', 600 ),
				'content_html' => str_repeat( 'é', 300 ),
				'excerpt'      => '',
				'terms'        => array_merge( array( str_repeat( 'c', 150 ) ), range( 1, 60 ) ),
			)
		);

		$document = SAMOMIND_Content::build_document( $data, 100 );

		$this->assertSame( 500, mb_strlen( $document['title'] ) );
		$this->assertSame( str_repeat( 'é', 100 ), $document['text'] );
		$this->assertCount( 50, $document['fields']['categories'] );
		$this->assertSame( 100, strlen( $document['fields']['categories'][0] ) );
	}

	public function test_nul_characters_are_removed() {
		$document = SAMOMIND_Content::build_document( $this->page_data( array( 'content_html' => "a\0b", 'excerpt' => '', 'title' => "t\0" ) ), 200000 );

		$this->assertSame( 'ab', $document['text'] );
		$this->assertSame( 't', $document['title'] );
	}

	public function test_modified_at_is_rfc3339_with_offset() {
		$this->assertSame( '2026-01-31T23:59:59+00:00', SAMOMIND_Content::rfc3339( '2026-01-31 23:59:59' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', SAMOMIND_Content::rfc3339( '0000-00-00 00:00:00' ) );
	}

	/**
	 * @dataProvider ineligible
	 */
	public function test_non_public_content_is_never_eligible( $overrides ) {
		$data = array_merge(
			array(
				'id'        => 5,
				'post_type' => 'page',
				'status'    => 'publish',
				'password'  => '',
			),
			$overrides
		);

		$this->assertFalse( SAMOMIND_Content::is_eligible_data( $data, array( 'post', 'page', 'product' ), array( 9 ) ) );
	}

	public function ineligible() {
		return array(
			'draft'              => array( array( 'status' => 'draft' ) ),
			'scheduled'          => array( array( 'status' => 'future' ) ),
			'pending'            => array( array( 'status' => 'pending' ) ),
			'private'            => array( array( 'status' => 'private' ) ),
			'trash'              => array( array( 'status' => 'trash' ) ),
			'password protected' => array( array( 'password' => 'secret' ) ),
			'untracked type'     => array( array( 'post_type' => 'attachment' ) ),
			'excluded id'        => array( array( 'id' => 9 ) ),
			'hidden product'     => array(
				array(
					'post_type' => 'product',
					'product'   => array( 'catalog_visibility' => 'hidden' ),
				),
			),
		);
	}

	public function test_published_public_content_is_eligible() {
		$types = array( 'post', 'page', 'product' );

		$this->assertTrue( SAMOMIND_Content::is_eligible_data( array( 'id' => 1, 'post_type' => 'post', 'status' => 'publish', 'password' => '' ), $types, array() ) );
		$this->assertTrue(
			SAMOMIND_Content::is_eligible_data(
				array(
					'id'        => 2,
					'post_type' => 'product',
					'status'    => 'publish',
					'password'  => '',
					'product'   => array( 'catalog_visibility' => 'search' ),
				),
				$types,
				array()
			)
		);
	}

	public function test_post_types_are_posts_pages_and_public_custom_types() {
		Functions\expect( 'get_post_types' )->once()->andReturn( array( 'book' => 'book', 'product' => 'product', 'attachment' => 'attachment' ) );
		$this->selected_types = array( 'post', 'page', 'book', 'product' );

		$this->assertSame( array( 'post', 'page', 'book' ), ( new SAMOMIND_Content() )->get_post_types() );
	}

	public function test_products_are_included_when_woocommerce_is_active() {
		Functions\when( 'get_post_types' )->justReturn( array( 'product' => 'product' ) );
		$this->selected_types = array( 'post', 'page', 'product' );
		$content              = $this->getMockBuilder( SAMOMIND_Content::class )->onlyMethods( array( 'woocommerce_active' ) )->getMock();
		$content->method( 'woocommerce_active' )->willReturn( true );

		$this->assertSame( array( 'post', 'page', 'product' ), $content->get_post_types() );
	}

	public function test_filter_can_remove_types_but_never_add_media() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book' ) );
		$this->selected_types = array( 'post', 'page', 'book' );
		Filters\expectApplied( 'SAMOMIND/content/post_types' )->once()->andReturn( array( 'page', 'book', 'attachment' ) );

		$this->assertSame( array( 'page', 'book' ), ( new SAMOMIND_Content() )->get_post_types() );
	}

	private function content_with_woocommerce( $active ) {
		$content = $this->getMockBuilder( SAMOMIND_Content::class )->onlyMethods( array( 'woocommerce_active' ) )->getMock();
		$content->method( 'woocommerce_active' )->willReturn( $active );
		return $content;
	}

	public function test_a_fresh_install_defaults_to_posts_and_pages() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'product' => 'product' ) );
		$content = $this->content_with_woocommerce( false );

		$this->assertSame( array( 'post', 'page' ), $content->get_post_types() );

		$content->seed_post_types();
		$this->assertSame( array( 'samomind_post_types' => array( 'post', 'page' ) ), $this->added );
	}

	public function test_a_fresh_install_with_woocommerce_also_ticks_products() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'product' => 'product' ) );
		$content = $this->content_with_woocommerce( true );

		$this->assertSame( array( 'post', 'page', 'product' ), $content->get_post_types() );

		$content->seed_post_types();
		$this->assertSame( array( 'post', 'page', 'product' ), $this->added['samomind_post_types'] );
	}

	public function test_a_custom_type_is_listed_but_unticked() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book' ) );
		$content = $this->content_with_woocommerce( false );

		$this->assertContains( 'book', $content->get_available_post_types() );
		$this->assertNotContains( 'book', $content->get_selected_post_types() );
		$this->assertNotContains( 'book', $content->get_post_types() );
	}

	public function test_woocommerce_activated_later_lists_products_unticked() {
		Functions\when( 'get_post_types' )->justReturn( array( 'product' => 'product' ) );
		$this->selected_types = array( 'post', 'page' );
		$content              = $this->content_with_woocommerce( true );

		$this->assertContains( 'product', $content->get_available_post_types() );
		$this->assertSame( array( 'post', 'page' ), $content->get_post_types() );
	}

	public function test_an_existing_install_keeps_its_current_types_after_upgrade() {
		// 0.3.2 and earlier: everything available was synced except the exclusions.
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'event' => 'event' ) );
		$this->legacy_excluded = array( 'event', 'retired_type' );
		$content               = $this->content_with_woocommerce( false );
		$before                = array( 'post', 'page', 'book' );

		$content->seed_post_types();

		$this->assertSame( $before, $this->added['samomind_post_types'] );
	}

	public function test_a_saved_selection_is_left_untouched() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book' ) );
		$this->selected_types  = array( 'book' );
		$this->legacy_excluded = array();
		$content               = $this->content_with_woocommerce( true );

		$content->seed_post_types();

		$this->assertSame( array(), $this->added );
		$this->assertSame( array( 'book' ), $content->get_post_types() );
	}

	public function test_an_empty_saved_selection_stays_empty() {
		$this->selected_types = array();

		$this->content_with_woocommerce( true )->seed_post_types();

		$this->assertSame( array(), $this->added );
		$this->assertSame( array(), $this->content_with_woocommerce( true )->get_selected_post_types() );
	}

	public function test_filter_excludes_ids() {
		Filters\expectApplied( 'SAMOMIND/content/excluded_ids' )->once()->andReturn( array( '12', 30 ) );

		$this->assertSame( array( 12, 30 ), ( new SAMOMIND_Content() )->get_excluded_ids() );
	}

	public function test_inventory_sorts_external_ids_in_byte_order() {
		$pairs = SAMOMIND_Content::sort_by_external_id(
			array(
				9   => 'a',
				10  => 'b',
				100 => 'c',
				2   => 'd',
			)
		);

		$this->assertSame( array( '10', '100', '2', '9' ), array_column( $pairs, 0 ) );
	}

	public function test_unticked_types_are_not_sent() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'event' => 'event' ) );
		Functions\when( 'get_post' )->justReturn( new WP_Post( array( 'ID' => 3, 'post_type' => 'book' ) ) );
		$this->selected_types = array( 'post', 'event' );
		$content              = new SAMOMIND_Content();

		$this->assertSame( array( 'post', 'event' ), $content->get_post_types() );
		$this->assertSame( array( 'post', 'page', 'book', 'event' ), $content->get_available_post_types() );
		$this->assertFalse( $content->is_eligible( new WP_Post( array( 'ID' => 3, 'post_type' => 'book' ) ) ) );
		$this->assertTrue( $content->is_eligible( new WP_Post( array( 'ID' => 4, 'post_type' => 'event' ) ) ) );
		$this->assertNull( $content->get_document( 3, 200000 ) );
	}

	public function test_filter_runs_after_the_setting() {
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'event' => 'event' ) );
		$this->selected_types = array( 'post', 'page', 'event' );
		Filters\expectApplied( 'SAMOMIND/content/post_types' )
			->once()
			->with( array( 'post', 'page', 'event' ) )
			->andReturnUsing(
				function ( $types ) {
					// A site can still add a type back or remove another.
					return array_merge( array_diff( $types, array( 'event' ) ), array( 'book' ) );
				}
			);

		$this->assertSame( array( 'post', 'page', 'book' ), ( new SAMOMIND_Content() )->get_post_types() );
	}

	public function test_included_count_is_the_ticked_types_after_the_excluded_ids() {
		Filters\expectApplied( 'SAMOMIND/content/excluded_ids' )->andReturn( array( 5, 6 ) );
		$content             = new SAMOMIND_Counting_Content();
		$content->candidates = 72;
		$content->among      = 2;

		$this->assertSame( 70, $content->count_included() );
		$this->assertSame( array( 5, 6 ), $content->among_ids );
	}

	public function test_included_count_without_excluded_ids_is_the_candidate_count() {
		$content             = new SAMOMIND_Counting_Content();
		$content->candidates = 46;
		$content->among      = 99;

		$this->assertSame( 46, $content->count_included() );
		$this->assertNull( $content->among_ids );
	}

	private function document_content( array $overrides = array() ) {
		$content       = new SAMOMIND_Doc_Content();
		$content->data = array_merge(
			$this->page_data(),
			array(
				'builder'    => 'bricks',
				'reasons'    => array( 'no_renderer' ),
				'source_len' => 4000,
				'content_html' => '',
				'excerpt'    => '',
			),
			$overrides
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return new WP_Post( array( 'ID' => $id ) );
			}
		);
		return $content;
	}

	public function test_a_site_developer_can_override_the_text_of_a_post() {
		$recorder                        = new SAMOMIND_Recording_Builders();
		$GLOBALS['samomind_test_plugin']->builders = $recorder;
		Filters\expectApplied( 'SAMOMIND/content/text' )->once()->andReturnUsing(
			function ( $text, $post ) {
				return 42 === $post->ID ? 'Written by the site developer for the Bricks page, long enough to count.' : $text;
			}
		);

		$document = $this->document_content()->get_document( 42, 200000 );

		$this->assertSame( 'Written by the site developer for the Bricks page, long enough to count.', $document['text'] );
		// The supplied text clears the "little text" flag.
		$this->assertSame( array( array( 42, 'bricks', array( 'no_renderer' ) ) ), $recorder->noted );
	}

	public function test_an_empty_extraction_from_a_large_page_is_reported_as_little_text() {
		$recorder                        = new SAMOMIND_Recording_Builders();
		$GLOBALS['samomind_test_plugin']->builders = $recorder;

		$document = $this->document_content()->get_document( 42, 200000 );

		$this->assertSame( '', $document['text'] );
		$this->assertSame( array( 'no_renderer', 'little_text' ), $recorder->noted[0][2] );
	}

	public function test_the_overridden_text_is_cut_to_the_api_limit() {
		$GLOBALS['samomind_test_plugin']->builders = new SAMOMIND_Recording_Builders();
		Filters\expectApplied( 'SAMOMIND/content/text' )->andReturn( str_repeat( 'x', 50 ) );

		$document = $this->document_content()->get_document( 42, 10 );

		$this->assertSame( 10, strlen( $document['text'] ) );
	}
}
