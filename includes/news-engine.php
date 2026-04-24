<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// WordPress required files for image downloading
require_once( ABSPATH . 'wp-admin/includes/media.php' );
require_once( ABSPATH . 'wp-admin/includes/file.php' );
require_once( ABSPATH . 'wp-admin/includes/image.php' );

function mna_execute_news_cycle() {
    $gnews_api    = get_option( 'mna_gnews_api' );
    $query        = get_option( 'mna_search_query', 'politics' );
    $country      = get_option( 'mna_country_code', 'mt' );
    $or_api       = get_option( 'mna_openrouter_api' );
    
    if ( empty( $gnews_api ) || empty( $or_api ) ) return;

    // 1. Fetch News from GNews API
    $gnews_url = "https://gnews.io/api/v4/search?q=" . urlencode($query) . "&country={$country}&lang=en&max=10&apikey={$gnews_api}";
    $response = wp_remote_get( $gnews_url, array('timeout' => 30) );
    
    if ( is_wp_error( $response ) ) return;
    
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['articles'] ) ) return;

    // 2. Prepare Data for AI (ID, Title, Description only. Save image URL separately)
    $news_pool = [];
    $image_map = [];
    
    foreach ( $body['articles'] as $index => $article ) {
        $article_id = "news_" . $index;
        $news_pool[] = [
            'id'          => $article_id,
            'title'       => $article['title'],
            'description' => $article['description']
        ];
        $image_map[$article_id] = $article['image']; // Store original image
    }

    // 3. Send to OpenRouter (Text Model)
    $text_model = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $agenda     = get_option( 'mna_agenda_prompt' );
    
    $system_prompt = "{$agenda}\n\n" . 
        "You must return ONLY a raw JSON array. Do not include markdown blocks like ```json. " .
        "Format: [ { \"source_id\": \"news_0\", \"title\": \"Your generated headline\", \"content\": \"Your formatted HTML article\", \"image_prompt\": \"A visual description of the article for an image generator\" } ]";

    $or_payload = [
        'model' => $text_model,
        'messages' => [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => json_encode( $news_pool ) ]
        ],
        'response_format' => ['type' => 'json_object'] // Force JSON if model supports it
    ];

    $ai_response = mna_call_openrouter( $or_payload, $or_api );
    if ( ! $ai_response ) return;

    // Parse the generated JSON articles
    // Clean potential markdown from output
    $clean_json = str_replace( ['```json', '```'], '', $ai_response );
    $articles = json_decode( $clean_json, true );
    
    if ( ! is_array( $articles ) ) return;

    // 4. Process and Publish Each Generated Article
    $generate_images = get_option( 'mna_generate_images' ) == '1';
    $image_model     = get_option( 'mna_image_model', 'black-forest-labs/flux.2-pro' );

    foreach ( $articles as $article ) {
        if ( empty( $article['title'] ) || empty( $article['content'] ) ) continue;

        $source_id = $article['source_id'] ?? null;
        $image_url_to_sideload = null;

        // Determine which image to use
        if ( $generate_images && ! empty( $article['image_prompt'] ) ) {
            // Call OpenRouter Image API via modalities parameter
            $img_payload = [
                'model'      => $image_model,
                'messages'   => [ ['role' => 'user', 'content' => $article['image_prompt']] ],
                'modalities' => ["image"] // OpenRouter specific flag for image generation
            ];
            $generated_img_data = mna_call_openrouter_image( $img_payload, $or_api );
            if ( $generated_img_data ) {
                $image_url_to_sideload = $generated_img_data;
            }
        } 
        
        // Fallback to original image if generation failed or is disabled
        if ( ! $image_url_to_sideload && $source_id && isset( $image_map[$source_id] ) ) {
            $image_url_to_sideload = $image_map[$source_id];
        }

        // Insert WordPress Post
        $post_data = array(
            'post_title'   => sanitize_text_field( $article['title'] ),
            'post_content' => wp_kses_post( $article['content'] ),
            'post_status'  => 'publish',
            'post_author'  => 1, // Default to admin ID 1
            'post_category'=> array( get_option('default_category') )
        );
        $post_id = wp_insert_post( $post_data );

        // Attach Image
        if ( ! is_wp_error( $post_id ) && $image_url_to_sideload ) {
            $attachment_id = media_sideload_image( $image_url_to_sideload, $post_id, $article['title'], 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }
    }
}

/**
 * Helper to call OpenRouter Text/JSON APIs
 */
function mna_call_openrouter( $payload, $api_key ) {
    $response = wp_remote_post( '[https://openrouter.ai/api/v1/chat/completions](https://openrouter.ai/api/v1/chat/completions)', [
        'timeout' => 120, // AI takes time
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => site_url(),
            'X-Title'       => 'Malta News AI'
        ],
        'body' => json_encode( $payload )
    ]);

    if ( is_wp_error( $response ) ) return false;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    return $body['choices'][0]['message']['content'] ?? false;
}

/**
 * Helper to call OpenRouter Image APIs
 */
function mna_call_openrouter_image( $payload, $api_key ) {
    $response = wp_remote_post( '[https://openrouter.ai/api/v1/chat/completions](https://openrouter.ai/api/v1/chat/completions)', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => site_url(),
        ],
        'body' => json_encode( $payload )
    ]);

    if ( is_wp_error( $response ) ) return false;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    // OpenRouter returns images in the `images` array of the message object
    if ( isset( $body['choices'][0]['message']['images'][0] ) ) {
        return $body['choices'][0]['message']['images'][0];
    }
    
    return false;
}