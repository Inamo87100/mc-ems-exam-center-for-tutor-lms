<?php

function ajax_user_search($request) {
    // Check for the necessary permissions
    if ( ! current_user_can( 'read' ) ) {
        wp_send_json_error( 'Unauthorized access.' );
        return;
    }

    $search_params = isset($request['search_for']) ? $request['search_for'] : '';
    $search_fields = isset($request['search_fields']) ? array_map('sanitize_text_field', $request['search_fields']) : ['email'];

    // Perform sanitization
    foreach ($search_fields as $field) {
        if (!in_array($field, ['email', 'first_name', 'last_name', 'display_name'])) {
            wp_send_json_error( 'Invalid search fields.' );
            return;
        }
    }

    $search_query = '';
    // Build the search query based on search fields
    foreach ($search_fields as $field) {
        switch ($field) {
            case 'email':
                $search_query .= 'email LIKE %' . $request['search_term'] . '% OR ';
                break;
            case 'first_name':
                $search_query .= 'first_name LIKE %' . $request['search_term'] . '% OR ';
                break;
            case 'last_name':
                $search_query .= 'last_name LIKE %' . $request['search_term'] . '% OR ';
                break;
            case 'display_name':
                $search_query .= 'display_name LIKE %' . $request['search_term'] . '% OR ';
                break;
        }
    }

    // Proctor role filtering
    $proctor_roles = MCEMS_Settings::get_proctor_roles();
    if ($search_params === 'proctor' && !empty($proctor_roles)) {
        $search_query .= 'role IN (' . implode(',', array_map('esc_sql', $proctor_roles)) . ') AND ';
    }

    // Remove trailing OR
    $search_query = rtrim($search_query, ' OR ');

    // Execute the query and get users
    $results = $wpdb->get_results("SELECT id, email, display_name, first_name, last_name, roles FROM users WHERE {$search_query}");

    // Return formatted results
    wp_send_json_success($results);
}

// Your other existing code

