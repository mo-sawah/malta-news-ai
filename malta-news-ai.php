<?php
/**
 * Plugin Name: Malta News AI Editor
 * Description: Fully automated, AI-driven editorial desk using GNews and OpenRouter.
 * Version: 1.0.0
 * Author: Mohamed Sawah
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Define plugin paths
define( 'MNA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MNA_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once MNA_DIR . 'includes/admin-page.php';
require_once MNA_DIR . 'includes/news-engine.php';

/**
 * Bulletproof Server Cron Endpoint
 * Instead of relying on WP-Cron, trigger this from your server (cPanel/RunCloud) every hour:
 * curl -s "https://yoursite.com/?mna_cron=YOUR_SECRET_KEY"
 */
add_action( 'init', 'mna_listen_for_server_cron' );
function mna_listen_for_server_cron() {
    if ( isset( $_GET['mna_cron'] ) ) {
        $saved_key = get_option( 'mna_cron_secret', 'change_me_123' );
        if ( sanitize_text_field( $_GET['mna_cron'] ) === $saved_key ) {
            // Trigger the engine
            mna_execute_news_cycle();
            wp_die( 'News cycle executed successfully.', 'MNA Cron', array( 'response' => 200 ) );
        } else {
            wp_die( 'Unauthorized.', 'MNA Cron', array( 'response' => 403 ) );
        }
    }
}