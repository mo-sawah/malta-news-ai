<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register Settings
add_action( 'admin_init', 'mna_register_settings_pro' );
function mna_register_settings_pro() {
    $settings = [
        'mna_gnews_api', 'mna_search_query', 'mna_country_code', 
        'mna_openrouter_api', 'mna_text_model', 'mna_enable_web_search',
        'mna_editor_prompt', 'mna_writer_prompt', 
        'mna_post_author', 'mna_post_category', 
        'mna_generate_images', 'mna_image_model'
    ];
    foreach ( $settings as $setting ) {
        register_setting( 'mna_settings_group', $setting );
    }
}

function mna_render_settings_page() {
    ?>
    <style>
        .mna-dashboard { max-width: 900px; margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .mna-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .mna-header h1 { font-size: 24px; margin: 0; }
        .mna-actions { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; gap: 10px; border-left: 4px solid #0073aa; }
        .mna-btn-ajax { background: #0073aa; color: #fff; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .mna-btn-ajax:hover { background: #005177; }
        .mna-btn-ajax:disabled { background: #ccc; cursor: not-allowed; }
        .mna-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mna-card h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px; color: #23282d; }
        .mna-form-row { margin-bottom: 15px; }
        .mna-form-row label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
        .mna-form-row input[type="text"], .mna-form-row textarea, .mna-form-row select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .mna-form-row textarea { height: 120px; font-family: monospace; font-size: 13px; }
        .mna-help-text { font-size: 12px; color: #666; margin-top: 4px; display: block; }
    </style>

    <div class="wrap mna-dashboard">
        <div class="mna-header">
            <h1>AI News Editor (Pro Engine)</h1>
        </div>

        <div class="mna-actions">
            <div>
                <strong>Manual Controls:</strong>
                <span class="mna-help-text" style="display:inline; margin-left: 10px;">Test your settings without waiting for cron.</span>
            </div>
            <div style="margin-left: auto;">
                <button class="mna-btn-ajax" data-action="mna_run_step_1">Run Step 1: Editor (Fetch & Queue)</button>
                <button class="mna-btn-ajax" data-action="mna_run_step_2" style="background: #00a32a;">Run Step 2: Writer (Draft 1 Post)</button>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'mna_settings_group' ); ?>

            <div class="mna-card">
                <h2>1. News Sourcing (GNews API)</h2>
                <div class="mna-form-row">
                    <label>GNews API Key</label>
                    <input type="text" name="mna_gnews_api" value="<?php echo esc_attr( get_option( 'mna_gnews_api' ) ); ?>" placeholder="Enter GNews API Key">
                </div>
                <div style="display: flex; gap: 20px;">
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Search Query</label>
                        <input type="text" name="mna_search_query" value="<?php echo esc_attr( get_option( 'mna_search_query', 'politics' ) ); ?>">
                        <span class="mna-help-text">Leave blank for all top news, or enter 'politics', 'economy', etc.</span>
                    </div>
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Country Code</label>
                        <input type="text" name="mna_country_code" value="<?php echo esc_attr( get_option( 'mna_country_code', 'mt' ) ); ?>">
                        <span class="mna-help-text">'mt' for Malta, 'us' for USA.</span>
                    </div>
                </div>
            </div>

            <div class="mna-card">
                <h2>2. OpenRouter AI Settings</h2>
                <div class="mna-form-row">
                    <label>OpenRouter API Key</label>
                    <input type="text" name="mna_openrouter_api" value="<?php echo esc_attr( get_option( 'mna_openrouter_api' ) ); ?>">
                </div>
                <div class="mna-form-row">
                    <label>Text Model</label>
                    <input type="text" name="mna_text_model" value="<?php echo esc_attr( get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' ) ); ?>">
                </div>
                <div class="mna-form-row">
                    <label>
                        <input type="checkbox" name="mna_enable_web_search" value="1" <?php checked( 1, get_option( 'mna_enable_web_search' ), true ); ?>>
                        <strong>Enable OpenRouter Web Search</strong>
                    </label>
                    <span class="mna-help-text">If the selected model supports live web search, checking this will allow the AI to look up additional live facts.</span>
                </div>
            </div>

            <div class="mna-card">
                <h2>3. The Editor (Step 1 Prompt)</h2>
                <span class="mna-help-text" style="margin-bottom: 10px;">This prompt tells the AI how to filter the 10 articles and create a summary/plan for what should be written.</span>
                <div class="mna-form-row">
                    <textarea name="mna_editor_prompt"><?php echo esc_textarea( get_option( 'mna_editor_prompt', "You are the Senior Editor of a pro-government news publication. Review the provided news JSON. Select the most important stories that matter to our agenda. For each selected story, write a suggested headline and a detailed summary of the facts and the angle we should take to defend the government or criticize the opposition. Discard irrelevant news." ) ); ?></textarea>
                </div>
            </div>

            <div class="mna-card">
                <h2>4. The Writer & Publisher (Step 2 Prompt)</h2>
                <span class="mna-help-text" style="margin-bottom: 10px;">This prompt is used when the AI writes the actual 600-800 word article based on the summary from Step 1.</span>
                <div class="mna-form-row">
                    <textarea name="mna_writer_prompt"><?php echo esc_textarea( get_option( 'mna_writer_prompt', "You are an expert political journalist writing a highly engaging, pro-government news article. I will provide you with a news summary and an angle. Write a comprehensive 600-800 word article using proper HTML tags (<h2>, <p>, <ul>). Adopt a professional, persuasive tone." ) ); ?></textarea>
                </div>

                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Post Author</label>
                        <?php wp_dropdown_users( array( 'name' => 'mna_post_author', 'selected' => get_option( 'mna_post_author', 1 ) ) ); ?>
                    </div>
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Post Category</label>
                        <?php wp_dropdown_categories( array( 'name' => 'mna_post_category', 'selected' => get_option( 'mna_post_category', get_option('default_category') ), 'hide_empty' => 0 ) ); ?>
                    </div>
                </div>
            </div>

            <div class="mna-card">
                <h2>5. AI Image Generation</h2>
                <div class="mna-form-row">
                    <label>
                        <input type="checkbox" name="mna_generate_images" value="1" <?php checked( 1, get_option( 'mna_generate_images' ), true ); ?>>
                        <strong>Generate new Featured Images (Uses OpenRouter Modalitiy API)</strong>
                    </label>
                    <span class="mna-help-text">If unchecked, the plugin will download and use the original image from the GNews source.</span>
                </div>
                <div class="mna-form-row">
                    <label>Image Model</label>
                    <input type="text" name="mna_image_model" value="<?php echo esc_attr( get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' ) ); ?>">
                </div>
            </div>

            <?php submit_button( 'Save All Settings', 'primary', 'submit', true, ['style' => 'font-size: 16px; padding: 10px 30px;'] ); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.mna-btn-ajax').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var action = btn.data('action');
            var originalText = btn.text();
            
            btn.text('Processing (Please wait)...').prop('disabled', true);
            
            $.post(ajaxurl, {
                action: action,
                // nonce protection will be added in the ajax handler
            }, function(response) {
                alert(response.data || response);
                btn.text(originalText).prop('disabled', false);
            }).fail(function() {
                alert('Request timed out or failed. Check console for errors.');
                btn.text(originalText).prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}