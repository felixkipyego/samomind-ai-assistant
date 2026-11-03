<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Scheduler
 *
 * Runs the sync worker in the background through Action Scheduler when
 * WooCommerce provides it, otherwise through WP-Cron, plus an hourly
 * WP-Cron tick for check-ins (docs/ARCHITECTURE.md A1).
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Scheduler
 * @since       0.1.0
 */
class SAMOMIND_Scheduler {

	/**
	 * Hook that runs one worker pass.
	 *
	 * @var string
	 */
	const WORKER_HOOK = 'SAMOMIND/worker';

	/**
	 * Hourly hook: check-in when due, then reconcile.
	 *
	 * @var string
	 */
	const HOURLY_HOOK = 'SAMOMIND/hourly';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const GROUP = 'samomind';

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function uses_action_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Run the worker after a delay, unless a run is already due sooner.
	 *
	 * @since   0.1.0
	 * @param   int $delay  Seconds from now.
	 * @return  void
	 */
	public function schedule_worker( $delay = 0 ) {
		$when = time() + max( 0, (int) $delay );
		$next = $this->next_worker_run();
		if ( $next > 0 && $next <= $when ) {
			return;
		}

		$this->unschedule_worker();
		if ( $this->uses_action_scheduler() ) {
			as_schedule_single_action( $when, self::WORKER_HOOK, array(), self::GROUP );
		} else {
			wp_schedule_single_event( $when, self::WORKER_HOOK );
		}
	}

	/**
	 * Time of the next pending worker run, 0 when none.
	 *
	 * @since   0.1.0
	 * @return  int
	 */
	public function next_worker_run() {
		if ( $this->uses_action_scheduler() ) {
			$next = as_next_scheduled_action( self::WORKER_HOOK, array(), self::GROUP );
			// True means a run is in progress, which does not cover new work.
			return is_int( $next ) ? $next : 0;
		}
		$next = wp_next_scheduled( self::WORKER_HOOK );
		return $next ? (int) $next : 0;
	}

	/**
	 * Remove pending worker runs from both schedulers.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function unschedule_worker() {
		if ( $this->uses_action_scheduler() ) {
			as_unschedule_all_actions( self::WORKER_HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::WORKER_HOOK );
	}

	/**
	 * Make sure the hourly tick is scheduled.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function ensure_hourly() {
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOURLY_HOOK );
		}
	}

	/**
	 * Remove everything this plugin scheduled.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function clear_all() {
		$this->unschedule_worker();
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
	}
}
