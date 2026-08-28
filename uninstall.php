<?php
/**
 * Uninstall: remove the plugin's table, options and scheduled event.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-bac-forms-table.php';

BAC_Forms_Table::drop();

delete_option( 'bac_settings' );
delete_option( 'bac_db_version' );
delete_option( 'bac_last_sync' );
delete_transient( 'bac_admin_notice' );

wp_clear_scheduled_hook( 'bac_sync_forms_event' );
