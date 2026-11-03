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
