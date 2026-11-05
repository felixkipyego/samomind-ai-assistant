<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Avada
 *
 * Avada / Fusion Builder (Avada Builder) stores `[fusion_builder_container]
 * [fusion_builder_row]...` shortcodes in `post_content` and sets the
 * `fusion_builder_status` meta to `active`. The `fusion_*` shortcodes are
 * rendered through the_content when they are registered; Avada is closed
 * source, so whether they are registered when WP-Cron runs the worker is
 * not verified. When they are not, the fallback strips the tags and keeps
 * the enclosed text.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Avada
 * @since       0.2.0
 */
class SAMOMIND_Builder_Avada extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'avada';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Avada / Fusion Builder';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( 'fusion_builder_status' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return 'active' === $this->meta( $post, 'fusion_builder_status' ) || $this->content_has( $post, '[fusion_builder_container' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'FusionBuilder' ) || shortcode_exists( 'fusion_builder_container' );
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function render( $post ) {
		if ( ! shortcode_exists( 'fusion_builder_container' ) ) {
			return null;
		}
		return $this->render_the_content( $post );
	}
}
