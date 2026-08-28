<?php
/**
 * Plugin Name:       Bonsai ActiveCampaign
 * Plugin URI:        https://bonsaidigitalcollective.com/
 * Description:        Connects a WordPress site to an ActiveCampaign account: syncs ActiveCampaign form definitions into a local table and renders/submits those forms natively (no ActiveCampaign JS widget, no iframe). Provides bac_get_form(), bac_get_forms() and bac_render_form() for themes.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Bonsai Digital Collective
 * Author URI:        https://bonsaidigitalcollective.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bonsai-active-campaign
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Plugin Update Checker (via Composer)
|--------------------------------------------------------------------------
| Pulls updates from GitHub release assets on the main branch, so the plugin
| can be updated from the WordPress admin without being in the .org directory.
*/
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$bac_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/Bonsai-Systems/bonsai-active-campaign',
	__FILE__,
	'bonsai-active-campaign',
	6
);

$bac_update_checker->setBranch( 'main' );
$bac_update_checker->getVcsApi()->enableReleaseAssets();

define( 'BAC_VERSION', '1.0.0' );
define( 'BAC_FILE', __FILE__ );
define( 'BAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BAC_URL', plugin_dir_url( __FILE__ ) );
define( 'BAC_OPTION', 'bac_settings' );
define( 'BAC_DB_VERSION_OPTION', 'bac_db_version' );
define( 'BAC_CRON_HOOK', 'bac_sync_forms_event' );

require_once BAC_DIR . 'includes/class-bac-forms-table.php';
require_once BAC_DIR . 'includes/class-bac-api.php';
require_once BAC_DIR . 'includes/class-bac-sync.php';
require_once BAC_DIR . 'includes/class-bac-settings.php';
require_once BAC_DIR . 'includes/class-bac-submit.php';
require_once BAC_DIR . 'includes/functions.php';

/**
 * Boot the plugin's runtime pieces.
 */
function bac_bootstrap() {
	BAC_Forms_Table::maybe_upgrade();
	BAC_Sync::init();
	BAC_Settings::init();
	BAC_Submit::init();

	load_plugin_textdomain( 'bonsai-active-campaign', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'bac_bootstrap' );

/**
 * Activation: create the forms table and schedule the sync cron.
 */
function bac_activate() {
	BAC_Forms_Table::install();
	BAC_Sync::schedule();
}
register_activation_hook( __FILE__, 'bac_activate' );

/**
 * Deactivation: clear the scheduled sync. Data is left in place (see uninstall.php).
 */
function bac_deactivate() {
	BAC_Sync::unschedule();
}
register_deactivation_hook( __FILE__, 'bac_deactivate' );
