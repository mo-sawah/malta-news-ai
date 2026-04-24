<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// AJAX Handler for Step 1 (The Editor)
add_action( 'wp_ajax_mna_run_step_1', 'mna_ajax_run_step_1' );
function mna_ajax_run_step_1() {
    // Security check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized access.' );
    }

    $result = mna_execute_step_1_editor();
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    } else {
        wp_send_json_success( $result );
    }
}

// AJAX Handler for Step 2 (The Writer)
add_action( 'wp_ajax_mna_run_step_2', 'mna_ajax_run_step_2' );
function mna_ajax_run_step_2() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized access.' );
    }

    // We will build mna_execute_step_2_writer() in the next file
    $result = mna_execute_step_2_writer();
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    } else {
        wp_send_json_success( $result );
    }
}