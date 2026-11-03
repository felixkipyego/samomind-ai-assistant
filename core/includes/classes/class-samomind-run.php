<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Run
 *
 * Thats where we bring the plugin to life
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Run
 * @since       0.1.0
 */
class SAMOMIND_Run {

	/**
	 * Our SAMOMIND_Run constructor to run the plugin logic.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * ######################
	 * ###
	 * #### WordPress HOOKS
	 * ###
	 * ######################
	 */

	/**
	 * Registers all WordPress and plugin related hooks
	 *
	 * @access  private
	 * @since   0.1.0
	 * @return  void
	 */
	private function add_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_backend_scripts_and_styles' ), 20 );

		// Widget snippet in the front-end footer.
		new SAMOMIND_Embed();

		// Content events (docs/ARCHITECTURE.md A8).
		$sync = SAMOMIND()->sync;
		add_action( 'transition_post_status', array( $sync, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $sync, 'on_before_delete_post' ), 10, 2 );

		// Background work (docs/ARCHITECTURE.md A1).
		add_action( SAMOMIND_Scheduler::WORKER_HOOK, array( $sync, 'run' ) );
		add_action( SAMOMIND_Scheduler::HOURLY_HOOK, array( $sync, 'on_hourly' ) );
		add_action( 'admin_init', array( SAMOMIND()->scheduler, 'ensure_hourly' ) );
	}

	/**
	 * ######################
	 * ###
	 * #### WP HOOK CALLBACKS
	 * ###
	 * ######################
	 */

	/**
	 * Enqueue backend scripts and styles.
	 *
	 * @access  public
	 * @since   0.1.0
	 * @return  void
	 */
	public function enqueue_backend_scripts_and_styles() {
		$screen = get_current_screen();

		// Only load on our admin pages.
		if ( ! $screen || false === strpos( $screen->id, 'samomind' ) ) {
			return;
		}

		wp_enqueue_style(
			'samomind-backend-styles',
			SAMOMIND_PLUGIN_URL . 'core/includes/assets/css/backend-styles.css',
			array(),
			SAMOMIND_VERSION,
			'all'
		);

		wp_enqueue_script(
			'samomind-backend-scripts',
			SAMOMIND_PLUGIN_URL . 'core/includes/assets/js/backend-scripts.js',
			array( 'jquery' ),
			SAMOMIND_VERSION,
			true
		);
	}
}
