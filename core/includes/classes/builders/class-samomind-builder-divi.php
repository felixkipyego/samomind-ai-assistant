<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Divi
 *
 * Divi 4 stores a page as nested `[et_pb_section]...` shortcodes in
 * `post_content` and sets the `_et_pb_use_builder` meta to `on`. Divi 5
 * stores it as WordPress block comments, `<!-- wp:divi/section {JSON} -->`,
 * in `post_content` with the same meta set (format from third-party Divi 5
 * documentation; Divi is closed source and neither format is verified
 * against its code).
 *
 * Divi 4: the_content renders it when the module shortcodes are registered.
 * Divi loads them for front-end, builder and AJAX requests; whether they
 * are loaded when WP-Cron runs the worker is not documented, so they are
 * only used when `et_pb_section` is registered, and otherwise the fallback
 * strips the tags and keeps the enclosed text.
 *
 * Divi 5: rendered through the_content when its block types are
 * registered, otherwise the text is taken from the `innerContent` values in
 * the block comments' JSON. Divi 5 text is in that JSON, not in the HTML.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Divi
 * @since       0.2.0
 */
class SAMOMIND_Builder_Divi extends SAMOMIND_Builder_Base {

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'divi';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Divi';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array( '_et_pb_use_builder' );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		return 'on' === $this->meta( $post, '_et_pb_use_builder' );
	}

	/**
	 * Whether the post uses Divi 5's block format.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function is_divi_5( $post ) {
		return $this->content_has( $post, '<!-- wp:divi/' );
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ET_BUILDER_VERSION' ) || shortcode_exists( 'et_pb_section' ) || $this->divi_5_blocks_registered();
	}

	/**
	 * Render the post to HTML, or null when there is no public renderer.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function render( $post ) {
		if ( $this->is_divi_5( $post ) ) {
			if ( $this->divi_5_blocks_registered() ) {
				return $this->render_the_content( $post );
			}
			return $this->divi_5_text( (string) $post->post_content );
		}
		if ( shortcode_exists( 'et_pb_section' ) ) {
			return $this->render_the_content( $post );
		}
		return null;
	}

	/**
	 * Whether Divi 5's block types are registered in this request.
	 *
	 * @return bool
	 */
	protected function divi_5_blocks_registered() {
		return class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'divi/section' );
	}

	/**
	 * The visible text of a Divi 5 page: every string under an
	 * `innerContent` key in the divi/* blocks' JSON attributes, one per
	 * line. The attributes are read from the block comments directly;
	 * WordPress escapes "--" inside them, so a comment cannot end early.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function divi_5_text( $content ) {
		$lines = array();
		if ( preg_match_all( '/<!--\s+wp:divi\/[a-z0-9-]+\s+(\{.*?\})\s+\/?-->/s', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) ) {
					$this->walk_attrs( $attrs, false, $lines );
				}
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Collect strings found below an `innerContent` key.
	 *
	 * @param array $node    Attributes.
	 * @param bool  $inside  Whether an innerContent key was passed.
	 * @param array $lines   Collected strings, changed in place.
	 * @return void
	 */
	private function walk_attrs( array $node, $inside, array &$lines ) {
		foreach ( $node as $key => $value ) {
			$now_inside = $inside || 'innerContent' === $key;
			if ( is_array( $value ) ) {
				$this->walk_attrs( $value, $now_inside, $lines );
			} elseif ( $now_inside && is_string( $value ) && '' !== trim( $value ) ) {
				$lines[] = $value;
			}
		}
	}
}
