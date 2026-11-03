<?php
/**
 * uninstall.php removes everything and disconnects a still-connected site.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * Records the cleanup query.
 */
class SAMOMIND_Fake_Wpdb {

	public $options = 'wp_options';
	public $queries = array();

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 0;
	}
}

class UninstallTest extends SAMOMIND_TestCase {

	private $deleted  = array();
	private $cleared  = array();
	private $requests = array();

	private function run_uninstall( array $options ) {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'samomind-ai-assistant/samomind-ai-assistant.php' );
		}

		$deleted  = &$this->deleted;
		$cleared  = &$this->cleared;
		$requests = &$this->requests;
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$requests ) {
				$requests[] = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"state":"disconnected"}',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( '4f8e2a1c-0b3d-4e5f-8a9b-1c2d3e4f5a6b' );

		$GLOBALS['wpdb'] = new SAMOMIND_Fake_Wpdb();

		include SAMOMIND_PLUGIN_DIR . 'uninstall.php';
	}

	public function test_removes_every_option_and_scheduled_hook() {
		$this->run_uninstall( array() );

		$this->assertSame(
			array(
				'samomind_api_base_url',
				'samomind_key_id',
				'samomind_api_secret',
				'samomind_embed_snippet',
				'samomind_excluded_post_types',
				'samomind_connection',
				'samomind_sync',
				'samomind_queue',
				'samomind_log',
				'samomind_lock',
			),
			$this->deleted
		);
		$this->assertContains( 'SAMOMIND/worker', $this->cleared );
		$this->assertContains( 'SAMOMIND/hourly', $this->cleared );
		$this->assertStringContainsString( 'samomind\\_notice\\_', $GLOBALS['wpdb']->queries[0] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_disconnects_a_site_that_is_still_connected() {
		$this->run_uninstall(
			array(
				'samomind_connection'   => array( 'source_id' => '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f' ),
				'samomind_api_base_url' => 'https://api.samomind.com',
				'samomind_key_id'       => '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10',
				'samomind_api_secret'   => 'smik_test',
			)
		);

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.samomind.com/api/v1/ingest/disconnect', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'source_id' => '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
				'reason'    => 'uninstall',
			),
			$this->requests[0]['body']
		);
	}
}
