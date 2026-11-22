<?php
/**
 * Step 2 of the update test, run on the NEW plugin after its files replaced
 * the old ones, with no activation: keys stay, the admin pages load, a sync
 * runs. Prints no key, secret or answer.
 */

require __DIR__ . '/mock-samomind.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

global $wpdb;

smoke_check( 'new files are running (' . SAMOMIND_VERSION . ')', '0.4.0' === SAMOMIND_VERSION );

// Keys and settings stay.
smoke_check( 'key id stays', SMOKE_KEY_ID === get_option( 'samomind_key_id' ) );
smoke_check( 'secret stays', SMOKE_SECRET === get_option( 'samomind_api_secret' ) );
smoke_check( 'site key stays', SMOKE_SITE_KEY === get_option( 'samomind_site_key' ) );
smoke_check( 'API address stays', SMOKE_API_BASE === get_option( 'samomind_api_base_url' ) );
smoke_check( 'the secret is still not autoloaded', ! isset( wp_load_alloptions()['samomind_api_secret'] ) );
smoke_check( 'still connected', SAMOMIND()->connection->is_connected() );
smoke_check( 'the widget is still on', SAMOMIND()->helpers->widget_is_active() );

// What 0.4.0 needs, with no activation hook.
$orders_table = SAMOMIND_Orders_State::table();
$table_exists = function () use ( $wpdb, $orders_table ) {
	return $orders_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) );
};
smoke_check( 'the hourly tick is scheduled', false !== wp_next_scheduled( SAMOMIND_Scheduler::HOURLY_HOOK ) );
smoke_check( 'no orders table yet: a site that never used order status gets none', ! $table_exists() );

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

// Switch order status on, as the order settings form does, and use it.
update_option( SAMOMIND_Orders_Settings::SECRET, SMOKE_ORDERS_SECRET, false );
update_option( SAMOMIND_Orders_Settings::OPT_IN, '1', false );
SAMOMIND()->orders->accept_terms( $admin_id );
SAMOMIND_Orders_State::maybe_install();
smoke_check( 'switching order status on makes the orders table', $table_exists() && SAMOMIND_Orders_State::DB_VERSION === (string) get_option( 'samomind_orders_db_version', '' ) );
smoke_check( 'order status is enabled', SAMOMIND()->orders->is_enabled() );
smoke_check( 'a signed ping from Samomind is answered (HTTP 200)', 200 === smoke_signed_ping() );
smoke_check( 'the same ping signed with a wrong key is refused', 401 === smoke_signed_ping_with_wrong_key() );

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
