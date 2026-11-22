<?php
/**
 * The "WooCommerce is active, but products are not synced" notice: once a
 * user dismisses it, it stays dismissed for that user.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

class AdminNoticeTest extends SAMOMIND_TestCase {

	/**
	 * User meta by user id, as update_user_meta() wrote it.
	 *
	 * @var array
	 */
	private $meta = array();

	private $can = true;

	private $user_id = 7;

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = array();
		$this->can     = true;
		$this->user_id = 7;

		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->user_id;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				return isset( $this->meta[ $user_id ][ $key ] ) ? $this->meta[ $user_id ][ $key ] : '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'check_ajax_referer' )->alias(
			function ( $action, $field ) {
				if ( 'samomind_nonce' !== $action || 'nonce' !== $field || empty( $_POST['nonce'] ) ) {
					throw new RuntimeException( 'bad nonce' );
				}
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function () {
				throw new RuntimeException( 'success' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status = 200 ) {
				throw new RuntimeException( 'error ' . $status );
			}
		);

		$content                         = $this->getMockBuilder( SAMOMIND_Content::class )->onlyMethods( array( 'shop_products_not_synced' ) )->getMock();
		$content->method( 'shop_products_not_synced' )->willReturn( true );
		$GLOBALS['samomind_test_plugin'] = (object) array( 'content' => $content );
		$_POST                           = array(
			'nonce'  => 'abc123',
			'notice' => 'shop_products',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function private_call( $method ) {
		$admin      = ( new ReflectionClass( SAMOMIND_Admin::class ) )->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod( SAMOMIND_Admin::class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		ob_start();
		$reflection->invoke( $admin );
		return ob_get_clean();
	}

	private function dismiss() {
		$admin = ( new ReflectionClass( SAMOMIND_Admin::class ) )->newInstanceWithoutConstructor();
		try {
			$admin->ajax_dismiss_notice();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'returned';
	}

	public function test_the_notice_names_itself_so_the_page_can_report_a_dismissal() {
		$this->assertStringContainsString( 'data-samomind-notice="shop_products"', $this->private_call( 'render_shop_notice' ) );
	}

	public function test_a_dismissed_notice_does_not_come_back() {
		$this->assertSame( 'success', $this->dismiss() );

		$this->assertSame( array( 'shop_products' ), $this->meta[7]['samomind_dismissed_notices'] );
		$this->assertSame( '', $this->private_call( 'render_shop_notice' ), 'The next page load does not show it.' );
	}

	public function test_dismissing_is_per_user() {
		$this->dismiss();

		$this->user_id = 8;
		$this->assertStringContainsString( 'products are not synced', $this->private_call( 'render_shop_notice' ), 'Another administrator still sees it.' );
	}

	public function test_dismissing_twice_stores_it_once() {
		$this->dismiss();
		$this->dismiss();

		$this->assertSame( array( 'shop_products' ), $this->meta[7]['samomind_dismissed_notices'] );
	}

	public function test_dismissing_needs_a_nonce_and_the_capability() {
		unset( $_POST['nonce'] );
		$this->assertSame( 'bad nonce', $this->dismiss() );

		$_POST['nonce'] = 'abc123';
		$this->can      = false;
		$this->assertSame( 'error 403', $this->dismiss() );

		$this->assertSame( array(), $this->meta );
	}

	public function test_only_known_notices_can_be_stored() {
		$_POST['notice'] = 'anything_else';
		$this->assertSame( 'error 400', $this->dismiss() );

		unset( $_POST['notice'] );
		$this->assertSame( 'error 400', $this->dismiss() );

		$this->assertSame( array(), $this->meta );
	}
}
