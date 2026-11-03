<?php
/**
 * Base test case: sets up Brain Monkey and the WordPress functions every
 * test needs.
 *
 * @package SAMOMIND
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class SAMOMIND_TestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( $value, '/\\' );
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
				return trim( strip_tags( $text ) );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
