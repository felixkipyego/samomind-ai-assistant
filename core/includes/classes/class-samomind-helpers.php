<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Helpers
 *
 * Option getters and small functions that are
 * used globally within the plugin.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Helpers
 * @since       0.1.0
 */
class SAMOMIND_Helpers {

	/**
	 * Default API base URL.
	 *
	 * @var string
	 */
	const DEFAULT_API_BASE_URL = 'https://api.samomind.com';

	/**
	 * Name of the wp-config.php constant that overrides the stored secret.
	 *
	 * @var string
	 */
	const SECRET_CONSTANT = 'SAMOMIND_API_SECRET';

	/**
	 * Get the API base URL, without a trailing slash.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_api_base_url() {
		$url = (string) get_option( 'samomind_api_base_url', '' );
		if ( '' === $url ) {
			$url = self::DEFAULT_API_BASE_URL;
		}
		return untrailingslashit( $url );
	}

	/**
	 * Get the ingest key id.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_key_id() {
		return (string) get_option( 'samomind_key_id', '' );
	}

	/**
	 * Whether the secret comes from the wp-config.php constant.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function secret_is_constant() {
		return defined( self::SECRET_CONSTANT ) && '' !== (string) constant( self::SECRET_CONSTANT );
	}

	/**
	 * Get the ingest secret. Only the request client may use the value.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_secret() {
		if ( $this->secret_is_constant() ) {
			return (string) constant( self::SECRET_CONSTANT );
		}
		return (string) get_option( 'samomind_api_secret', '' );
	}

	/**
	 * Whether a secret is available, without exposing it.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function has_secret() {
		return '' !== $this->get_secret();
	}

	/**
	 * Whether the key id and secret are both set.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function has_credentials() {
		return '' !== $this->get_key_id() && $this->has_secret();
	}

	/**
	 * The unticked types saved by 0.3.2 and earlier, or null when that
	 * option does not exist (a new install).
	 *
	 * @since   0.3.3
	 * @return  string[]|null
	 */
	public function get_legacy_excluded_post_types() {
		$types = get_option( 'samomind_excluded_post_types', null );
		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : null;
	}

	/**
	 * Post types ticked on the settings page, or null when nothing was saved
	 * yet. Stored as a list of ticked types, so a type registered later
	 * (or WooCommerce activated later) stays unticked until someone ticks it.
	 *
	 * @since   0.3.3
	 * @return  string[]|null
	 */
	public function get_selected_post_types() {
		$types = get_option( 'samomind_post_types', null );
		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : null;
	}

	/**
	 * Whether the admin turned the chat widget on.
	 *
	 * @since   0.3.0
	 * @return  bool
	 */
	public function is_widget_enabled() {
		return '1' === (string) get_option( 'samomind_widget_enabled', '0' );
	}

	/**
	 * Get the saved site key, or an empty string when it is missing or
	 * malformed (so a bad value in the database is never printed).
	 *
	 * @since   0.3.0
	 * @return  string
	 */
	public function get_site_key() {
		$key = get_option( 'samomind_site_key', '' );
		return SAMOMIND_Embed::is_valid_site_key( $key ) ? $key : '';
	}

	/**
	 * Whether the widget should load: switched on and a valid key saved.
	 *
	 * @since   0.3.0
	 * @return  bool
	 */
	public function widget_is_active() {
		return $this->is_widget_enabled() && '' !== $this->get_site_key();
	}

	/**
	 * The site's origin (scheme, host and port, no path), as Samomind's
	 * "Allowed origins" list expects it.
	 *
	 * @since   0.3.0
	 * @return  string
	 */
	public function get_site_origin() {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The site address reported to Samomind on connect.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_site_url() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Format a Unix time for the admin screens, in the site's timezone.
	 *
	 * @since   0.1.0
	 * @param   int $timestamp Unix time, 0 for never.
	 * @return  string
	 */
	public function format_time( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'samomind-ai-assistant' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
