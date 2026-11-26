<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Lookup
 *
 * Reads one WooCommerce order and returns only the fields in design
 * section 6. WooCommerce's CRUD layer only (wc_get_order, wc_get_orders and
 * the order getters), so it works with HPOS on or off.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Lookup
 * @since       0.4.0
 */
class SAMOMIND_Orders_Lookup {

	/**
	 * Shape of a number the shop accepts from Samomind.
	 *
	 * @var string
	 */
	const NUMBER_PATTERN = '/^[A-Za-z0-9\-#]{1,20}$/';

	/**
	 * Most items sent.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 50;

	/**
	 * Longest text sent for a name or a label.
	 *
	 * @var int
	 */
	const MAX_TEXT = 200;

	/**
	 * Largest JSON answer the shop sends for one lookup or list. Samomind
	 * refuses anything over 64 KB; this leaves room for the wrapper and the
	 * signature header.
	 *
	 * @since 0.4.1
	 * @var   int
	 */
	const MAX_ANSWER_BYTES = 60000;

	/**
	 * Longest total text sent; the API takes 32 characters.
	 *
	 * @since 0.4.1
	 * @var   int
	 */
	const MAX_TOTAL = 32;

	/**
	 * Most orders in one list.
	 *
	 * @since 0.4.1
	 * @var   int
	 */
	const MAX_LIST = 50;

	/**
	 * Statuses that are never somebody's order: a cart in progress and a
	 * deleted order. WooCommerce names them with a "wc-" prefix in queries.
	 *
	 * @since 0.4.1
	 * @var   string[]
	 */
	const EXCLUDED_STATUSES = array( 'checkout-draft', 'auto-draft', 'trash' );

	/**
	 * Fixed status vocabulary: WooCommerce status => label. Anything else is
	 * "other" with a cleaned label.
	 *
	 * @var string[]
	 */
	const STATUS_LABELS = array(
		'pending'    => 'Pending payment',
		'on-hold'    => 'On hold',
		'processing' => 'Processing',
		'completed'  => 'Completed',
		'cancelled'  => 'Cancelled',
		'refunded'   => 'Refunded',
		'failed'     => 'Failed',
	);

