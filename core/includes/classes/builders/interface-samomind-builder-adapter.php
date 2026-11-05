<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SAMOMIND_Builder_Adapter
 *
 * One page builder (or the core editors) behind one shape: how to tell a
 * post was built with it, which meta keys its saves write, and how to turn
 * its stored layout into HTML. An adapter never decides what is sent, and
 * never lets an exception out: SAMOMIND_Builders catches anything thrown
 * and falls back to plain text.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Adapter
 * @since       0.2.0
 */
interface SAMOMIND_Builder_Adapter {

	/**
	 * Short machine name, for example "elementor".
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Post meta keys this builder writes when it saves a layout. A change
	 * to one queues the post.
	 *
	 * @return string[]
	 */
	public function meta_keys();

	/**
	 * Whether the post's stored data says it was built with this builder.
	 * Reads the post only; the builder does not have to be loaded.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post );

	/**
	 * Whether the builder is loaded now, so its renderer can run.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Render the post to HTML with the builder's own renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null  HTML, or null when this builder has no usable
	 *                      public renderer (the fallback then runs).
	 * @throws Exception When the renderer fails.
	 */
	public function render( $post );

	/**
	 * The stored text the fallback works from: the post content, or the
	 * builder's own shortcode meta when the content is empty by design.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function fallback_source( $post );
}
