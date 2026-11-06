<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Embed
 *
 * Loads the chat widget on the front end with wp_enqueue_script(), when the
 * admin turned it on and saved a valid site key. The site key travels as a
 * data attribute added through the wp_script_attributes filter, so the
 * plugin never prints a script tag of its own and no inline code exists for
 * a security scanner to flag.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Embed
 * @since       0.1.0
 */
class SAMOMIND_Embed {

	/**
	 * Script handle. WordPress prints the tag with the id "samomind-widget-js".
	 *
	 * @var string
	 */
	const HANDLE = 'samomind-widget';

	/**
	 * The only script this class loads.
	 *
	 * @var string
	 */
	const WIDGET_URL = 'https://widget.samomind.com/widget.js';

	/**
	 * Shape of a site key: "pk_live_" and the URL-safe token the SamoMind
	 * backend generates (generate_site_key(), 256 bits, 43 characters).
	 *
	 * @var string
	 */
	const SITE_KEY_PATTERN = '/^pk_live_[A-Za-z0-9_-]{20,128}\z/';

	/**
	 * Our SAMOMIND_Embed constructor to run the plugin logic.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'migrate_pasted_snippet' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget' ) );
		add_filter( 'wp_script_attributes', array( $this, 'add_widget_attributes' ) );

		// Keep optimisation plugins from combining, minifying or delaying the loader.
		add_filter( 'rocket_exclude_js', array( $this, 'exclude_from_rocket' ) );
		add_filter( 'rocket_exclude_defer_js', array( $this, 'exclude_from_rocket' ) );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_from_rocket' ) );
		add_filter( 'litespeed_optimize_js_excludes', array( $this, 'exclude_from_litespeed' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'exclude_from_litespeed' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'exclude_from_autoptimize' ) );
		add_filter( 'sgo_javascript_combine_exclude_ids', array( $this, 'exclude_id_from_siteground' ) );
		add_filter( 'sgo_javascript_combine_excluded_external_paths', array( $this, 'exclude_from_siteground' ) );
		add_filter( 'sgo_js_async_exclude', array( $this, 'exclude_handle_from_siteground' ) );
		add_filter( 'wphb_delay_js_exclusions', array( $this, 'exclude_widget_url' ) );
		add_filter( 'wphb_dont_combine_handles', array( $this, 'exclude_handle_from_hummingbird' ), 10, 2 );
		add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'exclude_widget_url' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( $this, 'exclude_widget_url' ) );
		add_filter( 'perfmatters_minify_js_exclusions', array( $this, 'exclude_widget_url' ) );
		add_filter( 'flying_press_exclude_from_minify:js', array( $this, 'exclude_widget_url' ) );
	}

	/**
	 * Whether a value is a well-formed site key. Markup, spaces and quotes
	 * never match.
	 *
	 * @since   0.3.0
	 * @param   mixed $key  Candidate key.
	 * @return  bool
	 */
	public static function is_valid_site_key( $key ) {
		return is_string( $key ) && 1 === preg_match( self::SITE_KEY_PATTERN, $key );
	}

