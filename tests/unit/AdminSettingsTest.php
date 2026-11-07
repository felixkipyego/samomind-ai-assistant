<?php
/**
 * Settings sanitisers: what the plugin may call and print.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

class AdminSettingsTest extends SAMOMIND_TestCase {

	/**
	 * @var SAMOMIND_Admin
	 */
	private $admin;

	/**
	 * Messages passed to add_settings_error().
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Messages by code, as WordPress would print them (unescaped).
	 *
	 * @var string[]
	 */
	private $messages = array();

	protected function setUp(): void {
		parent::setUp();

		$this->errors = array();
		$errors       = &$this->errors;
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message ) use ( &$errors ) {
				$errors[]                 = $code;
				$this->messages[ $code ] = $message;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$saved = array(
					'samomind_api_base_url'  => 'https://api.samomind.com',
					'samomind_key_id'        => '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10',
					'samomind_site_key'      => 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA',
					'samomind_post_types'    => array( 'post', 'retired_type', 'event' ),
				);
				return isset( $saved[ $name ] ) ? $saved[ $name ] : $default;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return htmlspecialchars( $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_html__' )->alias(
			function ( $text ) {
				return htmlspecialchars( $text, ENT_QUOTES );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'add_action' )->justReturn( true );

		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'book' => 'book', 'event' => 'event' ) );
		$GLOBALS['samomind_test_plugin'] = (object) array(
			'helpers' => new SAMOMIND_Helpers(),
			'content' => new SAMOMIND_Content(),
		);
		$this->admin                     = new SAMOMIND_Admin();
	}

	public function test_base_url_accepts_https_and_drops_query_and_trailing_slash() {
		$this->assertSame( 'https://staging.samomind.com/api-root', $this->admin->sanitize_api_base_url( ' https://Staging.SamoMind.com/api-root/?x=1#y ' ) );
		$this->assertSame( array(), $this->errors );
	}

	public function test_base_url_empty_falls_back_to_production() {
		$this->assertSame( 'https://api.samomind.com', $this->admin->sanitize_api_base_url( '' ) );
	}

	public function test_base_url_allows_http_only_for_local_hosts() {
		$this->assertSame( 'http://localhost:8000', $this->admin->sanitize_api_base_url( 'http://localhost:8000' ) );
		$this->assertSame( 'http://samomind.test', $this->admin->sanitize_api_base_url( 'http://samomind.test' ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * @dataProvider bad_base_urls
	 */
	public function test_base_url_refuses_and_keeps_the_previous_value( $url ) {
		$this->assertSame( 'https://api.samomind.com', $this->admin->sanitize_api_base_url( $url ) );
		$this->assertSame( array( 'samomind_api_base_url' ), $this->errors );
	}

	public function bad_base_urls() {
		return array(
			'plain http'   => array( 'http://api.samomind.com' ),
			'no scheme'    => array( 'api.samomind.com' ),
			'ftp'          => array( 'ftp://api.samomind.com' ),
			'credentials'  => array( 'https://user:pass@api.samomind.com' ),
			'javascript'   => array( 'javascript:alert(1)' ),
		);
	}

	public function test_key_id_must_be_a_uuid() {
		$this->assertSame( '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f11', $this->admin->sanitize_key_id( ' 3F2B6C1E-8D4A-4F0B-9A7E-2C5D1B6E9F11 ' ) );
		$this->assertSame( '', $this->admin->sanitize_key_id( '' ) );
		$this->assertSame( '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10', $this->admin->sanitize_key_id( 'not-a-uuid"><script>' ) );
		$this->assertSame( array( 'samomind_key_id' ), $this->errors );
	}

	const GOOD_KEY = 'pk_live_Jc3x9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua-_Gp8eTqWcA';

	public function test_site_key_is_saved_trimmed() {
		$new = 'pk_live_' . str_repeat( 'b', 30 );

		$this->assertSame( $new, $this->admin->sanitize_site_key( "  $new \n" ) );
		$this->assertSame( array(), $this->errors );
	}

	public function test_empty_site_key_clears_it() {
		$this->assertSame( '', $this->admin->sanitize_site_key( '   ' ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * Red-proof: before the strict check, a key wrapped in markup was
	 * cleaned by sanitize_text_field() and saved. It must be refused.
	 *
	 * @dataProvider markup_in_a_key
	 */
	public function test_site_key_with_markup_is_refused_and_the_previous_key_kept( $value ) {
		$this->assertSame( self::GOOD_KEY, $this->admin->sanitize_site_key( $value ) );
		$this->assertSame( array( 'samomind_site_key' ), $this->errors );
	}

	public function markup_in_a_key() {
		$new = 'pk_live_' . str_repeat( 'b', 30 );
		return array(
			'script tag'          => array( '<script>alert(1)</script>' ),
			'key inside a tag'    => array( "<script>$new</script>" ),
			'full dashboard tag'  => array( '<script src="https://widget.samomind.com/widget.js" data-site-key="' . $new . '" async></script>' ),
			'bold'                => array( "<b>$new</b>" ),
			'quote breakout'      => array( $new . '" onload="alert(1)' ),
			'not a key'           => array( 'hello' ),
			'array'               => array( array( $new ) ),
		);
	}

	public function test_site_key_message_is_escaped() {
		$this->admin->sanitize_site_key( '<script>' );

		$this->assertStringNotContainsString( '<', $this->messages['samomind_site_key'] );
	}

	public function test_widget_switch_is_on_only_for_a_ticked_box() {
		$this->assertSame( '1', $this->admin->sanitize_widget_enabled( '1' ) );
		$this->assertSame( '1', $this->admin->sanitize_widget_enabled( 'on' ) );
		$this->assertSame( '0', $this->admin->sanitize_widget_enabled( '0' ) );
		$this->assertSame( '0', $this->admin->sanitize_widget_enabled( '' ) );
		$this->assertSame( '0', $this->admin->sanitize_widget_enabled( null ) );
		$this->assertSame( '0', $this->admin->sanitize_widget_enabled( array( '1' ) ) );
	}

	public function test_settings_error_messages_are_escaped() {
		$this->admin->sanitize_api_base_url( 'http://example.com' );
		$this->admin->sanitize_key_id( 'nope' );
		$this->admin->sanitize_site_key( 'nope' );

		$this->assertCount( 3, $this->messages );
		foreach ( $this->messages as $message ) {
			$this->assertStringNotContainsString( '<', $message );
		}
	}

	public function test_origin_comes_from_home_url_without_a_path() {
		$helpers = new SAMOMIND_Helpers();
		Functions\when( 'home_url' )->justReturn( 'https://Example.com/blog' );
		$this->assertSame( 'https://example.com', $helpers->get_site_origin() );

		Functions\when( 'home_url' )->justReturn( 'http://localhost:8888' );
		$this->assertSame( 'http://localhost:8888', $helpers->get_site_origin() );
	}

	public function test_checklist_is_stored_as_the_ticked_types() {
		// Ticked: post, book. Unticked: page, event. "retired_type" is not on the form, so it stays ticked.
		$stored = $this->admin->sanitize_post_types(
			array(
				'offered'  => array( 'post', 'page', 'book', 'event', 'not_a_type' ),
				'included' => array( 'post', 'book', 'not_a_type' ),
			)
		);

		$this->assertSame( array( 'retired_type', 'post', 'book' ), $stored );
	}

	public function test_unticking_everything_leaves_only_types_not_on_the_form() {
		$stored = $this->admin->sanitize_post_types( array( 'offered' => array( 'post', 'event' ) ) );

		$this->assertSame( array( 'retired_type' ), $stored );
	}

	public function test_checklist_without_offered_list_keeps_the_previous_value() {
		$this->assertSame( array( 'post', 'retired_type', 'event' ), $this->admin->sanitize_post_types( 'junk' ) );
		$this->assertSame( array( 'post', 'retired_type', 'event' ), $this->admin->sanitize_post_types( array( 'included' => array( 'page' ) ) ) );
		$this->assertSame( array(), $this->admin->sanitize_post_types( array() ) );
	}
}
