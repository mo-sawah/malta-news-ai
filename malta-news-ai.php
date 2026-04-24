<?php
/**
 * Plugin Name: Malta News AI Editor (Pro)
 * Description: Advanced two-step AI editorial desk with queuing, web search, and auto-publishing.
 * Version: 2.1.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define plugin constants
define( 'MNA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MNA_URL', plugin_dir_url( __FILE__ ) );
define( 'MNA_VERSION', '2.1.0' );

// Include all modular files
require_once MNA_DIR . 'includes/admin-settings.php';
require_once MNA_DIR . 'includes/admin-queue.php';
require_once MNA_DIR . 'includes/ajax-handlers.php';
require_once MNA_DIR . 'includes/step1-editor.php';
require_once MNA_DIR . 'includes/step2-writer.php';

/**
 * Activation Hook: Create/Upgrade Database Table
 */
register_activation_hook( __FILE__, 'mna_create_queue_table' );
function mna_create_queue_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';
    $charset_collate = $wpdb->get_charset_collate();

    // Upgraded Schema includes 'image_prompt'
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        source_id varchar(255) NOT NULL,
        source_url text NOT NULL,
        suggested_title text NOT NULL,
        ai_summary text NOT NULL,
        image_prompt text NOT NULL,
        status varchar(50) DEFAULT 'pending' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY source_id (source_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * NATIVE WP-CRON SETUP
 */
// 1. Add custom interval for 20 minutes
add_filter( 'cron_schedules', 'mna_add_cron_intervals' );
function mna_add_cron_intervals( $schedules ) {
    $schedules['mna_twenty'] = array( 'interval' => 1200, 'display' => 'Every 20 Minutes' );
    return $schedules;
}

// 2. Schedule or Clear Background Events based on Settings
add_action( 'init', 'mna_setup_wp_cron' );
function mna_setup_wp_cron() {
    // Step 1 Editor (Hourly)
    if ( get_option('mna_auto_editor') == '1' && ! wp_next_scheduled( 'mna_cron_step_1' ) ) {
        wp_schedule_event( time(), 'hourly', 'mna_cron_step_1' );
    } elseif ( get_option('mna_auto_editor') != '1' ) {
        wp_clear_scheduled_hook( 'mna_cron_step_1' );
    }

    // Step 2 Writer (Every 20 Mins)
    if ( get_option('mna_auto_writer') == '1' && ! wp_next_scheduled( 'mna_cron_step_2' ) ) {
        wp_schedule_event( time(), 'mna_twenty', 'mna_cron_step_2' );
    } elseif ( get_option('mna_auto_writer') != '1' ) {
        wp_clear_scheduled_hook( 'mna_cron_step_2' );
    }
}

// 3. Connect the Hooks to the Functions
add_action( 'mna_cron_step_1', 'mna_execute_step_1_editor' );
add_action( 'mna_cron_step_2', 'mna_execute_step_2_writer' );


/**
 * Build Admin Menus & Assets
 */
add_action( 'admin_menu', 'mna_advanced_admin_menu' );
function mna_advanced_admin_menu() {
    add_menu_page( 'AI News Editor', 'AI News Editor', 'manage_options', 'mna-settings', 'mna_render_settings_page', 'dashicons-welcome-write-blog', 20 );
    add_submenu_page( 'mna-settings', 'Queue & History', 'Queue & History', 'manage_options', 'mna-queue', 'mna_render_queue_page' );
}

add_action( 'admin_enqueue_scripts', 'mna_admin_assets' );
function mna_admin_assets( $hook ) {
    if ( strpos( $hook, 'mna-' ) === false ) return;
    wp_enqueue_script( 'mna-admin-js', MNA_URL . 'assets/admin.js', ['jquery'], MNA_VERSION, true );
}