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
	const MAX_IMAGE_CHARS    = 2048;
	const MAX_DISPLAY_CHARS  = 64;

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
		 * Filter the post types sent to Samomind. Runs after the settings
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
	 * What a site that never saved a selection syncs: every available type
	 * minus the old exclusion list (0.3.2 and earlier), or null when there is
	 * no old list. Worked out at runtime, so a type registered late in init
	 * is included exactly as before.
	 *
	 * @since   0.3.3
	 * @return  string[]|null
	 */
	public function get_legacy_selection() {
		$excluded = SAMOMIND()->helpers->get_legacy_excluded_post_types();
		return null === $excluded ? null : array_values( array_diff( $this->get_available_post_types(), $excluded ) );
	}

	/**
	 * The ticked post types: the saved selection, else what an existing site
	 * synced before, else the default.
	 *
	 * @since   0.3.3
	 * @return  string[]
	 */
	public function get_selected_post_types() {
		$saved = SAMOMIND()->helpers->get_selected_post_types();
		if ( null !== $saved ) {
			return $saved;
		}
		$legacy = $this->get_legacy_selection();
		return null === $legacy ? $this->get_default_post_types() : $legacy;
	}

	/**
	 * Activation on a new install: save the default selection. A site that
	 * has a saved selection, or the old exclusion list, is left alone.
	 *
	 * @since   0.3.3
	 * @return  void
	 */
	public function save_default_post_types() {
		$helpers = SAMOMIND()->helpers;
		if ( null !== $helpers->get_selected_post_types() || null !== $helpers->get_legacy_excluded_post_types() ) {
			return;
		}
		add_option( 'samomind_post_types', $this->get_default_post_types(), '', true );
	}

	/**
	 * Post ids a site has excluded.
	 *
	 * @since   0.1.0
	 * @return  int[]
	 */
	public function get_excluded_ids() {
		/**
		 * Filter the post ids never sent to Samomind.
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
	 * Whether a post is sent to Samomind right now.
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
			$data['product'] = array( 'catalog_visibility' => $this->product_visibilities( array( $post->ID ) )[ (int) $post->ID ] );
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
		if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
			// The card fields (F-33). The API refuses the whole body for one value that breaks a rule, so each is checked here and sent as null when it does.
			$fields['image_url']             = self::safe_image_url( isset( $data['product']['image_url'] ) ? $data['product']['image_url'] : null );
			$fields['price_display']         = self::plain_display( isset( $data['product']['price_display'] ) ? $data['product']['price_display'] : null );
			$fields['regular_price_display'] = self::plain_display( isset( $data['product']['regular_price_display'] ) ? $data['product']['regular_price_display'] : null );
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
					'price'                  => $this->product_price_text( $product ),
					'price_display'          => $this->product_price_display( $product ),
					'regular_price_display'  => $this->product_regular_price_display( $product ),
					'image_url'              => self::product_image_url( $product ),
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
		return self::modified_from( $post->post_modified_gmt, $post->post_date_gmt );
	}

	/**
	 * The modified time from the two columns it is read from: the modified
	 * time, or the publish time when WordPress left the modified time empty.
	 * One rule for a post object and for a row read by get_inventory().
	 *
	 * @since   0.4.1
	 * @param   string $modified_gmt  post_modified_gmt.
	 * @param   string $date_gmt      post_date_gmt.
	 * @return  string
	 */
	public static function modified_from( $modified_gmt, $date_gmt ) {
		return ( '0000-00-00 00:00:00' === $modified_gmt ) ? $date_gmt : $modified_gmt;
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
	 * A product's price as clean plain text, the way the shop shows it to a
	 * guest: same tax display, currency and number format, no markup and no
	 * screen-reader text. The API takes at most MAX_PRICE_CHARS (64).
	 *
	 * A simple or external product reads "$100.00", or "$70.00 (was $100.00)"
	 * on sale. A variable product reads "$30.00 to $50.00", with " (some on
	 * sale)" when any variation is. Grouped products have no price of their own: the range is over their
	 * children's displayed prices. A price a shop or plugin hides from guests
	 * (get_price_html() is empty) is not sent, nor is a product with no price.
	 *
	 * @since   0.3.6
	 * @param   WC_Product $product  Product.
	 * @return  string  Empty when there is no price.
	 */
	public function product_price_text( $product ) {
		return $this->price_text( $product, false );
	}

	/**
	 * The current guest price as the shop formats it and nothing else: no
	 * "(was ...)", no "(some on sale)" and no price suffix ("$89.00",
	 * "$39.00 to $45.00"). The card shows it beside the product. Empty when
	 * there is no price to show.
	 *
	 * @since   0.4.1
	 * @param   WC_Product $product  Product.
	 * @return  string
	 */
	public function product_price_display( $product ) {
		return $this->price_text( $product, true );
	}

	/**
	 * The regular price of a simple or external product that is on sale at a different
	 * price, as the shop formats it ("$110.00"); empty for everything else.
	 *
	 * @since   0.4.1
	 * @param   WC_Product $product  Product.
	 * @return  string
	 */
	public function product_regular_price_display( $product ) {
		if ( ! is_object( $product ) || ! $product->is_type( array( 'simple', 'external' ) ) || ! $product->is_on_sale() || '' === $product->get_price() || '' === $product->get_regular_price() ) {
			return '';
		}
		if ( '' === trim( wp_strip_all_tags( (string) $product->get_price_html() ) ) ) {
			return '';
		}
		$price   = wc_get_price_to_display( $product );
		$regular = wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		return (string) $regular !== (string) $price ? self::money( $regular ) : '';
	}

	/**
	 * The price text behind product_price_text() and product_price_display().
	 *
	 * @since   0.4.1
	 * @param   WC_Product $product  Product.
	 * @param   bool       $display  True for the bare price, false for the text the AI reads.
	 * @return  string
	 */
	private function price_text( $product, $display ) {
		if ( ! is_object( $product ) || '' === trim( wp_strip_all_tags( (string) $product->get_price_html() ) ) ) {
			return '';
		}

		if ( $product->is_type( array( 'variable', 'variable-subscription' ) ) ) {
			$prices = $product->get_variation_prices( true );
			if ( empty( $prices['price'] ) ) {
				return '';
			}
			$text = self::range_text(
				current( $prices['price'] ),
				end( $prices['price'] ),
				! $display && $product->is_on_sale(),
				current( $prices['regular_price'] ),
				end( $prices['regular_price'] )
			);
		} elseif ( $product->is_type( 'grouped' ) ) {
			$child_prices = array();
			$on_sale      = false;
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && '' !== $child->get_price() ) {
					$child_prices[] = wc_get_price_to_display( $child );
					$on_sale        = $on_sale || ( ! $display && $child->is_on_sale() );
				}
			}
			if ( empty( $child_prices ) ) {
				return '';
			}
			$text = self::range_text( min( $child_prices ), max( $child_prices ), $on_sale, null, null );
		} elseif ( '' === $product->get_price() ) {
			return '';
		} else {
			$price = wc_get_price_to_display( $product );
			$text  = self::money( $price );
			if ( ! $display && $product->is_on_sale() ) {
				$regular = wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
				if ( (string) $regular !== (string) $price ) {
					$text .= ' (was ' . self::money( $regular ) . ')';
				}
			}
		}

		if ( $display ) {
			return $text;
		}

		$suffix = trim( html_entity_decode( wp_strip_all_tags( (string) $product->get_price_suffix() ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $suffix ) {
			$text .= ' ' . $suffix;
		}

		return self::truncate( $text, self::MAX_PRICE_CHARS );
	}

	/**
	 * "$low to $high", one price when both are equal, and "(was $x)" or
	 * "(some on sale)" when something in the range is on sale.
	 *
	 * @since   0.3.6
	 * @param   float|string      $low      Lowest displayed price.
	 * @param   float|string      $high     Highest displayed price.
	 * @param   bool              $on_sale  Whether anything is on sale.
	 * @param   float|string|null $low_reg  Lowest regular price, when known.
	 * @param   float|string|null $high_reg Highest regular price, when known.
	 * @return  string
	 */
	private static function range_text( $low, $high, $on_sale, $low_reg, $high_reg ) {
		if ( (string) $low !== (string) $high ) {
			return self::money( $low ) . ' to ' . self::money( $high ) . ( $on_sale ? ' (some on sale)' : '' );
		}
		if ( $on_sale && null !== $low_reg && (string) $low_reg === (string) $high_reg && (string) $low_reg !== (string) $low ) {
			return self::money( $low ) . ' (was ' . self::money( $low_reg ) . ')';
		}
		return self::money( $low ) . ( $on_sale ? ' (some on sale)' : '' );
	}

	/**
	 * An amount in the store's currency and number format, as plain text.
	 *
	 * @since   0.3.6
	 * @param   float|string $amount    Amount.
	 * @param   string       $currency  Currency code; the store's when empty.
	 * @return  string
	 */
	public static function money( $amount, $currency = '' ) {
		$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount, '' === $currency ? array() : array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}

	/**
	 * The main image of a product at WooCommerce's thumbnail size, as an
	 * address the API takes, or null. A variation without an image of its own
	 * uses its parent's. A product with no image gives null, never
	 * WooCommerce's placeholder.
	 *
	 * @since   0.4.1
	 * @param   WC_Product|mixed $product  Product or variation.
	 * @return  string|null
	 */
	public static function product_image_url( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_image_id' ) ) {
			return null;
		}
		$image_id = (int) $product->get_image_id();
		if ( $image_id < 1 && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$parent   = wc_get_product( (int) $product->get_parent_id() );
			$image_id = $parent ? (int) $parent->get_image_id() : 0;
		}
		if ( $image_id < 1 ) {
			return null;
		}
		return self::safe_image_url( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) );
	}

	/**
	 * An image address the API takes: https, a host, no user name or
	 * password, no spaces or control characters, at most MAX_IMAGE_CHARS
	 * characters. Anything else is null; it is never repaired.
	 *
	 * @since   0.4.1
	 * @param   mixed $url  Address from the shop.
	 * @return  string|null
	 */
	public static function safe_image_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		if ( strlen( $url ) > self::MAX_IMAGE_CHARS * 4 || mb_strlen( $url, 'UTF-8' ) > self::MAX_IMAGE_CHARS ) {
			return null;
		}
		// The API's own pattern, with the "no @ before the path" rule that keeps user:pass out; a space, control or format character anywhere is refused.
		if ( 1 !== preg_match( '#^https://[^\s/?\#@]+(?:[/?\#]\S*)?$#u', $url ) || 0 !== preg_match( '/[\s\p{Z}\p{C}]/u', $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * A price as plain text the API takes in price_display: no markup, no
	 * control characters, at most MAX_DISPLAY_CHARS characters. A value that
	 * breaks a rule is null; a price is never cut in the middle.
	 *
	 * @since   0.4.1
	 * @param   mixed $text  Price text from the shop.
	 * @return  string|null
	 */
	public static function plain_display( $text ) {
		if ( ! is_string( $text ) ) {
			return null;
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) ) );
		if ( '' === $text || 0 !== preg_match( '/[<>\p{C}]/u', $text ) || mb_strlen( $text, 'UTF-8' ) > self::MAX_DISPLAY_CHARS ) {
			return null;
		}
		return $text;
	}

	/**
	 * Whether WooCommerce is active and its products are offered for sync
	 * but not ticked on the settings page.
	 *
	 * @since   0.3.6
	 * @return  bool
	 */
	public function shop_products_not_synced() {
		return $this->woocommerce_active()
			&& in_array( 'product', $this->get_available_post_types(), true )
			&& ! in_array( 'product', $this->get_selected_post_types(), true );
	}

	/**
	 * WooCommerce catalog visibility (visible, catalog, search or hidden) of
	 * a batch of products, read from the terms WooCommerce keeps it in. The
	 * one source for the reconcile list and for a push, so they cannot differ.
	 *
	 * @since   0.4.1
	 * @param   int[] $ids  Product ids.
	 * @return  string[]  Product id => visibility.
	 */
	public function product_visibilities( array $ids ) {
		global $wpdb;

		$ids        = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$visibility = array_fill_keys( $ids, 'visible' );
		if ( empty( $ids ) ) {
			return $visibility;
		}

		// WooCommerce keeps visibility as two terms of "product_visibility"; both is hidden. Read here as plain columns so no post or product is loaded into the object cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one batch of ids; the placeholders are generated here.
		$rows        = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id AS id, t.name AS name FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_visibility' AND tr.object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			),
			ARRAY_A
		);
		$hidden_from = array();
		foreach ( $rows as $row ) {
			$hidden_from[ (int) $row['id'] ][ (string) $row['name'] ] = true;
		}
		foreach ( $hidden_from as $id => $names ) {
			$search  = isset( $names['exclude-from-search'] );
			$catalog = isset( $names['exclude-from-catalog'] );
			if ( $search && $catalog ) {
				$visibility[ $id ] = 'hidden';
			} elseif ( $search ) {
				$visibility[ $id ] = 'catalog';
			} elseif ( $catalog ) {
				$visibility[ $id ] = 'search';
			}
		}
		return $visibility;
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
	 * @param   int      $after_id  Exclusive lower bound.
	 * @param   int      $limit     Rows.
	 * @param   string[] $only      Keep only these post types; none keeps all.
	 * @return  int[]
	 */
	public function get_candidate_ids( $after_id, $limit, array $only = array() ) {
		global $wpdb;

		$types = $this->types_within( $only );
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
	 * Published, unprotected posts of the tracked types after an id, in id
	 * order, as plain rows with only the columns eligibility and the modified
	 * time need.
	 *
	 * @since   0.4.1
	 * @param   int $after_id  Exclusive lower bound.
	 * @param   int $limit     Rows.
	 * @return  array[]
	 */
	public function get_candidate_rows( $after_id, $limit ) {
		global $wpdb;

		$types = $this->get_post_types();
		if ( empty( $types ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- id-range paging over posts, which WP_Query cannot express; a read of columns, not posts.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status, post_password, post_modified_gmt, post_date_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID > %d ORDER BY ID ASC LIMIT %d',
				array_merge( $types, array( (int) $after_id, (int) $limit ) )
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Ids of every published post of one type, eligible or not. Used to
	 * remove a type that was unticked; ids Samomind never had come back
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
	 * The tracked post types, narrowed to the given ones when any are given.
	 *
	 * @since   0.4.1
	 * @param   string[] $only  Post types to keep, or none to keep all.
	 * @return  string[]
	 */
	private function types_within( array $only ) {
		$types = $this->get_post_types();
		return empty( $only ) ? $types : array_values( array_intersect( $types, $only ) );
	}

	/**
	 * Number of published, unprotected posts of the tracked types: the
	 * progress total of a full sync, before per-post exclusions.
	 *
	 * @since   0.1.0
	 * @param   string[] $only  Keep only these post types; none keeps all.
	 * @return  int
	 */
	public function count_candidates( array $only = array() ) {
		global $wpdb;

		$types = $this->types_within( $only );
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
	 * products can show a few more than Samomind holds.
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
		// Plain rows, 500 at a time, judged by the same is_eligible_data() that judges a push. No post or product is loaded, so nothing builds up in the object cache and memory stays flat however many items the shop has.
		$entries  = array();
		$after    = 0;
		$types    = $this->get_post_types();
		$excluded = $this->get_excluded_ids();
		do {
			$rows  = $this->get_candidate_rows( $after, 500 );
			$found = count( $rows );

			$product_ids = array();
			foreach ( $rows as $row ) {
				if ( 'product' === $row['post_type'] ) {
					$product_ids[] = (int) $row['ID'];
				}
			}
			$visibility = $this->product_visibilities( $product_ids );

			foreach ( $rows as $row ) {
				$id   = (int) $row['ID'];
				$data = array(
					'id'        => $id,
					'post_type' => $row['post_type'],
					'status'    => $row['post_status'],
					'password'  => $row['post_password'],
				);
				if ( 'product' === $row['post_type'] ) {
					$data['product'] = array( 'catalog_visibility' => $visibility[ $id ] );
				}
				if ( self::is_eligible_data( $data, $types, $excluded ) ) {
					$entries[ (string) $id ] = self::rfc3339( self::modified_from( $row['post_modified_gmt'], $row['post_date_gmt'] ) );
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

	/**
	 * Clear this request's in-memory copy of the object cache, where the cache
	 * says it can: a persistent cache keeps what it has, and nothing is ever
	 * flushed or deleted from it. Without that support nothing happens.
	 *
	 * @since   0.4.1
	 * @return  void
	 */
	public static function release_runtime_cache() {
		if ( function_exists( 'wp_cache_flush_runtime' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Whether this request has used most of its memory, so a worker run can
	 * end now and carry on in the next one.
	 *
	 * @since   0.4.1
	 * @param   float $share  Share of the limit that counts as most of it.
	 * @return  bool
	 */
	public static function memory_is_tight( $share = 0.7 ) {
		$limit = ini_get( 'memory_limit' );
		if ( false === $limit || '' === $limit || '-1' === (string) $limit ) {
			return false;
		}
		$bytes = (int) $limit;
		$unit  = strtolower( substr( trim( (string) $limit ), -1 ) );
		if ( 'g' === $unit ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( 'm' === $unit ) {
			$bytes *= 1024 * 1024;
		} elseif ( 'k' === $unit ) {
			$bytes *= 1024;
		}
		return $bytes > 0 && memory_get_usage() > $share * $bytes;
	}
}
