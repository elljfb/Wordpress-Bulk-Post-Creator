<?php
/*
Plugin Name: Simple Bulk Post Creator
Description: Create multiple posts from a CSV file
Version: 1.0
Author: Elliot Fagan-Briggs
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add menu item to WordPress admin
add_action('admin_menu', 'bpc_add_admin_menu');
function bpc_add_admin_menu() {
    add_menu_page(
        'Bulk Post Creator',
        'Bulk Post Creator',
        'manage_options',
        'bulk-post-creator',
        'bpc_admin_page',
        'dashicons-upload',
        30
    );
}

// Create the admin page HTML
function bpc_admin_page() {
    ?>
    <div class="wrap">
        <h1>Bulk Post Creator</h1>
        <div class="notice notice-info">
            <p>Upload a CSV file with these columns: post title, post content, post category (optional)</p>
            <p>You can create your CSV file using Excel, Google Sheets, or any spreadsheet software - just save/export as CSV format.</p>
        </div>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('bpc_upload_nonce', 'bpc_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="csvfile">Upload CSV File</label></th>
                    <td>
                        <input type="file" name="csvfile" id="csvfile" accept=".csv" required>
                        <p class="description">Upload a CSV file with your post data.</p>
                    </td>
                </tr>
                <tr>
                    <th>Post Status</th>
                    <td>
                        <select name="post_status">
                            <option value="draft">Draft</option>
                            <option value="publish">Published</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="bpc_submit" class="button button-primary" value="Create Posts">
            </p>
        </form>
    </div>
    <?php
}

// Handle form submission
add_action('admin_init', 'bpc_handle_upload');
function bpc_handle_upload() {
    if (!isset($_POST['bpc_submit'])) {
        return;
    }

    // Verify nonce
    if (!isset($_POST['bpc_nonce']) || !wp_verify_nonce($_POST['bpc_nonce'], 'bpc_upload_nonce')) {
        wp_die('Security check failed');
    }

    // Check file upload
    if (!isset($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('bulk-post-creator', 'file-upload', 'Error uploading file', 'error');
        return;
    }

    // Get post status
    $post_status = isset($_POST['post_status']) ? $_POST['post_status'] : 'draft';

    // Process CSV file
    $file = fopen($_FILES['csvfile']['tmp_name'], 'r');
    if ($file === false) {
        add_settings_error('bulk-post-creator', 'file-open', 'Error opening CSV file', 'error');
        return;
    }

    // Read headers
    $headers = array_map('strtolower', array_map('trim', fgetcsv($file)));
    
    // Find column indexes
    $title_index = array_search('post title', $headers);
    $content_index = array_search('post content', $headers);
    $category_index = array_search('post category', $headers);

    if ($title_index === false || $content_index === false) {
        add_settings_error(
            'bulk-post-creator', 
            'missing-columns', 
            'CSV file must contain "post title" and "post content" columns', 
            'error'
        );
        fclose($file);
        return;
    }

    $posts_created = 0;
    $errors = [];

    // Process each row
    while (($row = fgetcsv($file)) !== false) {
        // Skip empty rows
        if (empty($row[$title_index]) && empty($row[$content_index])) {
            continue;
        }

        // Prepare post data
        $post_data = array(
            'post_title' => sanitize_text_field($row[$title_index]),
            'post_content' => wp_kses_post($row[$content_index]),
            'post_status' => $post_status,
            'post_type' => 'post'
        );

        // Create the post
        $post_id = wp_insert_post($post_data, true);

        if (!is_wp_error($post_id)) {
            $posts_created++;

            // Set category if provided
            if ($category_index !== false && !empty($row[$category_index])) {
                $category = sanitize_text_field($row[$category_index]);
                $term = term_exists($category, 'category');
                
                if (!$term) {
                    $term = wp_insert_term($category, 'category');
                }
                
                if (!is_wp_error($term)) {
                    wp_set_post_categories($post_id, [$term['term_id']], false);
                }
            }
        } else {
            $errors[] = "Error creating post: " . $post_id->get_error_message();
        }
    }

    fclose($file);

    // Display results
    if ($posts_created > 0) {
        add_settings_error(
            'bulk-post-creator',
            'posts-created',
            "Successfully created {$posts_created} posts",
            'success'
        );
    }

    if (!empty($errors)) {
        add_settings_error(
            'bulk-post-creator',
            'creation-errors',
            implode('<br>', $errors),
            'error'
        );
    }
}

// Add settings errors display
add_action('admin_notices', function() {
    settings_errors('bulk-post-creator');
});