	/**
	 * Whether the current request is a page a visitor sees. Admin screens,
	 * AJAX, the login page, feeds, REST and JSON responses, embeds and
	 * previews (block editor, Customizer) are not.
	 *
	 * @since   0.3.0
	 * @return  bool
	 */
	public function is_visitor_page() {
		if ( is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( wp_is_json_request() ) {
			return false;
		}
		$page = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		return 'wp-login.php' !== $page;
	}

	/**
	 * Enqueue the widget script in the footer with the defer strategy.
	 *
	 * @since   0.3.0
	 * @return  void
	 */
	public function enqueue_widget() {
		if ( ! SAMOMIND()->helpers->widget_is_active() || ! $this->is_visitor_page() ) {
			return;
		}
		// No version: the loader has its own URL and cache rules, and "?ver=" would show the WordPress version.
		wp_enqueue_script(
			self::HANDLE,
			self::WIDGET_URL,
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- see above.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Add the site key and API address the loader reads from its own script
	 * tag, plus markers that optimisation plugins and Cloudflare honour.
	 *
	 * @since   0.3.0
	 * @param   array $attributes  Script tag attributes.
	 * @return  array
	 */
	public function add_widget_attributes( $attributes ) {
		if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || self::HANDLE . '-js' !== $attributes['id'] ) {
			return $attributes;
		}
		$helpers = SAMOMIND()->helpers;
		if ( ! $helpers->widget_is_active() ) {
			return $attributes;
		}
		$attributes['data-site-key']      = $helpers->get_site_key();
		$attributes['data-base-url']      = $helpers->get_api_base_url();
		$attributes['data-no-optimize']   = '1'; // LiteSpeed Cache, SiteGround Optimizer.
		$attributes['data-noptimize']     = '1'; // Autoptimize.
		$attributes['data-cfasync']       = 'false'; // Cloudflare Rocket Loader.
		$attributes['data-jetpack-boost'] = 'ignore'; // Jetpack Boost.
		$attributes['nitro-exclude']      = true; // NitroPack, a bare attribute.
		return $attributes;
	}

	/**
	 * WP Rocket: exclusion patterns are matched against script URLs.
	 *
	 * @since   0.3.0
	 * @param   mixed $excluded  Patterns.
	 * @return  array
	 */
	public function exclude_from_rocket( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'widget.samomind.com/widget.js';
		return $excluded;
	}

	/**
	 * LiteSpeed Cache: a list of URL fragments.
	 *
	 * @since   0.3.0
	 * @param   mixed $excluded  Fragments.
	 * @return  array
	 */
	public function exclude_from_litespeed( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'widget.samomind.com';
		return $excluded;
	}

	/**
	 * Autoptimize: a comma separated string, or an array keyed by fragment.
	 *
	 * @since   0.3.0
	 * @param   mixed $excluded  Exclusions.
	 * @return  mixed
	 */
	public function exclude_from_autoptimize( $excluded ) {
		if ( is_array( $excluded ) ) {
			$excluded['widget.samomind.com'] = '';
			return $excluded;
		}
		$excluded = trim( (string) $excluded );
		return ( '' === $excluded ? '' : $excluded . ', ' ) . 'widget.samomind.com';
	}

	/**
	 * SiteGround Speed Optimizer, combine: external scripts are matched by a
	 * fragment of their URL. A combined file would copy the loader and drop
	 * our tag with its data attributes.
	 *
	 * @since   0.3.1
	 * @param   mixed $excluded  URL fragments.
	 * @return  array
	 */
	public function exclude_from_siteground( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'widget.samomind.com/widget.js';
		return $excluded;
	}

	/**
	 * SiteGround Speed Optimizer, combine: script tag ids that are left alone.
	 *
	 * @since   0.3.1
	 * @param   mixed $excluded  Tag ids.
	 * @return  array
	 */
	public function exclude_id_from_siteground( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = self::HANDLE . '-js';
		return $excluded;
	}

	/**
	 * SiteGround Speed Optimizer, defer: script handles it leaves alone.
	 *
	 * @since   0.3.1
	 * @param   mixed $excluded  Handles.
	 * @return  array
	 */
	public function exclude_handle_from_siteground( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = self::HANDLE;
		return $excluded;
	}

	/**
	 * Hummingbird, Perfmatters and FlyingPress: a list of URL fragments
	 * (Hummingbird also uses each entry as a pattern on the whole tag, where
	 * this text matches itself).
	 *
	 * @since   0.3.2
	 * @param   mixed $excluded  Fragments.
	 * @return  array
	 */
	public function exclude_widget_url( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'widget.samomind.com/widget.js';
		return $excluded;
	}

	/**
	 * Hummingbird, combine: the filter answers "do not combine this handle",
	 * so an earlier "yes" stays a yes.
	 *
	 * @since   0.3.2
	 * @param   mixed  $dont_combine  Answer so far.
	 * @param   string $handle        Script or style handle.
	 * @return  mixed
	 */
	public function exclude_handle_from_hummingbird( $dont_combine, $handle = '' ) {
		return self::HANDLE === $handle ? true : $dont_combine;
	}

	/**
	 * One time: a site that pasted the old embed snippet keeps its widget.
	 * The site key is read from the snippet, the setting is turned on, and
	 * the snippet option is deleted. A snippet without a usable key is just
	 * deleted.
	 *
	 * @since   0.3.0
	 * @return  void
	 */
	public function migrate_pasted_snippet() {
		$snippet = get_option( 'samomind_embed_snippet', false );
		if ( false === $snippet ) {
			return;
		}
		$key = '';
		if ( preg_match( '/data-site-key\s*=\s*["\']?([A-Za-z0-9_-]+)/', (string) $snippet, $match ) && self::is_valid_site_key( $match[1] ) ) {
			$key = $match[1];
		}
		if ( '' !== $key && '' === SAMOMIND()->helpers->get_site_key() ) {
			update_option( 'samomind_site_key', $key );
			update_option( 'samomind_widget_enabled', '1' );
		}
		delete_option( 'samomind_embed_snippet' );
	}
}
