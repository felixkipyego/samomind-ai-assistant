<?php
/**
 * Checks for the WordPress.org release, on real WordPress with WooCommerce:
 * the order terms text, the order settings always showing, the notice that
 * stays dismissed, and no outbound request before the owner connects.
 *
 * @package SAMOMIND
 */

require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

class ReleaseChecksTest extends SAMOMIND_Integration_TestCase {

	/**
	 * Every request WordPress was asked to make: the URL.
	 *
	 * @var string[]
	 */
	private $requested = array();

	private $option_names = array( 'samomind_api_base_url', 'samomind_key_id', 'samomind_api_secret', 'samomind_site_key', 'samomind_connection', 'samomind_sync', 'samomind_queue', 'samomind_post_types' );

	protected function setUp(): void {
		parent::setUp();
		$this->requested = array();
		add_filter( 'pre_http_request', array( $this, 'record_request' ), 1, 3 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'record_request' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		foreach ( $this->option_names as $name ) {
			delete_option( $name );
		}
		delete_option( 'samomind_orders_terms' );
		$_POST = array();
		parent::tearDown();
	}

	public function record_request( $pre, $args, $url ) {
		$this->requested[] = $url;
		return $pre;
	}

	public function die_handler() {
		return function () {
			throw new RuntimeException( 'wp_die' );
		};
	}

	private function private_html( $method ) {
		$reflection = new ReflectionMethod( 'SAMOMIND_Admin', $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		ob_start();
		$reflection->invoke( ( new ReflectionClass( 'SAMOMIND_Admin' ) )->newInstanceWithoutConstructor() );
		return (string) ob_get_clean();
	}

	private function plain_text( $html ) {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	// ---- The order terms, version 2026-11-26 --------------------------------

	public function test_the_card_shows_the_final_terms_word_for_word() {
		delete_option( 'samomind_orders_terms' );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$text = $this->plain_text( $this->private_html( 'render_orders_card' ) );

		$this->assertSame( '2026-11-26', SAMOMIND_Orders_Settings::TERMS_VERSION );
		$parts = array(
			'Order status: order data terms (version 2026-11-26)',
			'Before you switch on order status, please confirm that you accept these terms.',
			'Your customers can check only their own orders. A customer proves who they are with a 6-digit code that your shop emails to them, or by being signed in to your shop. After the code, they can see the orders placed with that email address; signed-in customers see the orders of their account. The assistant never decides who someone is.',
			'Samomind looks orders up live from your shop and keeps no copy of your orders. It never receives addresses, phone numbers or payment details. The email address and the code pass through Samomind only while the check runs and are not stored.',
			'The order number, status, dates and order total are always shown to a verified customer. You choose whether item names (with their product photos) and tracking details are also shown.',
			'You are the controller of your shop\'s order data, and Samomind processes it for you under Schedule 1 of the Samomind Terms of Service. You confirm that you have a lawful basis to let customers check their orders this way and that your shop\'s privacy notice tells them.',
			'Keep your orders key secret. If you think it has been exposed, replace it in the Samomind dashboard.',
			'The code emails are sent by your own shop, from its own email address.',
			'You can switch order status off at any time on this page.',
			'Terms of Service: https://samomind.com/terms-and-conditions/',
			'Privacy Policy: https://samomind.com/privacy-policy/',
		);
		$last  = -1;
		foreach ( $parts as $part ) {
			$at = strpos( $text, $part );
			$this->assertNotFalse( $at, 'Missing: ' . $part );
			$this->assertGreaterThan( $last, $at, 'Out of order: ' . $part );
			$last = $at;
		}
	}

	public function test_the_terms_are_a_numbered_list_of_six_and_no_draft_wording_is_left() {
		delete_option( 'samomind_orders_terms' );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$html = $this->private_html( 'render_orders_card' );

		$this->assertSame( 6, preg_match_all( '#<ol>.*?</ol>#s', $html, $lists ) ? substr_count( $lists[0][0], '<li>' ) : 0 );
		$this->assertStringNotContainsString( 'DRAFT', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'legal review', $html );
		$this->assertStringContainsString( 'I accept the order-data terms (version 2026-11-26)', $html );
		$this->assertStringContainsString( 'href="https://samomind.com/terms-and-conditions/"', $html );
		$this->assertStringContainsString( 'href="https://samomind.com/privacy-policy/"', $html );
	}

	public function test_accepting_records_the_version_the_administrator_and_the_time() {
		delete_option( 'samomind_orders_terms' );
		$this->assertFalse( SAMOMIND()->orders->terms_are_accepted() );

		SAMOMIND()->orders->accept_terms( 5 );

		$terms = SAMOMIND()->orders->get_terms();
		$this->assertSame( '2026-11-26', $terms['version'] );
		$this->assertSame( 5, $terms['user_id'] );
		$this->assertGreaterThan( 0, $terms['time'] );
	}

	public function test_a_shop_on_the_earlier_terms_is_asked_to_accept_the_updated_ones_by_an_administrator() {
		$this->assertSame( '2026-11', SAMOMIND()->orders->get_terms()['version'] );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$html = $this->private_html( 'render_orders_card' );
		$text = $this->plain_text( $html );

		$this->assertStringContainsString( 'Updated order terms: customers can see all their orders after one code. Review and accept to switch this on.', $text );
		$this->assertStringContainsString( 'Order status: order data terms (version 2026-11-26)', $text );
		$this->assertSame( 1, substr_count( $html, 'class="samomind-terms"' ), 'The terms are shown once.' );
		$this->assertStringContainsString( 'name="action" value="samomind_accept_orders_terms"', $html );
		$this->assertStringContainsString( 'Accept the updated terms', $html );
		$this->assertStringNotContainsString( 'name="samomind_orders_terms"', $html, 'No second checkbox: the button is the acceptance.' );
		$this->assertStringContainsString( 'name="samomind_orders_enabled"', $html );
		$this->assertStringContainsString( 'checked=\'checked\'>', $html, 'Order status stays switched on.' );
		$this->assertTrue( SAMOMIND()->orders->is_enabled(), 'The new terms never switch order status off.' );
	}

	public function test_the_updated_terms_notice_is_for_administrators_and_only_until_they_are_accepted() {
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertStringNotContainsString( 'samomind_accept_orders_terms', $this->private_html( 'render_orders_card' ), 'No button for a user without manage_options.' );

		SAMOMIND()->orders->accept_terms( 1 );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$html = $this->private_html( 'render_orders_card' );
		$this->assertStringNotContainsString( 'Updated order terms:', $html );
		$this->assertStringNotContainsString( 'samomind_accept_orders_terms', $html );
		$this->assertStringContainsString( 'name="samomind_orders_terms"', $html );
	}

	private function press_accept( $nonce_ok = true ) {
		$_REQUEST['_wpnonce'] = $nonce_ok ? wp_create_nonce( 'samomind_accept_orders_terms' ) : 'bad';
		$die                  = static function () {
			return static function () {
				throw new RuntimeException( 'wp_die' );
			};
		};
		$redirect             = static function () {
			throw new RuntimeException( 'redirect' );
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_redirect', $redirect );
		try {
			( new SAMOMIND_Admin() )->handle_accept_orders_terms();
			$result = 'returned';
		} catch ( RuntimeException $e ) {
			$result = $e->getMessage();
		}
		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_redirect', $redirect );
		unset( $_REQUEST['_wpnonce'] );
		return $result;
	}

	public function test_the_accept_button_records_the_new_terms_for_an_administrator_only() {
		$this->assertFalse( SAMOMIND()->orders->history_terms_are_accepted() );

		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertSame( 'wp_die', $this->press_accept() );
		$this->assertFalse( SAMOMIND()->orders->history_terms_are_accepted(), 'A user without manage_options cannot accept.' );

		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		$this->assertSame( 'wp_die', $this->press_accept( false ) );
		$this->assertFalse( SAMOMIND()->orders->history_terms_are_accepted(), 'A bad nonce changes nothing.' );

		$this->assertSame( 'redirect', $this->press_accept() );
		$terms = SAMOMIND()->orders->get_terms();
		$this->assertSame( '2026-11-26', $terms['version'] );
		$this->assertSame( $admin, $terms['user_id'] );
		$this->assertTrue( SAMOMIND()->orders->history_enabled() );
		$this->assertSame( self::SECRET, SAMOMIND()->orders->get_secret(), 'The key is untouched.' );
		$this->assertTrue( SAMOMIND()->orders->is_opted_in() );
	}

	// ---- The settings are always shown --------------------------------------

	public function test_the_order_settings_show_whatever_the_account_and_never_mention_payment() {
		delete_option( 'samomind_orders_terms' );
		wp_set_current_user( $this->make_user( 'administrator' ) );

		foreach ( array( true, false ) as $opted_in ) {
			update_option( SAMOMIND_Orders_Settings::OPT_IN, $opted_in ? '1' : '0', false );
			$html = $this->private_html( 'render_orders_card' );

			$this->assertStringContainsString( 'name="samomind_orders_enabled"', $html );
			$this->assertStringContainsString( 'name="samomind_orders_secret"', $html );
			$this->assertStringContainsString( 'name="samomind_orders_terms"', $html );
			$this->assertStringNotContainsString( 'disabled', $html, 'Nothing is locked.' );
			$this->assertDoesNotMatchRegularExpression( '/upgrade|premium|\bpro\b|paid|payment plan|subscri|pricing|buy /i', $this->plain_text( $html ), 'No payment or upgrade wording.' );
			// Samomind sends the plugin no word on whether the account has order status, so the card says nothing about it.
			$this->assertStringNotContainsString( 'not switched on for this Samomind account', $html );
		}
	}

	// ---- The notice that stays dismissed -------------------------------------

	public function test_the_products_notice_stays_dismissed_for_that_user_only() {
		$content = array_diff( SAMOMIND()->content->get_selected_post_types(), array( 'product' ) );
		update_option( 'samomind_post_types', array_values( $content ), false );
		$this->assertTrue( SAMOMIND()->content->shop_products_not_synced() );

		$one = $this->make_user( 'administrator' );
		$two = $this->make_user( 'administrator' );

		wp_set_current_user( $one );
		$this->assertStringContainsString( 'products are not synced', $this->private_html( 'render_shop_notice' ) );

		$_POST = array(
			'nonce'  => wp_create_nonce( 'samomind_nonce' ),
			'notice' => 'shop_products',
		);
		$_REQUEST['nonce'] = $_POST['nonce'];
		ob_start();
		try {
			( new SAMOMIND_Admin() )->ajax_dismiss_notice();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$answer = json_decode( (string) ob_get_clean(), true );
		$this->assertTrue( $answer['success'] );

		$this->assertSame( '', $this->private_html( 'render_shop_notice' ), 'Dismissed: it does not come back.' );
		$this->assertSame( array( 'shop_products' ), get_user_meta( $one, 'samomind_dismissed_notices', true ) );

		wp_set_current_user( $two );
		$this->assertStringContainsString( 'products are not synced', $this->private_html( 'render_shop_notice' ), 'Another administrator still sees it.' );

		// Solving it makes it go by itself.
		update_option( 'samomind_post_types', array( 'product' ), false );
		$this->assertSame( '', $this->private_html( 'render_shop_notice' ) );
		unset( $_REQUEST['nonce'] );
	}

	// ---- Nothing is sent before the owner connects ---------------------------

	public function test_no_request_before_keys_are_saved_or_a_code_is_pasted() {
		foreach ( $this->option_names as $name ) {
			delete_option( $name );
		}
		$this->assertFalse( SAMOMIND()->connection->is_connected() );
		$this->assertFalse( SAMOMIND()->helpers->has_credentials() );

		// Activation.
		SAMOMIND()->activate();

		// An administrator opens every page and the notices.
		// Only this plugin's own admin callbacks run: WooCommerce's admin screens cannot load in a CLI run.
		// register_settings() is left out: it adds sanitizers that stay for the rest of the process, and these tests delete the option rows.
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$admin = new SAMOMIND_Admin();
		$admin->register_admin_menu();
		SAMOMIND()->scheduler->ensure_hourly();
		ob_start();
		$admin->render_health_notice();
		$admin->render_status_page();
		$admin->render_settings_page();
		ob_end_clean();

		// A visitor opens a page of the shop.
		wp_set_current_user( 0 );
		do_action( 'wp_enqueue_scripts' );

		// Everything the plugin scheduled, and the plugin's own ticks, run now.
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( $events as $hook => $by_args ) {
				if ( 0 === strpos( $hook, 'SAMOMIND' ) || 0 === strpos( $hook, 'samomind' ) ) {
					foreach ( $by_args as $event ) {
						do_action_ref_array( $hook, $event['args'] );
					}
				}
			}
		}
		do_action( SAMOMIND_Scheduler::HOURLY_HOOK );
		do_action( SAMOMIND_Scheduler::WORKER_HOOK );

		$this->assertSame( array(), $this->requested, 'No request to Samomind or anywhere else.' );
	}

	public function test_the_recorder_does_see_a_request_once_keys_are_saved() {
		update_option( 'samomind_api_base_url', 'https://api.samomind.com', false );
		update_option( 'samomind_key_id', '3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10', false );
		update_option( 'samomind_api_secret', 'smik_Zk9Qw2Lm8Vb5Nt0Zr7Yh4Kd1Sf6Ua_Gp8eTqWcAxyz', false );

		SAMOMIND()->connection->connect();

		$this->assertSame( array( 'https://api.samomind.com/api/v1/ingest/connect' ), $this->requested );
	}
}
