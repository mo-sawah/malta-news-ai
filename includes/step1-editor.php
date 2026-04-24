<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function mna_execute_step_1_editor() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';

    $gnews_api = get_option( 'mna_gnews_api' );
    $or_api    = get_option( 'mna_openrouter_api' );
    $query     = get_option( 'mna_search_query', 'politics' );
    $country   = get_option( 'mna_country_code', 'mt' );
    
    if ( empty( $gnews_api ) || empty( $or_api ) ) {
        return new WP_Error( 'missing_api', 'GNews or OpenRouter API key is missing.' );
    }

    // 1. Fetch News from GNews API
    $gnews_url = "https://gnews.io/api/v4/search?q=" . urlencode($query) . "&country={$country}&lang=en&max=10&apikey={$gnews_api}";
    $response = wp_remote_get( $gnews_url, ['timeout' => 30] );
    
    if ( is_wp_error( $response ) ) return $response;
    
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['articles'] ) ) return new WP_Error( 'no_news', 'No news found for this query.' );

    // 2. Prepare data & Filter out articles we've already processed
    $news_pool = [];
    $image_map = get_option( 'mna_image_map', [] ); // Store images for Step 2
    $added_count = 0;

    foreach ( $body['articles'] as $article ) {
        // Create a unique ID based on the article URL
        $source_id = 'gnews_' . md5( $article['url'] );

        // Check if this article is already in our queue/history
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE source_id = %s", $source_id ) );
        if ( $exists > 0 ) continue; // Skip duplicates

        $news_pool[] = [
            'source_id'   => $source_id,
            'title'       => $article['title'],
            'description' => $article['description'],
            'url'         => $article['url']
        ];
        
        // Save the image URL mapped to the ID so the Writer (Step 2) can find it later
        $image_map[$source_id] = $article['image'];
    }

    if ( empty( $news_pool ) ) {
        return 'All recent news has already been processed. No new items added to the queue.';
    }

    // Save updated image map
    update_option( 'mna_image_map', $image_map );

    // 3. Send to OpenRouter (The Editor)
    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $editor_prompt = get_option( 'mna_editor_prompt' );
    $web_search    = get_option( 'mna_enable_web_search' ) == '1';
    
    $system_prompt = "{$editor_prompt}\n\n" . 
        "You must return ONLY a raw JSON array. Format exactly like this:\n" .
        "[\n  {\n    \"source_id\": \"(Keep the exact source_id provided)\",\n    \"suggested_title\": \"(Your new engaging headline)\",\n    \"ai_summary\": \"(Detailed instructions and angle for the writer)\"\n  }\n]";

    $or_payload = [
        'model' => $text_model,
        'messages' => [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => json_encode( $news_pool ) ]
        ],
        'response_format' => ['type' => 'json_object']
    ];

    // Enable OpenRouter Web Search if checked
    if ( $web_search ) {
        $or_payload['plugins'] = [ ['id' => 'web'] ];
    }

    // API Call
    $ai_response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $or_api,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => site_url(),
            'X-Title'       => 'Malta News AI Editor'
        ],
        'body' => json_encode( $or_payload )
    ]);

    if ( is_wp_error( $ai_response ) ) return $ai_response;
    
    $ai_body = json_decode( wp_remote_retrieve_body( $ai_response ), true );
    $content = $ai_body['choices'][0]['message']['content'] ?? false;
    
    if ( ! $content ) return new WP_Error( 'ai_error', 'Failed to get a valid response from the AI.' );

    // Clean Markdown and parse JSON
    $clean_json = str_replace( ['```json', '```'], '', $content );
    $approved_articles = json_decode( trim( $clean_json ), true );

    if ( ! is_array( $approved_articles ) ) {
        return new WP_Error( 'json_error', 'AI did not return valid JSON.' );
    }

    // 4. Insert approved plans into the Queue Database
    foreach ( $approved_articles as $article ) {
        if ( empty( $article['source_id'] ) || empty( $article['ai_summary'] ) ) continue;

        // Find the original URL for DB saving
        $original_url = '';
        foreach( $news_pool as $np ) {
            if ( $np['source_id'] === $article['source_id'] ) $original_url = $np['url'];
        }

        $wpdb->insert(
            $table_name,
            [
                'source_id'       => sanitize_text_field( $article['source_id'] ),
                'source_url'      => esc_url_raw( $original_url ),
                'suggested_title' => sanitize_text_field( $article['suggested_title'] ),
                'ai_summary'      => sanitize_textarea_field( $article['ai_summary'] ),
                'status'          => 'pending',
                'created_at'      => current_time( 'mysql' )
            ]
        );
        $added_count++;
    }

    return "Step 1 Complete: Added {$added_count} new article plans to the Pending Queue.";
}