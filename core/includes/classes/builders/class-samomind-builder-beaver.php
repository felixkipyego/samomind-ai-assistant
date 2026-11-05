<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Beaver
 *
 * Beaver Builder: `_fl_builder_enabled` marks a post built with it, the
 * published layout is `_fl_builder_data` (a draft is `_fl_builder_draft`
 * and is never sent), checked against classes/class-fl-builder-model.php
 * of the wordpress.org Lite release. `FLBuilder::render_content_by_id()`
 * (classes/class-fl-builder.php) ECHOES the layout and returns nothing, so
 * it is captured with output buffering here.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Beaver
 * @since       0.2.0
 */
class SAMOMIND_Builder_Beaver extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'beaver';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Beaver Builder';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( '_fl_builder_data', '_fl_builder_enabled' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return '' !== $this->meta( $post, '_fl_builder_enabled' ) && '0' !== $this->meta( $post, '_fl_builder_enabled' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'FLBuilder' ) && class_exists( 'FLBuilderModel' );
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function render( $post ) {
		return $this->layout_html( $post );
	}

	/**
	 * Capture the layout Beaver Builder echoes. Separate so tests can
	 * replace it.
	 *
	 * @param WP_Post $item Post.
	 * @return string
	 * @throws \Throwable Whatever Beaver Builder throws, after the buffer is closed.
	 */
	protected function layout_html( $item ) {
		ob_start();
		try {
			$result = FLBuilder::render_content_by_id( $item->ID );
		} catch ( \Throwable $error ) {
			ob_end_clean();
			throw $error;
		}
		$html = (string) ob_get_clean();
		// False means the layout is not viewable (for example password protected).
		return false === $result ? '' : $html;
	}
}
