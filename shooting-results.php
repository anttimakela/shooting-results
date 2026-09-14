<?php
/**
 * Plugin Name:       Shooting Results
 * Plugin URI:         https://github.com/anttimakela/shooting-results
 * Description:        Fast, big-button competition results recording for shooting ranges — every score autosaves immediately, so a page refresh never loses data. Record results from any device on a password-protected page, and export an Excel report by email.
 * Version:             1.2.10
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

define( 'SR_VERSION', '1.2.10' );
define( 'SR_PLUGIN_FILE', __FILE__ );
define( 'SR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SR_PLUGIN_DIR . 'includes/class-sr-db.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-xlsx-writer.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-mailer.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-shortcode.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-ajax.php';
require_once SR_PLUGIN_DIR . 'includes/class-sr-admin-page.php';
require_once SR_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/anttimakela/shooting-results/',
	SR_PLUGIN_FILE,
	'shooting-results'
);

/**
 * Removes the "Range Staff" role and its capability from installs that
 * predate 1.1.0, which gated results recording by WordPress login instead
 * of the page-password model used from 1.1.0 onward (see class-sr-ajax.php
 * SR_Ajax::guard()).
 */
function sr_remove_legacy_role() {
	remove_role( 'sr_range_staff' );
	$admin = get_role( 'administrator' );
	if ( $admin && $admin->has_cap( 'sr_manage_results' ) ) {
		$admin->remove_cap( 'sr_manage_results' );
	}
}

register_activation_hook(
	__FILE__,
	function () {
		SR_DB::install();
		sr_remove_legacy_role();
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'shooting-results', false, dirname( plugin_basename( SR_PLUGIN_FILE ) ) . '/languages' );
		SR_DB::maybe_upgrade();

		if ( 'yes' !== get_option( 'sr_role_removed' ) ) {
			sr_remove_legacy_role();
			update_option( 'sr_role_removed', 'yes' );
		}
	}
);

add_action( 'init', array( 'SR_Shortcode', 'init' ) );
add_action( 'init', array( 'SR_Ajax', 'init' ) );
SR_Admin_Page::init();
