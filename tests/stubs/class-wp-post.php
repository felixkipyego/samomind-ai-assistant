<?php
/**
 * Minimal WP_Post for unit tests.
 *
 * @package SAMOMIND
 */

class WP_Post {

	public $ID                = 0;
	public $post_type         = 'post';
	public $post_status       = 'publish';
	public $post_password     = '';
	public $post_modified_gmt = '2026-11-01 00:00:00';
	public $post_date_gmt     = '2026-11-01 00:00:00';

	public function __construct( array $fields = array() ) {
		foreach ( $fields as $name => $value ) {
			$this->$name = $value;
		}
	}
}
