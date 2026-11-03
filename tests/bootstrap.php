<?php
/**
 * Unit test bootstrap: no WordPress install, WordPress functions are
 * stubbed per test with Brain Monkey.
 *
 * @package SAMOMIND
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'SAMOMIND_NAME', 'SamoMind AI Assistant' );
define( 'SAMOMIND_VERSION', '0.1.0' );
define( 'SAMOMIND_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/stubs/class-wp-error.php';
require_once __DIR__ . '/class-samomind-testcase.php';

foreach ( glob( SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-*.php' ) as $samomind_class_file ) {
	// The CLI class extends nothing but calls WP_CLI; it is not unit tested.
	if ( false === strpos( $samomind_class_file, 'class-samomind-cli.php' ) ) {
		require_once $samomind_class_file;
	}
}
