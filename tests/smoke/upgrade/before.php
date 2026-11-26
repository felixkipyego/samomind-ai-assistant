<?php
/**
 * Step 1 of the update test, run on the OLD plugin: a site that is set up
 * and connected by hand, with a finished first sync. Uses only what the old
 * release has.
 */

require __DIR__ . '/mock-samomind.php';

smoke_check( 'old plugin is running', defined( 'SAMOMIND_VERSION' ) && '0.4.1' !== SAMOMIND_VERSION );

// A shop with pretty permalinks, which order status needs.
update_option( 'permalink_structure', '/%postname%/' );
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

// Products are ticked under What is synced, as on a shop that has them synced (the plugin was activated before WooCommerce here, so the default left them out).
update_option( 'samomind_post_types', array( 'post', 'page', 'product' ) );

// Two products, so the update has products to send again.
foreach ( array( 'Mug' => '10', 'Kettle' => '25' ) as $name => $price ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Update test ' . $name );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );
	$product->save();
}

// Which release this site starts on; releases before 0.4.0 have no order status.
update_option( 'smoke_from_version', SAMOMIND_VERSION, false );
if ( class_exists( 'SAMOMIND_Orders_Settings' ) ) {
	// Order status switched on the way the 0.4.0 release does it: its key, its terms (2026-11).
	update_option( 'samomind_orders_secret', SMOKE_ORDERS_SECRET, false );
	update_option( 'samomind_orders_enabled', '1', false );
	update_option( 'samomind_orders_share_items', '1', false );
	SAMOMIND()->orders->accept_terms( 1 );
	SAMOMIND_Orders_State::maybe_install();
	smoke_check( 'the old plugin accepted the terms 2026-11', '2026-11' === SAMOMIND()->orders->get_terms()['version'] );
}

$connected = SAMOMIND()->connection->connect();
smoke_check( 'old plugin connects', ! is_wp_error( $connected ) && SAMOMIND()->connection->is_connected() );

$progress = smoke_run_sync_to_the_end();
smoke_check( 'old plugin finishes its first sync (' . $progress['sent'] . ' sent)', 'complete' === $progress['state'] && $progress['sent'] > 0 );
smoke_check( 'the secret is not autoloaded', ! isset( wp_load_alloptions()['samomind_api_secret'] ) );

exit( $GLOBALS['smoke_failed'] ? 1 : 0 );
