<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Settings
 *
 * The shop's choices for the order status feature (docs/ORDERS_CONTRACT.md,
 * design sections 2 and 14): the opt-in, the accepted order-data terms, the
 * orders key (a secret apart from the ingest key), the two sharing switches,
 * and the capabilities reported to Samomind.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Settings
 * @since       0.4.0
 */
class SAMOMIND_Orders_Settings {

	/**
	 * Version of the order-data terms an administrator accepts. Bump it when
	 * the wording changes; the feature stays off until the new one is accepted.
	 *
	 * @var string
	 */
	const TERMS_VERSION = '2026-11';

	/**
	 * Order API version this plugin speaks.
	 *
	 * @var int
	 */
	const API_VERSION = 1;

	/**
	 * Oldest WooCommerce release the feature supports (design decision 9).
	 *
	 * @var string
	 */
	const MIN_WOOCOMMERCE = '8.2';

	/**
	 * Option: the opt-in switch, "1" or "0".
	 *
	 * @var string
	 */
	const OPT_IN = 'samomind_orders_enabled';

	/**
	 * Option: array( version, user_id, time ) of the accepted terms.
	 *
	 * @var string
	 */
	const TERMS = 'samomind_orders_terms';

	/**
	 * Option: the orders key (secret), never shown after saving.
	 *
	 * @var string
	 */
	const SECRET = 'samomind_orders_secret';

	/**
	 * Option: share tracking, "1" or "0". On by default (decision 8).
	 *
	 * @var string
	 */
	const SHARE_TRACKING = 'samomind_orders_share_tracking';

	/**
	 * Option: share item names, "1" or "0". Off by default (decision 8).
	 *
	 * @var string
	 */
	const SHARE_ITEMS = 'samomind_orders_share_items';

	/**
	 * Option: "1" after a check-in told Samomind the feature is on, so a later
	 * switch-off is reported once as well.
	 *
	 * @var string
	 */
	const REPORTED = 'samomind_orders_reported';

	/**
	 * Whether the admin ticked the opt-in box.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function is_opted_in() {
		return '1' === (string) get_option( self::OPT_IN, '0' );
	}

	/**
	 * The accepted terms record, or an empty array.
	 *
	 * @since   0.4.0
	 * @return  array
	 */
	public function get_terms() {
		$terms = get_option( self::TERMS, array() );
		if ( ! is_array( $terms ) || empty( $terms['version'] ) ) {
			return array();
		}
		return array(
			'version' => (string) $terms['version'],
			'user_id' => isset( $terms['user_id'] ) ? (int) $terms['user_id'] : 0,
			'time'    => isset( $terms['time'] ) ? (int) $terms['time'] : 0,
		);
	}

	/**
	 * Whether the current version of the terms was accepted.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function terms_are_accepted() {
		$terms = $this->get_terms();
		return ! empty( $terms ) && self::TERMS_VERSION === $terms['version'];
	}

	/**
	 * Record the acceptance of the current terms.
	 *
	 * @since   0.4.0
	 * @param   int $user_id  WordPress user id of the administrator.
	 * @return  void
	 */
	public function accept_terms( $user_id ) {
		update_option(
			self::TERMS,
			array(
				'version' => self::TERMS_VERSION,
				'user_id' => (int) $user_id,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * The orders key, for signing and checking only.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public function get_secret() {
		return (string) get_option( self::SECRET, '' );
	}

	/**
	 * Whether an orders key is saved, without exposing it.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function has_secret() {
		return '' !== $this->get_secret();
	}

	/**
	 * Whether item names are shared.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function shares_items() {
		return '1' === (string) get_option( self::SHARE_ITEMS, '0' );
	}

	/**
	 * Whether tracking is shared.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function shares_tracking() {
		return '1' === (string) get_option( self::SHARE_TRACKING, '1' );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Why the feature is off, or null when it is on. The codes are the ones
	 * in the contract: multisite, woocommerce_missing, not_opted_in.
	 *
	 * @since   0.4.0
	 * @return  string|null
	 */
	public function disabled_reason() {
		if ( is_multisite() ) {
			return 'multisite';
		}
		if ( ! $this->woocommerce_active() ) {
			return 'woocommerce_missing';
		}
		if ( version_compare( $this->woocommerce_version(), self::MIN_WOOCOMMERCE, '<' ) ) {
			return 'woocommerce_too_old';
		}
		if ( ! $this->is_opted_in() || ! $this->terms_are_accepted() || ! $this->has_secret() ) {
			return 'not_opted_in';
		}
		return null;
	}

	/**
	 * Whether the shop answers order requests.
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function is_enabled() {
		return null === $this->disabled_reason();
	}

	/**
	 * The WooCommerce version, or an empty string.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public function woocommerce_version() {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->version ) ) {
			return (string) WC()->version;
		}
		return defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '';
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 *
	 * @since   0.4.0
	 * @return  bool
	 */
	public function hpos_in_use() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The capabilities object of the contract (design section 2).
	 *
	 * @since   0.4.0
	 * @return  array
	 */
	public function capabilities() {
		$terms = $this->get_terms();
		return array(
			'api'                    => self::API_VERSION,
			'enabled'                => $this->is_enabled(),
			'terms_accepted_version' => isset( $terms['version'] ) ? $terms['version'] : null,
			'share_items'            => $this->shares_items(),
			'share_tracking'         => $this->shares_tracking(),
			'tracking'               => SAMOMIND_Orders_Lookup::tracking_provider(),
			'hpos'                   => $this->hpos_in_use(),
			'woocommerce'            => '' !== $this->woocommerce_version() ? $this->woocommerce_version() : null,
			'mail_dispatch'          => SAMOMIND_Orders_Mail::dispatch_mode(),
			'reason'                 => $this->disabled_reason(),
		);
	}

	/**
	 * What to add to a connect or check-in payload. Nothing until the shop
	 * has opted in, so a site that never uses the feature sends exactly what
	 * 0.3.x sent; after a switch-off, one more report says so.
	 *
	 * @since   0.4.0
	 * @return  array  Empty, or array( 'capabilities' => array( 'orders' => ... ) ).
	 */
	public function payload_addition() {
		$capabilities = $this->capabilities();
		$reported     = '1' === (string) get_option( self::REPORTED, '0' );

		if ( ! $this->is_opted_in() && ! $reported ) {
			return array();
		}

		update_option( self::REPORTED, $capabilities['enabled'] ? '1' : '0', false );

		return array( 'capabilities' => array( 'orders' => $capabilities ) );
	}
}
