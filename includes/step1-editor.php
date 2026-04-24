<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Temporarily reduce RSS cache lifetime to 5 minutes so you always get fresh news during testing
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
        // --- FIRECRAWL MODE (ROUND-ROBIN) ---
        $fc_api = get_option( 'mna_firecrawl_api' );
        $urls_str = get_option( 'mna_firecrawl_urls' );
        $urls = array_values( array_filter( array_map( 'trim', explode( "\n", $urls_str ) ) ) );
        
        if ( empty( $fc_api ) ) return new WP_Error( 'missing_api', 'Firecrawl API key is missing.' );
        if ( empty( $urls ) ) return new WP_Error( 'no_urls', 'No target URLs provided for Firecrawl.' );

        // Get the last index we checked
        $last_index = (int) get_option( 'mna_fc_last_index', 0 );
        
        // If we reached the end of the list, loop back to the start
        if ( $last_index >= count( $urls ) ) {
            $last_index = 0;
        }

        // Pick exactly ONE url to check this round
        $target_url = $urls[$last_index];

        // Update the database so it checks the NEXT url next time
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
            'timeout' => 45, // Wait up to 45 seconds for Firecrawl LLM
            'headers' => [
                'Authorization' => 'Bearer ' . $fc_api,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode( $fc_payload )
        ]);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fc_error', 'Firecrawl connection timed out or failed for: ' . $target_url );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $articles = $body['data']['extract']['articles'] ?? [];
        
        foreach ( $articles as $article ) {
            if ( empty($article['title']) || empty($article['url']) ) continue;
            
            // Fix relative URLs if Firecrawl misses the domain
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

        if ( empty( $news_pool ) ) {
            return "Checked {$target_url} but Firecrawl couldn't find any clear articles right now.";
        }
        
    } elseif ( $mode === 'gnews' ) {
        // --- GNEWS MODE ---
        $gnews_api = get_option( 'mna_gnews_api' );
        $query     = get_option( 'mna_search_query', 'Malta politics' );
        if ( empty( $gnews_api ) ) return new WP_Error( 'missing_api', 'GNews API key missing.' );

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
        // --- RSS MODE ---
        $feeds_str = get_option( 'mna_known_sources' );
        $feed_urls = array_filter( array_map( 'trim', explode( "\n", $feeds_str ) ) );
        if ( empty( $feed_urls ) ) return new WP_Error( 'no_feeds', 'No RSS feeds configured.' );
        
        include_once( ABSPATH . WPINC . '/feed.php' );
        $rss = fetch_feed( $feed_urls );
        
        if ( ! is_wp_error( $rss ) ) {
            $maxitems = $rss->get_item_quantity( 40 );
            $rss_items = $rss->get_items( 0, $maxitems );
            
            foreach ( $rss_items as $item ) {
                $url = $item->get_permalink();
                $image_url = null;
                if ( $enclosure = $item->get_enclosure() ) {
                    $image_url = $enclosure->get_link();
                }
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

    if ( empty( $news_pool ) ) return new WP_Error( 'no_news', 'No news could be fetched from the selected source.' );

    // ==========================================
    // FILTERING PHASE (Remove already processed)
    // ==========================================
    $fresh_news = [];
    $image_map = get_option( 'mna_image_map', [] );

    foreach ( $news_pool as $article ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE source_id = %s", $article['source_id'] ) );
        if ( $exists == 0 ) {
            $fresh_news[] = $article;
            if ( $article['image'] ) {
                $image_map[$article['source_id']] = $article['image'];
            }
        }
    }

    if ( empty( $fresh_news ) ) {
        return ( isset($target_url) ) ? "Checked {$target_url}, but all recent news has already been processed." : "All recent news has already been processed.";
    }
    
    update_option( 'mna_image_map', $image_map );

    // ==========================================
    // AI EVALUATION PHASE
    // ==========================================
    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $editor_prompt = get_option( 'mna_editor_prompt' );
    $web_search    = get_option( 'mna_enable_web_search' ) == '1';
    
    $system_prompt = "{$editor_prompt}\n\n" . 
        "You must return ONLY a raw JSON array. If NO stories meet the criteria, return an empty array: []\n" .
        "Format exactly like this if you find valid stories:\n" .
        "[\n  {\n    \"source_id\": \"(Keep exact ID)\",\n    \"suggested_title\": \"(Your new headline)\",\n    \"ai_summary\": \"(Correspondent assignment & instructions)\"\n  }\n]";

    // Chunk into batches of 10 to send to OpenRouter
    $chunks = array_chunk( $fresh_news, 10 );
    $strikes = 0;
    $added_count = 0;

    foreach ( $chunks as $chunk ) {
        if ( $strikes >= 2 ) break; // Max 2 API calls to OpenRouter to save time/money
        $strikes++;

        $ai_payload_data = [];
        foreach( $chunk as $item ) {
            $ai_payload_data[] = [
                'source_id'   => $item['source_id'],
                'title'       => $item['title'],
                'description' => $item['description']
            ];
        }

        $or_payload = [
            'model' => $text_model,
            'messages' => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user', 'content' => json_encode( $ai_payload_data ) ]
            ],
            'response_format' => ['type' => 'json_object']
        ];
        if ( $web_search ) $or_payload['plugins'] = [ ['id' => 'web'] ];

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
    if ( $added_count > 0 ) {
        return "Step 1 Complete: {$source_msg} Added {$added_count} new article plans to the Queue.";
    } else {
        return "{$source_msg} The AI Editor reviewed the news but found nothing relevant based on your prompt.";
    }
}