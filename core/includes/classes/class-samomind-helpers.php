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
	 * Post types unticked on the settings page. Stored as exclusions so a
	 * newly registered type is synced until someone unticks it.
	 *
	 * @since   0.1.0
	 * @return  string[]
	 */
	public function get_excluded_post_types() {
		$types = get_option( 'samomind_excluded_post_types', array() );
		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array();
	}

	/**
	 * Get the stored widget embed snippet.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_embed_snippet() {
		return (string) get_option( 'samomind_embed_snippet', '' );
	}

	/**
	 * The site address reported to SamoMind on connect.
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
