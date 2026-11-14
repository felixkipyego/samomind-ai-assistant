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
	 * The fields Samomind may show for an order, filtered by what the shop
	 * shares. Nothing else leaves the shop: no address, phone, email,
	 * payment, totals, notes or customer name.
	 *
	 * @since   0.4.0
	 * @param   WC_Order $order           The order.
	 * @param   bool     $share_items     Whether item names may be sent.
	 * @param   bool     $share_tracking  Whether tracking may be sent.
	 * @return  array
	 */
	public function order_fields( $order, $share_items, $share_tracking ) {
		$status = (string) $order->get_status();
		if ( isset( self::STATUS_LABELS[ $status ] ) ) {
			$label = self::STATUS_LABELS[ $status ];
		} else {
			$status = 'other';
			$label  = self::clean_text( wc_get_order_status_name( (string) $order->get_status() ), 40 );
			$label  = '' === $label ? 'Other' : $label;
		}

		$tracking = $share_tracking ? $this->first_tracking( $order ) : null;

		$fields = array(
			'order_number' => self::clean_text( (string) $order->get_order_number(), 20 ),
			'status'       => $status,
			'status_label' => $label,
			'created'      => self::date_text( $order->get_date_created() ),
			'paid'         => self::date_text( $order->get_date_paid() ),
			'shipped'      => $tracking ? $tracking['shipped'] : null,
			'completed'    => self::date_text( $order->get_date_completed() ),
		);

		if ( $share_items ) {
			$fields['items'] = $this->item_list( $order );
		}
		if ( $share_tracking ) {
			$fields['tracking'] = $tracking ? $tracking['tracking'] : null;
		}

		return $fields;
	}

	/**
	 * Line items as name and quantity.
	 *
	 * @since   0.4.0
	 * @param   WC_Order $order  The order.
	 * @return  array
	 */
	private function item_list( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			$name = self::clean_text( (string) $item->get_name(), self::MAX_TEXT );
			if ( '' === $name ) {
				continue;
			}
			$items[] = array(
				'name' => $name,
				'qty'  => max( 0, (int) $item->get_quantity() ),
			);
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
