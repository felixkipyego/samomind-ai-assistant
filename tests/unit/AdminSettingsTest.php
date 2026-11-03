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
					'samomind_embed_snippet' => '',
					'samomind_excluded_post_types' => array( 'retired_type', 'event' ),
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

	public function test_bad_snippet_keeps_the_previous_one() {
		$this->assertSame( '', $this->admin->sanitize_embed_snippet( '<script>alert(1)</script>' ) );
		$this->assertSame( array( 'samomind_embed_snippet' ), $this->errors );
	}

	public function test_settings_error_messages_are_escaped() {
		// settings_errors() prints messages as HTML; the shape message quotes "<script>".
		$this->admin->sanitize_embed_snippet( '<p>not a script</p>' );
		$this->admin->sanitize_api_base_url( 'http://example.com' );
		$this->admin->sanitize_key_id( 'nope' );

		$this->assertCount( 3, $this->messages );
		foreach ( $this->messages as $message ) {
			$this->assertStringNotContainsString( '<', $message );
		}
		$this->assertStringContainsString( '&lt;script&gt;', $this->messages['samomind_embed_snippet'] );
	}

	public function test_good_snippet_is_saved() {
		$snippet = '<script src="https://widget.samomind.com/widget.js" data-site-key="pk_live_abc" async></script>';

		$this->assertSame( $snippet, $this->admin->sanitize_embed_snippet( "\n" . $snippet . "\n" ) );
		$this->assertSame( array(), $this->errors );
	}

	public function test_checklist_is_stored_as_exclusions() {
		// Ticked: post, event. Unticked: page, book. "retired_type" is not on the form, so its exclusion stays.
		$stored = $this->admin->sanitize_excluded_post_types(
			array(
				'offered'  => array( 'post', 'page', 'book', 'event', 'not_a_type' ),
				'included' => array( 'post', 'event' ),
			)
		);

		$this->assertSame( array( 'retired_type', 'page', 'book' ), $stored );
	}

	public function test_unticking_everything_excludes_every_offered_type() {
		$stored = $this->admin->sanitize_excluded_post_types( array( 'offered' => array( 'post', 'page' ) ) );

		$this->assertSame( array( 'retired_type', 'event', 'post', 'page' ), $stored );
	}

	public function test_checklist_without_offered_list_keeps_the_previous_value() {
		$this->assertSame( array( 'retired_type', 'event' ), $this->admin->sanitize_excluded_post_types( 'junk' ) );
		$this->assertSame( array( 'retired_type', 'event' ), $this->admin->sanitize_excluded_post_types( array( 'included' => array( 'post' ) ) ) );
		$this->assertSame( array(), $this->admin->sanitize_excluded_post_types( array() ) );
	}
}