	/**
	 * Which tracking add-on is installed: wc_shipment_tracking, ast or none.
	 * Checked when asked, never at activation.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public static function tracking_provider() {
		if ( class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
			return 'wc_shipment_tracking';
		}
		if ( function_exists( 'ast_get_tracking_items' ) ) {
			return 'ast';
		}
		return 'none';
	}

	/**
	 * Find an order from the number a shopper typed: the order id first,
	 * then the number a sequential-numbers plugin shows. Refunds and other
	 * order-like records are never returned.
	 *
	 * @since   0.4.0
	 * @param   mixed $typed  The number as typed.
	 * @return  WC_Order|null
	 */
	public function find_order( $typed ) {
		if ( ! is_string( $typed ) ) {
			return null;
		}
		$number = ltrim( trim( $typed ), '#' );
		if ( 1 !== preg_match( self::NUMBER_PATTERN, $number ) ) {
			return null;
		}

		// Asks the order tables (or posts) what the id is, so a product or page id is never read as an order.
		if ( ctype_digit( $number ) && strlen( $number ) <= 12 && 'shop_order' === \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type( (int) $number ) ) {
			$order = wc_get_order( (int) $number );
			if ( self::is_shop_order( $order ) ) {
				return $order;
			}
		}

		/**
		 * Meta keys in which a sequential order number plugin stores the
		 * number it shows.
		 *
		 * @since 0.4.0
		 * @param string[] $keys Meta keys.
		 */
		foreach ( (array) apply_filters( 'SAMOMIND/orders/number_meta_keys', array( '_order_number', '_order_number_formatted' ) ) as $key ) {
			// The order tables honour meta_query and the post store honours meta_key and meta_value, so both are asked.
			$found = wc_get_orders(
				array(
					'limit'      => 5,
					'type'       => 'shop_order',
					'return'     => 'objects',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a short lookup on the order's number.
					'meta_key'   => (string) $key,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a short lookup on the order's number.
					'meta_value' => $number,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a short lookup on the order's number.
					'meta_query' => array(
						array(
							'key'   => (string) $key,
							'value' => $number,
						),
					),
				)
			);
			// Whatever the query returns is checked again: the number must really be in that order's meta.
			foreach ( is_array( $found ) ? $found : array() as $candidate ) {
				if ( self::is_shop_order( $candidate ) && (string) $candidate->get_meta( (string) $key, true ) === $number ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Whether a value is a real shop order, not a refund or nothing.
	 *
	 * @since   0.4.0
	 * @param   mixed $order  Whatever wc_get_order returned.
	 * @return  bool
	 */
	private static function is_shop_order( $order ) {
		return is_object( $order ) && $order instanceof WC_Order && 'shop_order' === $order->get_type();
	}

	/**
	 * Status, label and dates of an order: the part every answer shares.
	 *
	 * @since   0.4.1
	 * @param   WC_Order   $order     The order.
	 * @param   array|null $tracking  Result of first_tracking(), or null.
	 * @return  array
	 */
	private function base_fields( $order, $tracking ) {
		$status = (string) $order->get_status();
		if ( isset( self::STATUS_LABELS[ $status ] ) ) {
			$label = self::STATUS_LABELS[ $status ];
		} else {
			$status = 'other';
			$label  = self::clean_text( wc_get_order_status_name( (string) $order->get_status() ), 40 );
			$label  = '' === $label ? 'Other' : $label;
		}

		return array(
			'order_number' => self::clean_text( (string) $order->get_order_number(), 20 ),
			'status'       => $status,
			'status_label' => $label,
			'created'      => self::date_text( $order->get_date_created() ),
			'paid'         => self::date_text( $order->get_date_paid() ),
			'shipped'      => $tracking ? $tracking['shipped'] : null,
			'completed'    => self::date_text( $order->get_date_completed() ),
		);
	}

	/**
	 * The fields Samomind may show for an order, filtered by what the shop
	 * shares. Nothing else leaves the shop: no address, phone, email,
	 * payment, notes or customer name. With $history (order history is on)
	 * the order total is added, and each item gets its image address.
	 *
	 * @since   0.4.0
	 * @param   WC_Order $order           The order.
	 * @param   bool     $share_items     Whether item names may be sent.
	 * @param   bool     $share_tracking  Whether tracking may be sent.
	 * @param   bool     $history         Whether the answer is a v2 one.
	 * @return  array
	 */
	public function order_fields( $order, $share_items, $share_tracking, $history = false ) {
		$tracking = $share_tracking ? $this->first_tracking( $order ) : null;
		$fields   = $this->base_fields( $order, $tracking );

		if ( $share_items ) {
			$fields['items'] = $this->item_list( $order, $history );
		}
		if ( $share_tracking ) {
			$fields['tracking'] = $tracking ? $tracking['tracking'] : null;
		}
		if ( $history ) {
			$total           = self::total_text( $order );
			$fields['total'] = '' === $total ? null : $total;
		}

		// Every lookup answer, version 1 or 2, stays under what Samomind accepts.
		return $this->fit_size( $fields );
	}

	/**
	 * One row of an order list: status, dates, total and the number of items.
	 * No items, tracking, address, phone, email, payment method, note or IP.
	 *
	 * @since   0.4.1
	 * @param   WC_Order $order           The order.
	 * @param   bool     $share_tracking  Whether the shipped date may be sent.
	 * @return  array
	 */
	public function list_row( $order, $share_tracking ) {
		$row               = $this->base_fields( $order, $share_tracking ? $this->first_tracking( $order ) : null );
		$row['total']      = self::total_text( $order );
		$row['item_count'] = self::item_count( $order );
		return $row;
	}

	/**
	 * Bring a lookup answer under MAX_ANSWER_BYTES, measured the way the signed
	 * body is made (wp_json_encode of the whole answer). First the item image
	 * addresses go, from the last item back; if it is still too long, item
	 * names are shortened, longest first, ending with an ellipsis. Items are
	 * never dropped, and an answer that already fits is returned as it is.
	 *
	 * @since   0.4.1
	 * @param   array $fields  Fields from order_fields().
	 * @return  array
	 */
	public function fit_size( array $fields ) {
		if ( empty( $fields['items'] ) || self::answer_size( $fields ) <= self::MAX_ANSWER_BYTES ) {
			return $fields;
		}

		for ( $i = count( $fields['items'] ) - 1; $i >= 0; $i-- ) {
			if ( ! empty( $fields['items'][ $i ]['image_url'] ) ) {
				$fields['items'][ $i ]['image_url'] = null;
				if ( self::answer_size( $fields ) <= self::MAX_ANSWER_BYTES ) {
					return $fields;
				}
			}
		}

		// Still too long: the names. Take the longest and cut it by a fifth each time.
		for ( $guard = 0; $guard < 2000; $guard++ ) {
			$longest = -1;
			$length  = 1;
			foreach ( $fields['items'] as $i => $item ) {
				$chars = self::length( $item['name'] );
				if ( $chars > $length ) {
					$longest = $i;
					$length  = $chars;
				}
			}
			if ( $longest < 0 ) {
				break;
			}
			$keep                                = max( 1, (int) floor( $length * 0.8 ) - 1 );
			$fields['items'][ $longest ]['name'] = mb_substr( $fields['items'][ $longest ]['name'], 0, $keep, 'UTF-8' ) . '…';
			if ( self::answer_size( $fields ) <= self::MAX_ANSWER_BYTES ) {
				break;
			}
		}
		return $fields;
	}

	/**
	 * Bytes of the signed body for a lookup answer.
	 *
	 * @since   0.4.1
	 * @param   array $fields  Order fields.
	 * @return  int
	 */
	private static function answer_size( array $fields ) {
		$whole = array(
			'found' => true,
			'order' => $fields,
		);
		return strlen( (string) wp_json_encode( $whole ) );
	}

	/**
	 * The order total as plain text in the order's own currency, at most
	 * MAX_TOTAL characters. When the shop's formatting is longer, the plain
	 * amount and the currency code are used; never a cut-off price.
	 *
	 * @since   0.4.1
	 * @param   WC_Order $order  The order.
	 * @return  string  Empty when nothing fits.
	 */
	public static function total_text( $order ) {
		$text = self::clean_text( SAMOMIND_Content::money( $order->get_total(), (string) $order->get_currency() ), 200 );
		if ( self::length( $text ) <= self::MAX_TOTAL ) {
			return $text;
		}
		$text = self::clean_text( (string) $order->get_total() . ' ' . (string) $order->get_currency(), 200 );
		return self::length( $text ) <= self::MAX_TOTAL ? $text : '';
	}

	/**
	 * The sum of the quantities of the order's line items.
	 *
	 * @since   0.4.1
	 * @param   WC_Order $order  The order.
	 * @return  int
	 */
	public static function item_count( $order ) {
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			$count += max( 0, (int) $item->get_quantity() );
		}
		return min( 1000000, $count );
	}

	/**
	 * Characters in a text.
	 *
	 * @since   0.4.1
	 * @param   string $text  Text.
	 * @return  int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Statuses an email or customer search covers: every status the shop
	 * has except the excluded ones, in the form wc_get_orders() takes.
	 *
	 * @since   0.4.1
	 * @return  string[]
	 */
	private static function visible_statuses() {
		$excluded = array_map(
			static function ( $status ) {
				return 'wc-' . $status;
			},
			self::EXCLUDED_STATUSES
		);
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), $excluded ) );
	}

