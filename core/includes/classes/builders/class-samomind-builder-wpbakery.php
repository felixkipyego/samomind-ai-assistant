<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_WPBakery
 *
 * WPBakery Page Builder stores `[vc_row][vc_column][vc_column_text]...`
 * shortcodes in `post_content` and sets `_wpb_vc_js_status` to `true`. It
 * does not register its shortcodes in AJAX or cron requests for
 * performance; the documented remedy (WPBakery support threads and theme
 * documentation, not source-verified: WPBakery is closed source) is
 * `WPBMap::addAllMappedShortcodes()` before `do_shortcode()`. The adapter
 * does that when `vc_row` is not registered, then renders through
 * the_content.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_WPBakery
 * @since       0.2.0
 */
class SAMOMIND_Builder_WPBakery extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'wpbakery';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'WPBakery';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( '_wpb_vc_js_status' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return 'true' === $this->meta( $post, '_wpb_vc_js_status' ) || $this->content_has( $post, '[vc_row' ) || $this->content_has( $post, '[vc_section' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPBMap' ) || shortcode_exists( 'vc_row' );
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function render( $post ) {
		$this->register_shortcodes();
		if ( ! shortcode_exists( 'vc_row' ) ) {
			return null;
		}
		return $this->render_the_content( $post );
	}

	/**
	 * Register the mapped shortcodes when they are missing.
	 *
	 * @return void
	 */
	protected function register_shortcodes() {
		if ( ! shortcode_exists( 'vc_row' ) && class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
			WPBMap::addAllMappedShortcodes();
		}
	}
}
