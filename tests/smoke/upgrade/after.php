<?php
/**
 * Step 2 of the update test, run on the NEW plugin after its files replaced
 * the old ones, with no activation: keys stay, the admin pages load, a sync
 * runs. Prints no key, secret or answer.
 */

require __DIR__ . '/mock-samomind.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

global $wpdb;

smoke_check( 'new files are running (' . SAMOMIND_VERSION . ')', '0.4.1' === SAMOMIND_VERSION );

// Keys and settings stay.
smoke_check( 'key id stays', SMOKE_KEY_ID === get_option( 'samomind_key_id' ) );
smoke_check( 'secret stays', SMOKE_SECRET === get_option( 'samomind_api_secret' ) );
smoke_check( 'site key stays', SMOKE_SITE_KEY === get_option( 'samomind_site_key' ) );
smoke_check( 'API address stays', SMOKE_API_BASE === get_option( 'samomind_api_base_url' ) );
smoke_check( 'the secret is still not autoloaded', ! isset( wp_load_alloptions()['samomind_api_secret'] ) );
smoke_check( 'still connected', SAMOMIND()->connection->is_connected() );
smoke_check( 'the widget is still on', SAMOMIND()->helpers->widget_is_active() );

// What 0.4.1 needs, with no activation hook.
// The order settings and the accepted terms of the earlier release are kept, and the new terms are not taken for the shop.
smoke_check( 'order status stays on', '1' === get_option( 'samomind_orders_enabled' ) && SMOKE_ORDERS_SECRET === get_option( 'samomind_orders_secret' ) );
smoke_check( 'the accepted terms stay 2026-11', '2026-11' === SAMOMIND()->orders->get_terms()['version'] );
smoke_check( 'order status is still enabled with the earlier terms', SAMOMIND()->orders->is_enabled() );
smoke_check( 'order history is off until the new terms are accepted', false === SAMOMIND()->orders->capabilities()['history'] );
smoke_check( 'the orders table of the old release is still there', SAMOMIND_Orders_State::DB_VERSION === (string) get_option( 'samomind_orders_db_version', '' ) );

// The one-time resend of every product, started by the update itself.
$state = SAMOMIND()->sync->get_state();
smoke_check( 'the update queued a resend of the products only', 'running' === $state['full']['state'] && 'product' === $state['full']['only'] );
smoke_check( 'it is not marked done before it ran', SAMOMIND_Sync::CARDS_VERSION !== get_option( SAMOMIND_Sync::CARDS_OPTION, '' ) );
$progress = smoke_run_sync_to_the_end();
smoke_check( 'the resend ran to the end (' . $progress['sent'] . ' sent)', 'complete' === $progress['state'] && 2 === $progress['sent'] && 0 === $progress['refused'] );
smoke_check( 'it sent products only', count( $GLOBALS['smoke_documents'] ) === 2 && array( 'product' ) === array_values( array_unique( array_column( $GLOBALS['smoke_documents'], 'kind' ) ) ) );
$cards = array_values( $GLOBALS['smoke_documents'] );
smoke_check( 'each product carries the three card fields', isset( $cards[0]['fields']['image_url'] ) || array_key_exists( 'image_url', (array) $cards[0]['fields'] ) );
smoke_check( 'the prices are shown as the shop shows them', in_array( $cards[0]['fields']['price_display'], array( '$10.00', '$25.00' ), true ) && null === $cards[0]['fields']['regular_price_display'] && null === $cards[0]['fields']['image_url'] );
smoke_check( 'it is marked done', SAMOMIND_Sync::CARDS_VERSION === get_option( SAMOMIND_Sync::CARDS_OPTION, '' ) );
$before = count( $GLOBALS['smoke_documents'] );
$GLOBALS['smoke_documents'] = array();
SAMOMIND()->sync->maybe_resend_products();
smoke_run_sync_to_the_end();
smoke_check( 'it runs once: nothing more is sent the second time', array() === $GLOBALS['smoke_documents'] && 2 === $before && 'complete' === SAMOMIND()->sync->get_state()['full']['state'] );
$orders_table = SAMOMIND_Orders_State::table();
$table_exists = function () use ( $wpdb, $orders_table ) {
	return $orders_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) );
};
smoke_check( 'the hourly tick is scheduled', false !== wp_next_scheduled( SAMOMIND_Scheduler::HOURLY_HOOK ) );
smoke_check( 'the orders table of the old release is in place', $table_exists() );

// The admin pages load, with the new sections.
$admin_id = wp_insert_user(
	array(
		'user_login' => 'updatetest_admin',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'updatetest@example.com',
		'role'       => 'administrator',
	)
);
wp_set_current_user( $admin_id );
$admin = new SAMOMIND_Admin();
$admin->register_admin_menu();
$admin->register_settings();
ob_start();
$admin->render_status_page();
$status = (string) ob_get_clean();
ob_start();
$admin->render_settings_page();
$settings = (string) ob_get_clean();
smoke_check( 'the Status page loads', false !== strpos( $status, 'Samomind Status' ) );
smoke_check( 'the Settings page loads', false !== strpos( $settings, 'Samomind Settings' ) );
smoke_check( 'Settings has the Connect box', false !== strpos( $settings, 'Connect to Samomind' ) );
smoke_check( 'Settings has the Advanced fold', false !== strpos( $settings, 'Advanced: enter keys manually' ) );
smoke_check( 'Settings has the order status card', false !== strpos( $settings, 'samomind_orders_enabled' ) );

