<?php
/**
 * SamoMind AI Assistant
 *
 * @package       SAMOMIND
 * @author        SoftwaresKe Ltd
 * @license       GPL-2.0-or-later
 * @version       0.1.0
 *
 * @wordpress-plugin
 * Plugin Name:       SamoMind AI Assistant
 * Plugin URI:        https://samomind.com
 * Description:       Keeps your SamoMind AI assistant's knowledge base in sync with this site's public pages, posts and products, and adds the SamoMind chat widget.
 * Version:           0.3.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SoftwaresKe Ltd
 * Author URI:        https://samomind.com
 * Text Domain:       samomind-ai-assistant
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * You should have received a copy of the GNU General Public License
 * along with SamoMind AI Assistant. If not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin name.
define( 'SAMOMIND_NAME', 'SamoMind AI Assistant' );

// Plugin version.
define( 'SAMOMIND_VERSION', '0.3.2' );

// Plugin Root File.
define( 'SAMOMIND_PLUGIN_FILE', __FILE__ );

// Plugin base.
define( 'SAMOMIND_PLUGIN_BASE', plugin_basename( SAMOMIND_PLUGIN_FILE ) );

// Plugin Folder Path.
define( 'SAMOMIND_PLUGIN_DIR', plugin_dir_path( SAMOMIND_PLUGIN_FILE ) );

// Plugin Folder URL.
define( 'SAMOMIND_PLUGIN_URL', plugin_dir_url( SAMOMIND_PLUGIN_FILE ) );

/**
 * Load the main class for the core functionality
 */
require_once SAMOMIND_PLUGIN_DIR . 'core/class-samomind-ai-assistant.php';

/**
 * The main function to load the only instance
 * of our master class.
 *
 * @since   0.1.0
 * @return  object|SamoMind_AI_Assistant
 */
function SAMOMIND() {
	return SamoMind_AI_Assistant::instance();
}

SAMOMIND();

register_activation_hook( SAMOMIND_PLUGIN_FILE, array( SAMOMIND(), 'activate' ) );
register_deactivation_hook( SAMOMIND_PLUGIN_FILE, array( SAMOMIND(), 'deactivate' ) );
