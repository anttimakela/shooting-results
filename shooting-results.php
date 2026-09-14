<?php
/**
 * Plugin Name:       Shooting Results
 * Plugin URI:         https://github.com/anttimakela/shooting-results
 * Description:        Fast, big-button competition results recording for shooting ranges — every score autosaves immediately, so a page refresh never loses data. Exports an Excel report by email.
 * Version:             1.0.0
 * Requires at least:  6.4
 * Requires PHP:        7.4
 * Author:              Antti Mäkelä
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         shooting-results
 * Domain Path:         /languages
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

define( 'SR_VERSION', '1.0.0' );
define( 'SR_PLUGIN_FILE', __FILE__ );
define( 'SR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SR_PLUGIN_DIR . 'includes/class-sr-db.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-capabilities.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-xlsx-writer.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-mailer.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-ajax.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-admin-page.php';
require_once SR_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/anttimakela/shooting-results/',
	SR_PLUGIN_FILE,
	'shooting-results'
);

register_activation_hook(
	__FILE__,
	function () {
		SR_DB::install();
		SR_Capabilities::install();
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'shooting-results', false, dirname( plugin_basename( SR_PLUGIN_FILE ) ) . '/languages' );
		SR_DB::maybe_upgrade();
	}
);

add_action( 'init', array( 'SR_Ajax', 'init' ) );
SR_Admin_Page::init();
