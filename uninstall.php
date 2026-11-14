<?php
/**
 * Removes everything Samomind AI Assistant stored on this site.
 *
 * If a source id is stored, disconnect with reason "uninstall" (contract
 * section 9), best effort, then delete every option, transient and
 * scheduled event the plugin created. Deactivation sends nothing.
 *
 * @package SAMOMIND
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/core/includes/classes/class-samomind-client.php';
require_once __DIR__ . '/core/includes/classes/class-samomind-scheduler.php';

$samomind_connection = get_option( 'samomind_connection', array() );
if ( is_array( $samomind_connection ) && ! empty( $samomind_connection['source_id'] ) ) {
	$samomind_base_url = (string) get_option( 'samomind_api_base_url', '' );
	$samomind_client   = new SAMOMIND_Client(
		'' !== $samomind_base_url ? $samomind_base_url : 'https://api.samomind.com',
		(string) get_option( 'samomind_key_id', '' ),
		defined( 'SAMOMIND_API_SECRET' ) ? (string) constant( 'SAMOMIND_API_SECRET' ) : (string) get_option( 'samomind_api_secret', '' )
	);
	$samomind_client->post(
		'/disconnect',
		array(
			'source_id' => (string) $samomind_connection['source_id'],
			'reason'    => 'uninstall',
		),
		SAMOMIND_Client::fresh_key( 'disconnect' )
	);
}

$samomind_options = array(
	'samomind_api_base_url',
	'samomind_key_id',
	'samomind_api_secret',
	'samomind_widget_enabled',
	'samomind_site_key',
	'samomind_embed_snippet', // Left by 0.2.x if the site never reached the one-time migration.
	'samomind_excluded_post_types', // Kept by 0.3.2 and earlier.
	'samomind_post_types',
	'samomind_incomplete',
	'samomind_connection',
	'samomind_sync',
	'samomind_queue',
	'samomind_log',
	'samomind_lock',
	'samomind_orders_enabled',
	'samomind_orders_terms',
	'samomind_orders_secret',
	'samomind_orders_share_tracking',
	'samomind_orders_share_items',
	'samomind_orders_reported',
	'samomind_orders_proof_seconds',
);

foreach ( $samomind_options as $samomind_option ) {
	delete_option( $samomind_option );
}

// One-time admin notices (samomind_notice_<user id>).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_samomind_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_samomind_notice_' ) . '%',
		$wpdb->esc_like( '_transient_samomind_ord_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_samomind_ord_' ) . '%'
	)
);

( new SAMOMIND_Scheduler() )->clear_all();
