<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Connection
 *
 * Connect, disconnect and check in (docs/API_CONTRACT.md 5.5), and the
 * connection state the status page shows.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Connection
 * @since       0.1.0
 */
class SAMOMIND_Connection {

	/**
	 * Option holding the connection state.
	 *
	 * @var string
	 */
	const OPTION = 'samomind_connection';

	/**
	 * Connector label sent on connect (contract 9).
	 *
	 * @var string
	 */
	const CONNECTOR = 'wordpress';

	/**
	 * Seconds of failing requests before the admin is told (contract 5.7).
	 *
	 * @var int
	 */
	const FAILING_NOTICE_AFTER = 86400;

	/**
	 * Seconds between check-ins made by loading the Status page.
	 *
	 * @var int
	 */
	const STATUS_CHECK_INTERVAL = 60;

	/**
	 * Limits used until connect returns the server's own (contract 6).
	 *
	 * @var array
	 */
	const DEFAULT_LIMITS = array(
		'max_documents_per_push' => 50,
		'max_body_bytes'         => 2097152,
		'max_text_chars'         => 200000,
		'max_delete_ids'         => 500,
		'max_reconcile_entries'  => 5000,
	);

	/**
	 * Get the connection state with defaults filled in.
	 *
	 * @since   0.1.0
	 * @return  array
	 */
	public function get_state() {
		$state           = get_option( self::OPTION, array() );
		$state           = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'source_id'          => '',
				'site_url'           => '',
				'connected_at'       => 0,
				'checkin_interval'   => DAY_IN_SECONDS,
				'limits'             => self::DEFAULT_LIMITS,
				'last_checkin_at'    => 0,
				'server_status'      => '',
				'supported_versions' => array(),
				'last_error'         => '',
				'blocked'            => array(),
				'failing_since'      => 0,
				'status_checked_at'  => 0,
			)
		);
		$state['limits'] = wp_parse_args( (array) $state['limits'], self::DEFAULT_LIMITS );
		return $state;
	}

	/**
	 * Merge changes into the connection state.
	 *
	 * @since   0.1.0
	 * @param   array $changes  Keys to set.
	 * @return  void
	 */
	public function update_state( array $changes ) {
		update_option( self::OPTION, array_merge( $this->get_state(), $changes ), false );
	}

	/**
	 * Whether a source id is stored.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function is_connected() {
		return '' !== $this->get_source_id();
	}

	/**
	 * The stored source id.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public function get_source_id() {
		$state = $this->get_state();
		return (string) $state['source_id'];
	}

	/**
	 * One of the server's limits.
	 *
	 * @since   0.1.0
	 * @param   string $name  Key of DEFAULT_LIMITS.
	 * @return  int
	 */
	public function get_limit( $name ) {
		$state = $this->get_state();
		return max( 1, (int) $state['limits'][ $name ] );
	}

	/**
	 * The block that stops background work, or an empty array.
	 *
	 * @since   0.1.0
	 * @return  array  Keys code, message, at.
	 */
	public function get_block() {
		$state = $this->get_state();
		return (array) $state['blocked'];
	}

	/**
	 * Whether background work is stopped until an admin acts.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function is_blocked() {
		$block = $this->get_block();
		return ! empty( $block['code'] );
	}

	/**
	 * Clear the block, for example after the key was replaced.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function clear_block() {
		$this->update_state(
			array(
				'blocked'       => array(),
				'failing_since' => 0,
			)
		);
	}

	/**
	 * Whether requests have failed for longer than the notice threshold.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function is_failing_too_long() {
		$state = $this->get_state();
		return $state['failing_since'] > 0 && time() - $state['failing_since'] >= self::FAILING_NOTICE_AFTER;
	}

	/**
	 * Mark requests as healthy again.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function clear_failure() {
		$state = $this->get_state();
		if ( $state['failing_since'] > 0 ) {
			$this->update_state( array( 'failing_since' => 0 ) );
		}
	}

	/**
	 * Record a failed request and decide what happens next.
	 *
	 * @since   0.1.0
	 * @param   WP_Error $error  Error from the client.
	 * @return  string  The SAMOMIND_Client::action_for() result.
	 */
	public function handle_error( $error ) {
		$action = SAMOMIND_Client::action_for( $error );
		if ( 'detached' === $action ) {
			$this->detach( $error );
			return $action;
		}

		$changes = array( 'last_error' => self::describe( $error ) );

		if ( 'block' === $action ) {
			$changes['blocked'] = array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'at'      => time(),
			);
		}
		if ( 'retry' === $action ) {
			$state = $this->get_state();
			if ( 0 === (int) $state['failing_since'] ) {
				$changes['failing_since'] = time();
			}
		}
		if ( 'applied' !== $action ) {
			$this->update_state( $changes );
		}

		return $action;
	}

	/**
	 * The source was disconnected or removed on the SamoMind side. Forget
	 * the source id and all pending work, and block until an admin clicks
	 * Connect (or runs `wp samomind connect`). Nothing calls /connect on
	 * its own.
	 *
	 * @since   0.1.0
	 * @param   WP_Error $error  source_disconnected or unknown_source.
	 * @return  void
	 */
	public function detach( $error ) {
		$message = 'source_disconnected' === $error->get_error_code()
			? __( 'Disconnected from the SamoMind dashboard.', 'samomind-ai-assistant' )
			: __( 'SamoMind no longer knows this site; it may have been removed in the dashboard.', 'samomind-ai-assistant' );

		SAMOMIND()->sync->reset();
		update_option(
			self::OPTION,
			array(
				'last_error' => self::describe( $error ),
				'blocked'    => array(
					'code'    => $error->get_error_code(),
					'message' => $message,
					'at'      => time(),
				),
			),
			false
		);
	}

	/**
	 * Text for last_error: code and the API's message, at most 500
	 * characters (contract: PushCheckinRequest.last_error).
	 *
	 * @since   0.1.0
	 * @param   WP_Error $error  Error.
	 * @return  string
	 */
	public static function describe( $error ) {
		return mb_substr( $error->get_error_code() . ': ' . $error->get_error_message(), 0, 500, 'UTF-8' );
	}

	/**
	 * Build the request client. Separate so it can be replaced in tests.
	 *
	 * @since   0.1.0
	 * @return  SAMOMIND_Client
	 */
	protected function client() {
		return SAMOMIND_Client::from_settings();
	}

	/**
	 * Call /connect, store the source id and start the first sync.
	 *
	 * @since   0.1.0
	 * @return  array|WP_Error  The API's response, or its error.
	 */
	public function connect() {
		$payload = array(
			'site_url'          => SAMOMIND()->helpers->get_site_url(),
			'connector'         => self::CONNECTOR,
			'connector_version' => SAMOMIND_VERSION,
			'platform_version'  => get_bloginfo( 'version' ),
		);

		$result = $this->client()->post( '/connect', $payload, SAMOMIND_Client::fresh_key( 'connect' ) );
		// Contract 5.4: connect_conflict means "retry connect".
		if ( is_wp_error( $result ) && 'connect_conflict' === $result->get_error_code() ) {
			$result = $this->client()->post( '/connect', $payload, SAMOMIND_Client::fresh_key( 'connect' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->handle_error( $result );
			return $result;
		}
		if ( empty( $result['source_id'] ) || ! is_string( $result['source_id'] ) ) {
			return new WP_Error( 'invalid_response', __( 'SamoMind did not return a source id.', 'samomind-ai-assistant' ) );
		}

		$limits = self::DEFAULT_LIMITS;
		if ( isset( $result['limits'] ) && is_array( $result['limits'] ) ) {
			foreach ( array_keys( $limits ) as $name ) {
				if ( isset( $result['limits'][ $name ] ) && (int) $result['limits'][ $name ] > 0 ) {
					$limits[ $name ] = (int) $result['limits'][ $name ];
				}
			}
		}

		$this->update_state(
			array(
				'source_id'        => sanitize_text_field( $result['source_id'] ),
				'site_url'         => $payload['site_url'],
				'connected_at'     => time(),
				'checkin_interval' => isset( $result['checkin_interval_seconds'] ) ? max( HOUR_IN_SECONDS, (int) $result['checkin_interval_seconds'] ) : DAY_IN_SECONDS,
				'limits'           => $limits,
				'last_checkin_at'  => time(),
				'last_error'       => '',
				'blocked'          => array(),
				'failing_since'    => 0,
			)
		);

		// Contract 9: first sync on connect. Sync begin returns any stored cursor.
		SAMOMIND()->sync->start_full_sync();

		return $result;
	}

	/**
	 * Call /disconnect and forget the source id. Settings are kept.
	 *
	 * @since   0.1.0
	 * @param   string $reason  uninstall or admin (deactivation sends nothing).
	 * @return  true|WP_Error
	 */
	public function disconnect( $reason ) {
		if ( ! $this->is_connected() ) {
			return new WP_Error( 'not_connected', __( 'This site is not connected.', 'samomind-ai-assistant' ) );
		}

		$result = $this->client()->post(
			'/disconnect',
			array(
				'source_id' => $this->get_source_id(),
				'reason'    => $reason,
			),
			SAMOMIND_Client::fresh_key( 'disconnect' )
		);

		// An unknown or already disconnected source is the state we want.
		if ( is_wp_error( $result ) && 'detached' !== SAMOMIND_Client::action_for( $result ) ) {
			$this->handle_error( $result );
			return $result;
		}

		$this->forget();
		return true;
	}

	/**
	 * Drop the source id and all sync state, keeping the settings.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function forget() {
		delete_option( self::OPTION );
		SAMOMIND()->sync->reset();
	}

	/**
	 * Whether the server's check-in interval has passed.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function is_checkin_due() {
		$state = $this->get_state();
		// A few minutes of slack so an hourly tick does not slip a whole hour.
		return time() - (int) $state['last_checkin_at'] >= (int) $state['checkin_interval'] - 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Call /checkin.
	 *
	 * @since   0.1.0
	 * @param   bool $manual  True for a button or CLI check-in (fresh key),
	 *                        false for the scheduled one (key per hour, so a
	 *                        WP-Cron re-run is not applied twice).
	 * @param   bool $schedule  False to leave the last check-in time alone,
	 *                          so a status refresh does not push the daily
	 *                          check-in and reconcile back.
	 * @return  array|WP_Error
	 */
	public function checkin( $manual, $schedule = true ) {
		if ( ! $this->is_connected() ) {
			return new WP_Error( 'not_connected', __( 'This site is not connected.', 'samomind-ai-assistant' ) );
		}

		$state   = $this->get_state();
		$payload = array(
			'source_id'         => $state['source_id'],
			'connector_version' => SAMOMIND_VERSION,
			'platform_version'  => get_bloginfo( 'version' ),
		);
		if ( '' !== $state['last_error'] ) {
			$payload['last_error'] = $state['last_error'];
		}
		$key = $manual
			? SAMOMIND_Client::fresh_key( 'checkin' )
			: SAMOMIND_Client::event_key( 'checkin', array( $state['source_id'], gmdate( 'Y-m-d-H' ) ) );

		$result = $this->client()->post( '/checkin', $payload, $key );

		if ( is_wp_error( $result ) ) {
			$action = $this->handle_error( $result );
			if ( 'applied' !== $action ) {
				return $result;
			}
			$result = array();
		}

		$versions = isset( $result['supported_api_versions'] ) ? array_map( 'strval', (array) $result['supported_api_versions'] ) : $state['supported_versions'];
		$changes  = array(
			'last_checkin_at'    => $schedule ? time() : $state['last_checkin_at'],
			'server_status'      => isset( $result['status'] ) ? sanitize_text_field( $result['status'] ) : $state['server_status'],
			'checkin_interval'   => isset( $result['checkin_interval_seconds'] ) ? max( HOUR_IN_SECONDS, (int) $result['checkin_interval_seconds'] ) : $state['checkin_interval'],
			'supported_versions' => $versions,
			'last_error'         => '',
			'blocked'            => array(),
			'failing_since'      => 0,
		);
		if ( ! empty( $versions ) && ! in_array( SAMOMIND_Client::API_VERSION, $versions, true ) ) {
			$changes['blocked'] = array(
				'code'    => 'unsupported_api_version',
				'message' => __( 'SamoMind no longer supports this plugin version. Update the plugin.', 'samomind-ai-assistant' ),
				'at'      => time(),
			);
		}
		$this->update_state( $changes );

		return $result;
	}

	/**
	 * Check in for the Status page, so a disconnect made in the SamoMind
	 * dashboard, or a deleted source, shows within a page load. At most
	 * once a minute per site, never calls /connect, and does nothing when
	 * the site is not connected or a bad key already paused it.
	 *
	 * @since   0.2.0
	 * @return  array|WP_Error|null  Null when it did not check in.
	 */
	public function refresh_status() {
		if ( ! $this->is_connected() ) {
			return null;
		}
		$block = $this->get_block();
		// A suspended tenant or disabled source can still check in (5.4), a bad key cannot.
		if ( ! empty( $block['code'] ) && ! in_array( $block['code'], array( 'tenant_suspended', 'source_disabled' ), true ) ) {
			return null;
		}
		$state = $this->get_state();
		if ( time() - (int) $state['status_checked_at'] < self::STATUS_CHECK_INTERVAL ) {
			return null;
		}

		// Stamp first, so a second page load during a slow request does not repeat it.
		$this->update_state( array( 'status_checked_at' => time() ) );
		return $this->checkin( true, false );
	}
}
