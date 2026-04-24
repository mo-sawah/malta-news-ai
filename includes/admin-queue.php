<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function mna_render_queue_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';

    // Check if table exists (fallback safety)
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
        echo '<div class="wrap"><h1>Database Error</h1><p>The queue table does not exist. Please deactivate and reactivate the plugin to create it.</p></div>';
        return;
    }

    // Fetch items from the database
    $pending_items = $wpdb->get_results( "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC" );
    $published_items = $wpdb->get_results( "SELECT * FROM $table_name WHERE status = 'published' ORDER BY created_at DESC LIMIT 50" );
    
    ?>
    <style>
        .mna-dashboard { max-width: 1100px; margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .mna-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mna-card h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px; color: #23282d; }
        .mna-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .mna-badge-pending { background: #fff4db; color: #d69c00; }
        .mna-badge-published { background: #e5f5fa; color: #008a20; }
        .mna-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .mna-table th, .mna-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .mna-table th { background: #fafafa; font-weight: 600; color: #333; }
        .mna-table tr:hover { background-color: #fcfcfc; }
        .mna-table tr:last-child td { border-bottom: none; }
        .mna-summary-box { font-size: 13px; color: #444; max-height: 100px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 6px; border: 1px solid #eee; line-height: 1.5; }
        code.mna-id { background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #d63638; }
    </style>

    <div class="wrap mna-dashboard">
        <h1>Queue & Published History</h1>
        <p style="font-size: 14px; color: #555; margin-bottom: 20px;">
            This dashboard monitors your editorial pipeline. "Pending" articles have been planned by the AI Editor and are waiting to be written. "Published" articles have been successfully drafted and posted to your WordPress site.
        </p>

        <div class="mna-card">
            <h2>Pending AI Generation (<?php echo count($pending_items); ?> in Queue)</h2>
            <?php if ( empty( $pending_items ) ) : ?>
                <p style="color: #666;"><em>The queue is currently empty. Run Step 1 (The Editor) from the Settings page to fetch and evaluate new articles.</em></p>
            <?php else : ?>
                <table class="mna-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Date Added</th>
                            <th style="width: 25%;">Suggested Title & ID</th>
                            <th style="width: 50%;">AI Summary & Editorial Angle</th>
                            <th style="width: 10%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending_items as $item ) : ?>
                            <tr>
                                <td><?php echo date( 'M j, Y H:i', strtotime( $item->created_at ) ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $item->suggested_title ); ?></strong><br><br>
                                    <code class="mna-id">ID: <?php echo esc_html( $item->source_id ); ?></code>
                                </td>
                                <td><div class="mna-summary-box"><?php echo nl2br( esc_html( $item->ai_summary ) ); ?></div></td>
                                <td><span class="mna-badge mna-badge-pending">Pending</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="mna-card">
            <h2>Recently Published History</h2>
            <?php if ( empty( $published_items ) ) : ?>
                <p style="color: #666;"><em>No articles have been published via AI yet.</em></p>
            <?php else : ?>
                <table class="mna-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Date Published</th>
                            <th style="width: 15%;">Source ID</th>
                            <th style="width: 55%;">Title</th>
                            <th style="width: 10%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $published_items as $item ) : ?>
                            <tr>
                                <td><?php echo date( 'M j, Y H:i', strtotime( $item->created_at ) ); ?></td>
                                <td><code class="mna-id"><?php echo esc_html( $item->source_id ); ?></code></td>
                                <td><strong><?php echo esc_html( $item->suggested_title ); ?></strong></td>
                                <td><span class="mna-badge mna-badge-published">Published</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}