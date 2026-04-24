<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Manual Bulk Triggers (From Settings Page)
add_action( 'wp_ajax_mna_run_step_1', 'mna_ajax_run_step_1' );
function mna_ajax_run_step_1() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    $result = mna_execute_step_1_editor();
    is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( $result );
}

add_action( 'wp_ajax_mna_run_step_2', 'mna_ajax_run_step_2' );
function mna_ajax_run_step_2() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    $result = mna_execute_step_2_writer(); // Pulls oldest item automatically
    is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( $result );
}

// 2. Individual Item Triggers (From Queue Page)
add_action( 'wp_ajax_mna_delete_item', 'mna_ajax_delete_item' );
function mna_ajax_delete_item() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'mna_queue';
    $id = intval( $_POST['item_id'] );
    
    $deleted = $wpdb->delete( $table_name, [ 'id' => $id ], [ '%d' ] );
    if ( $deleted ) {
        wp_send_json_success( 'Item deleted.' );
    } else {
        wp_send_json_error( 'Failed to delete item.' );
    }
}

add_action( 'wp_ajax_mna_generate_single', 'mna_ajax_generate_single' );
function mna_ajax_generate_single() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    
    $id = intval( $_POST['item_id'] );
    // Pass the specific ID to step 2 so it forces that article to be written
    $result = mna_execute_step_2_writer( $id ); 
    
    is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( $result );
}