<?php
/**
 * The cached-page row of the threat table (design section 5): nothing about
 * the visitor is ever in a front-end page.
 *
 * @package SAMOMIND
 */

class OrdersFrontEndTest extends SAMOMIND_Integration_TestCase {

	/**
	 * The head and footer of a front-end page, as a logged-in shopper's request builds them.
	 */
	private function render() {
		$_SERVER['SERVER_NAME'] = 'localhost';
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		ob_start();
		do_action( 'wp_enqueue_scripts' );
		wp_head();
		wp_footer();
		return (string) ob_get_clean();
	}

	public function test_no_identity_in_front_end_html() {
		update_option( 'samomind_widget_enabled', '1' );
		update_option( 'samomind_site_key', 'pk_live_abcdefghijklmnop' );

		$user = $this->make_user();
		$this->log_in( $user );
		$data = get_userdata( $user );

		$on = $this->render();

		// Nothing of this feature is hooked to anything that prints a page.
		foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_scripts', 'wp_print_footer_scripts', 'wp_body_open', 'the_content', 'template_redirect' ) as $hook ) {
			$callbacks = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ]->callbacks : array();
			foreach ( $callbacks as $priority ) {
				foreach ( $priority as $callback ) {
					$function = $callback['function'];
					$owner    = is_array( $function ) && is_object( $function[0] ) ? get_class( $function[0] ) : '';
					$this->assertStringStartsNotWith( 'SAMOMIND_Orders', $owner, "An order class prints on $hook." );
				}
			}
		}

		// And a shopper's identity is not in it, whatever the feature does.
		$session = hash( 'sha256', wp_get_session_token() );
		foreach ( array( $data->user_login, $data->user_email, $data->display_name, $session, wp_get_session_token(), 'challenge', 'samomind_nonce', 'account-proof' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $on, "The page contains $needle." );
		}
		$this->assertDoesNotMatchRegularExpression( '/(user_id|userId|uid)["\']?\s*[:=]\s*["\']?' . $user . '\b/', $on );

		delete_option( 'samomind_widget_enabled' );
		delete_option( 'samomind_site_key' );
	}

	public function test_the_loader_tag_carries_the_rest_root_of_a_sub_folder_shop() {
		update_option( 'samomind_widget_enabled', '1' );
		update_option( 'samomind_site_key', 'pk_live_' . str_repeat( 'a', 24 ) );

		// The shop lives at /blog, and a security plugin has taken the api.w.org link out of the head.
		$sub_folder = static function () {
			return 'http://localhost/blog';
		};
		add_filter( 'pre_option_home', $sub_folder );
		add_filter( 'pre_option_siteurl', $sub_folder );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );

		try {
			$html     = $this->render();
			$expected = rest_url();

			$this->assertStringContainsString( '/blog/wp-json/', $expected );
			$this->assertStringContainsString( 'data-samomind-rest-root="' . esc_attr( $expected ) . '"', $html );
			$this->assertStringNotContainsString( 'api.w.org', $html );
		} finally {
			remove_filter( 'pre_option_home', $sub_folder );
			remove_filter( 'pre_option_siteurl', $sub_folder );
			add_action( 'wp_head', 'rest_output_link_wp_head', 10, 0 );
			delete_option( 'samomind_widget_enabled' );
			delete_option( 'samomind_site_key' );
		}
	}

	public function test_the_plugin_adds_no_script_data_about_orders_or_users() {
		$this->log_in( $this->make_user() );
		$html = $this->render();
		$this->assertStringNotContainsString( 'samomind_orders', $html );
		$this->assertStringNotContainsString( 'samomind/v1/orders', $html );
	}
}
