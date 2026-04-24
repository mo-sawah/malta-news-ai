<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'wp_feed_cache_transient_lifetime', function(){ return 300; } );

function mna_execute_step_1_editor() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';
    $or_api = get_option( 'mna_openrouter_api' );
    
    if ( empty( $or_api ) ) return new WP_Error( 'missing_api', 'OpenRouter API key is missing.' );

    $mode = get_option( 'mna_source_mode', 'firecrawl' );
    $news_pool = [];

    // ==========================================
    // DATA GATHERING PHASE
    // ==========================================
    if ( $mode === 'firecrawl' ) {
        $fc_api = get_option( 'mna_firecrawl_api' );
        $urls_str = get_option( 'mna_firecrawl_urls' );
        $urls = array_values( array_filter( array_map( 'trim', explode( "\n", $urls_str ) ) ) );
        
        if ( empty( $fc_api ) ) return new WP_Error( 'missing_api', 'Firecrawl API missing.' );
        if ( empty( $urls ) ) return new WP_Error( 'no_urls', 'No URLs for Firecrawl.' );

        $last_index = (int) get_option( 'mna_fc_last_index', 0 );
        if ( $last_index >= count( $urls ) ) $last_index = 0;
        $target_url = $urls[$last_index];
        update_option( 'mna_fc_last_index', $last_index + 1 );

        $fc_payload = [
            'url' => $target_url,
            'formats' => ['extract'],
            'extract' => [
                'prompt' => 'Extract the 10 most recent news articles from this page. Ignore ads and sidebars.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'articles' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'url' => ['type' => 'string'],
                                    'image' => ['type' => 'string']
                                ],
                                'required' => ['title', 'url']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = wp_remote_post( 'https://api.firecrawl.dev/v1/scrape', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $fc_api,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode( $fc_payload )
        ]);

        if ( is_wp_error( $response ) ) return new WP_Error( 'fc_error', 'Firecrawl timeout: ' . $target_url );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $articles = $body['data']['extract']['articles'] ?? [];
        
        foreach ( $articles as $article ) {
            if ( empty($article['title']) || empty($article['url']) ) continue;
            
            $article_url = $article['url'];
            if ( strpos($article_url, '/') === 0 ) {
                $parsed = parse_url($target_url);
                $article_url = $parsed['scheme'] . '://' . $parsed['host'] . $article_url;
            }
            
            $news_pool[] = [
                'source_id'   => 'fc_' . md5( $article_url ),
                'title'       => $article['title'],
                'description' => $article['description'] ?? '',
                'url'         => $article_url,
                'image'       => $article['image'] ?? null
            ];
        }
        if ( empty( $news_pool ) ) return "Checked {$target_url} but no clear articles found.";
        
    } elseif ( $mode === 'gnews' ) {
        // GNEWS Logic...
        $gnews_api = get_option( 'mna_gnews_api' );
        $query     = get_option( 'mna_search_query', 'Malta politics' );
        if ( empty( $gnews_api ) ) return new WP_Error( 'missing_api', 'GNews API missing.' );

        $exact_query = trim( "Malta " . $query );
        $gnews_url = "https://gnews.io/api/v4/search?q=" . urlencode($exact_query) . "&lang=en&max=30&apikey={$gnews_api}";
        $response = wp_remote_get( $gnews_url, ['timeout' => 30] );
        
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( !empty( $body['articles'] ) ) {
                foreach ( $body['articles'] as $article ) {
                    $news_pool[] = [
                        'source_id'   => 'gnews_' . md5( $article['url'] ),
                        'title'       => $article['title'],
                        'description' => $article['description'],
                        'url'         => $article['url'],
                        'image'       => $article['image'] ?? null
                    ];
                }
            }
        }
    } else {
        // RSS Logic...
        $feeds_str = get_option( 'mna_known_sources' );
        $feed_urls = array_filter( array_map( 'trim', explode( "\n", $feeds_str ) ) );
        if ( empty( $feed_urls ) ) return new WP_Error( 'no_feeds', 'No RSS feeds.' );
        
        include_once( ABSPATH . WPINC . '/feed.php' );
        $rss = fetch_feed( $feed_urls );
        
        if ( ! is_wp_error( $rss ) ) {
            $maxitems = $rss->get_item_quantity( 40 );
            $rss_items = $rss->get_items( 0, $maxitems );
            foreach ( $rss_items as $item ) {
                $url = $item->get_permalink();
                $image_url = $item->get_enclosure() ? $item->get_enclosure()->get_link() : null;
                $news_pool[] = [
                    'source_id'   => 'rss_' . md5( $url ),
                    'title'       => $item->get_title(),
                    'description' => wp_trim_words( strip_tags( $item->get_description() ), 40 ),
                    'url'         => $url,
                    'image'       => $image_url
                ];
            }
        }
    }

    if ( empty( $news_pool ) ) return new WP_Error( 'no_news', 'No news fetched.' );

    // ==========================================
    // FILTERING PHASE
    // ==========================================
    $fresh_news = [];
    $image_map = get_option( 'mna_image_map', [] );

    foreach ( $news_pool as $article ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE source_id = %s", $article['source_id'] ) );
        if ( $exists == 0 ) {
            $fresh_news[] = $article;
            if ( $article['image'] ) $image_map[$article['source_id']] = $article['image'];
        }
    }

    if ( empty( $fresh_news ) ) return isset($target_url) ? "Checked {$target_url}, all news already processed." : "All news already processed.";
    update_option( 'mna_image_map', $image_map );

    // ==========================================
    // AI EVALUATION PHASE
    // ==========================================
    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $editor_prompt = get_option( 'mna_editor_prompt' );
    
    // UPDATED PROMPT: Now asks for 'image_prompt'
    $system_prompt = "{$editor_prompt}\n\n" . 
        "You must return ONLY a raw JSON array. If NO stories meet criteria, return: []\n" .
        "Format exactly like this for valid stories:\n" .
        "[\n  {\n    \"source_id\": \"(Keep exact ID)\",\n    \"suggested_title\": \"(Your new headline)\",\n    \"ai_summary\": \"(Correspondent assignment & instructions)\",\n    \"image_prompt\": \"(A highly detailed visual description of the article for an AI image generator. Retro 8-bit or pixel art style.)\"\n  }\n]";

    $chunks = array_chunk( $fresh_news, 10 );
    $strikes = 0;
    $added_count = 0;

    foreach ( $chunks as $chunk ) {
        if ( $strikes >= 2 ) break; 
        $strikes++;

        $ai_payload_data = [];
        foreach( $chunk as $item ) {
            $ai_payload_data[] = ['source_id' => $item['source_id'], 'title' => $item['title'], 'description' => $item['description']];
        }

        $or_payload = [
            'model' => $text_model,
            'messages' => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user', 'content' => json_encode( $ai_payload_data ) ]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $ai_response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $or_api,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
                'X-Title'       => 'Malta News AI Editor'
            ],
            'body' => json_encode( $or_payload )
        ]);

        if ( is_wp_error( $ai_response ) ) continue; 
        
        $ai_body = json_decode( wp_remote_retrieve_body( $ai_response ), true );
        $content = $ai_body['choices'][0]['message']['content'] ?? '[]';
        
        $clean_json = str_replace( ['```json', '```'], '', $content );
        $approved_articles = json_decode( trim( $clean_json ), true );

        if ( is_array( $approved_articles ) && count( $approved_articles ) > 0 ) {
            foreach ( $approved_articles as $article ) {
                if ( empty( $article['source_id'] ) || empty( $article['ai_summary'] ) ) continue;

                $original_url = '';
                foreach( $fresh_news as $fn ) {
                    if ( $fn['source_id'] === $article['source_id'] ) $original_url = $fn['url'];
                }

                $wpdb->insert(
                    $table_name,
                    [
                        'source_id'       => sanitize_text_field( $article['source_id'] ),
                        'source_url'      => esc_url_raw( $original_url ),
                        'suggested_title' => sanitize_text_field( $article['suggested_title'] ),
                        'ai_summary'      => sanitize_textarea_field( $article['ai_summary'] ),
                        'image_prompt'    => sanitize_textarea_field( $article['image_prompt'] ?? '' ), // NEW
                        'status'          => 'pending',
                        'created_at'      => current_time( 'mysql' )
                    ]
                );
                $added_count++;
            }
            break; 
        }
    }

    $source_msg = isset($target_url) ? "Checked {$target_url}." : "";
    return $added_count > 0 ? "Step 1 Complete: {$source_msg} Added {$added_count} plans." : "{$source_msg} No relevant news found.";
}