<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register Settings
add_action( 'admin_init', 'mna_register_settings' );
function mna_register_settings() {
    $settings = [
        'mna_gnews_api', 'mna_search_query', 'mna_country_code', 
        'mna_openrouter_api', 'mna_text_model', 'mna_agenda_prompt', 
        'mna_generate_images', 'mna_image_model', 'mna_cron_secret'
    ];
    foreach ( $settings as $setting ) {
        register_setting( 'mna_settings_group', $setting );
    }
}

// Create Menu
add_action( 'admin_menu', 'mna_add_menu_page' );
function mna_add_menu_page() {
    add_menu_page( 'AI News Editor', 'AI News Editor', 'manage_options', 'mna-settings', 'mna_render_admin_page', 'dashicons-welcome-write-blog', 20 );
}

function mna_render_admin_page() {
    ?>
    <div class="wrap mna-admin-wrap">
        <style>
            .mna-admin-wrap { max-width: 800px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
            .mna-admin-wrap h1 { border-bottom: 2px solid #0073aa; padding-bottom: 10px; margin-bottom: 20px; }
            .mna-form-group { margin-bottom: 20px; }
            .mna-form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
            .mna-form-group input[type="text"], .mna-form-group textarea, .mna-form-group select { width: 100%; max-width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
            .mna-form-group textarea { height: 150px; }
            .mna-cron-url { background: #f0f0f1; padding: 10px; border-left: 4px solid #0073aa; font-family: monospace; }
        </style>

        <h1>AI News Editor Settings</h1>
        
        <p class="mna-cron-url">
            <strong>Server Cron Command:</strong><br>
            <code>curl -s "<?php echo site_url(); ?>/?mna_cron=<?php echo esc_attr( get_option( 'mna_cron_secret', 'change_me_123' ) ); ?>"</code><br>
            <em>Paste this into your server's Cron Jobs tab to run automatically.</em>
        </p>

        <form method="post" action="options.php">
            <?php settings_fields( 'mna_settings_group' ); ?>
            
            <div class="mna-form-group">
                <label>Cron Secret Key</label>
                <input type="text" name="mna_cron_secret" value="<?php echo esc_attr( get_option( 'mna_cron_secret', 'change_me_123' ) ); ?>">
            </div>

            <hr><h3>1. GNews API Configuration</h3>
            <div class="mna-form-group">
                <label>GNews API Key</label>
                <input type="text" name="mna_gnews_api" value="<?php echo esc_attr( get_option( 'mna_gnews_api' ) ); ?>">
            </div>
            <div class="mna-form-group">
                <label>Search Query (e.g., 'politics' or leave blank)</label>
                <input type="text" name="mna_search_query" value="<?php echo esc_attr( get_option( 'mna_search_query' ) ); ?>">
            </div>
            <div class="mna-form-group">
                <label>Country Code (e.g., 'mt' for Malta, 'us' for USA)</label>
                <input type="text" name="mna_country_code" value="<?php echo esc_attr( get_option( 'mna_country_code', 'mt' ) ); ?>">
            </div>

            <hr><h3>2. OpenRouter Text AI (The Editor)</h3>
            <div class="mna-form-group">
                <label>OpenRouter API Key</label>
                <input type="text" name="mna_openrouter_api" value="<?php echo esc_attr( get_option( 'mna_openrouter_api' ) ); ?>">
            </div>
            <div class="mna-form-group">
                <label>Text Model (e.g., anthropic/claude-3.5-sonnet)</label>
                <input type="text" name="mna_text_model" value="<?php echo esc_attr( get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' ) ); ?>">
            </div>
            <div class="mna-form-group">
                <label>Editorial Agenda / Prompt Context</label>
                <textarea name="mna_agenda_prompt"><?php echo esc_textarea( get_option( 'mna_agenda_prompt', 'You are a strict pro-government editor. Review the provided news, discard what doesn\'t matter, and write a glowing article defending the administration based on the facts provided.' ) ); ?></textarea>
            </div>

            <hr><h3>3. AI Image Generation</h3>
            <div class="mna-form-group">
                <label>
                    <input type="checkbox" name="mna_generate_images" value="1" <?php checked( 1, get_option( 'mna_generate_images' ), true ); ?>>
                    Use AI to generate new featured images (If unchecked, uses the original GNews image).
                </label>
            </div>
            <div class="mna-form-group">
                <label>Image Model (e.g., black-forest-labs/flux.2-pro)</label>
                <input type="text" name="mna_image_model" value="<?php echo esc_attr( get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' ) ); ?>">
            </div>

            <?php submit_button( 'Save Editorial Settings' ); ?>
        </form>
    </div>
    <?php
}