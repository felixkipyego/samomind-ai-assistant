<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SamoMind_AI_Assistant' ) ) :

	/**
	 * Main SamoMind_AI_Assistant Class.
	 *
	 * @package     SAMOMIND
	 * @subpackage  Classes/SamoMind_AI_Assistant
	 * @since       0.1.0
	 */
	final class SamoMind_AI_Assistant {

		/**
		 * The real instance
		 *
		 * @access  private
		 * @since   0.1.0
		 * @var     object|SamoMind_AI_Assistant
		 */
		private static $instance;

		/**
		 * SAMOMIND helpers object.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @var     object|SAMOMIND_Helpers
		 */
		public $helpers;

		/**
		 * SAMOMIND settings object.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @var     object|SAMOMIND_Settings
		 */
		public $settings;

		/**
		 * SAMOMIND request log object.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @var     object|SAMOMIND_Log
		 */
		public $log;

		/**
		 * SAMOMIND content object.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @var     object|SAMOMIND_Content
		 */
		public $content;

		/**
		 * Throw error on object clone.
		 *
		 * Cloning instances of the class is forbidden.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @return  void
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'You are not allowed to clone this class.', 'samomind-ai-assistant' ), '0.1.0' );
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @return  void
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'You are not allowed to unserialize this class.', 'samomind-ai-assistant' ), '0.1.0' );
		}

		/**
		 * Main SamoMind_AI_Assistant Instance.
		 *
		 * Insures that only one instance of SamoMind_AI_Assistant exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @static
		 * @return  object|SamoMind_AI_Assistant  The one true SamoMind_AI_Assistant
		 */
		public static function instance() {
			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof SamoMind_AI_Assistant ) ) {
				self::$instance = new SamoMind_AI_Assistant();
				self::$instance->base_hooks();
				self::$instance->includes();
				self::$instance->helpers  = new SAMOMIND_Helpers();
				self::$instance->settings = new SAMOMIND_Settings();
				self::$instance->log      = new SAMOMIND_Log();
				self::$instance->content  = new SAMOMIND_Content();

				// Fire the plugin logic.
				new SAMOMIND_Run();

				/**
				 * Fire a custom action to allow dependencies
				 * after the successful plugin setup
				 */
				do_action( 'SAMOMIND/plugin_loaded' );
			}

			return self::$instance;
		}

		/**
		 * Include required files.
		 *
		 * @access  private
		 * @since   0.1.0
		 * @return  void
		 */
		private function includes() {
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-helpers.php';
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-settings.php';
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-log.php';
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-client.php';
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-content.php';
			require_once SAMOMIND_PLUGIN_DIR . 'core/includes/classes/class-samomind-run.php';
		}

		/**
		 * Add base hooks for the core functionality
		 *
		 * @access  private
		 * @since   0.1.0
		 * @return  void
		 */
		private function base_hooks() {
			add_action( 'plugins_loaded', array( self::$instance, 'load_textdomain' ) );
		}

		/**
		 * Loads the plugin language files.
		 *
		 * @access  public
		 * @since   0.1.0
		 * @return  void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'samomind-ai-assistant', false, dirname( plugin_basename( SAMOMIND_PLUGIN_FILE ) ) . '/languages/' );
		}
	}

endif; // End if class_exists check.
