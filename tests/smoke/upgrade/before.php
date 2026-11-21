<?php
/**
 * Step 1 of the update test, run on the OLD plugin: a site that is set up
 * and connected by hand, with a finished first sync. Uses only what the old
 * release has.
 */

require __DIR__ . '/mock-samomind.php';

smoke_check( 'old plugin is running', defined( 'SAMOMIND_VERSION' ) && '0.4.0' !== SAMOMIND_VERSION );

update_option( 'samomind_api_base_url', SMOKE_API_BASE, false );
update_option( 'samomind_key_id', SMOKE_KEY_ID, false );
update_option( 'samomind_api_secret', SMOKE_SECRET, false );
update_option( 'samomind_site_key', SMOKE_SITE_KEY );
update_option( 'samomind_widget_enabled', '1' );

foreach ( array( 'One', 'Two', 'Three' ) as $title ) {
	wp_insert_post(
		array(
			'post_title'   => 'Update test ' . $title,
			'post_content' => 'Text of ' . $title . '.',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);
}

$connected = SAMOMIND()->connection->connect();
smoke_check( 'old plugin connects', ! is_wp_error( $connected ) && SAMOMIND()->connection->is_connected() );

$progress = smoke_run_sync_to_the_end();
smoke_check( 'old plugin finishes its first sync (' . $progress['sent'] . ' sent)', 'complete' === $progress['state'] && $progress['sent'] > 0 );
smoke_check( 'the secret is not autoloaded', ! isset( wp_load_alloptions()['samomind_api_secret'] ) );

exit( $GLOBALS['smoke_failed'] ? 1 : 0 );
