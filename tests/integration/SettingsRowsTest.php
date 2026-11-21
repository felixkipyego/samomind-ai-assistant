<?php
/**
 * The Settings sanitizers make a missing option row themselves. That must not
 * call the sanitizer again and again when the row is missing: it once ran
 * until memory was gone.
 *
 * @package SAMOMIND
 */

class SettingsRowsTest extends SAMOMIND_Integration_TestCase {

	const KEY_ID = '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10';

	private $registered = array( 'samomind_api_base_url', 'samomind_key_id', 'samomind_post_types', 'samomind_widget_enabled', 'samomind_site_key' );

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'samomind_key_id' );
		delete_option( 'samomind_api_base_url' );
		// What admin_init does on a real admin request.
		( new SAMOMIND_Admin() )->register_settings();
	}

	protected function tearDown(): void {
		foreach ( $this->registered as $option ) {
			unregister_setting( SAMOMIND_Admin::SETTINGS_GROUP, $option );
		}
		// The rows activation makes, not autoloaded.
		delete_option( 'samomind_key_id' );
		delete_option( 'samomind_api_base_url' );
		add_option( 'samomind_key_id', '', '', false );
		add_option( 'samomind_api_base_url', SAMOMIND_Helpers::DEFAULT_API_BASE_URL, '', false );
		parent::tearDown();
	}

	public function test_saving_a_key_id_when_its_row_is_missing_works() {
		$this->assertFalse( get_option( 'samomind_key_id', false ), 'The row is gone.' );

		update_option( 'samomind_key_id', self::KEY_ID );

		$this->assertSame( self::KEY_ID, get_option( 'samomind_key_id' ) );
	}

	public function test_saving_an_api_address_when_its_row_is_missing_works() {
		$this->assertFalse( get_option( 'samomind_api_base_url', false ), 'The row is gone.' );

		update_option( 'samomind_api_base_url', 'https://api.example.com/' );

		$this->assertSame( 'https://api.example.com', get_option( 'samomind_api_base_url' ) );
	}

	public function test_a_bad_value_with_the_row_missing_is_refused_without_looping() {
		update_option( 'samomind_key_id', 'not-a-key-id' );
		update_option( 'samomind_api_base_url', 'http://api.example.com' );

		$this->assertSame( '', (string) get_option( 'samomind_key_id', '' ), 'The key id was refused.' );
		$this->assertSame( SAMOMIND_Helpers::DEFAULT_API_BASE_URL, SAMOMIND()->helpers->get_api_base_url(), 'The http address was refused.' );
	}

	public function test_an_empty_key_id_when_the_row_is_missing_is_stored_as_empty() {
		update_option( 'samomind_key_id', '' );

		$this->assertSame( '', get_option( 'samomind_key_id' ) );
	}

	public function test_the_sanitizers_are_asked_once_per_save_not_over_and_over() {
		$calls = 0;
		$count = function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'sanitize_option_samomind_key_id', $count, 99 );
		update_option( 'samomind_key_id', self::KEY_ID );
		remove_filter( 'sanitize_option_samomind_key_id', $count, 99 );

		$this->assertLessThanOrEqual( 3, $calls, 'The row is made once, then the value saved.' );
	}
}
