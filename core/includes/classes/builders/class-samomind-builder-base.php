<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builder_Base
 *
 * Shared pieces of the adapters: reading meta, rendering post content
 * through the_content the way the theme would, and the default fallback
 * source.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builder_Base
 * @since       0.2.0
 */
abstract class SAMOMIND_Builder_Base implements SAMOMIND_Builder_Adapter {

	/**
	 * Post meta keys written on save; subclasses override.
	 *
	 * @return string[]
	 */
	public function meta_keys() {
		return array();
	}

	/**
	 * The post content, unless a subclass has a better source.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function fallback_source( $post ) {
		return (string) $post->post_content;
	}

	/**
	 * A post meta value as a string.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $key   Meta key.
	 * @return string
	 */
	protected function meta( $post, $key ) {
		$value = get_post_meta( $post->ID, $key, true );
		return is_scalar( $value ) ? (string) $value : ( empty( $value ) ? '' : 'array' );
	}

	/**
	 * Whether the post content contains a string.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $needle  Text to look for.
	 * @return bool
	 */
	protected function content_has( $post, $needle ) {
		return false !== strpos( (string) $post->post_content, $needle );
	}

	/**
	 * Run the_content filters for a post, so blocks and shortcodes render
	 * as on the front end. Shortcodes and blocks read the global post
	 * while rendering, so it is set for the call and put back.
	 *
	 * @param WP_Post $item Post.
	 * @return string
	 */
	protected function render_the_content( $item ) {
		global $post;

		$previous = $post;
		$post     = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $item );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, rendering as the theme would.
		$html = (string) apply_filters( 'the_content', $item->post_content );

		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the original.
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		return $html;
	}
}
