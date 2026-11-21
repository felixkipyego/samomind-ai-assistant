<?php
/**
 * The Connect and Disconnect buttons: who may press them, and what they say.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

class SAMOMIND_Fake_Plugin_Connect {

	public $codes        = array();
	public $disconnected = 0;
	public $result;

	public function connect( $typed ) {
		$this->codes[] = $typed;
		return $this->result;
	}

	public function disconnect() {
		++$this->disconnected;
	}
}

class PluginConnectAdminTest extends SAMOMIND_TestCase {

	/**
	 * @var SAMOMIND_Fake_Plugin_Connect
	 */
	private $connect;

	/**
	 * Notices stored by finish().
	 *
	 * @var array
	 */
	private $notices = array();

	private $can = true;

	protected function setUp(): void {
		parent::setUp();

		$this->can     = true;
		$this->notices = array();
		$this->connect = new SAMOMIND_Fake_Plugin_Connect();
		$this->connect->result = array(
			'site_name'        => 'Shop Example',
			'home_url_allowed' => true,
		);

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->justReturn( 'admin.php?page=samomind-settings' );
		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				if ( 'samomind_plugin_connect' !== $action && 'samomind_plugin_disconnect' !== $action ) {
					throw new RuntimeException( 'wrong nonce action' );
				}
				if ( empty( $_POST['_wpnonce'] ) ) {
					throw new RuntimeException( 'bad nonce' );
				}
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->notices[] = $value;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new RuntimeException( 'redirect' );
			}
		);

		$GLOBALS['samomind_test_plugin'] = (object) array(
			'plugin_connect' => $this->connect,
			'helpers'        => new SAMOMIND_Helpers(),
		);
		$_POST                           = array(
			'_wpnonce'              => 'abc123',
			'samomind_connect_code' => ' smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567 ',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function press( $method ) {
		$admin = new SAMOMIND_Admin();
		try {
			$admin->$method();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'returned';
	}

	public function test_connect_needs_the_manage_options_capability() {
		$this->can = false;

		$this->assertSame( 'wp_die', $this->press( 'handle_plugin_connect' ) );
		$this->assertSame( array(), $this->connect->codes );
	}

	public function test_connect_needs_a_valid_nonce() {
		unset( $_POST['_wpnonce'] );

		$this->assertSame( 'bad nonce', $this->press( 'handle_plugin_connect' ) );
		$this->assertSame( array(), $this->connect->codes );
	}

	public function test_disconnect_needs_the_capability_and_a_nonce() {
		$this->can = false;
		$this->assertSame( 'wp_die', $this->press( 'handle_plugin_disconnect' ) );

		$this->can = true;
		unset( $_POST['_wpnonce'] );
		$this->assertSame( 'bad nonce', $this->press( 'handle_plugin_disconnect' ) );

		$this->assertSame( 0, $this->connect->disconnected );
	}

	public function test_connect_hands_the_typed_code_on_and_reports_success() {
		$this->assertSame( 'redirect', $this->press( 'handle_plugin_connect' ) );

		$this->assertSame( array( 'smc_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ), $this->connect->codes );
		$this->assertSame( 'success', $this->notices[0]['type'] );
		$this->assertStringNotContainsString( 'smc_', $this->notices[0]['message'] );
	}

	public function test_connect_tells_the_owner_to_allow_the_website_address() {
		$this->connect->result['home_url_allowed'] = false;

		$this->press( 'handle_plugin_connect' );

		$this->assertSame( 'warning', $this->notices[0]['type'] );
		$this->assertStringContainsString( 'Finish in your Samomind dashboard: click Allow next to your website address.', $this->notices[0]['message'] );
	}

	public function test_connect_shows_the_refusal_without_the_code() {
		$this->connect->result = new WP_Error( 'refused', "That code isn't right. Copy it again from your Samomind dashboard." );

		$this->press( 'handle_plugin_connect' );

		$this->assertSame( 'error', $this->notices[0]['type'] );
		$this->assertSame( "That code isn't right. Copy it again from your Samomind dashboard.", $this->notices[0]['message'] );
	}

	public function test_disconnect_says_it_only_clears_this_site() {
		$this->press( 'handle_plugin_disconnect' );

		$this->assertSame( 1, $this->connect->disconnected );
		$this->assertStringContainsString( 'To stop these keys working everywhere, create a new code in your Samomind dashboard.', $this->notices[0]['message'] );
	}

	public function test_the_new_actions_are_registered() {
		$this->assertContains( 'plugin_connect', SAMOMIND_Admin::ACTIONS );
		$this->assertContains( 'plugin_disconnect', SAMOMIND_Admin::ACTIONS );
	}
}
