<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Oxygen
 *
 * Oxygen (classic, 3.x to 4.x) keeps the layout in post meta: shortcodes
 * in `ct_builder_shortcodes` (up to 3.9), JSON in `ct_builder_json` (4.0
 * on); since 4.8.3 the keys carry a leading underscore (`_ct_builder_*`).
 * The keys come from Oxygen's own documentation as quoted by third-party
 * sources; Oxygen is closed source. It has no public function that renders
 * a post to a string, so the fallback runs on the shortcode meta, which
 * keeps the enclosed text of `[ct_text_block]...[/ct_text_block]`. A
 * JSON-only layout (4.x) has no text to recover and is reported as "may be
 * incomplete". Oxygen 6 is a different product with its own storage and is
 * not detected.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Oxygen
 * @since       0.2.0
 */
class SAMOMIND_Builder_Oxygen extends SAMOMIND_Builder_Base {

	/**
	 * Keys that hold shortcodes, newest naming first.
	 *
	 * @var string[]
	 */
	const SHORTCODE_KEYS = array( '_ct_builder_shortcodes', 'ct_builder_shortcodes' );

	/**
	 * Keys that hold JSON, newest naming first.
	 *
	 * @var string[]
	 */
	const JSON_KEYS = array( '_ct_builder_json', 'ct_builder_json' );

	/**
	 * Short machine name of the builder.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'oxygen';
	}

	/**
	 * Name shown on the Status page.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Oxygen';
	}

	/**
	 * Post meta keys the builder writes when it saves a layout.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array_merge( self::SHORTCODE_KEYS, self::JSON_KEYS );
	}

	/**
	 * Whether the post was built with this builder, from its stored data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function detect( $post ) {
		foreach ( $this->meta_keys() as $key ) {
			if ( '' !== $this->meta( $post, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the builder is loaded now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'CT_VERSION' ) || function_exists( 'do_oxygen_elements' );
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

	/**
	 * The shortcode meta, which holds the text; the post content is empty.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function fallback_source( $post ) {
		foreach ( self::SHORTCODE_KEYS as $key ) {
			$value = $this->meta( $post, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) $post->post_content;
	}
}
