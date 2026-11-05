<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Builders
 *
 * The adapter layer (docs/ARCHITECTURE.md A10): picks the adapter for a
 * post, runs it without ever letting a failure out, falls back to plain
 * text, and keeps the list of posts whose text may be incomplete.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Builders
 * @since       0.2.0
 */
class SAMOMIND_Builders {

	/**
	 * Option holding the posts whose text may be incomplete:
	 * post id => array( builder, reasons, at ).
	 *
	 * @var string
	 */
	const INCOMPLETE_OPTION = 'samomind_incomplete';

	/**
	 * Posts kept in the list; the oldest entries go first.
	 *
	 * @var int
	 */
	const MAX_INCOMPLETE = 200;

	/**
	 * Text shorter than this, from stored content of at least
	 * LITTLE_TEXT_SOURCE characters, counts as "little text".
	 *
	 * @var int
	 */
	const LITTLE_TEXT_CHARS = 40;

	/**
	 * Stored content size from which little text is suspicious.
	 *
	 * @var int
	 */
	const LITTLE_TEXT_SOURCE = 300;

	/**
	 * Reasons that put a post on the "may be incomplete" list. The
	 * fallback running because a builder has no public renderer
	 * ("no_renderer") is by design and not one of them.
	 *
	 * @var string[]
	 */
	const FLAGGED_REASONS = array( 'adapter_failed', 'builder_inactive', 'unrendered_shortcodes', 'little_text' );

	/**
	 * Adapters, in detection order, core last.
	 *
	 * @var SAMOMIND_Builder_Adapter[]
	 */
	private $adapters;

	/**
	 * Lookup of the meta keys that queue a post, built once.
	 *
	 * @var array|null
	 */
	private $meta_keys = null;

	/**
	 * Set up the adapters.
	 *
	 * @param SAMOMIND_Builder_Adapter[]|null $adapters  Adapters in detection order; the default list when null.
	 */
	public function __construct( $adapters = null ) {
		if ( null === $adapters ) {
			$adapters = array(
				new SAMOMIND_Builder_Elementor(),
				new SAMOMIND_Builder_Beaver(),
				new SAMOMIND_Builder_Bricks(),
				new SAMOMIND_Builder_Oxygen(),
				new SAMOMIND_Builder_Divi(),
				new SAMOMIND_Builder_WPBakery(),
				new SAMOMIND_Builder_Avada(),
				new SAMOMIND_Builder_Core(),
			);
		}
		$this->adapters = $adapters;
	}

	/**
	 * All adapters, in detection order.
	 *
	 * @since   0.2.0
	 * @return  SAMOMIND_Builder_Adapter[]
	 */
	public function get_adapters() {
		return $this->adapters;
	}

	/**
	 * The first adapter that claims the post; core claims the rest.
	 *
	 * @since   0.2.0
	 * @param   WP_Post $post  Post.
	 * @return  SAMOMIND_Builder_Adapter
	 */
	public function adapter_for( $post ) {
		foreach ( $this->adapters as $adapter ) {
			try {
				if ( $adapter->detect( $post ) ) {
					return $adapter;
				}
			} catch ( \Throwable $error ) {
				continue;
			}
		}
		return end( $this->adapters );
	}

	/**
	 * Whether a meta key is one a builder writes when it saves a layout.
	 *
	 * @since   0.2.0
	 * @param   string $key  Meta key.
	 * @return  bool
	 */
	public function is_builder_meta_key( $key ) {
		if ( null === $this->meta_keys ) {
			$keys = array();
			foreach ( $this->adapters as $adapter ) {
				$keys = array_merge( $keys, $adapter->meta_keys() );
			}

			/**
			 * Filter the post meta keys whose change queues the post for a push.
			 * Add the key of a builder that is not built in.
			 *
			 * @since 0.2.0
			 * @param string[] $keys Meta keys.
			 */
			$keys            = (array) apply_filters( 'SAMOMIND/sync/builder_meta_keys', $keys );
			$this->meta_keys = array_fill_keys( array_map( 'strval', $keys ), true );
		}
		return isset( $this->meta_keys[ $key ] );
	}

	/**
	 * The HTML for a post, from its builder when it can, else from the
	 * fallback. Never throws.
	 *
	 * @since   0.2.0
	 * @param   WP_Post $post  Post.
	 * @return  array  html, builder (slug), reasons (string[]), source_len.
	 */
	public function extract( $post ) {
		$adapter = $this->adapter_for( $post );
		$reasons = array();
		$html    = null;
		$is_core = 'core' === $adapter->get_slug();

		if ( ! $adapter->is_active() ) {
			// The post was built with a builder that is not loaded now.
			$reasons[] = 'builder_inactive';
		} else {
			try {
				$html = $adapter->render( $post );
			} catch ( \Throwable $error ) {
				$reasons[] = 'adapter_failed';
				$html      = null;
			}
			if ( null === $html && ! in_array( 'adapter_failed', $reasons, true ) ) {
				$reasons[] = 'no_renderer';
			}
		}

		if ( null === $html || ( ! $is_core && '' === trim( (string) $html ) ) ) {
			$html = self::strip_shortcodes( $adapter->fallback_source( $post ) );
		}

		$html = (string) $html;
		if ( self::has_shortcode_tokens( wp_strip_all_tags( $html ) ) ) {
			$reasons[] = 'unrendered_shortcodes';
			$html      = self::strip_shortcodes( $html );
		}

		return array(
			'html'       => $html,
			'builder'    => $adapter->get_slug(),
			'reasons'    => array_values( array_unique( $reasons ) ),
			'source_len' => $this->source_length( $post, $adapter ),
		);
	}

