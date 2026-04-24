<?php
/**
 * Plugin Name: Malta News AI Editor (Pro)
 * Description: Advanced two-step AI editorial desk with queuing, web search, and auto-publishing.
 * Version: 2.0.1
 * Author: Mohamed Sawah
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define plugin constants
define( 'MNA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MNA_URL', plugin_dir_url( __FILE__ ) );
define( 'MNA_VERSION', '2.0.1' );

// Include all modular files
require_once MNA_DIR . 'includes/admin-settings.php';
require_once MNA_DIR . 'includes/admin-queue.php';
require_once MNA_DIR . 'includes/ajax-handlers.php';
require_once MNA_DIR . 'includes/step1-editor.php';
require_once MNA_DIR . 'includes/step2-writer.php';

/**
 * Activation Hook: Create the Custom Database Table for the Queue
 */
register_activation_hook( __FILE__, 'mna_create_queue_table' );
function mna_create_queue_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        source_id varchar(255) NOT NULL,
        source_url text NOT NULL,
        suggested_title text NOT NULL,
        ai_summary text NOT NULL,
        status varchar(50) DEFAULT 'pending' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY source_id (source_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * Build the Admin Menus
 */
add_action( 'admin_menu', 'mna_advanced_admin_menu' );
function mna_advanced_admin_menu() {
    // Main Settings Page
    add_menu_page( 
        'AI News Editor', 
        'AI News Editor', 
        'manage_options', 
        'mna-settings', 
        'mna_render_settings_page', 
        'dashicons-welcome-write-blog', 
        20 
    );

    // Submenu: Queue & History
    add_submenu_page( 
        'mna-settings', 
        'Queue & History', 
        'Queue & History', 
        'manage_options', 
        'mna-queue', 
        'mna_render_queue_page' 
    );
}

/**
 * Enqueue Admin Scripts/Styles for a dynamic UI
 */
add_action( 'admin_enqueue_scripts', 'mna_admin_assets' );
function mna_admin_assets( $hook ) {
    if ( strpos( $hook, 'mna-' ) === false ) return;
    
    wp_enqueue_style( 'mna-admin-css', MNA_URL . 'assets/admin.css', [], MNA_VERSION );
    wp_enqueue_script( 'mna-admin-js', MNA_URL . 'assets/admin.js', ['jquery'], MNA_VERSION, true );
    wp_localize_script( 'mna-admin-js', 'mna_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mna_secure_nonce' )
    ]);
}