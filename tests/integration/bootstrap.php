<?php
/**
 * Integration bootstrap: a real WordPress and WooCommerce in wp-env.
 *
 * Run it with bin/integration.sh, which starts wp-env and runs the suite
 * twice, with WooCommerce's order tables (HPOS) off and on. SAMOMIND_HPOS
 * says which: "1" or "0".
 *
 * @package SAMOMIND
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$samomind_wp_load = getenv( 'SAMOMIND_WP_LOAD' ) ? getenv( 'SAMOMIND_WP_LOAD' ) : '/var/www/html/wp-load.php';
if ( ! file_exists( $samomind_wp_load ) ) {
	fwrite( STDERR, "WordPress not found at $samomind_wp_load. Run the integration suite inside wp-env (bin/integration.sh).\n" );
	exit( 1 );
}
require_once $samomind_wp_load;

if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'SAMOMIND' ) ) {
	fwrite( STDERR, "WooCommerce and Samomind AI Assistant must both be active.\n" );
	exit( 1 );
}

$samomind_hpos = '1' === getenv( 'SAMOMIND_HPOS' );
if ( $samomind_hpos ) {
	wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
}
update_option( 'woocommerce_custom_orders_table_enabled', $samomind_hpos ? 'yes' : 'no' );
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() !== $samomind_hpos ) {
	fwrite( STDERR, "Could not switch HPOS to " . ( $samomind_hpos ? 'on' : 'off' ) . ".\n" );
	exit( 1 );
}

// Orders left behind by an earlier, interrupted run.
foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'objects' ) ) as $samomind_stale ) {
	$samomind_stale->delete( true );
}

require_once __DIR__ . '/class-samomind-integration-testcase.php';