	/**
	 * Size of what is stored for the post: its content, the adapter's
	 * fallback source and the builder's meta, whichever is largest.
	 *
	 * @since   0.2.0
	 * @param   WP_Post                  $post     Post.
	 * @param   SAMOMIND_Builder_Adapter $adapter  Adapter.
	 * @return  int
	 */
	private function source_length( $post, $adapter ) {
		$size = max( strlen( (string) $post->post_content ), strlen( $adapter->fallback_source( $post ) ) );
		foreach ( $adapter->meta_keys() as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) ) {
				$size = max( $size, strlen( $value ) );
			}
		}
		return $size;
	}

	/**
	 * Reasons a finished text may be incomplete, on top of those found
	 * while extracting.
	 *
	 * @since   0.2.0
	 * @param   array  $reasons     Reasons from extract().
	 * @param   string $text        Final plain text.
	 * @param   int    $source_len  Size of the stored content.
	 * @return  string[]
	 */
	public static function quality_reasons( array $reasons, $text, $source_len ) {
		if ( (int) $source_len >= self::LITTLE_TEXT_SOURCE && mb_strlen( (string) $text, 'UTF-8' ) < self::LITTLE_TEXT_CHARS ) {
			$reasons[] = 'little_text';
		}
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Remove shortcode tags, keep the text between them, and leave
	 * escaped `[[tag]]` alone. Works on any tag, registered or not.
	 * A tag with attributes needs at least one `name=value`, so ordinary
	 * bracketed words such as "[citation needed]" stay.
	 *
	 * @since   0.2.0
	 * @param   string $text  Text or HTML.
	 * @return  string
	 */
	public static function strip_shortcodes( $text ) {
		// A space, not nothing, so text on either side of a tag does not run together.
		return (string) preg_replace( self::token_pattern(), ' ', (string) $text );
	}

	/**
	 * Whether text still holds shortcode-looking tokens.
	 *
	 * @since   0.2.0
	 * @param   string $text  Plain text.
	 * @return  bool
	 */
	public static function has_shortcode_tokens( $text ) {
		return 1 === preg_match( self::token_pattern(), (string) $text );
	}

	/**
	 * Pattern for an opening, self-closing or closing shortcode tag.
	 *
	 * @return string
	 */
	private static function token_pattern() {
		$name = '[a-z][a-z0-9_-]*';
		$attr = '(?:\s+(?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*=(?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*)';
		return '/(?<!\[)\[(?:\/' . $name . '|' . $name . $attr . '?\/?)\](?!\])/';
	}

	/**
	 * Record what extraction found for a post: add it to the list when
	 * something may be missing, take it off when not. Writes only on a
	 * change, so a full sync does not write once per post.
	 *
	 * @since   0.2.0
	 * @param   int      $post_id  Post id.
	 * @param   string   $builder  Adapter slug.
	 * @param   string[] $reasons  Reasons from quality_reasons().
	 * @return  void
	 */
	public function note_quality( $post_id, $builder, array $reasons ) {
		$flagged = array_values( array_intersect( $reasons, self::FLAGGED_REASONS ) );
		$list    = $this->read_incomplete();
		$key     = (string) (int) $post_id;

		if ( empty( $flagged ) ) {
			if ( isset( $list[ $key ] ) ) {
				unset( $list[ $key ] );
				update_option( self::INCOMPLETE_OPTION, $list, false );
			}
			return;
		}
		if ( isset( $list[ $key ] ) && $list[ $key ]['reasons'] === $flagged && $list[ $key ]['builder'] === $builder ) {
			return;
		}

		$list[ $key ] = array(
			'builder' => (string) $builder,
			'reasons' => $flagged,
			'at'      => time(),
		);
		if ( count( $list ) > self::MAX_INCOMPLETE ) {
			uasort(
				$list,
				function ( $a, $b ) {
					return $b['at'] - $a['at'];
				}
			);
			$list = array_slice( $list, 0, self::MAX_INCOMPLETE, true );
		}
		update_option( self::INCOMPLETE_OPTION, $list, false );
	}

	/**
	 * Take a post off the list, for example when it is deleted.
	 *
	 * @since   0.2.0
	 * @param   int $post_id  Post id.
	 * @return  void
	 */
	public function forget( $post_id ) {
		$list = $this->read_incomplete();
		$key  = (string) (int) $post_id;
		if ( isset( $list[ $key ] ) ) {
			unset( $list[ $key ] );
			update_option( self::INCOMPLETE_OPTION, $list, false );
		}
	}

	/**
	 * The list as stored: post id => array( builder, reasons, at ).
	 *
	 * @since   0.2.0
	 * @return  array
	 */
	public function get_incomplete() {
		return $this->read_incomplete();
	}

	/**
	 * Read the stored list.
	 *
	 * @return array
	 */
	private function read_incomplete() {
		$list = get_option( self::INCOMPLETE_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Plain wording for a reason code.
	 *
	 * @since   0.2.0
	 * @param   string $code  Reason code.
	 * @return  string
	 */
	public static function reason_label( $code ) {
		$labels = array(
			'adapter_failed'        => __( 'the page builder failed to render it, plain text was used', 'samomind-ai-assistant' ),
			'builder_inactive'      => __( 'built with a page builder that is not active', 'samomind-ai-assistant' ),
			'unrendered_shortcodes' => __( 'shortcodes did not render', 'samomind-ai-assistant' ),
			'little_text'           => __( 'very little text came out of a large page', 'samomind-ai-assistant' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}
}
