<?php
/**
 * Removes everything SamoMind AI Assistant stored on this site.
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
	'samomind_embed_snippet',
	'samomind_connection',
	'samomind_sync',
	'samomind_queue',
	'samomind_log',
	'samomind_lock',
);

foreach ( $samomind_options as $samomind_option ) {
	delete_option( $samomind_option );
}

// One-time admin notices (samomind_notice_<user id>).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_samomind_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_samomind_notice_' ) . '%'
	)
);

( new SAMOMIND_Scheduler() )->clear_all();
