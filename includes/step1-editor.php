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
        // --- FIRECRAWL MODE ---
        $fc_api = get_option( 'mna_firecrawl_api' );
        $urls_str = get_option( 'mna_firecrawl_urls' );
        $urls = array_filter( array_map( 'trim', explode( "\n", $urls_str ) ) );
        
        if ( empty( $fc_api ) ) return new WP_Error( 'missing_api', 'Firecrawl API key is missing.' );
        if ( empty( $urls ) ) return new WP_Error( 'no_urls', 'No target URLs provided for Firecrawl.' );

        // Limit to top 3 URLs per run to prevent server timeout
        foreach ( array_slice( $urls, 0, 3 ) as $url ) {
            $fc_payload = [
                'url' => $url,
                'formats' => ['extract'],
                'extract' => [
                    'prompt' => 'Extract the 15 most recent news articles from this page. Ignore ads and sidebars.',
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
                'timeout' => 45, // Firecrawl LLM extraction takes a few seconds
                'headers' => [
                    'Authorization' => 'Bearer ' . $fc_api,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode( $fc_payload )
            ]);

            if ( ! is_wp_error( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $articles = $body['data']['extract']['articles'] ?? [];
                
                foreach ( $articles as $article ) {
                    if ( empty($article['title']) || empty($article['url']) ) continue;
                    
                    // Fix relative URLs if Firecrawl misses the domain
                    $article_url = $article['url'];
                    if ( strpos($article_url, '/') === 0 ) {
                        $parsed = parse_url($url);
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
            }
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

    if ( empty( $news_pool ) ) return new WP_Error( 'no_news', 'No news could be fetched from the selected sources.' );

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

    if ( empty( $fresh_news ) ) return 'All recent news has already been processed. No new items added to the queue.';
    update_option( 'mna_image_map', $image_map );

    // ==========================================
    // AI EVALUATION PHASE (The 3-Strike Loop)
    // ==========================================
    $text_model    = get_option( 'mna_text_model', 'anthropic/claude-3.5-sonnet' );
    $editor_prompt = get_option( 'mna_editor_prompt' );
    $web_search    = get_option( 'mna_enable_web_search' ) == '1';
    
    $system_prompt = "{$editor_prompt}\n\n" . 
        "You must return ONLY a raw JSON array. If NO stories meet the criteria, return an empty array: []\n" .
        "Format exactly like this if you find valid stories:\n" .
        "[\n  {\n    \"source_id\": \"(Keep exact ID)\",\n    \"suggested_title\": \"(Your new headline)\",\n    \"ai_summary\": \"(Correspondent assignment & instructions)\"\n  }\n]";

    // Chunk into batches of 10
    $chunks = array_chunk( $fresh_news, 10 );
    $strikes = 0;
    $added_count = 0;

    foreach ( $chunks as $chunk ) {
        if ( $strikes >= 3 ) break; // Max 3 API calls per run to save money
        $strikes++;

        // Clean chunk for AI (don't send images to save tokens)
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
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $or_api,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
                'X-Title'       => 'Malta News AI Editor'
            ],
            'body' => json_encode( $or_payload )
        ]);

        if ( is_wp_error( $ai_response ) ) continue; // If API fails, try next chunk
        
        $ai_body = json_decode( wp_remote_retrieve_body( $ai_response ), true );
        $content = $ai_body['choices'][0]['message']['content'] ?? '[]';
        
        $clean_json = str_replace( ['```json', '```'], '', $content );
        $approved_articles = json_decode( trim( $clean_json ), true );

        // If the AI found valid political articles, add them and BREAK the loop
        if ( is_array( $approved_articles ) && count( $approved_articles ) > 0 ) {
            foreach ( $approved_articles as $article ) {
                if ( empty( $article['source_id'] ) || empty( $article['ai_summary'] ) ) continue;

                // Find original URL
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
            break; // We found the gold! Stop checking the rest of the chunks.
        }
    }

    if ( $added_count > 0 ) {
        return "Step 1 Complete: Added {$added_count} new article plans to the Pending Queue (Took {$strikes} attempts).";
    } else {
        return "Checked {$strikes} batches of news, but the AI deemed none of them relevant based on your prompt.";
    }
}