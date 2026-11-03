<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Embed
 *
 * Validates the widget snippet pasted from the SamoMind dashboard and
 * prints it in the front-end footer. Only one shape is accepted: a single
 * empty <script> tag whose src is on the widget host, with data-*
 * attributes and async or defer. Anything else is refused.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Embed
 * @since       0.1.0
 */
class SAMOMIND_Embed {

	/**
	 * The only host a widget script may load from.
	 *
	 * @var string
	 */
	const WIDGET_HOST = 'widget.samomind.com';

	/**
	 * Our SAMOMIND_Embed constructor to run the plugin logic.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'wp_footer', array( $this, 'print_snippet' ), 100 );
	}

	/**
	 * Print the widget script tag, rebuilt from the validated attributes.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function print_snippet() {
		if ( is_admin() ) {
			return;
		}
		$attributes = self::parse( SAMOMIND()->helpers->get_embed_snippet() );
		if ( is_wp_error( $attributes ) || empty( $attributes ) ) {
			return;
		}
		wp_print_script_tag( $attributes );
	}

	/**
	 * Parse and validate a snippet.
	 *
	 * @since   0.1.0
	 * @param   string $snippet  Pasted snippet.
	 * @return  array|WP_Error  Attributes for wp_print_script_tag(), an empty
	 *                          array for an empty snippet, or the reason it
	 *                          was refused.
	 */
	public static function parse( $snippet ) {
		$snippet = trim( (string) $snippet );
		if ( '' === $snippet ) {
			return array();
		}

		if ( ! preg_match( '#^<script\b([^<>]*)>\s*</script>$#i', $snippet, $tag ) ) {
			return self::error( 'invalid_shape', __( 'Paste the single <script> tag exactly as the SamoMind dashboard shows it. Inline code and other tags are not accepted.', 'samomind-ai-assistant' ) );
		}

		$pattern = '/\s+([a-zA-Z][a-zA-Z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';
		preg_match_all( $pattern, $tag[1], $matches, PREG_SET_ORDER );

		// Whatever the pattern did not consume is malformed markup.
		if ( '' !== trim( (string) preg_replace( $pattern, '', $tag[1] ) ) ) {
			return self::error( 'invalid_shape', __( 'The script tag has malformed attributes.', 'samomind-ai-assistant' ) );
		}

		$attributes = array();
		foreach ( $matches as $match ) {
			$name      = strtolower( $match[1] );
			$has_value = false !== strpos( $match[0], '=' );
			// Only one of the three value groups (double, single, unquoted) matched.
			$value = ( isset( $match[2] ) ? $match[2] : '' ) . ( isset( $match[3] ) ? $match[3] : '' ) . ( isset( $match[4] ) ? $match[4] : '' );

			if ( isset( $attributes[ $name ] ) ) {
				/* translators: %s: attribute name */
				return self::error( 'duplicate_attribute', sprintf( __( 'The attribute "%s" appears twice.', 'samomind-ai-assistant' ), $name ) );
			}

			if ( 'src' === $name ) {
				if ( ! self::is_widget_url( $value ) ) {
					/* translators: %s: widget host name */
					return self::error( 'invalid_src', sprintf( __( 'The script must load from https://%s.', 'samomind-ai-assistant' ), self::WIDGET_HOST ) );
				}
				$attributes['src'] = $value;
			} elseif ( 'async' === $name || 'defer' === $name ) {
				if ( $has_value && '' !== $value && strtolower( $value ) !== $name ) {
					/* translators: %s: attribute name */
					return self::error( 'invalid_attribute', sprintf( __( 'The attribute "%s" takes no value.', 'samomind-ai-assistant' ), $name ) );
				}
				$attributes[ $name ] = true;
			} elseif ( preg_match( '/^data-[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
				$attributes[ $name ] = $value;
			} else {
				/* translators: %s: attribute name */
				return self::error( 'attribute_not_allowed', sprintf( __( 'The attribute "%s" is not allowed. Only src, async, defer and data-* attributes are accepted.', 'samomind-ai-assistant' ), $name ) );
			}
		}

		if ( ! isset( $attributes['src'] ) ) {
			return self::error( 'invalid_src', __( 'The script tag has no src.', 'samomind-ai-assistant' ) );
		}

		return $attributes;
	}

	/**
	 * Whether a URL is an https URL on the widget host, with no user,
	 * password or port.
	 *
	 * @since   0.1.0
	 * @param   string $url  URL.
	 * @return  bool
	 */
	public static function is_widget_url( $url ) {
		if ( preg_match( '/[\s\\\\]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& self::WIDGET_HOST === strtolower( $parts['host'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['port'] );
	}

	/**
	 * Build a refusal.
	 *
	 * @since   0.1.0
	 * @param   string $code     Code.
	 * @param   string $message  Message shown to the admin.
	 * @return  WP_Error
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
