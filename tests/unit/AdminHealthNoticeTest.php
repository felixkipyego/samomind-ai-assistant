<?php
/**
 * The sync health notice: where it shows, the exact words, and that it goes
 * by itself once the problem is solved.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * The parts of the connection the notice reads.
 */
class SAMOMIND_Fake_Health_Connection {

	public $connected = true;
	public $blocked   = false;
	public $failing   = false;
	public $block     = array(
		'code'    => '',
		'message' => '',
	);
	public $last_error = '';

	public function is_connected() {
		return $this->connected;
	}

	public function is_blocked() {
		return $this->blocked;
	}

	public function is_failing_too_long() {
		return $this->failing;
	}

	public function get_block() {
		return $this->block;
	}

	public function get_state() {
		return array( 'last_error' => $this->last_error );
	}
}

class AdminHealthNoticeTest extends SAMOMIND_TestCase {

	/**
	 * @var SAMOMIND_Fake_Health_Connection
	 */
	private $connection;

	private $screen = 'dashboard';

	private $can = true;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = new SAMOMIND_Fake_Health_Connection();
		$this->screen     = 'dashboard';
		$this->can        = true;

		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'get_current_screen' )->alias(
			function () {
				return null === $this->screen ? null : (object) array( 'id' => $this->screen );
			}
		);
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias(
			function ( $path ) {
				return 'https://shop.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$GLOBALS['samomind_test_plugin'] = (object) array( 'connection' => $this->connection );
	}

	private function notice() {
		ob_start();
		( new SAMOMIND_Admin() )->render_health_notice();
		return ob_get_clean();
	}

	private function pause() {
		$this->connection->blocked = true;
		$this->connection->block   = array(
			'code'    => 'key_revoked',
			'message' => 'The key was revoked.',
		);
	}

	public function test_it_shows_on_the_dashboard_the_plugins_screen_and_the_plugins_own_pages() {
		$this->pause();

		foreach ( array( 'dashboard', 'plugins', 'toplevel_page_samomind-assistant', 'samomind_page_samomind-status', 'samomind_page_samomind-settings' ) as $screen ) {
			$this->screen = $screen;
			$this->assertStringContainsString( 'Samomind sync is paused', $this->notice(), $screen );
		}
	}

	public function test_it_stays_quiet_everywhere_else_in_the_admin() {
		$this->pause();

		foreach ( array( 'edit-post', 'post', 'options-general', 'themes', 'users', 'update-core', 'upload', 'woocommerce_page_wc-orders', 'plugin-install' ) as $screen ) {
			$this->screen = $screen;
			$this->assertSame( '', $this->notice(), $screen );
		}

		$this->screen = null;
		$this->assertSame( '', $this->notice(), 'No screen known' );
	}

	public function test_it_is_for_administrators_only() {
		$this->pause();
		$this->can = false;

		$this->assertSame( '', $this->notice() );
	}

	public function test_it_goes_by_itself_once_the_problem_is_solved() {
		$this->pause();
		$this->assertNotSame( '', $this->notice() );

		$this->connection->blocked = false;
		$this->assertSame( '', $this->notice(), 'Connected and not blocked: nothing to say.' );
	}

	public function test_a_site_that_never_connected_says_nothing() {
		$this->connection->connected = false;

		$this->assertSame( '', $this->notice() );
	}

	public function test_the_words_for_a_paused_sync() {
		$this->pause();

		$this->assertSame(
			'<div class="notice notice-error"><p>Samomind sync is paused: The key was revoked. (key_revoked) <a href="https://shop.example/wp-admin/admin.php?page=samomind-status">Open Samomind status</a></p></div>',
			$this->notice()
		);
	}

	public function test_the_words_for_a_site_disconnected_in_the_dashboard() {
		$this->connection->connected = false;
		$this->connection->blocked   = true;
		$this->connection->block     = array(
			'code'    => 'source_disconnected',
			'message' => 'Disconnected from the Samomind dashboard.',
		);

		$this->assertSame(
			'<div class="notice notice-warning"><p>Samomind: Disconnected from the Samomind dashboard. Nothing is sent until you click Connect. <a href="https://shop.example/wp-admin/admin.php?page=samomind-status">Open Samomind status</a></p></div>',
			$this->notice()
		);
	}

	public function test_the_words_for_a_sync_failing_for_over_a_day() {
		$this->connection->failing    = true;
		$this->connection->last_error = 'timeout';

		$this->assertSame(
			'<div class="notice notice-error"><p>Samomind sync has been failing for more than 24 hours. Last error: timeout <a href="https://shop.example/wp-admin/admin.php?page=samomind-status">Open Samomind status</a></p></div>',
			$this->notice()
		);
	}

	public function test_it_is_not_dismissible() {
		$this->pause();

		$this->assertStringNotContainsString( 'is-dismissible', $this->notice() );
	}
}
