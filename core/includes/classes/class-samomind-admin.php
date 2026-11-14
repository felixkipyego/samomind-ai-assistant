<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Admin
 *
 * The admin screens: settings (API base URL, key id, secret, widget
 * snippet) and status (connection, sync progress, request log, actions).
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Admin
 * @since       0.1.0
 */
class SAMOMIND_Admin {

	/**
	 * Admin page slug
	 *
	 * @var string
	 */
	const MENU_SLUG = 'samomind-assistant';

	/**
	 * Status submenu slug
	 *
	 * @var string
	 */
	const STATUS_SLUG = 'samomind-status';

	/**
	 * Settings submenu slug
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'samomind-settings';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'samomind_settings_group';

	/**
	 * Transient prefix for the one-time notice after an action.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'samomind_notice_';

	/**
	 * Actions posted to admin-post.php, each with its own nonce.
	 *
	 * @var string[]
	 */
	const ACTIONS = array( 'connect', 'disconnect', 'sync_now', 'checkin', 'reconcile', 'clear_log', 'replace_secret', 'save_orders' );

	/**
	 * Our SAMOMIND_Admin constructor to run the plugin logic.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * Register all admin hooks.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	private function add_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_health_notice' ) );

		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_samomind_' . $action, array( $this, 'handle_' . $action ) );
		}

		// New credentials may lift a block such as key_revoked.
		add_action( 'update_option_samomind_key_id', array( $this, 'on_credentials_changed' ) );
		add_action( 'update_option_samomind_api_base_url', array( $this, 'on_credentials_changed' ) );
	}

	/**
	 * Register the admin menu and submenus.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Samomind', 'samomind-ai-assistant' ),
			__( 'Samomind', 'samomind-ai-assistant' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_status_page' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Samomind Status', 'samomind-ai-assistant' ),
			__( 'Status', 'samomind-ai-assistant' ),
			'manage_options',
			self::STATUS_SLUG,
			array( $this, 'render_status_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Samomind Settings', 'samomind-ai-assistant' ),
			__( 'Settings', 'samomind-ai-assistant' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		// Remove the duplicate top-level entry that WordPress auto-creates.
		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
	}

	/**
	 * Register plugin settings with the WordPress Settings API. The secret
	 * is not one of them: it has its own form (handle_replace_secret).
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			'samomind_api_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_base_url' ),
				'default'           => SAMOMIND_Helpers::DEFAULT_API_BASE_URL,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'samomind_key_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_key_id' ),
				'default'           => '',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'samomind_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => array(),
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'samomind_widget_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_widget_enabled' ),
				'default'           => '0',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'samomind_site_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_site_key' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Make sure an option row exists with autoload off before the Settings
	 * API saves it. update_option() keeps the autoload flag of an existing
	 * row but creates a missing one autoloaded, which would load the
	 * connection details on every request.
	 *
	 * @since   0.3.4
	 * @param   string $option  Option name.
	 * @return  void
	 */
	private function keep_out_of_autoload( $option ) {
		if ( false === get_option( $option, false ) ) {
			add_option( $option, '', '', false );
		}
	}

	/**
	 * Sanitize the API base URL: https, or http for a local development
	 * host; no query or fragment; no trailing slash.
	 *
	 * @since   0.1.0
	 * @param   mixed $value  Submitted value.
	 * @return  string
	 */
	public function sanitize_api_base_url( $value ) {
		$this->keep_out_of_autoload( 'samomind_api_base_url' );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return SAMOMIND_Helpers::DEFAULT_API_BASE_URL;
		}

