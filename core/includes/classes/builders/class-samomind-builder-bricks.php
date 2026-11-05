<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Bricks
 *
 * Bricks stores the page as JSON in the `_bricks_page_content_2` post meta
 * (`_bricks_page_content` before 1.3 or so) and leaves `post_content`
 * empty, so a plugin that reads only the content sees nothing. The key
 * comes from the Bricks forum and third-party docs; Bricks is closed
 * source and its Academy documents a render filter but no public function
 * that renders a post, so there is no renderer here: the fallback runs and
 * a Bricks page is reported as "may be incomplete" unless a site developer
 * supplies its text with the SAMOMIND/content/text filter.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Bricks
 * @since       0.2.0
 */
class SAMOMIND_Builder_Bricks extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'bricks';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Bricks';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_editor_mode' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		// Bricks' value for "edited with the WordPress editor instead".
		if ( 'wordpress' === $this->meta( $post, '_bricks_editor_mode' ) ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
			return false;
		}
		return '' !== $this->meta( $post, '_bricks_page_content_2' ) || '' !== $this->meta( $post, '_bricks_page_content' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Frontend' );
	}

	/**
	 * No public renderer to call.
	 *
	 * @param WP_Post $post Post.
	 * @return null
	 */
	public function render( $post ) {
		return null;
	}
}
