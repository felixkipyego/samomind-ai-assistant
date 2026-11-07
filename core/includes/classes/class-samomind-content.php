<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Content
 *
 * Decides which posts are public enough to send and maps a post to the
 * contract's PushDocument (docs/API_CONTRACT.md section 4). Nothing about
 * users, visitors, comments, drafts or private content leaves here.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Content
 * @since       0.1.0
 */
class SAMOMIND_Content {

	/**
	 * Post types never sent, whatever a filter returns (contract 4: no
	 * media files).
	 *
	 * @var string[]
	 */
	const NEVER_SENT_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'shop_order', 'shop_coupon', 'product_variation' );

	/**
	 * Public taxonomies that describe presentation, not content.
	 *
	 * @var string[]
	 */
	const SKIPPED_TAXONOMIES = array( 'post_format' );

	/**
	 * Contract limits on PushDocument fields.
	 */
	const MAX_TITLE_CHARS    = 500;
	const MAX_PRICE_CHARS    = 64;
	const MAX_STOCK_CHARS    = 32;
	const MAX_CATEGORIES     = 50;
	const MAX_CATEGORY_CHARS = 100;
	const MAX_URL_CHARS      = 2048;

	/**
	 * Post types whose published items are sent: the available types that
	 * are ticked on the settings page, then the filter.
	 *
	 * @since   0.1.0
	 * @return  string[]
	 */
	public function get_post_types() {
		$types = array_values( array_intersect( $this->get_available_post_types(), $this->get_selected_post_types() ) );

		/**
		 * Filter the post types sent to SamoMind. Runs after the settings
		 * page's post type selection.
		 *
		 * @since 0.1.0
		 * @param string[] $types Post type names.
		 */
		$types = (array) apply_filters( 'SAMOMIND/content/post_types', $types );

		return array_values( array_diff( array_unique( array_map( 'strval', $types ) ), self::NEVER_SENT_TYPES ) );
	}

	/**
	 * Post types the content rules allow, before the settings page and the
	 * filter: pages, posts, public custom post types, and products when
	 * WooCommerce is active. These are the settings page's checklist.
	 *
	 * @since   0.1.0
	 * @return  string[]
	 */
	public function get_available_post_types() {
		$types  = array( 'post', 'page' );
		$custom = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'_builtin'           => false,
			),
			'names'
		);
		foreach ( (array) $custom as $type ) {
			if ( 'product' === $type && ! $this->woocommerce_active() ) {
				continue;
			}
			$types[] = (string) $type;
		}

		return array_values( array_diff( array_unique( $types ), self::NEVER_SENT_TYPES ) );
	}

	/**
	 * What a new install ticks: posts, pages, and products when WooCommerce
	 * is active.
	 *
	 * @since   0.3.3
	 * @return  string[]
	 */
	public function get_default_post_types() {
		$types = array( 'post', 'page' );
		if ( $this->woocommerce_active() ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * The ticked post types: the saved selection, or the default while
	 * nothing is saved.
	 *
	 * @since   0.3.3
	 * @return  string[]
	 */
	public function get_selected_post_types() {
		$saved = SAMOMIND()->helpers->get_selected_post_types();
		return null === $saved ? $this->get_default_post_types() : $saved;
	}

	/**
	 * Save the selection once, when none is saved. A new install gets the
	 * default. A site that already had the plugin (it has the old exclusion
	 * list) gets the types that are synced today, so nothing changes for it.
	 * add_option() runs no queue hook: this never deletes or re-sends anything.
	 *
	 * @since   0.3.3
	 * @return  void
	 */
	public function seed_post_types() {
		if ( null !== SAMOMIND()->helpers->get_selected_post_types() ) {
			return;
		}
		$legacy = get_option( 'samomind_excluded_post_types', null );
		if ( is_array( $legacy ) ) {
			$selection = array_values( array_diff( $this->get_available_post_types(), array_map( 'strval', $legacy ) ) );
		} else {
			$selection = $this->get_default_post_types();
		}
		add_option( 'samomind_post_types', $selection, '', true );
	}

	/**
	 * Post ids a site has excluded.
	 *
	 * @since   0.1.0
	 * @return  int[]
	 */
	public function get_excluded_ids() {
		/**
		 * Filter the post ids never sent to SamoMind.
		 *
		 * @since 0.1.0
		 * @param int[] $ids Post ids.
		 */
		return array_map( 'absint', (array) apply_filters( 'SAMOMIND/content/excluded_ids', array() ) );
	}

	/**
	 * The contract's document kind for a post type.
	 *
	 * @since   0.1.0
	 * @param   string $post_type  Post type name.
	 * @return  string  page, post, product or custom.
	 */
	public static function kind_for( $post_type ) {
		if ( in_array( $post_type, array( 'page', 'post', 'product' ), true ) ) {
			return $post_type;
		}
		return 'custom';
	}

	/**
	 * The eligibility rules, on plain data so they can be tested.
	 *
	 * @since   0.1.0
	 * @param   array $data          Keys id, post_type, status, password, and
	 *                               for products product.catalog_visibility.
	 * @param   array $post_types    Post types that are sent.
	 * @param   array $excluded_ids  Post ids that are never sent.
	 * @return  bool
	 */
	public static function is_eligible_data( array $data, array $post_types, array $excluded_ids ) {
		if ( ! isset( $data['status'] ) || 'publish' !== $data['status'] ) {
			return false;
		}
		if ( isset( $data['password'] ) && '' !== (string) $data['password'] ) {
			return false;
		}
		if ( ! isset( $data['post_type'] ) || ! in_array( $data['post_type'], $post_types, true ) ) {
			return false;
		}
		if ( in_array( (int) $data['id'], $excluded_ids, true ) ) {
			return false;
		}
		if ( 'product' === $data['post_type'] && isset( $data['product']['catalog_visibility'] ) && 'hidden' === $data['product']['catalog_visibility'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a post is sent to SamoMind right now.
	 *
	 * @since   0.1.0
	 * @param   WP_Post|null $post  Post.
	 * @return  bool
	 */
	public function is_eligible( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$data = array(
			'id'        => (int) $post->ID,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'password'  => $post->post_password,
		);
		if ( 'product' === $post->post_type ) {
			$data['product'] = array( 'catalog_visibility' => $this->product_visibility( $post->ID ) );
		}
		return self::is_eligible_data( $data, $this->get_post_types(), $this->get_excluded_ids() );
	}

	/**
	 * Whether a post type is one this plugin tracks.
	 *
	 * @since   0.1.0
	 * @param   string $post_type  Post type name.
	 * @return  bool
	 */
	public function is_tracked_type( $post_type ) {
		return in_array( $post_type, $this->get_post_types(), true );
	}

	/**
	 * Build the document for a post id, or null when it is not eligible.
	 *
	 * @since   0.1.0
	 * @param   int $post_id         Post id.
	 * @param   int $max_text_chars  The API's text limit.
	 * @return  array|null
	 */
	public function get_document( $post_id, $max_text_chars ) {
		$post = get_post( $post_id );
		if ( ! $this->is_eligible( $post ) ) {
			return null;
		}

		$data     = $this->collect( $post );
		$document = self::build_document( $data, $max_text_chars );

		/**
		 * Filter the text sent for a post, after extraction. Use it to supply
		 * the text of a page the plugin cannot read, or to correct one.
		 *
		 * @since 0.2.0
		 * @param string  $text  Extracted plain text.
		 * @param WP_Post $post  The post.
		 * @param array   $data  What was collected: builder (adapter slug), reasons.
		 */
		$text             = (string) apply_filters( 'SAMOMIND/content/text', $document['text'], $post, $data );
		$document['text'] = self::truncate( $text, (int) $max_text_chars );

		SAMOMIND()->builders->note_quality(
			$post->ID,
			$data['builder'],
			SAMOMIND_Builders::quality_reasons( $data['reasons'], $document['text'], $data['source_len'] )
		);

		return $document;
	}

	/**
	 * Map collected post data to a PushDocument.
	 *
	 * @since   0.1.0
	 * @param   array $data            Output of collect().
	 * @param   int   $max_text_chars  The API's text limit.
	 * @return  array
	 */
	public static function build_document( array $data, $max_text_chars ) {
		$parts = array(
			self::to_text( isset( $data['excerpt'] ) ? $data['excerpt'] : '' ),
			self::to_text( isset( $data['content_html'] ) ? $data['content_html'] : '' ),
		);
		if ( isset( $data['product']['short_description_html'] ) ) {
			$parts[] = self::to_text( $data['product']['short_description_html'] );
		}
		$text = implode( "\n\n", array_filter( array_unique( $parts ), 'strlen' ) );

		$document = array(
			'external_id' => (string) (int) $data['id'],
			'url'         => self::truncate( (string) $data['url'], self::MAX_URL_CHARS ),
			'kind'        => self::kind_for( $data['post_type'] ),
			'title'       => self::truncate( self::to_text( isset( $data['title'] ) ? $data['title'] : '' ), self::MAX_TITLE_CHARS ),
			'text'        => self::truncate( $text, (int) $max_text_chars ),
			'modified_at' => self::rfc3339( isset( $data['modified_gmt'] ) ? $data['modified_gmt'] : '' ),
		);

		$fields = array();
		if ( ! empty( $data['product']['price'] ) ) {
			$fields['price'] = self::truncate( self::to_text( $data['product']['price'] ), self::MAX_PRICE_CHARS );
		}
		if ( ! empty( $data['product']['stock_status'] ) ) {
			$fields['stock_status'] = self::truncate( self::to_text( $data['product']['stock_status'] ), self::MAX_STOCK_CHARS );
		}
		$categories = array();
		foreach ( isset( $data['terms'] ) ? (array) $data['terms'] : array() as $term ) {
			$term = self::truncate( self::to_text( (string) $term ), self::MAX_CATEGORY_CHARS );
			if ( '' !== $term && ! in_array( $term, $categories, true ) ) {
				$categories[] = $term;
			}
		}
		if ( ! empty( $categories ) ) {
			$fields['categories'] = array_slice( $categories, 0, self::MAX_CATEGORIES );
		}
		if ( ! empty( $fields ) ) {
			$document['fields'] = $fields;
		}

		return $document;
	}

	/**
	 * Reduce rendered HTML to clean text.
	 *
	 * @since   0.1.0
	 * @param   string $html  HTML.
	 * @return  string
	 */
	public static function to_text( $html ) {
		$html = (string) $html;
		// Keep block boundaries as line breaks so words do not run together.
		$html = (string) preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/blockquote|/pre|/section|/article|/figcaption)\b[^>]*>#i', "$0\n", $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// The API refuses NUL characters (contract 4).
		$text = str_replace( "\0", '', $text );
		$text = str_replace( array( "\r\n", "\r", "\t", "\xC2\xA0" ), array( "\n", "\n", ' ', ' ' ), $text );
		$text = (string) preg_replace( '/ {2,}/', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * RFC 3339 time with offset from a MySQL GMT datetime.
	 *
	 * @since   0.1.0
	 * @param   string $gmt  For example "2026-11-02 08:30:00".
	 * @return  string  For example "2026-11-02T08:30:00+00:00".
	 */
	public static function rfc3339( $gmt ) {
		$time = ( '' === (string) $gmt || 0 === strpos( (string) $gmt, '0000-00-00' ) ) ? false : strtotime( $gmt . ' UTC' );
		if ( false === $time ) {
			$time = time();
		}
		return gmdate( 'Y-m-d\TH:i:s', $time ) . '+00:00';
	}

	/**
	 * Cut a string to a number of characters.
	 *
	 * @since   0.1.0
	 * @param   string $text   Text.
	 * @param   int    $chars  Maximum characters.
	 * @return  string
	 */
	private static function truncate( $text, $chars ) {
		if ( $chars <= 0 || mb_strlen( $text, 'UTF-8' ) <= $chars ) {
			return $text;
		}
		return mb_substr( $text, 0, $chars, 'UTF-8' );
	}

	/**
	 * Gather what build_document() needs from a post.
	 *
	 * @since   0.1.0
	 * @param   WP_Post $post  An eligible post.
	 * @return  array
	 */
	public function collect( $post ) {
		$extracted = SAMOMIND()->builders->extract( $post );

		$data = array(
			'id'           => (int) $post->ID,
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'password'     => $post->post_password,
			'url'          => (string) get_permalink( $post ),
			'title'        => get_the_title( $post ),
			'content_html' => $extracted['html'],
			'builder'      => $extracted['builder'],
			'reasons'      => $extracted['reasons'],
			'source_len'   => $extracted['source_len'],
			'excerpt'      => $post->post_excerpt,
			'modified_gmt' => self::modified_gmt( $post ),
			'terms'        => $this->get_term_names( $post ),
		);

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$data['product'] = array(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, so the description renders as on the product page.
					'short_description_html' => apply_filters( 'woocommerce_short_description', $product->get_short_description() ),
					'price'                  => $product->get_price_html(),
					'stock_status'           => $product->get_stock_status(),
					'catalog_visibility'     => $product->get_catalog_visibility(),
				);
			}
		}

		return $data;
	}

	/**
	 * A post's last modified GMT time, falling back to its publish time
	 * when WordPress left the modified time empty.
	 *
	 * @since   0.1.0
	 * @param   WP_Post $post  Post.
	 * @return  string
	 */
	public static function modified_gmt( $post ) {
		return ( '0000-00-00 00:00:00' === $post->post_modified_gmt ) ? $post->post_date_gmt : $post->post_modified_gmt;
	}

	/**
	 * Names of the post's terms in public taxonomies.
	 *
	 * @since   0.1.0
	 * @param   WP_Post $post  Post.
	 * @return  string[]
	 */
	private function get_term_names( $post ) {
		$names = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) || in_array( $taxonomy->name, self::SKIPPED_TAXONOMIES, true ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) ) {
				$names = array_merge( $names, $terms );
			}
		}
		return $names;
	}

	/**
	 * WooCommerce catalog visibility, empty when not a product.
	 *
	 * @since   0.1.0
	 * @param   int $post_id  Post id.
	 * @return  string
	 */
	private function product_visibility( $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $post_id );
		return $product ? (string) $product->get_catalog_visibility() : '';
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Published, unprotected post ids of the tracked types after an id, in
	 * id order. Eligibility is checked per post by the caller.
	 *
	 * @since   0.1.0
	 * @param   int $after_id  Exclusive lower bound.
	 * @param   int $limit     Rows.
	 * @return  int[]
	 */
	public function get_candidate_ids( $after_id, $limit ) {
		global $wpdb;

		$types = $this->get_post_types();
		if ( empty( $types ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- id-range paging over posts, which WP_Query cannot express; results change with every save.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID > %d ORDER BY ID ASC LIMIT %d',
				array_merge( $types, array( (int) $after_id, (int) $limit ) )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Ids of every published post of one type, eligible or not. Used to
	 * remove a type that was unticked; ids SamoMind never had come back
	 * not_found, which is harmless.
	 *
	 * @since   0.1.0
	 * @param   string $post_type  Post type name.
	 * @return  int[]
	 */
	public function get_published_ids( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids only, read once per settings change.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC",
				$post_type
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Ids of the published, unprotected posts of one type. Used to send a
	 * type again after it was ticked; per-post rules are checked when the
	 * push is built.
	 *
	 * @since   0.2.0
	 * @param   string $post_type  Post type name.
	 * @return  int[]
	 */
	public function get_included_ids( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids only, read once per settings change.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC",
				$post_type
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of published, unprotected posts of the tracked types: the
	 * progress total of a full sync, before per-post exclusions.
	 *
	 * @since   0.1.0
	 * @return  int
	 */
	public function count_candidates() {
		global $wpdb;

		$types = $this->get_post_types();
		if ( empty( $types ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same query as get_candidate_ids(), counted.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')',
				$types
			)
		);
	}

	/**
	 * Number of published items that are included right now: published,
	 * unprotected posts of the ticked types after the filters, less the
	 * ids the SAMOMIND/content/excluded_ids filter lists. Hidden
	 * WooCommerce products are not subtracted, so a shop with hidden
	 * products can show a few more than SamoMind holds.
	 *
	 * @since   0.2.0
	 * @return  int
	 */
	public function count_included() {
		$total    = $this->count_candidates();
		$excluded = $this->get_excluded_ids();
		if ( 0 === $total || empty( $excluded ) ) {
			return $total;
		}
		return max( 0, $total - $this->count_candidates_among( $excluded ) );
	}

	/**
	 * How many of the given ids are candidates (published, unprotected,
	 * of a ticked type).
	 *
	 * @since   0.2.0
	 * @param   int[] $ids  Post ids.
	 * @return  int
	 */
	public function count_candidates_among( array $ids ) {
		global $wpdb;

		$types = $this->get_post_types();
		if ( empty( $types ) || empty( $ids ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same rules as count_candidates(), limited to a list of ids.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( $types, array_map( 'intval', $ids ) )
			)
		);
	}

	/**
	 * Every eligible document as external_id => modified_at, sorted by
	 * external_id in byte order (contract 5.6).
	 *
	 * @since   0.1.0
	 * @return  array
	 */
	public function get_inventory() {
		$entries = array();
		$after   = 0;
		do {
			$ids   = $this->get_candidate_ids( $after, 500 );
			$found = count( $ids );
			_prime_post_caches( $ids, false, false );
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $this->is_eligible( $post ) ) {
					$entries[ (string) $id ] = self::rfc3339( self::modified_gmt( $post ) );
				}
				$after = $id;
			}
		} while ( 500 === $found );

		return self::sort_by_external_id( $entries );
	}

	/**
	 * Sort an external_id => value map by key in byte order. PHP turns
	 * numeric string keys into integers, so this returns a list of pairs.
	 *
	 * @since   0.1.0
	 * @param   array $entries  external_id => modified_at.
	 * @return  array  List of array( external_id, modified_at ).
	 */
	public static function sort_by_external_id( array $entries ) {
		$pairs = array();
		foreach ( $entries as $id => $modified ) {
			$pairs[] = array( (string) $id, $modified );
		}
		usort(
			$pairs,
			function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		return $pairs;
	}
}