		$parts = wp_parse_url( $value );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$https = isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] );
		$local = isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] )
			&& ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || preg_match( '/\.(local|test)$/', $host ) );

		if ( '' === $host || ( ! $https && ! $local ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			add_settings_error( self::SETTINGS_GROUP, 'samomind_api_base_url', esc_html__( 'The API base URL must be an https address (http is accepted only for localhost, .local and .test hosts). The previous value was kept.', 'samomind-ai-assistant' ) );
			return SAMOMIND()->helpers->get_api_base_url();
		}

		$url = $parts['scheme'] . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );
		return untrailingslashit( esc_url_raw( $url, array( 'https', 'http' ) ) );
	}

	/**
	 * Sanitize the key id: a UUID, or empty.
	 *
	 * @since   0.1.0
	 * @param   mixed $value  Submitted value.
	 * @return  string
	 */
	public function sanitize_key_id( $value ) {
		$this->keep_out_of_autoload( 'samomind_key_id' );
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		if ( '' === $value || preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
			return $value;
		}
		add_settings_error( self::SETTINGS_GROUP, 'samomind_key_id', esc_html__( 'The key id is a UUID such as 3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10. The previous value was kept.', 'samomind-ai-assistant' ) );
		return SAMOMIND()->helpers->get_key_id();
	}

	/**
	 * Turn the post type checklist into the stored list of ticked types. The
	 * form posts every type it offered (offered[]) and the ticked ones
	 * (included[]); selections for types not on the form are kept.
	 *
	 * @since   0.1.0
	 * @param   mixed $value  Submitted value.
	 * @return  string[]
	 */
	public function sanitize_post_types( $value ) {
		$previous = SAMOMIND()->content->get_selected_post_types();
		if ( ! is_array( $value ) ) {
			return $previous;
		}
		if ( ! isset( $value['offered'] ) && ! isset( $value['included'] ) ) {
			// A plain list, as stored.
			return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
		}
		if ( ! isset( $value['offered'] ) ) {
			return $previous;
		}

		$available = SAMOMIND()->content->get_available_post_types();
		$offered   = array_intersect( array_map( 'sanitize_key', (array) $value['offered'] ), $available );
		$included  = isset( $value['included'] ) ? array_map( 'sanitize_key', (array) $value['included'] ) : array();

		return array_values( array_unique( array_merge( array_diff( $previous, $offered ), array_intersect( $offered, $included ) ) ) );
	}

	/**
	 * Sanitize the widget switch: "1" when ticked, otherwise "0".
	 *
	 * @since   0.3.0
	 * @param   mixed $value  Submitted value.
	 * @return  string
	 */
	public function sanitize_widget_enabled( $value ) {
		return in_array( $value, array( '1', 1, true, 'on' ), true ) ? '1' : '0';
	}

	/**
	 * Sanitize the site key. The value must match the key format as typed;
	 * it is never stripped of markup first, because that would turn
	 * "<script>pk_live_...</script>" into something that passes.
	 *
	 * @since   0.3.0
	 * @param   mixed $value  Submitted value.
	 * @return  string
	 */
	public function sanitize_site_key( $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || SAMOMIND_Embed::is_valid_site_key( $value ) ) {
			return $value;
		}
		add_settings_error( self::SETTINGS_GROUP, 'samomind_site_key', esc_html__( 'The site key looks like pk_live_ followed by letters and numbers. Copy it from the Widget screen of the Samomind dashboard, without a script tag. The previous key was kept.', 'samomind-ai-assistant' ) );
		return SAMOMIND()->helpers->get_site_key();
	}

	/**
	 * Lift a block when the key id or base URL changes.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function on_credentials_changed() {
		SAMOMIND()->connection->clear_block();
	}

	/**
	 * ######################
	 * ###
	 * #### ACTIONS
	 * ###
	 * ######################
	 */

	/**
	 * Capability and nonce check for an admin-post action.
	 *
	 * @since   0.1.0
	 * @param   string $action  Action name without the prefix.
	 * @return  void
	 */
	private function verify_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'samomind-ai-assistant' ) );
		}
		check_admin_referer( 'samomind_' . $action );
	}

	/**
	 * Store a notice for the next page load and go back to a page.
	 *
	 * @since   0.1.0
	 * @param   string $page     Page slug.
	 * @param   string $type     success, warning or error.
	 * @param   string $message  Plain text.
	 * @return  void
	 */
	private function finish( $page, $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'page', $page, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A refusal shown verbatim, with its code.
	 *
	 * @since   0.1.0
	 * @param   string   $prefix  What failed.
	 * @param   WP_Error $error   Error.
	 * @return  string
	 */
	private static function error_text( $prefix, $error ) {
		return $prefix . ' ' . $error->get_error_message() . ' (' . $error->get_error_code() . ')';
	}

	/**
	 * Connect this site.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_connect() {
		$this->verify_action( 'connect' );

		if ( ! SAMOMIND()->helpers->has_credentials() ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'Enter the key id and secret from the Samomind dashboard first.', 'samomind-ai-assistant' ) );
		}

		$result = SAMOMIND()->connection->connect();
		if ( is_wp_error( $result ) ) {
			$this->finish( self::STATUS_SLUG, 'error', self::error_text( __( 'Connect failed:', 'samomind-ai-assistant' ), $result ) );
		}
		$this->finish( self::STATUS_SLUG, 'success', __( 'Connected. The first sync has started in the background.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Disconnect this site. Samomind removes its documents.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_disconnect() {
		$this->verify_action( 'disconnect' );

		$result = SAMOMIND()->connection->disconnect( 'admin' );
		if ( is_wp_error( $result ) ) {
			$this->finish( self::STATUS_SLUG, 'error', self::error_text( __( 'Disconnect failed:', 'samomind-ai-assistant' ), $result ) );
		}
		$this->finish( self::STATUS_SLUG, 'success', __( 'Disconnected. Samomind removes this site\'s documents. Your settings are kept.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Start a full sync.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_sync_now() {
		$this->verify_action( 'sync_now' );

		if ( ! SAMOMIND()->connection->is_connected() ) {
			$this->finish( self::STATUS_SLUG, 'error', __( 'Connect the site first.', 'samomind-ai-assistant' ) );
		}
		SAMOMIND()->sync->start_full_sync();
		$this->finish( self::STATUS_SLUG, 'success', __( 'Full sync started in the background.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Check in now.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_checkin() {
		$this->verify_action( 'checkin' );

		$result = SAMOMIND()->connection->checkin( true );
		if ( is_wp_error( $result ) ) {
			$this->finish( self::STATUS_SLUG, 'error', self::error_text( __( 'Check-in failed:', 'samomind-ai-assistant' ), $result ) );
		}
		$this->finish( self::STATUS_SLUG, 'success', __( 'Checked in.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Start a reconcile.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_reconcile() {
		$this->verify_action( 'reconcile' );

		if ( ! SAMOMIND()->connection->is_connected() ) {
			$this->finish( self::STATUS_SLUG, 'error', __( 'Connect the site first.', 'samomind-ai-assistant' ) );
		}
		SAMOMIND()->sync->request_reconcile( 'manual-' . wp_generate_uuid4() );
		$this->finish( self::STATUS_SLUG, 'success', __( 'Reconcile started in the background.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Clear the request log.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_clear_log() {
		$this->verify_action( 'clear_log' );

		SAMOMIND()->log->clear();
		$this->finish( self::STATUS_SLUG, 'success', __( 'Request log cleared.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Save a new secret. The value is never shown again.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function handle_replace_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'samomind-ai-assistant' ) );
		}
		check_admin_referer( 'samomind_replace_secret' );

		if ( SAMOMIND()->helpers->secret_is_constant() ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'The secret is set in wp-config.php and cannot be changed here.', 'samomind-ai-assistant' ) );
		}

		$secret = isset( $_POST['samomind_api_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['samomind_api_secret'] ) ) ) : '';
		if ( '' === $secret ) {
			$this->finish( self::SETTINGS_SLUG, 'warning', __( 'No secret entered; nothing changed.', 'samomind-ai-assistant' ) );
		}
		if ( ! preg_match( '/^smik_[A-Za-z0-9_-]+$/', $secret ) ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'That does not look like a Samomind ingest secret (it starts with smik_). Nothing changed.', 'samomind-ai-assistant' ) );
		}

		update_option( 'samomind_api_secret', $secret, false );
		SAMOMIND()->connection->clear_block();
		$this->finish( self::SETTINGS_SLUG, 'success', __( 'Secret saved. It will not be shown again.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Save the order status settings: the opt-in, the accepted terms, the
	 * orders key and the two sharing choices. The key is never shown again.
	 * Opting in needs the terms ticked; a change is reported to Samomind at
	 * once when the site is connected.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public function handle_save_orders() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'samomind-ai-assistant' ) );
		}
		check_admin_referer( 'samomind_save_orders' );

		$orders = SAMOMIND()->orders;
		if ( is_multisite() ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'Order status is not available on multisite networks.', 'samomind-ai-assistant' ) );
		}

		if ( $orders->woocommerce_active() && $orders->plain_permalinks() ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'Order status needs pretty permalinks. Go to Settings > Permalinks, pick any option other than Plain, save, and try again.', 'samomind-ai-assistant' ) );
		}

		$notes = array();

		$key = isset( $_POST['samomind_orders_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['samomind_orders_secret'] ) ) ) : '';
		if ( ! empty( $_POST['samomind_orders_remove_key'] ) ) {
			delete_option( SAMOMIND_Orders_Settings::SECRET );
			$notes[] = __( 'The orders key was removed.', 'samomind-ai-assistant' );
		} elseif ( '' !== $key ) {
			if ( 1 === preg_match( '/^smok_[A-Za-z0-9_-]+$/', $key ) ) {
				update_option( SAMOMIND_Orders_Settings::SECRET, $key, false );
			} else {
				$notes[] = __( 'That does not look like an orders key (it starts with smok_). The key was not changed.', 'samomind-ai-assistant' );
			}
		}

		if ( ! empty( $_POST['samomind_orders_terms'] ) && ! $orders->terms_are_accepted() ) {
			$orders->accept_terms( get_current_user_id() );
		}

		$wants_on = ! empty( $_POST['samomind_orders_enabled'] );
		if ( $wants_on && ! $orders->terms_are_accepted() ) {
			$wants_on = false;
			$notes[]  = __( 'Order status stays off until you accept the order-data terms.', 'samomind-ai-assistant' );
		}
		update_option( SAMOMIND_Orders_Settings::OPT_IN, $wants_on ? '1' : '0', false );
		update_option( SAMOMIND_Orders_Settings::SHARE_TRACKING, ! empty( $_POST['samomind_orders_share_tracking'] ) ? '1' : '0', false );
		update_option( SAMOMIND_Orders_Settings::SHARE_ITEMS, ! empty( $_POST['samomind_orders_share_items'] ) ? '1' : '0', false );

		if ( SAMOMIND()->connection->is_connected() ) {
			SAMOMIND()->connection->checkin( true, false );
		}

		$reason = $orders->disabled_reason();
		if ( null === $reason ) {
			array_unshift( $notes, __( 'Order status settings saved. Customers can now check their order in the chat.', 'samomind-ai-assistant' ) );
		} else {
			array_unshift( $notes, __( 'Order status settings saved. Order status is not switched on yet.', 'samomind-ai-assistant' ) );
		}
		$this->finish( self::SETTINGS_SLUG, null === $reason ? 'success' : 'warning', implode( ' ', $notes ) );
	}

	/**
	 * AJAX: sync progress for the status page.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function ajax_sync_progress() {
		check_ajax_referer( 'samomind_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'samomind-ai-assistant' ) ), 403 );
		}

		$progress = SAMOMIND()->sync->get_progress();
		wp_send_json_success(
			array(
				'state'     => $progress['state'],
				'label'     => $this->sync_state_label( $progress['state'] ),
				'percent'   => $progress['percent'],
				'sent'      => $progress['sent'],
				'total'     => $progress['total'],
				'message'   => $progress['message'],
				'pending'   => $progress['pending'],
				'reconcile' => $this->sync_state_label( $progress['reconcile']['state'] ),
			)
		);
	}

	/**
	 * ######################
	 * ###
	 * #### PAGES
	 * ###
	 * ######################
	 */

	/**
	 * Show the one-time notice stored by finish().
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	private function render_action_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info' ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Tell admins that WooCommerce products are not being synced. It only
	 * says so: the selection is never changed for them.
	 *
	 * @since   0.3.6
	 * @return  void
	 */
	private function render_shop_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! SAMOMIND()->content->shop_products_not_synced() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'WooCommerce is active, but products are not synced. Tick Products under Samomind > Settings > What is synced.', 'samomind-ai-assistant' )
		);
	}

	/**
	 * Tell admins when sync is blocked, or failing for over 24 hours
	 * (contract 5.7).
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function render_health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$connection = SAMOMIND()->connection;
		if ( ! $connection->is_connected() && ! $connection->is_blocked() ) {
			return;
		}

		$state = $connection->get_state();
		$block = $connection->get_block();

		if ( ! $connection->is_connected() ) {
			// Disconnected on the Samomind side; only an admin reconnects.
			$class   = 'notice-warning';
			$message = sprintf(
				/* translators: %s: reason, for example "Disconnected from the Samomind dashboard." */
				__( 'Samomind: %s Nothing is sent until you click Connect.', 'samomind-ai-assistant' ),
				$block['message']
			);
		} elseif ( $connection->is_blocked() ) {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: 1: API message, 2: error code */
				__( 'Samomind sync is paused: %1$s (%2$s)', 'samomind-ai-assistant' ),
				$block['message'],
				$block['code']
			);
		} elseif ( $connection->is_failing_too_long() ) {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: %s: last error */
				__( 'Samomind sync has been failing for more than 24 hours. Last error: %s', 'samomind-ai-assistant' ),
				$state['last_error']
			);
		} else {
			return;
		}

		printf(
			'<div class="notice %1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $class ),
			esc_html( $message ),
			esc_url( add_query_arg( 'page', self::STATUS_SLUG, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Open Samomind status', 'samomind-ai-assistant' )
		);
	}

	/**
	 * A form with one button posting an action to admin-post.php.
	 *
	 * @since   0.1.0
	 * @param   string $action   Action name without the prefix.
	 * @param   string $label    Button label.
	 * @param   string $button_class  Button classes.
	 * @param   string $confirm  Confirmation text, empty for none.
	 * @return  void
	 */
	private function render_action_button( $action, $label, $button_class = 'button', $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="samomind-inline-form">
			<?php wp_nonce_field( 'samomind_' . $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'samomind_' . $action ); ?>">
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"<?php echo '' !== $confirm ? ' data-samomind-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Label for a sync state.
	 *
	 * @since   0.1.0
	 * @param   string $state  idle, running, complete or stopped.
	 * @return  string
	 */
	private function sync_state_label( $state ) {
		$labels = array(
			'idle'     => __( 'Not started', 'samomind-ai-assistant' ),
			'running'  => __( 'Running', 'samomind-ai-assistant' ),
			'complete' => __( 'Complete', 'samomind-ai-assistant' ),
			'stopped'  => __( 'Stopped', 'samomind-ai-assistant' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * The posts whose text may be incomplete, with the reason for each.
	 *
	 * @since   0.2.0
	 * @return  void
	 */
	private function render_incomplete_card() {
		$adapters = array();
		foreach ( SAMOMIND()->builders->get_adapters() as $adapter ) {
			$adapters[ $adapter->get_slug() ] = $adapter->get_label();
		}

		$rows = array();
		foreach ( SAMOMIND()->builders->get_incomplete() as $post_id => $entry ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$rows[ (int) $post_id ] = array( $post, $entry );
		}
		?>
		<div class="samomind-card" id="samomind-incomplete">
			<h2><?php esc_html_e( 'Pages that may be incomplete', 'samomind-ai-assistant' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'None. The text of every page the plugin has read looked complete.', 'samomind-ai-assistant' ); ?></p>
			<?php else : ?>
				<p>
					<strong class="samomind-incomplete-count"><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></strong>
					<?php esc_html_e( 'pages were sent with text that may be missing something, because the plugin could not read their page builder layout fully. Open the page to check what the assistant can say about it. A developer can supply the text with the SAMOMIND/content/text filter.', 'samomind-ai-assistant' ); ?>
				</p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'samomind-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'Built with', 'samomind-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'Why', 'samomind-ai-assistant' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $rows, 0, 50, true ) as $post_id => $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $row[0] ) ); ?></a>
									<code>#<?php echo esc_html( (string) $post_id ); ?></code>
								</td>
								<td><?php echo esc_html( isset( $adapters[ $row[1]['builder'] ] ) ? $adapters[ $row[1]['builder'] ] : $row[1]['builder'] ); ?></td>
								<td><?php echo esc_html( implode( '; ', array_map( array( 'SAMOMIND_Builders', 'reason_label' ), $row[1]['reasons'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Status admin page.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// A lightweight check-in (at most once a minute) so a disconnect made
		// in the Samomind dashboard shows now, not at the next background call.
		SAMOMIND()->connection->refresh_status();

		$helpers    = SAMOMIND()->helpers;
		$connection = SAMOMIND()->connection;
		$state      = $connection->get_state();
		$progress   = SAMOMIND()->sync->get_progress();
		$connected  = $connection->is_connected();
		$rows       = SAMOMIND()->log->get_rows();
		?>
		<div class="wrap samomind-admin-wrap">
			<h1><?php esc_html_e( 'Samomind Status', 'samomind-ai-assistant' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_action_notice(); ?>
			<?php $this->render_shop_notice(); ?>

			<div class="samomind-card">
				<h2><?php esc_html_e( 'Connection', 'samomind-ai-assistant' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'samomind-ai-assistant' ); ?></th>
						<td>
							<?php if ( $connected && $connection->is_blocked() ) : ?>
								<span class="samomind-badge samomind-badge--error"><?php esc_html_e( 'Connected, paused', 'samomind-ai-assistant' ); ?></span>
							<?php elseif ( $connected ) : ?>
								<span class="samomind-badge samomind-badge--ok"><?php esc_html_e( 'Connected', 'samomind-ai-assistant' ); ?></span>
							<?php elseif ( $connection->is_blocked() ) : ?>
								<span class="samomind-badge samomind-badge--error"><?php echo esc_html( $connection->get_block()['message'] ); ?></span>
							<?php else : ?>
								<span class="samomind-badge"><?php esc_html_e( 'Not connected', 'samomind-ai-assistant' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site address', 'samomind-ai-assistant' ); ?></th>
						<td><code><?php echo esc_html( $helpers->get_site_url() ); ?></code></td>
					</tr>
					<?php if ( $connected ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Source id', 'samomind-ai-assistant' ); ?></th>
							<td><code><?php echo esc_html( $state['source_id'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connected since', 'samomind-ai-assistant' ); ?></th>
							<td><?php echo esc_html( $helpers->format_time( $state['connected_at'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last check-in', 'samomind-ai-assistant' ); ?></th>
							<td>
								<?php echo esc_html( $helpers->format_time( $state['last_checkin_at'] ) ); ?>
								<?php if ( '' !== $state['server_status'] ) : ?>
									<?php
									/* translators: %s: status reported by Samomind */
									echo esc_html( sprintf( __( '(Samomind reports: %s)', 'samomind-ai-assistant' ), $state['server_status'] ) );
									?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $connection->is_blocked() ) : ?>
						<?php $block = $connection->get_block(); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Paused because', 'samomind-ai-assistant' ); ?></th>
							<td><?php echo esc_html( $block['message'] . ' (' . $block['code'] . ')' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $state['last_error'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last error', 'samomind-ai-assistant' ); ?></th>
							<td><?php echo esc_html( $state['last_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<p class="samomind-actions">
					<?php
					if ( $connected ) {
						$this->render_action_button( 'sync_now', __( 'Sync now', 'samomind-ai-assistant' ), 'button button-primary' );
						$this->render_action_button( 'checkin', __( 'Check in now', 'samomind-ai-assistant' ) );
						$this->render_action_button( 'reconcile', __( 'Reconcile now', 'samomind-ai-assistant' ) );
						$this->render_action_button( 'connect', __( 'Reconnect', 'samomind-ai-assistant' ) );
						$this->render_action_button( 'disconnect', __( 'Disconnect', 'samomind-ai-assistant' ), 'button samomind-button-danger', __( 'Disconnect this site? Samomind removes every document it received from this site. Your settings stay.', 'samomind-ai-assistant' ) );
					} else {
						$this->render_action_button( 'connect', __( 'Connect', 'samomind-ai-assistant' ), 'button button-primary' );
					}
					?>
				</p>
			</div>

			<div class="samomind-card">
				<h2><?php esc_html_e( 'Sync', 'samomind-ai-assistant' ); ?></h2>
				<table class="form-table" role="presentation" id="samomind-sync-progress" data-state="<?php echo esc_attr( $progress['state'] ); ?>">
					<tr>
						<th scope="row"><?php esc_html_e( 'Full sync', 'samomind-ai-assistant' ); ?></th>
						<td>
							<strong class="samomind-sync-label"><?php echo esc_html( $this->sync_state_label( $progress['state'] ) ); ?></strong>
							<progress class="samomind-progress" max="100" value="<?php echo esc_attr( $progress['percent'] ); ?>"></progress>
							<span class="samomind-sync-count">
								<?php
								/* translators: 1: documents sent, 2: published items */
								echo esc_html( sprintf( __( 'last full sync: %1$d sent of about %2$d published items', 'samomind-ai-assistant' ), $progress['sent'], $progress['total'] ) );
								?>
							</span>
							<p class="description samomind-sync-message"><?php echo esc_html( $progress['message'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Included on this site', 'samomind-ai-assistant' ); ?></th>
						<td>
							<strong class="samomind-included-count"><?php echo esc_html( number_format_i18n( SAMOMIND()->content->count_included() ) ); ?></strong>
							<p class="description"><?php esc_html_e( 'Published items of the post types ticked under Settings, after filters. This is what the plugin keeps in step with Samomind; the figures below are about the last full sync.', 'samomind-ai-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last full sync', 'samomind-ai-assistant' ); ?></th>
						<td><?php echo esc_html( $helpers->format_time( $progress['finished_at'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Documents', 'samomind-ai-assistant' ); ?></th>
						<td>
							<?php
							/* translators: 1: queued, 2: unchanged, 3: refused */
							echo esc_html( sprintf( __( 'Last full sync: %1$d accepted, %2$d unchanged, %3$d refused.', 'samomind-ai-assistant' ), $progress['accepted'], $progress['unchanged'], $progress['refused'] ) );
							?>
							<p class="description"><?php esc_html_e( 'The Samomind API does not report a page count to the plugin; the dashboard shows the indexed count for this site.', 'samomind-ai-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reconcile', 'samomind-ai-assistant' ); ?></th>
						<td>
							<span class="samomind-reconcile-label"><?php echo esc_html( $this->sync_state_label( $progress['reconcile']['state'] ) ); ?></span>
							<?php
							if ( $progress['reconcile']['finished_at'] > 0 ) {
								/* translators: 1: time, 2: missing, 3: stale, 4: deleted */
								echo esc_html( sprintf( __( '(last %1$s: %2$d missing, %3$d stale, %4$d removed by Samomind)', 'samomind-ai-assistant' ), $helpers->format_time( $progress['reconcile']['finished_at'] ), $progress['reconcile']['missing'], $progress['reconcile']['stale'], $progress['reconcile']['deleted'] ) );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Waiting to send', 'samomind-ai-assistant' ); ?></th>
						<td>
							<span class="samomind-pending"><?php echo esc_html( number_format_i18n( $progress['pending'] ) ); ?></span>
							<?php if ( $progress['retry_at'] > time() ) : ?>
								<?php
								/* translators: 1: attempt number, 2: time */
								echo esc_html( sprintf( __( '(attempt %1$d failed, next try %2$s)', 'samomind-ai-assistant' ), $progress['retry_attempts'], $helpers->format_time( $progress['retry_at'] ) ) );
								?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $progress['notice'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Note', 'samomind-ai-assistant' ); ?></th>
							<td><?php echo esc_html( $progress['notice'] ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<?php $this->render_incomplete_card(); ?>

			<div class="samomind-card">
				<h2><?php esc_html_e( 'Request log', 'samomind-ai-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The last 200 requests to Samomind: time, endpoint, HTTP status and error code. Request bodies and credentials are never logged.', 'samomind-ai-assistant' ); ?></p>
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'No requests yet.', 'samomind-ai-assistant' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'samomind-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'Endpoint', 'samomind-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'HTTP status', 'samomind-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'Code', 'samomind-ai-assistant' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $helpers->format_time( $row['time'] ) ); ?></td>
									<td><code><?php echo esc_html( $row['endpoint'] ); ?></code></td>
									<td><?php echo esc_html( 0 === (int) $row['status'] ? __( 'network', 'samomind-ai-assistant' ) : (string) $row['status'] ); ?></td>
									<td><?php echo esc_html( $row['code'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><?php $this->render_action_button( 'clear_log', __( 'Clear log', 'samomind-ai-assistant' ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Settings admin page.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$helpers = SAMOMIND()->helpers;
		?>
		<div class="wrap samomind-admin-wrap">
			<h1><?php esc_html_e( 'Samomind Settings', 'samomind-ai-assistant' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'In the Samomind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret here. The secret is shown only once there.', 'samomind-ai-assistant' ); ?>
			</p>
			<hr class="wp-header-end">

			<?php $this->render_action_notice(); ?>
			<?php $this->render_shop_notice(); ?>
			<?php settings_errors( self::SETTINGS_GROUP ); ?>

			<div class="samomind-card">
				<h2><?php esc_html_e( 'Ingest secret', 'samomind-ai-assistant' ); ?></h2>
				<?php if ( $helpers->secret_is_constant() ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="samomind_api_secret"><?php esc_html_e( 'Secret', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<input type="password" id="samomind_api_secret" class="regular-text" value="************" readonly disabled>
								<p class="description"><?php esc_html_e( 'Set by the SAMOMIND_API_SECRET constant in wp-config.php, which takes priority. Change it there.', 'samomind-ai-assistant' ); ?></p>
							</td>
						</tr>
					</table>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
						<?php wp_nonce_field( 'samomind_replace_secret' ); ?>
						<input type="hidden" name="action" value="samomind_replace_secret">
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="samomind_api_secret"><?php esc_html_e( 'Secret', 'samomind-ai-assistant' ); ?></label></th>
								<td>
									<p>
										<?php if ( $helpers->has_secret() ) : ?>
											<span class="samomind-badge samomind-badge--ok"><?php esc_html_e( 'A secret is saved', 'samomind-ai-assistant' ); ?></span>
										<?php else : ?>
											<span class="samomind-badge"><?php esc_html_e( 'No secret saved', 'samomind-ai-assistant' ); ?></span>
										<?php endif; ?>
									</p>
									<input type="password" id="samomind_api_secret" name="samomind_api_secret" class="regular-text" value="" autocomplete="new-password" spellcheck="false" placeholder="smik_...">
									<p class="description"><?php esc_html_e( 'The saved secret is never shown. Paste a new one to replace it. You can also define SAMOMIND_API_SECRET in wp-config.php instead.', 'samomind-ai-assistant' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( $helpers->has_secret() ? __( 'Replace secret', 'samomind-ai-assistant' ) : __( 'Save secret', 'samomind-ai-assistant' ), 'secondary' ); ?>
					</form>
				<?php endif; ?>
			</div>

			<div class="samomind-card">
				<form method="post" action="options.php">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>

					<h2><?php esc_html_e( 'Connection', 'samomind-ai-assistant' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="samomind_key_id"><?php esc_html_e( 'Key id', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<input type="text" id="samomind_key_id" name="samomind_key_id" class="regular-text code" spellcheck="false"
									value="<?php echo esc_attr( $helpers->get_key_id() ); ?>"
									placeholder="3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="samomind_api_base_url"><?php esc_html_e( 'API base URL', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<input type="url" id="samomind_api_base_url" name="samomind_api_base_url" class="regular-text code"
									value="<?php echo esc_attr( $helpers->get_api_base_url() ); ?>">
								<p class="description">
									<?php
									/* translators: %s: default API URL */
									echo esc_html( sprintf( __( 'Leave as %s unless Samomind asks you to use a staging address. The plugin sends requests only to this address.', 'samomind-ai-assistant' ), SAMOMIND_Helpers::DEFAULT_API_BASE_URL ) );
									?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'What is synced', 'samomind-ai-assistant' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Post types', 'samomind-ai-assistant' ); ?></th>
							<td>
								<fieldset id="samomind-post-types">
									<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'samomind-ai-assistant' ); ?></legend>
									<?php
									$selected = SAMOMIND()->content->get_selected_post_types();
									foreach ( SAMOMIND()->content->get_available_post_types() as $post_type ) :
										$type_object = get_post_type_object( $post_type );
										$type_label  = $type_object ? $type_object->labels->name : $post_type;
										?>
										<input type="hidden" name="samomind_post_types[offered][]" value="<?php echo esc_attr( $post_type ); ?>">
										<label>
											<input type="checkbox" name="samomind_post_types[included][]" value="<?php echo esc_attr( $post_type ); ?>"<?php checked( in_array( $post_type, $selected, true ) ); ?>>
											<?php echo esc_html( $type_label ); ?> <code><?php echo esc_html( $post_type ); ?></code>
										</label><br>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Only published, public, not password-protected items of the ticked types are sent.', 'samomind-ai-assistant' ); ?></p>
								<p class="description"><?php esc_html_e( 'We start with posts, pages and products. Tick other content types only if visitors ask about them.', 'samomind-ai-assistant' ); ?></p>
								<p class="description"><?php esc_html_e( 'Unticking a type removes its items from Samomind: they are queued for deletion and sent in the background. Ticking it again adds them back at the next daily reconcile, or click "Sync now" on the Status page.', 'samomind-ai-assistant' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Chat widget', 'samomind-ai-assistant' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Show the chat', 'samomind-ai-assistant' ); ?></th>
							<td>
								<input type="hidden" name="samomind_widget_enabled" value="0">
								<label>
									<input type="checkbox" id="samomind_widget_enabled" name="samomind_widget_enabled" value="1"<?php checked( $helpers->is_widget_enabled() ); ?>>
									<?php esc_html_e( 'Show the Samomind chat widget on this site', 'samomind-ai-assistant' ); ?>
								</label>
								<p class="description">
									<?php
									echo wp_kses(
										/* translators: %s: the site address, for example https://example.com */
										sprintf( __( 'Before you turn this on, add <code>%s</code> to <strong>Allowed origins</strong> in the Samomind dashboard. The chat will not load on this site until you do.', 'samomind-ai-assistant' ), esc_html( $helpers->get_site_origin() ) ),
										array(
											'code'   => array(),
											'strong' => array(),
										)
									);
									?>
								</p>
								<?php if ( $helpers->is_widget_enabled() && '' === $helpers->get_site_key() ) : ?>
									<p class="description"><strong><?php esc_html_e( 'The chat is not showing yet: save a site key below.', 'samomind-ai-assistant' ); ?></strong></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="samomind_site_key"><?php esc_html_e( 'Site key', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<input type="text" id="samomind_site_key" name="samomind_site_key" class="regular-text code" spellcheck="false" autocomplete="off"
									value="<?php echo esc_attr( $helpers->get_site_key() ); ?>" placeholder="pk_live_...">
								<p class="description"><?php esc_html_e( 'Copy the site key from the Widget screen of the Samomind dashboard. Only the key, not a script tag.', 'samomind-ai-assistant' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Settings', 'samomind-ai-assistant' ) ); ?>
				</form>
			</div>

			<?php $this->render_orders_card(); ?>
		</div>
		<?php
	}

	/**
	 * The order status card: opt-in, terms, orders key, sharing choices.
	 * The terms text is a draft until the legal review.
	 * The saved key is never printed.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	private function render_orders_card() {
		$orders = SAMOMIND()->orders;
		$terms  = $orders->get_terms();
		?>
		<div class="samomind-card" id="samomind-orders">
			<h2><?php esc_html_e( 'Order status in the chat', 'samomind-ai-assistant' ); ?></h2>
			<?php if ( is_multisite() ) : ?>
				<p class="description"><?php esc_html_e( 'Order status is not available on multisite networks.', 'samomind-ai-assistant' ); ?></p>
			<?php elseif ( ! $orders->woocommerce_active() ) : ?>
				<p class="description"><?php esc_html_e( 'Order status needs WooCommerce.', 'samomind-ai-assistant' ); ?></p>
			<?php elseif ( $orders->plain_permalinks() ) : ?>
				<p class="description">
					<?php esc_html_e( 'Order status needs pretty permalinks. Go to Settings > Permalinks, pick any option other than Plain, save, and come back here.', 'samomind-ai-assistant' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Open Permalinks', 'samomind-ai-assistant' ); ?></a>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Let customers ask the assistant where their order is. A customer proves it is theirs with a code emailed from this shop to the order\'s billing address, or by being logged in. Off by default: nothing about orders is sent to Samomind until you switch this on.', 'samomind-ai-assistant' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
					<?php wp_nonce_field( 'samomind_save_orders' ); ?>
					<input type="hidden" name="action" value="samomind_save_orders">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Order status', 'samomind-ai-assistant' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="samomind_orders_enabled" name="samomind_orders_enabled" value="1"<?php checked( $orders->is_opted_in() ); ?>>
									<?php esc_html_e( 'Let customers check order status in the chat', 'samomind-ai-assistant' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Order-data terms', 'samomind-ai-assistant' ); ?></th>
							<td>
								<p class="description"><strong><?php esc_html_e( 'DRAFT wording, pending legal review. It may change before release.', 'samomind-ai-assistant' ); ?></strong></p>
								<p class="description">
									<?php esc_html_e( 'You are responsible for your shop\'s order data and need a lawful basis to show it to the customer it belongs to. Tell your customers that their order status may be shown to them through an AI assistant. Samomind handles order data for you only to answer a customer\'s own question about their order, and does not store orders, email addresses or codes. Keep the orders key secret and replace it if you think it has leaked.', 'samomind-ai-assistant' ); ?>
									<a href="https://samomind.com/terms-and-conditions/" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of service', 'samomind-ai-assistant' ); ?></a>
								</p>
								<label>
									<input type="checkbox" id="samomind_orders_terms" name="samomind_orders_terms" value="1"<?php checked( $orders->terms_are_accepted() ); ?>>
									<?php
									/* translators: %s: version of the order-data terms, for example 2026-11 */
									echo esc_html( sprintf( __( 'I accept the order-data terms (version %s)', 'samomind-ai-assistant' ), SAMOMIND_Orders_Settings::TERMS_VERSION ) );
									?>
								</label>
								<?php if ( $orders->terms_are_accepted() ) : ?>
									<p class="description">
										<?php
										$accepted_by = ! empty( $terms['user_id'] ) ? get_userdata( (int) $terms['user_id'] ) : false;
										/* translators: 1: name of the user, 2: date and time */
										echo esc_html( sprintf( __( 'Accepted by %1$s on %2$s.', 'samomind-ai-assistant' ), $accepted_by ? $accepted_by->display_name : __( 'a former user', 'samomind-ai-assistant' ), SAMOMIND()->helpers->format_time( $terms['time'] ) ) );
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="samomind_orders_secret"><?php esc_html_e( 'Orders key', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<p>
									<?php if ( $orders->has_secret() ) : ?>
										<span class="samomind-badge samomind-badge--ok"><?php esc_html_e( 'A key is saved', 'samomind-ai-assistant' ); ?></span>
									<?php else : ?>
										<span class="samomind-badge"><?php esc_html_e( 'No key saved', 'samomind-ai-assistant' ); ?></span>
									<?php endif; ?>
								</p>
								<input type="password" id="samomind_orders_secret" name="samomind_orders_secret" class="regular-text" value="" autocomplete="new-password" spellcheck="false" placeholder="smok_...">
								<p class="description"><?php esc_html_e( 'A separate key from the ingest secret. Create it in the Samomind dashboard, next to the ingest key. The saved key is never shown; paste a new one to replace it.', 'samomind-ai-assistant' ); ?></p>
								<?php if ( $orders->has_secret() ) : ?>
									<label><input type="checkbox" name="samomind_orders_remove_key" value="1"> <?php esc_html_e( 'Remove the saved key (this switches order status off)', 'samomind-ai-assistant' ); ?></label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What the assistant may say', 'samomind-ai-assistant' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="samomind_orders_share_tracking" value="1"<?php checked( $orders->shares_tracking() ); ?>>
									<?php esc_html_e( 'Share tracking (carrier, number and link, and the shipped date)', 'samomind-ai-assistant' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="samomind_orders_share_items" value="1"<?php checked( $orders->shares_items() ); ?>>
									<?php esc_html_e( 'Share item names', 'samomind-ai-assistant' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'The status and the dates are always shared with the customer who verified the order. Addresses, phone numbers, emails, payment details and totals are never sent.', 'samomind-ai-assistant' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save order status settings', 'samomind-ai-assistant' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
