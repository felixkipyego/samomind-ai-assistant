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
	const ACTIONS = array( 'connect', 'disconnect', 'sync_now', 'checkin', 'reconcile', 'clear_log', 'replace_secret' );

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
			__( 'SamoMind', 'samomind-ai-assistant' ),
			__( 'SamoMind', 'samomind-ai-assistant' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_status_page' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'SamoMind Status', 'samomind-ai-assistant' ),
			__( 'Status', 'samomind-ai-assistant' ),
			'manage_options',
			self::STATUS_SLUG,
			array( $this, 'render_status_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'SamoMind Settings', 'samomind-ai-assistant' ),
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
			'samomind_embed_snippet',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_embed_snippet' ),
				'default'           => '',
			)
		);
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
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		if ( '' === $value || preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
			return $value;
		}
		add_settings_error( self::SETTINGS_GROUP, 'samomind_key_id', esc_html__( 'The key id is a UUID such as 3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10. The previous value was kept.', 'samomind-ai-assistant' ) );
		return SAMOMIND()->helpers->get_key_id();
	}

	/**
	 * Sanitize the widget snippet with SAMOMIND_Embed::parse().
	 *
	 * @since   0.1.0
	 * @param   mixed $value  Submitted value.
	 * @return  string
	 */
	public function sanitize_embed_snippet( $value ) {
		$value  = trim( (string) $value );
		$result = SAMOMIND_Embed::parse( $value );
		if ( is_wp_error( $result ) ) {
			// settings_errors() prints messages as HTML, and this one quotes "<script>".
			add_settings_error( self::SETTINGS_GROUP, 'samomind_embed_snippet', esc_html( $result->get_error_message() . ' ' . __( 'The previous snippet was kept.', 'samomind-ai-assistant' ) ) );
			return SAMOMIND()->helpers->get_embed_snippet();
		}
		return $value;
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
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'Enter the key id and secret from the SamoMind dashboard first.', 'samomind-ai-assistant' ) );
		}

		$result = SAMOMIND()->connection->connect();
		if ( is_wp_error( $result ) ) {
			$this->finish( self::STATUS_SLUG, 'error', self::error_text( __( 'Connect failed:', 'samomind-ai-assistant' ), $result ) );
		}
		$this->finish( self::STATUS_SLUG, 'success', __( 'Connected. The first sync has started in the background.', 'samomind-ai-assistant' ) );
	}

	/**
	 * Disconnect this site. SamoMind removes its documents.
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
		$this->finish( self::STATUS_SLUG, 'success', __( 'Disconnected. SamoMind removes this site\'s documents. Your settings are kept.', 'samomind-ai-assistant' ) );
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
		$this->verify_action( 'replace_secret' );

		if ( SAMOMIND()->helpers->secret_is_constant() ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'The secret is set in wp-config.php and cannot be changed here.', 'samomind-ai-assistant' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_action() checked the nonce above.
		$secret = isset( $_POST['samomind_api_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['samomind_api_secret'] ) ) ) : '';
		if ( '' === $secret ) {
			$this->finish( self::SETTINGS_SLUG, 'warning', __( 'No secret entered; nothing changed.', 'samomind-ai-assistant' ) );
		}
		if ( ! preg_match( '/^smik_[A-Za-z0-9_-]+$/', $secret ) ) {
			$this->finish( self::SETTINGS_SLUG, 'error', __( 'That does not look like a SamoMind ingest secret (it starts with smik_). Nothing changed.', 'samomind-ai-assistant' ) );
		}

		update_option( 'samomind_api_secret', $secret, false );
		SAMOMIND()->connection->clear_block();
		$this->finish( self::SETTINGS_SLUG, 'success', __( 'Secret saved. It will not be shown again.', 'samomind-ai-assistant' ) );
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
		$link  = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'page', self::STATUS_SLUG, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Open SamoMind status', 'samomind-ai-assistant' )
		);

		if ( ! $connection->is_connected() ) {
			// Disconnected on the SamoMind side; only an admin reconnects.
			$block = $connection->get_block();
			printf(
				'<div class="notice notice-warning"><p>%1$s %2$s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: reason, for example "Disconnected from the SamoMind dashboard." */
						__( 'SamoMind: %s Nothing is sent until you click Connect.', 'samomind-ai-assistant' ),
						$block['message']
					)
				),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			);
		} elseif ( $connection->is_blocked() ) {
			$block = $connection->get_block();
			printf(
				'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: API message, 2: error code */
						__( 'SamoMind sync is paused: %1$s (%2$s)', 'samomind-ai-assistant' ),
						$block['message'],
						$block['code']
					)
				),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			);
		} elseif ( $connection->is_failing_too_long() ) {
			printf(
				'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: last error */
						__( 'SamoMind sync has been failing for more than 24 hours. Last error: %s', 'samomind-ai-assistant' ),
						$state['last_error']
					)
				),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			);
		}
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
	 * Render the Status admin page.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$helpers    = SAMOMIND()->helpers;
		$connection = SAMOMIND()->connection;
		$state      = $connection->get_state();
		$progress   = SAMOMIND()->sync->get_progress();
		$connected  = $connection->is_connected();
		$rows       = SAMOMIND()->log->get_rows();
		?>
		<div class="wrap samomind-admin-wrap">
			<h1><?php esc_html_e( 'SamoMind Status', 'samomind-ai-assistant' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_action_notice(); ?>

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
									/* translators: %s: status reported by SamoMind */
									echo esc_html( sprintf( __( '(SamoMind reports: %s)', 'samomind-ai-assistant' ), $state['server_status'] ) );
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
						$this->render_action_button( 'disconnect', __( 'Disconnect', 'samomind-ai-assistant' ), 'button samomind-button-danger', __( 'Disconnect this site? SamoMind removes every document it received from this site. Your settings stay.', 'samomind-ai-assistant' ) );
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
								echo esc_html( sprintf( __( '%1$d sent of about %2$d published items', 'samomind-ai-assistant' ), $progress['sent'], $progress['total'] ) );
								?>
							</span>
							<p class="description samomind-sync-message"><?php echo esc_html( $progress['message'] ); ?></p>
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
							<p class="description"><?php esc_html_e( 'The SamoMind API does not report a page count to the plugin; the dashboard shows the indexed count for this site.', 'samomind-ai-assistant' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reconcile', 'samomind-ai-assistant' ); ?></th>
						<td>
							<span class="samomind-reconcile-label"><?php echo esc_html( $this->sync_state_label( $progress['reconcile']['state'] ) ); ?></span>
							<?php
							if ( $progress['reconcile']['finished_at'] > 0 ) {
								/* translators: 1: time, 2: missing, 3: stale, 4: deleted */
								echo esc_html( sprintf( __( '(last %1$s: %2$d missing, %3$d stale, %4$d removed by SamoMind)', 'samomind-ai-assistant' ), $helpers->format_time( $progress['reconcile']['finished_at'] ), $progress['reconcile']['missing'], $progress['reconcile']['stale'], $progress['reconcile']['deleted'] ) );
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

			<div class="samomind-card">
				<h2><?php esc_html_e( 'Request log', 'samomind-ai-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The last 200 requests to SamoMind: time, endpoint, HTTP status and error code. Request bodies and credentials are never logged.', 'samomind-ai-assistant' ); ?></p>
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
			<h1><?php esc_html_e( 'SamoMind Settings', 'samomind-ai-assistant' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'In the SamoMind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret here. The secret is shown only once there.', 'samomind-ai-assistant' ); ?>
			</p>
			<hr class="wp-header-end">

			<?php $this->render_action_notice(); ?>
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
									echo esc_html( sprintf( __( 'Leave as %s unless SamoMind asks you to use a staging address. The plugin sends requests only to this address.', 'samomind-ai-assistant' ), SAMOMIND_Helpers::DEFAULT_API_BASE_URL ) );
									?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Chat widget', 'samomind-ai-assistant' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="samomind_embed_snippet"><?php esc_html_e( 'Embed snippet', 'samomind-ai-assistant' ); ?></label></th>
							<td>
								<textarea id="samomind_embed_snippet" name="samomind_embed_snippet" class="large-text code" rows="6" spellcheck="false"><?php echo esc_textarea( $helpers->get_embed_snippet() ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Paste the snippet from the Widget screen of the SamoMind dashboard to show the chat on every page. Leave empty to turn the widget off. Only a script tag from widget.samomind.com is accepted.', 'samomind-ai-assistant' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Settings', 'samomind-ai-assistant' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
