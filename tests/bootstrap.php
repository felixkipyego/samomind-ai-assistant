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
define( 'SAMOMIND_VERSION', '0.3.2' );
define( 'SAMOMIND_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/stubs/class-wp-error.php';
require_once __DIR__ . '/stubs/class-wp-post.php';
require_once __DIR__ . '/stubs/samomind-function.php';
require_once __DIR__ . '/class-samomind-testcase.php';

require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/builders/interface-samomind-builder-adapter.php';
require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/builders/class-samomind-builder-base.php';
foreach ( glob( SAMOMIND_PLUGIN_DIR . 'core/includes/classes/builders/class-samomind-builder-*.php' ) as $samomind_class_file ) {
	require_once $samomind_class_file;
}
foreach ( glob( SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-*.php' ) as $samomind_class_file ) {
	require_once $samomind_class_file;
}