// Order status as the earlier release left it: a signed ping, and one order checked with a code (version 1).
smoke_check( 'a signed ping from Samomind is answered (HTTP 200)', 200 === smoke_signed_ping() );
smoke_check( 'the same ping signed with a wrong key is refused', 401 === smoke_signed_ping_with_wrong_key() );
$ping = smoke_signed( 'ping' )->get_data();
smoke_check( 'the ping reports order status on and history off', true === $ping['capabilities']['orders']['enabled'] && false === $ping['capabilities']['orders']['history'] );
smoke_check( 'the Settings page offers the updated terms with an Accept button', false !== strpos( $settings, 'Updated order terms: customers can see all their orders after one code.' ) && false !== strpos( $settings, 'samomind_accept_orders_terms' ) );

$order = new WC_Order();
$order->set_billing_email( 'buyer@example.com' );
$order->set_total( 30 );
$order->set_status( 'processing' );
$order->save();
$session = substr( hash( 'sha256', 'update-test' ), 0, 32 );
$start   = smoke_signed( 'start', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $session, 'order_number' => (string) $order->get_id(), 'email' => 'buyer@example.com', 'locale' => 'en' ) );
smoke_run_plugin_shutdown();
smoke_check( 'order status of version 1 works: a code is emailed for one order', 202 === $start->get_status() && 1 === count( $GLOBALS['smoke_mails'] ) );
$email_start = smoke_signed( 'start', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $session, 'scope' => 'email', 'email' => 'buyer@example.com', 'locale' => 'en' ) );
smoke_check( 'history is refused until the new terms are accepted', 403 === $email_start->get_status() && 1 === count( $GLOBALS['smoke_mails'] ) );
smoke_check( 'a list is not granted', array( 'granted' => false ) === smoke_signed( 'list', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $session, 'limit' => 5 ) )->get_data() );

// The administrator accepts the updated terms: history is on, the key and the opt-in are untouched.
SAMOMIND()->orders->accept_terms( $admin_id );
smoke_check( 'accepting the new terms turns history on', SAMOMIND()->orders->history_enabled() && true === smoke_signed( 'ping' )->get_data()['capabilities']['orders']['history'] );
smoke_check( 'the key and the opt-in are untouched', SMOKE_ORDERS_SECRET === get_option( 'samomind_orders_secret' ) && '1' === get_option( 'samomind_orders_enabled' ) );
$session2 = substr( hash( 'sha256', 'update-test-2' ), 0, 32 );
$email_start = smoke_signed( 'start', array( 'request_id' => wp_generate_uuid4(), 'session_ref' => $session2, 'scope' => 'email', 'email' => 'buyer@example.com', 'locale' => 'en' ) );
smoke_run_plugin_shutdown();
smoke_check( 'an email code is now sent for an address with orders', 202 === $email_start->get_status() && 2 === count( $GLOBALS['smoke_mails'] ) );
$order->delete( true );

// Saving the same settings through the Settings API changes nothing.
update_option( 'samomind_key_id', SMOKE_KEY_ID );
update_option( 'samomind_api_base_url', SMOKE_API_BASE );
smoke_check( 'saving the same key id and address keeps them', SMOKE_KEY_ID === get_option( 'samomind_key_id' ) && SMOKE_API_BASE === get_option( 'samomind_api_base_url' ) );

// A check-in and a full sync run, with a new page in the mix.
$checkin = SAMOMIND()->connection->checkin( true );
smoke_check( 'check-in works', ! is_wp_error( $checkin ) );
wp_insert_post(
	array(
		'post_title'   => 'Update test Four',
		'post_content' => 'Text of Four.',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);
SAMOMIND()->sync->start_full_sync();
$progress = smoke_run_sync_to_the_end();
smoke_check( 'a full sync runs to the end (' . $progress['sent'] . ' sent, ' . $progress['refused'] . ' refused)', 'complete' === $progress['state'] && $progress['sent'] >= 4 && 0 === $progress['refused'] );
smoke_check( 'the sync reached the ingest endpoints only', ! array_diff( $GLOBALS['smoke_requests'], array( '/api/v1/ingest/checkin', '/api/v1/ingest/sync', '/api/v1/ingest/documents', '/api/v1/ingest/reconcile' ) ) );

// Keys still there at the end.
smoke_check( 'keys still there after the sync', SMOKE_KEY_ID === get_option( 'samomind_key_id' ) && SMOKE_SECRET === get_option( 'samomind_api_secret' ) && SMOKE_SITE_KEY === get_option( 'samomind_site_key' ) );

wp_delete_user( $admin_id );
exit( $GLOBALS['smoke_failed'] ? 1 : 0 );
