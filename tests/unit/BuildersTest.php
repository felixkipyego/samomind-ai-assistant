<?php
/**
 * The adapter layer on synthetic fixtures of each builder's stored format
 * (no real site content): detection, text extraction, the fallback, the
 * quality check and the text filter.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Elementor adapter whose renderer returns a fixture.
 */
class SAMOMIND_Test_Elementor extends SAMOMIND_Builder_Elementor {

	public $active = true;
	public $html   = '';
	public $throw  = false;

	public function is_active() {
		return $this->active;
	}

	protected function builder_content( $item ) {
		if ( $this->throw ) {
			throw new RuntimeException( 'renderer failed' );
		}
		return $this->html;
	}
}

/**
 * Beaver Builder adapter whose layout is a fixture.
 */
class SAMOMIND_Test_Beaver extends SAMOMIND_Builder_Beaver {

	public $active = true;
	public $html   = '';

	public function is_active() {
		return $this->active;
	}

	protected function layout_html( $item ) {
		return $this->html;
	}
}

class BuildersTest extends SAMOMIND_TestCase {

	/**
	 * Post meta: post id => key => value.
	 *
	 * @var array
	 */
	private $meta = array();

	/**
	 * Options, for the incomplete list.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * What the_content returns; null passes the content through.
	 *
	 * @var string|null
	 */
	private $rendered = null;

