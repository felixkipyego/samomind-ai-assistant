<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Settings
 *
 * This class contains the plugin wide settings.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Settings
 * @since       0.1.0
 */
class SAMOMIND_Settings {

	/**
	 * The plugin name
	 *
	 * @var     string
	 * @since   0.1.0
	 */
	private $plugin_name;

	/**
	 * Our SAMOMIND_Settings constructor
	 * to run the plugin logic.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->plugin_name = SAMOMIND_NAME;
	}

	/**
	 * Return the plugin name
	 *
	 * @access  public
	 * @since   0.1.0
	 * @return  string The plugin name
	 */
	public function get_plugin_name() {
		return apply_filters( 'SAMOMIND/settings/get_plugin_name', $this->plugin_name );
	}
}
