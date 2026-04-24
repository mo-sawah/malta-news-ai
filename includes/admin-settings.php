<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', 'mna_register_settings_pro' );
function mna_register_settings_pro() {
    $settings = [
        'mna_auto_editor', 'mna_auto_writer',
        'mna_source_mode', 'mna_known_sources', 
        'mna_firecrawl_api', 'mna_firecrawl_urls', 
        'mna_gnews_api', 'mna_search_query', 'mna_country_code', 
        'mna_openrouter_api', 'mna_text_model', 'mna_enable_web_search',
        'mna_editor_prompt', 'mna_writer_prompt', 
        'mna_post_author', 'mna_post_category', 
        'mna_generate_images', 'mna_image_model'
    ];
    foreach ( $settings as $setting ) register_setting( 'mna_settings_group', $setting );
}

function mna_render_settings_page() {
    $default_rss = "https://timesofmalta.com/rss\nhttps://www.maltatoday.com.mt/rss";
    $default_firecrawl = "https://timesofmalta.com/news/national\nhttps://www.maltatoday.com.mt/news/national/";
    ?>
    <style>
        .mna-dashboard { max-width: 900px; margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .mna-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .mna-header h1 { font-size: 24px; margin: 0; }
        .mna-actions { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; gap: 10px; border-left: 4px solid #0073aa; align-items: center; }
        .mna-btn-ajax { background: #0073aa; color: #fff; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .mna-btn-ajax:disabled { background: #ccc; cursor: not-allowed; }
        .mna-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mna-card h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px; color: #23282d; }
        .mna-form-row { margin-bottom: 15px; }
        .mna-form-row label { display: block; font-weight: 600; margin-bottom: 5px; }
        .mna-form-row input[type="text"], .mna-form-row textarea, .mna-form-row select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .mna-form-row textarea { height: 120px; font-family: monospace; }
        .mna-help-text { font-size: 12px; color: #666; margin-top: 4px; display: block; }
        .mna-toggle-box { background: #f0f0f1; padding: 15px; border-radius: 6px; border: 1px solid #ccd0d4; margin-bottom: 20px; display: flex; gap: 20px; }
    </style>

    <div class="wrap mna-dashboard">
        <div class="mna-header"><h1>AI News Editor (Pro Engine)</h1></div>

        <div class="mna-actions">
            <div><strong>Manual Controls:</strong></div>
            <div style="margin-left: auto;">
                <button class="mna-btn-ajax" data-action="mna_run_step_1">Run Step 1: Editor (Fetch & Queue)</button>
                <button class="mna-btn-ajax" data-action="mna_run_step_2" style="background: #00a32a;">Run Step 2: Writer (Draft 1 Post)</button>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'mna_settings_group' ); ?>

            <div class="mna-toggle-box">
                <div style="flex: 1;">
                    <label style="font-weight: bold; font-size: 15px; display:flex; align-items:center; gap: 8px;">
                        <input type="checkbox" name="mna_auto_editor" value="1" <?php checked( 1, get_option( 'mna_auto_editor' ), true ); ?>>
                        Enable Auto-Editor (Runs every 1 hour)
                    </label>
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: bold; font-size: 15px; display:flex; align-items:center; gap: 8px; color: #008a20;">
                        <input type="checkbox" name="mna_auto_writer" value="1" <?php checked( 1, get_option( 'mna_auto_writer' ), true ); ?>>
                        Enable Auto-Writer (Runs every 20 mins)
                    </label>
                </div>
            </div>

            <div class="mna-card">
                <h2>1. News Sourcing Engine</h2>
                <div class="mna-form-row">
                    <label>Source Mode</label>
                    <select name="mna_source_mode" id="mna_source_mode">
                        <option value="firecrawl" <?php selected( get_option( 'mna_source_mode', 'firecrawl' ), 'firecrawl' ); ?>>Firecrawl Direct Site AI Scrape (Recommended)</option>
                        <option value="rss" <?php selected( get_option( 'mna_source_mode' ), 'rss' ); ?>>Known Local Sources (RSS)</option>
                        <option value="gnews" <?php selected( get_option( 'mna_source_mode' ), 'gnews' ); ?>>Global Search (GNews API)</option>
                    </select>
                </div>
                <div id="mna_firecrawl_wrapper">
                    <div class="mna-form-row"><label>Firecrawl API Key</label><input type="text" name="mna_firecrawl_api" value="<?php echo esc_attr( get_option( 'mna_firecrawl_api' ) ); ?>"></div>
                    <div class="mna-form-row"><label>Target Website Category URLs (One per line)</label><textarea name="mna_firecrawl_urls"><?php echo esc_textarea( get_option( 'mna_firecrawl_urls', $default_firecrawl ) ); ?></textarea></div>
                </div>
                <div id="mna_rss_wrapper" style="display: none;">
                    <div class="mna-form-row"><label>Known Source RSS Feeds (One per line)</label><textarea name="mna_known_sources"><?php echo esc_textarea( get_option( 'mna_known_sources', $default_rss ) ); ?></textarea></div>
                </div>
                <div id="mna_gnews_wrapper" style="display: none;">
                    <div class="mna-form-row"><label>GNews API Key</label><input type="text" name="mna_gnews_api" value="<?php echo esc_attr( get_option( 'mna_gnews_api' ) ); ?>"></div>
                    <div style="display: flex; gap: 20px;">
                        <div class="mna-form-row" style="flex: 1;"><label>Search Query</label><input type="text" name="mna_search_query" value="<?php echo esc_attr( get_option( 'mna_search_query', 'Malta politics' ) ); ?>"></div>
                        <div class="mna-form-row" style="flex: 1;"><label>Country Code</label><input type="text" name="mna_country_code" value="<?php echo esc_attr( get_option( 'mna_country_code', 'mt' ) ); ?>"></div>
                    </div>
                </div>
            </div>

            <div class="mna-card">
                <h2>2. OpenRouter AI Settings</h2>
                <div class="mna-form-row"><label>OpenRouter API Key</label><input type="text" name="mna_openrouter_api" value="<?php echo esc_attr( get_option( 'mna_openrouter_api' ) ); ?>"></div>
                <div class="mna-form-row"><label>Text Model</label><input type="text" name="mna_text_model" value="<?php echo esc_attr( get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' ) ); ?>"></div>
                <div class="mna-form-row"><label><input type="checkbox" name="mna_enable_web_search" value="1" <?php checked( 1, get_option( 'mna_enable_web_search' ), true ); ?>> <strong>Enable OpenRouter Web Search</strong></label></div>
            </div>

            <div class="mna-card">
                <h2>3. The Editor (Step 1 Prompt)</h2>
                <div class="mna-form-row"><textarea name="mna_editor_prompt"><?php echo esc_textarea( get_option( 'mna_editor_prompt', "You are the Editor-in-Chief of APPOSTLI..." ) ); ?></textarea></div>
            </div>

            <div class="mna-card">
                <h2>4. The Writer & Publisher (Step 2 Prompt)</h2>
                <div class="mna-form-row"><textarea name="mna_writer_prompt"><?php echo esc_textarea( get_option( 'mna_writer_prompt', "You are an elite correspondent for APPOSTLI..." ) ); ?></textarea></div>
                
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Post Author</label>
                        <select name="mna_post_author">
                            <option value="auto" <?php selected( get_option('mna_post_author', 'auto'), 'auto' ); ?>>Automatic (AI Assigned)</option>
                            <?php 
                            foreach( get_users() as $u ) {
                                echo '<option value="'.$u->ID.'" '.selected(get_option('mna_post_author'), $u->ID, false).'>'.$u->display_name.'</option>';
                            } 
                            ?>
                        </select>
                    </div>
                    <div class="mna-form-row" style="flex: 1;">
                        <label>Post Category</label>
                        <select name="mna_post_category">
                            <option value="auto" <?php selected( get_option('mna_post_category', 'auto'), 'auto' ); ?>>Automatic (AI Assigned)</option>
                            <?php 
                            foreach( get_categories(['hide_empty' => 0]) as $c ) {
                                echo '<option value="'.$c->term_id.'" '.selected(get_option('mna_post_category'), $c->term_id, false).'>'.$c->name.'</option>';
                            } 
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mna-card">
                <h2>5. AI Image Generation</h2>
                <div class="mna-form-row"><label><input type="checkbox" name="mna_generate_images" value="1" <?php checked( 1, get_option( 'mna_generate_images' ), true ); ?>> <strong>Generate new Featured Images</strong></label></div>
                <div class="mna-form-row"><label>Image Model</label><input type="text" name="mna_image_model" value="<?php echo esc_attr( get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' ) ); ?>"></div>
            </div>

            <?php submit_button( 'Save All Settings', 'primary', 'submit', true, ['style' => 'font-size: 16px; padding: 10px 30px;'] ); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function toggleSourceUI() {
            var mode = $('#mna_source_mode').val();
            $('#mna_firecrawl_wrapper, #mna_rss_wrapper, #mna_gnews_wrapper').hide();
            if (mode === 'firecrawl') $('#mna_firecrawl_wrapper').show();
            else if (mode === 'rss') $('#mna_rss_wrapper').show();
            else if (mode === 'gnews') $('#mna_gnews_wrapper').show();
        }
        $('#mna_source_mode').on('change', toggleSourceUI);
        toggleSourceUI();

        $('.mna-btn-ajax').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var action = btn.data('action');
            var originalText = btn.text();
            btn.text('Processing...').prop('disabled', true);
            $.post(ajaxurl, { action: action }, function(response) {
                alert(response.data || response);
                btn.text(originalText).prop('disabled', false);
            }).fail(function() {
                alert('Request failed.');
                btn.text(originalText).prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}