	/**
	 * Whether an order is one a search may return: a shop order that is not
	 * a draft or deleted.
	 *
	 * @since   0.4.1
	 * @param   mixed $order  Whatever a query returned.
	 * @return  bool
	 */
	private static function is_listable( $order ) {
		return self::is_shop_order( $order ) && ! in_array( (string) $order->get_status(), self::EXCLUDED_STATUSES, true );
	}

	/**
	 * Whether any order has this billing email. Asks for one id only.
	 *
	 * @since   0.4.1
	 * @param   string $email  Normalised address.
	 * @return  bool
	 */
	public function email_has_orders( $email ) {
		$found = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'status'        => self::visible_statuses(),
				'billing_email' => $email,
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return is_array( $found ) && ! empty( $found );
	}

	/**
	 * The newest orders of an address, newest first. Each one's billing email
	 * is compared again, so an order of another address is never returned.
	 *
	 * @since   0.4.1
	 * @param   string $email  Normalised address.
	 * @param   int    $limit  Most orders to fetch.
	 * @return  WC_Order[]
	 */
	public function orders_for_email( $email, $limit ) {
		$found  = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'status'        => self::visible_statuses(),
				'billing_email' => $email,
				'limit'         => max( 1, (int) $limit ),
				'orderby'       => 'date',
				'order'         => 'DESC',
				'return'        => 'objects',
			)
		);
		$orders = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			if ( self::is_listable( $order ) && self::normalise_email( $order->get_billing_email() ) === $email ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * The newest orders of a customer, newest first.
	 *
	 * @since   0.4.1
	 * @param   int $user_id  Customer id.
	 * @param   int $limit    Most orders to fetch.
	 * @return  WC_Order[]
	 */
	public function orders_for_customer( $user_id, $limit ) {
		if ( (int) $user_id < 1 ) {
			return array();
		}
		$found  = wc_get_orders(
			array(
				'type'        => 'shop_order',
				'status'      => self::visible_statuses(),
				'customer_id' => (int) $user_id,
				'limit'       => max( 1, (int) $limit ),
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		$orders = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			if ( self::is_listable( $order ) && (int) $order->get_customer_id() === (int) $user_id ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * Whether an email or customer grant covers an order.
	 *
	 * @since   0.4.1
	 * @param   WC_Order $order    The order.
	 * @param   string   $email    Address of the session's email grant, or an empty string.
	 * @param   int      $user_id  Customer of the session's customer grant, or 0.
	 * @return  bool
	 */
	public function grant_covers( $order, $email, $user_id ) {
		if ( ! self::is_listable( $order ) ) {
			return false;
		}
		if ( '' !== $email && self::normalise_email( $order->get_billing_email() ) === $email ) {
			return true;
		}
		return (int) $user_id > 0 && (int) $order->get_customer_id() === (int) $user_id;
	}

	/**
	 * Trimmed, lower-cased email.
	 *
	 * @since   0.4.1
	 * @param   mixed $email  Address.
	 * @return  string
	 */
	public static function normalise_email( $email ) {
		return SAMOMIND_Orders_Store::normalise_email( $email );
	}

	/**
	 * Line items as name and quantity, and with $history the image address
	 * of the variation, else the product, else null.
	 *
	 * @since   0.4.0
	 * @param   WC_Order $order    The order.
	 * @param   bool     $history  Whether to add image_url.
	 * @return  array
	 */
	private function item_list( $order, $history = false ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			$name = self::clean_text( (string) $item->get_name(), self::MAX_TEXT );
			if ( '' === $name ) {
				continue;
			}
			$entry = array(
				'name' => $name,
				'qty'  => max( 0, (int) $item->get_quantity() ),
			);
			if ( $history ) {
				$product            = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
				$entry['image_url'] = $product ? SAMOMIND_Content::product_image_url( $product ) : null;
			}
			$items[] = $entry;
		}
		return $items;
	}

	/**
	 * The first tracking item of whichever add-on is installed.
	 *
	 * @since   0.4.0
	 * @param   WC_Order $order  The order.
	 * @return  array|null  array( tracking => array, shipped => string|null ).
	 */
	private function first_tracking( $order ) {
		$provider = self::tracking_provider();
		$items    = array();
		if ( 'wc_shipment_tracking' === $provider ) {
			$actions = WC_Shipment_Tracking_Actions::get_instance();
			$items   = $actions->get_tracking_items( $order->get_id(), true );
		} elseif ( 'ast' === $provider ) {
			$items = ast_get_tracking_items( $order->get_id() );
		}
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}
		$item = reset( $items );
		if ( ! is_array( $item ) ) {
			return null;
		}

		$carrier = '';
		foreach ( array( 'formatted_tracking_provider', 'tracking_provider', 'custom_tracking_provider' ) as $key ) {
			if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				$carrier = self::clean_text( $item[ $key ], 80 );
				break;
			}
		}
		$number = isset( $item['tracking_number'] ) && is_string( $item['tracking_number'] ) ? self::clean_text( $item['tracking_number'], 80 ) : '';
		if ( '' === $number ) {
			return null;
		}

		$link = '';
		foreach ( array( 'formatted_tracking_link', 'custom_tracking_link' ) as $key ) {
			if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				$link = $item[ $key ];
				break;
			}
		}

		$shipped = null;
		if ( ! empty( $item['date_shipped'] ) ) {
			$time    = is_numeric( $item['date_shipped'] ) ? (int) $item['date_shipped'] : strtotime( (string) $item['date_shipped'] );
			$shipped = $time ? wp_date( 'Y-m-d', $time ) : null;
		}

		return array(
			'tracking' => array(
				'carrier' => $carrier,
				'number'  => $number,
				'url'     => self::safe_https_url( $link ),
			),
			'shipped'  => $shipped,
		);
	}

	/**
	 * A tracking link only if it is https, has no credentials, is at most
	 * 2048 characters long and does not use an IP address as the host.
	 *
	 * @since   0.4.0
	 * @param   string $url  The link a tracking add-on built.
	 * @return  string|null
	 */
	public static function safe_https_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = trim( $parts['host'], '[]' );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Plain text for a name or label: no markup, no control or bidi
	 * characters, one space between words, cut to a length.
	 *
	 * @since   0.4.0
	 * @param   string $text    Text from the shop.
	 * @param   int    $length  Most characters.
	 * @return  string
	 */
	public static function clean_text( $text, $length ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$text = (string) preg_replace( '/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) $length, 'UTF-8' ) : substr( $text, 0, (int) $length );
	}

	/**
	 * A date as Y-m-d in the shop's time zone, or null.
	 *
	 * @since   0.4.0
	 * @param   WC_DateTime|null $date  A WooCommerce date.
	 * @return  string|null
	 */
	private static function date_text( $date ) {
		if ( ! is_object( $date ) || ! method_exists( $date, 'getTimestamp' ) ) {
			return null;
		}
		return wp_date( 'Y-m-d', $date->getTimestamp() );
	}
}
