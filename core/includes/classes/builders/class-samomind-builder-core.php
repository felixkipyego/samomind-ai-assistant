<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Core
 *
 * The block editor and the Classic editor: the post content run through
 * the_content filters. Also the adapter for any post no builder claims.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Core
 * @since       0.2.0
 */
class SAMOMIND_Builder_Core extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'core';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Block or Classic editor', 'samomind-ai-assistant' );
	}

	/**
	 * Core claims every post nothing else did; the registry asks it last.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return true;
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return true;
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function render( $post ) {
		return $this->render_the_content( $post );
	}
}
