<?php
/**
 * The chat widget: the settings, when the script is enqueued, the
 * attributes it gets and the one-time move from the old pasted snippet.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

class EmbedTest extends SAMOMIND_TestCase {

	// What generate_site_key() makes: "pk_live_" and 43 URL-safe characters.
	const KEY = 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA';

	/**
	 * @var SAMOMIND_Embed
	 */
	private $embed;

	/**
	 * Stored options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Arguments of every wp_enqueue_script() call.
	 *
	 * @var array
	 */
	private $enqueued = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options  = array(
			'samomind_widget_enabled' => '1',
			'samomind_site_key'       => self::KEY,
		);
		$this->enqueued = array();
		$options        = &$this->options;
		$enqueued       = &$this->enqueued;

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function () use ( &$enqueued ) {
				$enqueued[] = func_get_args();
			}
		);
		foreach ( array( 'is_admin', 'is_feed', 'is_embed', 'is_preview', 'is_customize_preview', 'wp_is_json_request' ) as $function ) {
			Functions\when( $function )->justReturn( false );
		}
		Functions\when( 'rest_url' )->justReturn( 'https://example.com/blog/wp-json/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		unset( $GLOBALS['pagenow'] );

		$GLOBALS['samomind_test_plugin'] = (object) array( 'helpers' => new SAMOMIND_Helpers() );
		$this->embed                     = new SAMOMIND_Embed();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['pagenow'], $GLOBALS['samomind_test_plugin'] );
		parent::tearDown();
	}

	public function test_enqueues_the_loader_in_the_footer_with_defer_and_no_version() {
		$this->embed->enqueue_widget();

		$this->assertCount( 1, $this->enqueued );
		list( $handle, $src, $deps, $ver, $args ) = $this->enqueued[0];
		$this->assertSame( 'samomind-widget', $handle );
		$this->assertSame( 'https://widget.samomind.com/widget.js', $src );
		$this->assertSame( array(), $deps );
		$this->assertNull( $ver );
		$this->assertSame(
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			),
			$args
		);
	}

	public function test_off_by_default_enqueues_nothing() {
		unset( $this->options['samomind_widget_enabled'] );

		$this->embed->enqueue_widget();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_switched_off_enqueues_nothing() {
		$this->options['samomind_widget_enabled'] = '0';

		$this->embed->enqueue_widget();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_no_key_enqueues_nothing() {
		$this->options['samomind_site_key'] = '';

		$this->embed->enqueue_widget();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_a_malformed_key_already_in_the_database_enqueues_nothing() {
		$this->options['samomind_site_key'] = '"><script>alert(1)</script>';

		$this->embed->enqueue_widget();

		$this->assertSame( array(), $this->enqueued );
	}

	/**
	 * @dataProvider not_a_visitor_page
	 */
	public function test_never_enqueues_outside_visitor_pages( $function, $globals ) {
		if ( null !== $function ) {
			Functions\when( $function )->justReturn( true );
		}
		foreach ( $globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}

		$this->embed->enqueue_widget();

		$this->assertSame( array(), $this->enqueued );
	}

	public function not_a_visitor_page() {
		return array(
			'wp-admin and admin-ajax' => array( 'is_admin', array() ),
			'feed'                    => array( 'is_feed', array() ),
			'oEmbed'                  => array( 'is_embed', array() ),
			'block editor preview'    => array( 'is_preview', array() ),
			'customizer'              => array( 'is_customize_preview', array() ),
			'json response'           => array( 'wp_is_json_request', array() ),
			'login page'              => array( null, array( 'pagenow' => 'wp-login.php' ) ),
		);
	}

	/**
	 * Runs in its own process because it defines a constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_request_constant() {
		define( 'REST_REQUEST', true );

		$this->assertFalse( $this->embed->is_visitor_page() );
	}

	public function test_adds_the_site_key_and_api_address_to_our_tag_only() {
		$attributes = $this->embed->add_widget_attributes(
			array(
				'src'   => 'https://widget.samomind.com/widget.js',
				'id'    => 'samomind-widget-js',
				'defer' => true,
			)
		);

		$this->assertSame( self::KEY, $attributes['data-site-key'] );
		$this->assertSame( 'https://api.samomind.com', $attributes['data-base-url'] );
		$this->assertSame( 'https://example.com/blog/wp-json/', $attributes['data-samomind-rest-root'] );
		$this->assertSame( 'https://widget.samomind.com/widget.js', $attributes['src'] );
		$this->assertTrue( $attributes['defer'] );
		$this->assertSame( '1', $attributes['data-no-optimize'] );
		$this->assertSame( '1', $attributes['data-noptimize'] );
		$this->assertSame( 'false', $attributes['data-cfasync'] );
		$this->assertSame( 'ignore', $attributes['data-jetpack-boost'] );
		$this->assertTrue( $attributes['nitro-exclude'] );

		$other = array(
			'src' => 'https://example.com/other.js',
			'id'  => 'other-js',
		);
		$this->assertSame( $other, $this->embed->add_widget_attributes( $other ) );
		$this->assertSame( array( 'type' => 'module' ), $this->embed->add_widget_attributes( array( 'type' => 'module' ) ) );
	}

	public function test_attributes_use_the_configured_api_base_url() {
		$this->options['samomind_api_base_url'] = 'https://staging.samomind.com/';

		$attributes = $this->embed->add_widget_attributes( array( 'id' => 'samomind-widget-js' ) );

		$this->assertSame( 'https://staging.samomind.com', $attributes['data-base-url'] );
	}

	public function test_no_key_attribute_when_the_widget_is_off() {
		$this->options['samomind_widget_enabled'] = '0';

		$attributes = $this->embed->add_widget_attributes( array( 'id' => 'samomind-widget-js' ) );

		$this->assertArrayNotHasKey( 'data-site-key', $attributes );
		$this->assertArrayNotHasKey( 'data-samomind-rest-root', $attributes );
	}

	public function test_optimiser_exclusions() {
		$this->assertSame( array( 'a', 'widget.samomind.com/widget.js' ), $this->embed->exclude_from_rocket( array( 'a' ) ) );
		$this->assertSame( array( 'widget.samomind.com/widget.js' ), $this->embed->exclude_from_rocket( '' ) );
		$this->assertSame( array( 'a', 'widget.samomind.com' ), $this->embed->exclude_from_litespeed( array( 'a' ) ) );
		$this->assertSame( 'widget.samomind.com', $this->embed->exclude_from_autoptimize( '' ) );
		$this->assertSame( 'wp-includes/js/jquery/jquery.js, widget.samomind.com', $this->embed->exclude_from_autoptimize( 'wp-includes/js/jquery/jquery.js' ) );
		$this->assertSame( array( 'a' => '', 'widget.samomind.com' => '' ), $this->embed->exclude_from_autoptimize( array( 'a' => '' ) ) );
	}

	public function test_siteground_exclusions_add_to_the_existing_lists() {
		$this->assertSame( array( 'scripts.sirv.com', 'widget.samomind.com/widget.js' ), $this->embed->exclude_from_siteground( array( 'scripts.sirv.com' ) ) );
		$this->assertSame( array( 'widget.samomind.com/widget.js' ), $this->embed->exclude_from_siteground( null ) );
		$this->assertSame( array( 'other-js', 'samomind-widget-js' ), $this->embed->exclude_id_from_siteground( array( 'other-js' ) ) );
		$this->assertSame( array( 'samomind-widget-js' ), $this->embed->exclude_id_from_siteground( '' ) );
		$this->assertSame( array( 'jquery', 'samomind-widget' ), $this->embed->exclude_handle_from_siteground( array( 'jquery' ) ) );
		$this->assertSame( array( 'samomind-widget' ), $this->embed->exclude_handle_from_siteground( false ) );
	}

	public function test_url_exclusions_add_to_the_existing_lists() {
		$this->assertSame( array( 'jquery.min.js', 'widget.samomind.com/widget.js' ), $this->embed->exclude_widget_url( array( 'jquery.min.js' ) ) );
		$this->assertSame( array( 'widget.samomind.com/widget.js' ), $this->embed->exclude_widget_url( null ) );
	}

	public function test_hummingbird_combine_answer_is_kept_or_set_for_our_handle() {
		$this->assertTrue( $this->embed->exclude_handle_from_hummingbird( false, 'samomind-widget' ) );
		$this->assertTrue( $this->embed->exclude_handle_from_hummingbird( true, 'samomind-widget' ) );
		$this->assertFalse( $this->embed->exclude_handle_from_hummingbird( false, 'jquery' ) );
		$this->assertTrue( $this->embed->exclude_handle_from_hummingbird( true, 'jquery' ) );
	}

	public function test_optimiser_filters_are_registered() {
		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ] = $callback[1];
				return true;
			}
		);

		new SAMOMIND_Embed();

		foreach ( array( 'wphb_delay_js_exclusions', 'perfmatters_delay_js_exclusions', 'perfmatters_defer_js_exclusions', 'perfmatters_minify_js_exclusions', 'flying_press_exclude_from_minify:js', 'flying_press_exclude_from_defer:js', 'flying_press_exclude_from_delay:js' ) as $hook ) {
			$this->assertSame( 'exclude_widget_url', $hooks[ $hook ], $hook );
		}
		$this->assertSame( 'exclude_handle_from_hummingbird', $hooks['wphb_dont_combine_handles'] );
	}

	public function test_siteground_filters_are_registered() {
		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ] = $callback[1];
				return true;
			}
		);

		new SAMOMIND_Embed();

		$this->assertSame( 'exclude_id_from_siteground', $hooks['sgo_javascript_combine_exclude_ids'] );
		$this->assertSame( 'exclude_from_siteground', $hooks['sgo_javascript_combine_excluded_external_paths'] );
		$this->assertSame( 'exclude_handle_from_siteground', $hooks['sgo_js_async_exclude'] );
	}

	/**
	 * @dataProvider good_keys
	 */
	public function test_valid_site_keys( $key ) {
		$this->assertTrue( SAMOMIND_Embed::is_valid_site_key( $key ) );
	}

	public function good_keys() {
		return array(
			'generated'     => array( self::KEY ),
			'shortest'      => array( 'pk_live_' . str_repeat( 'a', 20 ) ),
			'longest'       => array( 'pk_live_' . str_repeat( 'Z', 128 ) ),
		);
	}

	/**
	 * @dataProvider bad_keys
	 */
	public function test_invalid_site_keys( $key ) {
		$this->assertFalse( SAMOMIND_Embed::is_valid_site_key( $key ) );
	}

	public function bad_keys() {
		$ok = self::KEY;
		return array(
			'empty'               => array( '' ),
			'not a string'        => array( 12345 ),
			'script tag'          => array( '<script src="https://widget.samomind.com/widget.js" data-site-key="' . $ok . '"></script>' ),
			'key inside a tag'    => array( '<b>' . $ok . '</b>' ),
			'quote breakout'      => array( $ok . '" onload="alert(1)' ),
			'space inside'        => array( 'pk_live_abc def' . str_repeat( 'a', 20 ) ),
			'newline at the end'  => array( $ok . "\n" ),
			'wrong prefix'        => array( 'sk_live_' . str_repeat( 'a', 30 ) ),
			'too short'           => array( 'pk_live_abc' ),
			'too long'            => array( 'pk_live_' . str_repeat( 'a', 129 ) ),
			'ingest key id'       => array( '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10' ),
		);
	}

	public function test_old_snippet_is_moved_to_the_new_setting_once() {
		unset( $this->options['samomind_widget_enabled'], $this->options['samomind_site_key'] );
		$this->options['samomind_embed_snippet'] = '<script src="https://widget.samomind.com/widget.js" data-site-key="' . self::KEY . '" data-base-url="https://api.samomind.com" async></script>';

		$this->embed->migrate_pasted_snippet();

		$this->assertSame( self::KEY, $this->options['samomind_site_key'] );
		$this->assertSame( '1', $this->options['samomind_widget_enabled'] );
		$this->assertArrayNotHasKey( 'samomind_embed_snippet', $this->options );
	}

	public function test_migration_does_not_overwrite_a_key_saved_in_the_new_setting() {
		$this->options['samomind_widget_enabled'] = '0';
		$this->options['samomind_embed_snippet']  = '<script src="https://widget.samomind.com/widget.js" data-site-key="pk_live_' . str_repeat( 'x', 30 ) . '" async></script>';

		$this->embed->migrate_pasted_snippet();

		$this->assertSame( self::KEY, $this->options['samomind_site_key'] );
		$this->assertSame( '0', $this->options['samomind_widget_enabled'] );
		$this->assertArrayNotHasKey( 'samomind_embed_snippet', $this->options );
	}

	public function test_an_old_snippet_without_a_usable_key_is_only_deleted() {
		unset( $this->options['samomind_widget_enabled'], $this->options['samomind_site_key'] );
		$this->options['samomind_embed_snippet'] = '<script src="https://widget.samomind.com/widget.js" data-site-key="pk_live_abc" async></script>';

		$this->embed->migrate_pasted_snippet();

		$this->assertSame( array(), $this->options );
	}

	public function test_migration_does_nothing_without_an_old_snippet() {
		$before = $this->options;

		$this->embed->migrate_pasted_snippet();

		$this->assertSame( $before, $this->options );
	}
}
