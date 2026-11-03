<?php
/**
 * Removes everything SamoMind AI Assistant stored on this site.
 *
 * @package SAMOMIND
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$samomind_options = array(
	'samomind_api_base_url',
	'samomind_key_id',
	'samomind_api_secret',
	'samomind_embed_snippet',
);

foreach ( $samomind_options as $samomind_option ) {
	delete_option( $samomind_option );
}
