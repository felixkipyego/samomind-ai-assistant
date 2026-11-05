<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Elementor
 *
 * Elementor keeps the layout in the `_elementor_data` post meta and sets
 * `_elementor_edit_mode` to `builder` on a page built with it (Elementor
 * core/base/document.php: ELEMENTOR_DATA_META_KEY, BUILT_WITH_ELEMENTOR_META_KEY).
 * Its front end replaces the content on the_content with
 * `Plugin::$instance->frontend->get_builder_content( $post_id )`
 * (includes/frontend.php, apply_builder_in_content), which this adapter
 * calls the same way, with the global post set as on the front end.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Elementor
 * @since       0.2.0
 */
class SAMOMIND_Builder_Elementor extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'elementor';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Elementor';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( '_elementor_data', '_elementor_edit_mode' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return 'builder' === $this->meta( $post, '_elementor_edit_mode' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend );
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function render( $post ) {
		return $this->builder_content( $post );
	}

	/**
	 * Elementor's renderer for one post, with the global post set the way
	 * the front end has it. Separate so tests can replace it.
	 *
	 * @param WP_Post $item Post.
	 * @return string
	 */
	protected function builder_content( $item ) {
		global $post;

		$previous = $post;
		$post     = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $item );
		try {
			$html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content( $item->ID );
		} finally {
			$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the original.
			if ( $previous instanceof WP_Post ) {
				setup_postdata( $previous );
			}
		}
		return $html;
	}
}
