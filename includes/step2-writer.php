<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function mna_execute_step_2_writer() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';

    // 0. Load WP media files ONLY when executing (prevents 504 timeouts)
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );

    $or_api = get_option( 'mna_openrouter_api' );
    if ( empty( $or_api ) ) {
        return new WP_Error( 'missing_api', 'OpenRouter API key is missing.' );
    }

    // 1. Get ONE pending article from the queue
    $pending_item = $wpdb->get_row( "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1" );
    
    if ( ! $pending_item ) {
        return 'Queue is empty. No pending articles to write. Run Step 1 first.';
    }

    // 2. Prepare the Writer AI Prompt
    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $writer_prompt = get_option( 'mna_writer_prompt' );
    $web_search    = get_option( 'mna_enable_web_search' ) == '1';
    
    $system_prompt = "{$writer_prompt}\n\n" . 
        "You must return ONLY a raw JSON object. Do not include markdown blocks like ```json.\n" .
        "Format exactly like this:\n" .
        "{\n" .
        "  \"final_title\": \"(The highly engaging final headline)\",\n" .
        "  \"content\": \"(The full 600-800 word article formatted in HTML using <h2>, <p>, etc. Do not include <h1> or <html> tags.)\",\n" .
        "  \"image_prompt\": \"(A highly detailed visual description of the article for an AI image generator)\"\n" .
        "}";

    $user_content = json_encode([
        'planned_title'  => $pending_item->suggested_title,
        'editor_summary' => $pending_item->ai_summary,
        'source_url'     => $pending_item->source_url
    ]);

    $or_payload = [
        'model' => $text_model,
        'messages' => [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => "Draft the article based on this editorial plan:\n" . $user_content ]
        ],
        'response_format' => ['type' => 'json_object']
    ];

    if ( $web_search ) {
        $or_payload['plugins'] = [ ['id' => 'web'] ];
    }

    // 3. Call AI to write the article
    $ai_response = wp_remote_post( '[https://openrouter.ai/api/v1/chat/completions](https://openrouter.ai/api/v1/chat/completions)', [
        'timeout' => 120, // Give the AI time to write a long article
        'headers' => [
            'Authorization' => 'Bearer ' . $or_api,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => site_url(),
            'X-Title'       => 'Malta News AI Writer'
        ],
        'body' => json_encode( $or_payload )
    ]);

    if ( is_wp_error( $ai_response ) ) return $ai_response;
    
    $ai_body = json_decode( wp_remote_retrieve_body( $ai_response ), true );
    $content = $ai_body['choices'][0]['message']['content'] ?? false;
    
    if ( ! $content ) return new WP_Error( 'ai_error', 'AI Writer failed to generate content.' );

    // Clean JSON
    $clean_json = str_replace( ['```json', '```'], '', $content );
    $draft = json_decode( trim( $clean_json ), true );

    if ( ! is_array( $draft ) || empty( $draft['content'] ) ) {
        return new WP_Error( 'json_error', 'AI did not return the article in the requested JSON format.' );
    }

    // 4. Handle Featured Image
    $generate_images = get_option( 'mna_generate_images' ) == '1';
    $image_model     = get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' );
    $image_url_to_sideload = null;

    if ( $generate_images && ! empty( $draft['image_prompt'] ) ) {
        // Generate AI Image
        $img_payload = [
            'model'      => $image_model,
            'messages'   => [ ['role' => 'user', 'content' => $draft['image_prompt']] ],
            'modalities' => ["image"]
        ];
        
        $img_response = wp_remote_post( '[https://openrouter.ai/api/v1/chat/completions](https://openrouter.ai/api/v1/chat/completions)', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $or_api,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
            ],
            'body' => json_encode( $img_payload )
        ]);

        if ( ! is_wp_error( $img_response ) ) {
            $img_body = json_decode( wp_remote_retrieve_body( $img_response ), true );
            if ( isset( $img_body['choices'][0]['message']['images'][0] ) ) {
                $image_url_to_sideload = $img_body['choices'][0]['message']['images'][0];
            }
        }
    }

    // Fallback to original GNews Image if AI generation failed or is disabled
    if ( ! $image_url_to_sideload ) {
        $image_map = get_option( 'mna_image_map', [] );
        if ( isset( $image_map[ $pending_item->source_id ] ) ) {
            $image_url_to_sideload = $image_map[ $pending_item->source_id ];
        }
    }

    // 5. Publish to WordPress
    $post_author   = get_option( 'mna_post_author', 1 );
    $post_category = get_option( 'mna_post_category', get_option('default_category') );
    $source_credit = "\n\n<p><em>Source URL: <a href='" . esc_url( $pending_item->source_url ) . "' target='_blank'>Reference Link</a></em></p>";

    $post_data = [
        'post_title'   => sanitize_text_field( $draft['final_title'] ),
        'post_content' => wp_kses_post( $draft['content'] ) . wp_kses_post( $source_credit ),
        'post_status'  => 'publish',
        'post_author'  => (int) $post_author,
        'post_category'=> [ (int) $post_category ]
    ];
    
    $post_id = wp_insert_post( $post_data );

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'post_error', 'Failed to insert the post into WordPress.' );
    }

    // Attach Image
    if ( $image_url_to_sideload ) {
        $attachment_id = media_sideload_image( $image_url_to_sideload, $post_id, $draft['final_title'], 'id' );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    // 6. Update Queue Status to 'published'
    $wpdb->update(
        $table_name,
        [ 'status' => 'published' ],
        [ 'id' => $pending_item->id ],
        [ '%s' ],
        [ '%d' ]
    );

    return "Success: Drafted and published '{$draft['final_title']}'!";
}