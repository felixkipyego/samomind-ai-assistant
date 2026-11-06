<?php
/**
 * The version is written in three places; they must agree.
 *
 * @package SAMOMIND
 */

class PluginHeaderTest extends SAMOMIND_TestCase {

	public function test_header_version_matches_constant_and_readme() {
		$main   = file_get_contents( SAMOMIND_PLUGIN_DIR . 'samomind-ai-assistant.php' );
		$readme = file_get_contents( SAMOMIND_PLUGIN_DIR . 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+' . preg_quote( SAMOMIND_VERSION, '/' ) . '$/m', $main );
		$this->assertStringContainsString( "define( 'SAMOMIND_VERSION', '" . SAMOMIND_VERSION . "' );", $main );
		$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( SAMOMIND_VERSION, '/' ) . '$/m', $readme );
	}

	public function test_header_declares_minimum_versions_and_licence() {
		$main = file_get_contents( SAMOMIND_PLUGIN_DIR . 'samomind-ai-assistant.php' );

		$this->assertMatchesRegularExpression( '/^ \* Requires at least: 6\.4$/m', $main );
		$this->assertMatchesRegularExpression( '/^ \* Requires PHP:\s+7\.4$/m', $main );
		$this->assertMatchesRegularExpression( '/^ \* License:\s+GPL-2\.0-or-later$/m', $main );
	}
}