	/**
	 * Shortcodes the site has registered.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Number of update_option calls.
	 *
	 * @var int
	 */
	private $writes = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->meta       = array();
		$this->options    = array();
		$this->rendered   = null;
		$this->registered = array();
		$this->writes     = 0;
		$test             = $this;

		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) use ( $test ) {
				return $test->meta_value( $id, $key );
			}
		);
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'shortcode_exists' )->alias(
			function ( $tag ) use ( $test ) {
				return $test->is_registered( $tag );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $test ) {
				return $test->option( $name, $default );
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( $test ) {
				$test->write_option( $name, $value );
				return true;
			}
		);
		Filters\expectApplied( 'the_content' )->andReturnUsing(
			function ( $content ) use ( $test ) {
				return $test->rendered_content( $content );
			}
		);
	}

	public function meta_value( $id, $key ) {
		return isset( $this->meta[ $id ][ $key ] ) ? $this->meta[ $id ][ $key ] : '';
	}

	public function is_registered( $tag ) {
		return in_array( $tag, $this->registered, true );
	}

	public function option( $name, $default ) {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
	}

	public function write_option( $name, $value ) {
		$this->options[ $name ] = $value;
		++$this->writes;
	}

	public function rendered_content( $content ) {
		return null === $this->rendered ? $content : $this->rendered;
	}

	private function post( $id, $content = '', array $meta = array() ) {
		$this->meta[ $id ] = $meta;
		return new WP_Post(
			array(
				'ID'           => $id,
				'post_content' => $content,
			)
		);
	}

	/**
	 * What the site would send for a post: extraction, then plain text.
	 */
	private function text_for( SAMOMIND_Builders $builders, $post ) {
		$extracted = $builders->extract( $post );
		return SAMOMIND_Content::to_text( $extracted['html'] );
	}

	private function builders_with( array $adapters ) {
		$adapters[] = new SAMOMIND_Builder_Core();
		return new SAMOMIND_Builders( $adapters );
	}

	// Adapters: one fixture of the stored format each.

	public function test_core_renders_blocks_and_classic_content_through_the_content_filters() {
		$this->rendered = '<h2>Opening hours</h2><p>Nine to five.</p>';
		$post           = $this->post( 1, '<!-- wp:heading --><h2>Opening hours</h2><!-- /wp:heading -->' );

		$extracted = ( new SAMOMIND_Builders() )->extract( $post );

		$this->assertSame( 'core', $extracted['builder'] );
		$this->assertSame( "Opening hours\nNine to five.", SAMOMIND_Content::to_text( $extracted['html'] ) );
		$this->assertSame( array(), $extracted['reasons'] );
	}

	public function test_elementor_page_is_rendered_by_elementor() {
		$adapter       = new SAMOMIND_Test_Elementor();
		$adapter->html = '<div class="elementor"><h2>Welcome to Acme Bikes</h2><div><p>We repair bikes in Nairobi.</p></div></div>';
		$post          = $this->post( 2, '', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[{"id":"a1","elType":"section"}]' ) );

		$extracted = $this->builders_with( array( $adapter ) )->extract( $post );

		$this->assertSame( 'elementor', $extracted['builder'] );
		$this->assertSame( "Welcome to Acme Bikes\nWe repair bikes in Nairobi.", SAMOMIND_Content::to_text( $extracted['html'] ) );
		$this->assertSame( array(), $extracted['reasons'] );
	}

	public function test_elementor_ignores_a_page_not_built_with_it() {
		$post = $this->post( 3, 'Plain', array( '_elementor_data' => '[]' ) );

		$this->assertFalse( ( new SAMOMIND_Builder_Elementor() )->detect( $post ) );
	}

	public function test_beaver_builder_layout_is_captured_from_its_output() {
		$adapter       = new SAMOMIND_Test_Beaver();
		$adapter->html = '<div class="fl-builder-content"><div class="fl-row"><h3>Our team</h3><p>Four people.</p></div></div>';
		$post          = $this->post( 4, '', array( '_fl_builder_enabled' => '1', '_fl_builder_data' => 'a:0:{}' ) );

		$extracted = $this->builders_with( array( $adapter ) )->extract( $post );

		$this->assertSame( 'beaver', $extracted['builder'] );
		$this->assertSame( "Our team\nFour people.", SAMOMIND_Content::to_text( $extracted['html'] ) );
	}

	public function test_beaver_builder_ignores_a_page_with_it_switched_off() {
		$post = $this->post( 5, '', array( '_fl_builder_enabled' => '0' ) );

		$this->assertFalse( ( new SAMOMIND_Builder_Beaver() )->detect( $post ) );
	}

	public function test_bricks_has_no_renderer_so_the_fallback_runs_and_the_page_is_flagged() {
		$json = str_repeat( '{"id":"abc123","name":"heading","settings":{"text":"Hello from Bricks"}},', 8 );
		$post = $this->post( 6, '', array( '_bricks_page_content_2' => '[' . $json . ']', '_bricks_editor_mode' => 'bricks' ) );

		$builders  = $this->builders_with( array( new SAMOMIND_Builder_Bricks() ) );
		$extracted = $builders->extract( $post );

		$this->assertSame( 'bricks', $extracted['builder'] );
		$this->assertContains( 'builder_inactive', $extracted['reasons'] );
		$this->assertSame( '', $this->text_for( $builders, $post ) );
		// Big stored layout, no text: flagged.
		$this->assertContains( 'little_text', SAMOMIND_Builders::quality_reasons( $extracted['reasons'], '', $extracted['source_len'] ) );
	}

	public function test_bricks_page_in_wordpress_editor_mode_is_not_claimed() {
		$post = $this->post( 7, 'Text', array( '_bricks_page_content_2' => '[]', '_bricks_editor_mode' => 'wordpress' ) );

		$this->assertFalse( ( new SAMOMIND_Builder_Bricks() )->detect( $post ) );
	}

	public function test_oxygen_text_is_taken_from_its_shortcode_meta() {
		$post = $this->post(
			8,
			'',
			array( 'ct_builder_shortcodes' => '[ct_section][ct_headline]Hello Oxygen[/ct_headline][ct_text_block]We fix bikes[/ct_text_block][/ct_section]' )
		);

		$builders = $this->builders_with( array( new SAMOMIND_Builder_Oxygen() ) );

		$this->assertSame( 'oxygen', $builders->extract( $post )['builder'] );
		$this->assertSame( 'Hello Oxygen We fix bikes', $this->text_for( $builders, $post ) );
	}

	public function test_oxygen_with_the_underscore_keys_of_4_8_3() {
		$post = $this->post( 9, '', array( '_ct_builder_shortcodes' => '[ct_text_block]New keys[/ct_text_block]' ) );

		$this->assertTrue( ( new SAMOMIND_Builder_Oxygen() )->detect( $post ) );
		$this->assertSame( 'New keys', $this->text_for( $this->builders_with( array( new SAMOMIND_Builder_Oxygen() ) ), $post ) );
	}

	public function test_divi_4_is_rendered_when_its_shortcodes_are_registered() {
		$this->registered = array( 'et_pb_section' );
		$this->rendered   = '<div class="et_pb_section"><h2>About us</h2><p>Founded in 2010.</p></div>';
		$post             = $this->post( 10, '[et_pb_section][et_pb_row][et_pb_text]About us[/et_pb_text][/et_pb_row][/et_pb_section]', array( '_et_pb_use_builder' => 'on' ) );

		$builders = $this->builders_with( array( new SAMOMIND_Builder_Divi() ) );

		$this->assertSame( 'divi', $builders->extract( $post )['builder'] );
		$this->assertSame( "About us\nFounded in 2010.", $this->text_for( $builders, $post ) );
	}

	public function test_divi_4_without_its_shortcodes_loaded_keeps_the_enclosed_text() {
		$content = '[et_pb_section fb_built="1" _builder_version="4.27"][et_pb_row][et_pb_column type="4_4"][et_pb_text _builder_version="4.27"]<p>About us</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$post    = $this->post( 11, $content, array( '_et_pb_use_builder' => 'on' ) );
		// Divi is loaded (its constant is what is_active reads) but its shortcodes are not.
		$adapter = new class() extends SAMOMIND_Builder_Divi {
			public function is_active() {
				return true;
			}
		};

		$builders  = $this->builders_with( array( $adapter ) );
		$extracted = $builders->extract( $post );

		$this->assertContains( 'no_renderer', $extracted['reasons'] );
		$this->assertSame( 'About us', $this->text_for( $builders, $post ) );
	}

	public function test_divi_5_block_format_is_read_from_the_block_attributes() {
		$content = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#fff"}}}}}} -->'
			. '<!-- wp:divi/row {"module":{}} --><!-- wp:divi/column {"module":{}} -->'
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Divi five says hello</p>"}}}} /-->'
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
		$post    = $this->post( 12, $content, array( '_et_pb_use_builder' => 'on' ) );
		$adapter = new class() extends SAMOMIND_Builder_Divi {
			public function is_active() {
				return true;
			}
		};

		$builders = $this->builders_with( array( $adapter ) );

		$this->assertTrue( $adapter->is_divi_5( $post ) );
		$this->assertSame( 'Divi five says hello', $this->text_for( $builders, $post ) );
	}

	public function test_wpbakery_is_rendered_when_its_shortcodes_are_registered() {
		$this->registered = array( 'vc_row' );
		$this->rendered   = '<div class="vc_row"><p>Bike repairs from 500 shillings.</p></div>';
		$post             = $this->post( 13, '[vc_row][vc_column][vc_column_text]Bike repairs[/vc_column_text][/vc_column][/vc_row]', array( '_wpb_vc_js_status' => 'true' ) );

		$builders = $this->builders_with( array( new SAMOMIND_Builder_WPBakery() ) );

		$this->assertSame( 'wpbakery', $builders->extract( $post )['builder'] );
		$this->assertSame( 'Bike repairs from 500 shillings.', $this->text_for( $builders, $post ) );
	}

	public function test_wpbakery_unregistered_falls_back_to_the_enclosed_text() {
		$post    = $this->post( 14, '[vc_row][vc_column][vc_column_text]Bike repairs[/vc_column_text][/vc_column][/vc_row]' );
		$adapter = new class() extends SAMOMIND_Builder_WPBakery {
			public function is_active() {
				return true;
			}
		};

		$this->assertSame( 'Bike repairs', $this->text_for( $this->builders_with( array( $adapter ) ), $post ) );
	}

	public function test_avada_fusion_shortcodes_fall_back_to_the_enclosed_text() {
		$content = '[fusion_builder_container hundred_percent="no" equal_height_columns="no"][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title]Avada heading[/fusion_title][fusion_text]Body copy here.[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$post    = $this->post( 15, $content, array( 'fusion_builder_status' => 'active' ) );
		$adapter = new class() extends SAMOMIND_Builder_Avada {
			public function is_active() {
				return true;
			}
		};

		$builders = $this->builders_with( array( $adapter ) );

		$this->assertSame( 'avada', $builders->extract( $post )['builder'] );
		$this->assertSame( 'Avada heading Body copy here.', $this->text_for( $builders, $post ) );
	}

	// Fallbacks and failures.

	public function test_a_missing_builder_plugin_falls_back_cleanly() {
		$adapter       = new SAMOMIND_Test_Elementor();
		$adapter->active = false;
		$post          = $this->post( 16, '<p>Old text kept in the content.</p> [elementor-template id="5"]', array( '_elementor_edit_mode' => 'builder' ) );

		$builders  = $this->builders_with( array( $adapter ) );
		$extracted = $builders->extract( $post );

		$this->assertSame( 'elementor', $extracted['builder'] );
		$this->assertContains( 'builder_inactive', $extracted['reasons'] );
		$this->assertSame( 'Old text kept in the content.', $this->text_for( $builders, $post ) );
	}

	public function test_an_adapter_that_throws_never_fatals_and_falls_back() {
		$adapter        = new SAMOMIND_Test_Elementor();
		$adapter->throw = true;
		$post           = $this->post( 17, '<p>Stored text.</p>', array( '_elementor_edit_mode' => 'builder' ) );

		$builders  = $this->builders_with( array( $adapter ) );
		$extracted = $builders->extract( $post );

		$this->assertContains( 'adapter_failed', $extracted['reasons'] );
		$this->assertSame( 'Stored text.', $this->text_for( $builders, $post ) );
	}

	public function test_an_adapter_failure_is_logged_once_per_post() {
		$builders = $this->builders_with( array() );

		$builders->note_quality( 17, 'elementor', array( 'adapter_failed' ) );
		$builders->note_quality( 17, 'elementor', array( 'adapter_failed' ) );
		$builders->note_quality( 17, 'elementor', array( 'adapter_failed' ) );

		$this->assertSame( 1, $this->writes );
		$this->assertSame( array( 'adapter_failed' ), $builders->get_incomplete()['17']['reasons'] );
	}

	public function test_the_fallback_never_leaves_shortcode_tokens() {
		$samples = array(
			'[a][b c="d e"]one[/b][/a]',
			'[gallery ids="1,2,3"] after',
			'before [button link="https://x.test/?a=[1]"]Click[/button]',
			'[et_pb_text _builder_version="4.0" text_font="||||"]Hi[/et_pb_text]',
			"[shortcode attr='single quoted']kept[/shortcode]",
			'[self_closing /] text',
			'<p>[vc_row][vc_column_text]Nested <b>bold</b>[/vc_column_text][/vc_row]</p>',
		);
		foreach ( $samples as $sample ) {
			$clean = SAMOMIND_Builders::strip_shortcodes( $sample );
			$this->assertFalse( SAMOMIND_Builders::has_shortcode_tokens( $clean ), 'tokens left in: ' . $clean );
		}
		$this->assertSame( 'one', trim( SAMOMIND_Content::to_text( SAMOMIND_Builders::strip_shortcodes( '[a][b c="d e"]one[/b][/a]' ) ) ) );
	}

	public function test_the_fallback_leaves_ordinary_bracketed_words_and_escaped_shortcodes() {
		$text = 'Reference [1] and [citation needed] and [[not_a_tag]] stay.';

		$this->assertSame( $text, SAMOMIND_Builders::strip_shortcodes( $text ) );
	}

	// Quality check.

	public function test_an_unrendered_page_is_flagged_and_listed() {
		// The theme renders nothing for these tags: they come back as typed.
		$this->rendered = '[acme_slider id="3"]Intro text[/acme_slider] [acme_form]';
		$post           = $this->post( 20, '[acme_slider id="3"]Intro text[/acme_slider] [acme_form]' );
		$builders       = new SAMOMIND_Builders();

		$extracted = $builders->extract( $post );
		$reasons   = SAMOMIND_Builders::quality_reasons( $extracted['reasons'], SAMOMIND_Content::to_text( $extracted['html'] ), $extracted['source_len'] );
		$builders->note_quality( 20, $extracted['builder'], $reasons );

		$this->assertContains( 'unrendered_shortcodes', $extracted['reasons'] );
		$this->assertSame( 'Intro text', trim( SAMOMIND_Content::to_text( $extracted['html'] ) ) );
		$this->assertSame( array( 20 ), array_keys( $builders->get_incomplete() ) );

		// Fixed later: off the list.
		$builders->note_quality( 20, 'core', array() );
		$this->assertSame( array(), $builders->get_incomplete() );
	}

	public function test_a_large_page_with_almost_no_text_is_flagged() {
		$reasons = SAMOMIND_Builders::quality_reasons( array(), 'Hi', 5000 );

		$this->assertSame( array( 'little_text' ), $reasons );
	}

	public function test_a_short_page_with_short_text_is_fine() {
		$this->assertSame( array(), SAMOMIND_Builders::quality_reasons( array(), 'Contact us', 120 ) );
	}

	public function test_no_renderer_alone_does_not_flag_a_page() {
		$builders = new SAMOMIND_Builders();

		$builders->note_quality( 30, 'oxygen', array( 'no_renderer' ) );

		$this->assertSame( array(), $builders->get_incomplete() );
	}

	// Which meta keys queue a post.

	public function test_every_builders_storage_keys_queue_a_post() {
		$builders = new SAMOMIND_Builders();

		foreach ( array( '_elementor_data', '_fl_builder_data', '_bricks_page_content_2', 'ct_builder_shortcodes', '_ct_builder_json', '_et_pb_use_builder', '_wpb_vc_js_status', 'fusion_builder_status' ) as $key ) {
			$this->assertTrue( $builders->is_builder_meta_key( $key ), $key );
		}
		$this->assertFalse( $builders->is_builder_meta_key( '_edit_lock' ) );
		// A draft layout is not live content.
		$this->assertFalse( $builders->is_builder_meta_key( '_fl_builder_draft' ) );
	}

	public function test_a_site_can_add_a_builder_meta_key() {
		Filters\expectApplied( 'SAMOMIND/sync/builder_meta_keys' )->andReturnUsing(
			function ( $keys ) {
				$keys[] = '_my_builder_layout';
				return $keys;
			}
		);

		$this->assertTrue( ( new SAMOMIND_Builders() )->is_builder_meta_key( '_my_builder_layout' ) );
	}
}
