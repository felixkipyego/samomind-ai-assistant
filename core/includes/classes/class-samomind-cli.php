<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the Samomind connection and sync.
 *
 * Every command calls the same code the admin buttons call. `sync` and
 * `reconcile` then run the background worker in the foreground until the
 * work is done.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_CLI
 * @since       0.1.0
 */
class SAMOMIND_CLI {

	/**
	 * Seconds to wait between worker passes, to stay under the API's
	 * per-key rate limit (20 requests per pass, 60 per minute).
	 *
	 * @var int
	 */
	const PASS_PAUSE = 20;

	/**
	 * Show the connection and sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind status
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$helpers    = SAMOMIND()->helpers;
		$connection = SAMOMIND()->connection;
		$state      = $connection->get_state();
		$progress   = SAMOMIND()->sync->get_progress();
		$block      = $connection->get_block();

		$rows = array(
			array( 'Field', 'Value' ),
			array( 'Plugin version', SAMOMIND_VERSION ),
			array( 'API base URL', $helpers->get_api_base_url() ),
			array( 'Key id', '' !== $helpers->get_key_id() ? $helpers->get_key_id() : '(not set)' ),
			array( 'Secret', $helpers->secret_is_constant() ? 'set (wp-config.php constant)' : ( $helpers->has_secret() ? 'set' : 'not set' ) ),
			array( 'Site address', $helpers->get_site_url() ),
			array( 'Connected', $connection->is_connected() ? 'yes' : 'no' ),
			array( 'Source id', $state['source_id'] ),
			array( 'Last check-in', $state['last_checkin_at'] ? gmdate( 'c', $state['last_checkin_at'] ) : 'never' ),
			array( 'Server status', $state['server_status'] ),
			array( 'Paused', empty( $block['code'] ) ? 'no' : $block['code'] . ': ' . $block['message'] ),
			array( 'Last error', $state['last_error'] ),
			array( 'Full sync', $progress['state'] . ' (' . $progress['sent'] . ' of about ' . $progress['total'] . ' sent)' ),
			array( 'Last full sync', $progress['finished_at'] ? gmdate( 'c', $progress['finished_at'] ) : 'never' ),
			array( 'Last full sync result', $progress['accepted'] . ' accepted, ' . $progress['unchanged'] . ' unchanged, ' . $progress['refused'] . ' refused' ),
			array( 'Reconcile', $progress['reconcile']['state'] ),
			array( 'Waiting to send', (string) $progress['pending'] ),
			array( 'Scheduler', SAMOMIND()->scheduler->uses_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ),
		);
		if ( '' !== $progress['message'] ) {
			$rows[] = array( 'Sync message', $progress['message'] );
		}

		$header = array_shift( $rows );
		$items  = array();
		foreach ( $rows as $row ) {
			$items[] = array_combine( $header, $row );
		}
		WP_CLI\Utils\format_items( 'table', $items, $header );
	}

	/**
	 * Connect this site to Samomind and start the first sync in the background.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind connect
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function connect( $args, $assoc_args ) {
		if ( ! SAMOMIND()->helpers->has_credentials() ) {
			WP_CLI::error( 'Set the key id and secret first (Samomind > Settings, or the SAMOMIND_API_SECRET constant).' );
		}
		$result = SAMOMIND()->connection->connect();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Connect failed: ' . $result->get_error_message() . ' (' . $result->get_error_code() . ')' );
		}
		WP_CLI::success( 'Connected, source id ' . SAMOMIND()->connection->get_source_id() . '. The first sync runs in the background; `wp samomind sync` runs one now.' );
	}

	/**
	 * Disconnect this site. Samomind removes every document it received from it.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind disconnect --yes
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function disconnect( $args, $assoc_args ) {
		WP_CLI::confirm( 'Disconnect this site? Samomind removes every document it received from it.', $assoc_args );
		$result = SAMOMIND()->connection->disconnect( 'admin' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Disconnect failed: ' . $result->get_error_message() . ' (' . $result->get_error_code() . ')' );
		}
		WP_CLI::success( 'Disconnected. Settings are kept.' );
	}

	/**
	 * Run a full sync now and wait for it to finish.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind sync
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$this->require_connection();
		SAMOMIND()->sync->start_full_sync();
		$this->drive();

		$progress = SAMOMIND()->sync->get_progress();
		if ( 'stopped' === $progress['state'] ) {
			WP_CLI::error( 'Sync stopped: ' . $progress['message'] );
		}
		WP_CLI::success( sprintf( 'Full sync %s: %d sent, %d accepted, %d unchanged, %d refused.', $progress['state'], $progress['sent'], $progress['accepted'], $progress['unchanged'], $progress['refused'] ) );
	}

	/**
	 * Reconcile this site's inventory with Samomind now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind reconcile
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function reconcile( $args, $assoc_args ) {
		$this->require_connection();
		SAMOMIND()->sync->request_reconcile( 'cli-' . wp_generate_uuid4() );
		$this->drive();

		$reconcile = SAMOMIND()->sync->get_progress()['reconcile'];
		if ( 'stopped' === $reconcile['state'] ) {
			WP_CLI::error( 'Reconcile stopped: ' . $reconcile['message'] );
		}
		WP_CLI::success( sprintf( 'Reconcile %s: %d missing, %d stale (both pushed), %d removed by Samomind.', $reconcile['state'], $reconcile['missing'], $reconcile['stale'], $reconcile['deleted'] ) );
	}

	/**
	 * Check in with Samomind now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp samomind checkin
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Named arguments.
	 */
	public function checkin( $args, $assoc_args ) {
		$this->require_connection();
		$result = SAMOMIND()->connection->checkin( true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Check-in failed: ' . $result->get_error_message() . ' (' . $result->get_error_code() . ')' );
		}
		$status = isset( $result['status'] ) ? $result['status'] : 'unknown';
		WP_CLI::success( 'Checked in. Samomind reports: ' . $status );
	}

	/**
	 * Stop with an error when the site is not connected.
	 *
	 * @return void
	 */
	private function require_connection() {
		if ( ! SAMOMIND()->connection->is_connected() ) {
			WP_CLI::error( 'This site is not connected. Run `wp samomind connect` first.' );
		}
	}

	/**
	 * Run worker passes until there is no work left.
	 *
	 * @return void
	 */
	private function drive() {
		$sync = SAMOMIND()->sync;
		do {
			$outcome  = $sync->run();
			$progress = $sync->get_progress();
			WP_CLI::log( sprintf( 'Full sync %s, %d of about %d sent; reconcile %s; %d waiting.', $progress['state'], $progress['sent'], $progress['total'], $progress['reconcile']['state'], $progress['pending'] ) );

			if ( 'blocked' === $outcome ) {
				$block = SAMOMIND()->connection->get_block();
				WP_CLI::error( 'Paused: ' . $block['message'] . ' (' . $block['code'] . ')' );
			}
			if ( 'retry' === $outcome ) {
				WP_CLI::error( 'A request failed and will be retried in the background at ' . gmdate( 'c', $progress['retry_at'] ) . '. Last error: ' . SAMOMIND()->connection->get_state()['last_error'] );
			}
			if ( 'detached' === $outcome || 'not_connected' === $outcome ) {
				$block = SAMOMIND()->connection->get_block();
				WP_CLI::error( ( empty( $block['message'] ) ? 'This site is not connected.' : $block['message'] ) . ' Run `wp samomind connect` to connect again.' );
			}
			if ( 'more' === $outcome || 'locked' === $outcome ) {
				sleep( self::PASS_PAUSE );
			}
		} while ( 'idle' !== $outcome || $sync->has_work() );
	}
}
