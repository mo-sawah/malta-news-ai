<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function mna_render_queue_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';

    // Check if table exists
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
        echo '<div class="wrap"><h1>Database Error</h1><p>Table missing. Deactivate and Reactivate plugin.</p></div>';
        return;
    }

    // Fetch items
    $pending_items = $wpdb->get_results( "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC" );
    $published_items = $wpdb->get_results( "SELECT * FROM $table_name WHERE status = 'published' ORDER BY created_at DESC LIMIT 50" );
    
    ?>
    <style>
        .mna-dashboard { max-width: 1200px; margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .mna-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mna-card h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px; color: #23282d; }
        .mna-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .mna-badge-pending { background: #fff4db; color: #d69c00; }
        .mna-badge-published { background: #e5f5fa; color: #008a20; }
        .mna-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .mna-table th, .mna-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .mna-table th { background: #fafafa; font-weight: 600; color: #333; }
        .mna-summary-box { font-size: 13px; color: #444; max-height: 100px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 6px; border: 1px solid #eee; }
        .mna-btn-sm { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; color: #fff; margin-bottom: 4px; display: block; width: 100%; text-align: center; }
        .mna-btn-generate { background: #00a32a; }
        .mna-btn-generate:hover { background: #008a20; }
        .mna-btn-delete { background: #d63638; }
        .mna-btn-delete:hover { background: #b32d2e; }
        code.mna-id { background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #d63638; display: inline-block; margin-top: 5px; }
    </style>

    <div class="wrap mna-dashboard">
        <h1>Queue & Published History</h1>

        <div class="mna-card">
            <h2>Pending AI Generation (<?php echo count($pending_items); ?> in Queue)</h2>
            <?php if ( empty( $pending_items ) ) : ?>
                <p><em>The queue is currently empty.</em></p>
            <?php else : ?>
                <table class="mna-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Date Added</th>
                            <th style="width: 20%;">Suggested Title</th>
                            <th style="width: 45%;">AI Summary / Angle</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending_items as $item ) : ?>
                            <tr id="mna-row-<?php echo esc_attr( $item->id ); ?>">
                                <td><?php echo date( 'M j, H:i', strtotime( $item->created_at ) ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $item->suggested_title ); ?></strong><br>
                                    <code class="mna-id"><?php echo esc_html( $item->source_id ); ?></code>
                                </td>
                                <td><div class="mna-summary-box"><?php echo nl2br( esc_html( $item->ai_summary ) ); ?></div></td>
                                <td><span class="mna-badge mna-badge-pending">Pending</span></td>
                                <td>
                                    <button class="mna-btn-sm mna-btn-generate mna-action-btn" data-action="mna_generate_single" data-id="<?php echo esc_attr( $item->id ); ?>">Write & Publish</button>
                                    <button class="mna-btn-sm mna-btn-delete mna-action-btn" data-action="mna_delete_item" data-id="<?php echo esc_attr( $item->id ); ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="mna-card">
            <h2>Recently Published</h2>
            <?php if ( empty( $published_items ) ) : ?>
                <p><em>No articles have been published via AI yet.</em></p>
            <?php else : ?>
                <table class="mna-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 65%;">Title & Source ID</th>
                            <th style="width: 20%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $published_items as $item ) : ?>
                            <tr>
                                <td><?php echo date( 'M j, H:i', strtotime( $item->created_at ) ); ?></td>
                                <td><strong><?php echo esc_html( $item->suggested_title ); ?></strong> <code class="mna-id"><?php echo esc_html( $item->source_id ); ?></code></td>
                                <td><span class="mna-badge mna-badge-published">Published</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.mna-action-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var action = btn.data('action');
            var item_id = btn.data('id');
            var row = $('#mna-row-' + item_id);
            
            if (action === 'mna_delete_item' && !confirm('Are you sure you want to delete this article from the queue?')) {
                return;
            }

            var originalText = btn.text();
            btn.text('Wait...').prop('disabled', true);
            
            $.post(ajaxurl, {
                action: action,
                item_id: item_id
            }, function(response) {
                if (response.success) {
                    if (action === 'mna_delete_item' || action === 'mna_generate_single') {
                        row.fadeOut(400, function() { $(this).remove(); });
                    }
                    if (action === 'mna_generate_single') {
                        alert(response.data); // Show success message
                    }
                } else {
                    alert('Error: ' + response.data);
                    btn.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert('Request timed out or failed.');
                btn.text(originalText).prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}