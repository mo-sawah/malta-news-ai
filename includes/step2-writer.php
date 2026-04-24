<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function mna_execute_step_2_writer( $specific_id = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';

    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );

    $or_api = get_option( 'mna_openrouter_api' );
    if ( empty( $or_api ) ) return new WP_Error( 'missing_api', 'OpenRouter API key is missing.' );

    if ( $specific_id ) {
        $pending_item = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_name WHERE status = 'pending' AND id = %d", $specific_id) );
    } else {
        $pending_item = $wpdb->get_row( "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1" );
    }
    
    if ( ! $pending_item ) return new WP_Error('empty_queue', 'No pending articles found to write.');

    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $writer_prompt = get_option( 'mna_writer_prompt' );
    $web_search    = get_option( 'mna_enable_web_search' ) == '1';
    
    $system_prompt = "{$writer_prompt}\n\n" . 
        "You must return ONLY a raw JSON object. Format exactly like this:\n" .
        "{\n" .
        "  \"final_title\": \"(The highly engaging final headline)\",\n" .
        "  \"content\": \"(The full 400-600 word article formatted using ONLY <p> tags.)\"\n" .
        "}";

    $user_content = "Draft the article based on this editorial plan:\n" . json_encode([
        'planned_title'  => $pending_item->suggested_title,
        'editor_summary' => $pending_item->ai_summary,
        'source_url'     => $pending_item->source_url
    ]);

    if ( $web_search ) {
        $user_content .= "\nCRITICAL INSTRUCTION: Use your web search capabilities to visit '{$pending_item->source_url}' and read the full article to gather the deep facts, quotes, and context before writing the article.";
    }

    $or_payload = [
        'model' => $text_model,
        'messages' => [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => $user_content ]
        ],
        'response_format' => ['type' => 'json_object']
    ];

    if ( $web_search ) $or_payload['plugins'] = [ ['id' => 'web'] ];

    $ai_response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
        'timeout' => 120, 
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

    $clean_json = str_replace( ['```json', '```'], '', $content );
    $draft = json_decode( trim( $clean_json ), true );

    if ( ! is_array( $draft ) || empty( $draft['content'] ) ) {
        return new WP_Error( 'json_error', 'AI did not return valid JSON.' );
    }

    $generate_images = get_option( 'mna_generate_images' ) == '1';
    $image_model     = get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' );
    $image_url_to_sideload = null;

    if ( $generate_images && ! empty( $pending_item->image_prompt ) ) {
        $img_payload = [
            'model'      => $image_model,
            'messages'   => [ ['role' => 'user', 'content' => $pending_item->image_prompt] ],
            'modalities' => ["image"]
        ];
        
        $img_response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
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

    if ( ! $image_url_to_sideload ) {
        $image_map = get_option( 'mna_image_map', [] );
        if ( isset( $image_map[ $pending_item->source_id ] ) ) {
            $image_url_to_sideload = $image_map[ $pending_item->source_id ];
        }
    }

    // ==========================================
    // DYNAMIC AUTHOR & CATEGORY ASSIGNMENT
    // ==========================================
    $post_author_setting   = get_option( 'mna_post_author', 'auto' );
    $post_category_setting = get_option( 'mna_post_category', 'auto' );

    // Check if set to auto. If yes, use the AI's choice from the DB. If no, use the hardcoded choice.
    $final_author   = ( $post_author_setting === 'auto' ) ? $pending_item->author_id : (int) $post_author_setting;
    $final_category = ( $post_category_setting === 'auto' ) ? $pending_item->category_id : (int) $post_category_setting;

    $post_data = [
        'post_title'   => sanitize_text_field( $draft['final_title'] ),
        'post_content' => wp_kses_post( $draft['content'] ) . wp_kses_post( $source_credit ),
        'post_status'  => 'publish',
        'post_author'  => $final_author, // DYNAMIC!
        'post_category'=> [ $final_category ] // DYNAMIC!
    ];
    
    $post_id = wp_insert_post( $post_data );

    if ( is_wp_error( $post_id ) ) return new WP_Error( 'post_error', 'Failed to insert post into WP.' );

    if ( $image_url_to_sideload ) {
        $attachment_id = media_sideload_image( $image_url_to_sideload, $post_id, $draft['final_title'], 'id' );
        if ( ! is_wp_error( $attachment_id ) ) set_post_thumbnail( $post_id, $attachment_id );
    }

    $wpdb->update(
        $table_name,
        [ 'status' => 'published' ],
        [ 'id' => $pending_item->id ],
        [ '%s' ],
        [ '%d' ]
    );

    return "Success: Drafted and published '{$draft['final_title']}'!";
}