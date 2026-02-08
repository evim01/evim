<?php

if (!defined('ABSPATH')) {
    exit;
}

function evim_content_importer_register_menu() {
    add_management_page(
        __('EVIM Content Importer', 'evim-content-importer'),
        __('EVIM Content Importer', 'evim-content-importer'),
        'manage_options',
        'evim-content-importer',
        'evim_content_importer_render_page'
    );
}
add_action('admin_menu', 'evim_content_importer_register_menu');

function evim_content_importer_render_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    if (isset($_GET['evim_imported']) && $_GET['evim_imported'] === '1') {
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if ($post_id) {
            $edit_link = get_edit_post_link($post_id, '');
            $notice = sprintf(
                '<div class="notice notice-success"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('Import complete. Review the draft post:', 'evim-content-importer'),
                esc_url($edit_link),
                esc_html__('Edit Post', 'evim-content-importer')
            );
        }
    }

    if (isset($_GET['evim_error'])) {
        $notice = sprintf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sanitize_text_field(wp_unslash($_GET['evim_error'])))
        );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('EVIM Content Importer', 'evim-content-importer') . '</h1>';
    echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<p>' . esc_html__('Paste a URL to import full content, remove common ad blocks, and download images to your media library.', 'evim-content-importer') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('evim_content_import', 'evim_content_import_nonce');
    echo '<input type="hidden" name="action" value="evim_content_import">';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="evim_import_url">' . esc_html__('Source URL', 'evim-content-importer') . '</label></th>';
    echo '<td><input type="url" id="evim_import_url" name="evim_import_url" class="regular-text" placeholder="https://example.com/article" required></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th scope="row"><label for="evim_import_title">' . esc_html__('Title (optional)', 'evim-content-importer') . '</label></th>';
    echo '<td><input type="text" id="evim_import_title" name="evim_import_title" class="regular-text"></td>';
    echo '</tr>';
    echo '</table>';
    submit_button(__('Import as Draft', 'evim-content-importer'));
    echo '</form>';
    echo '</div>';
}

function evim_content_importer_handle_post() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to import content.', 'evim-content-importer'));
    }

    check_admin_referer('evim_content_import', 'evim_content_import_nonce');

    $url = isset($_POST['evim_import_url']) ? esc_url_raw(wp_unslash($_POST['evim_import_url'])) : '';
    $title = isset($_POST['evim_import_title']) ? sanitize_text_field(wp_unslash($_POST['evim_import_title'])) : '';

    if (empty($url)) {
        wp_safe_redirect(add_query_arg('evim_error', rawurlencode(__('URL is required.', 'evim-content-importer')), admin_url('tools.php?page=evim-content-importer')));
        exit;
    }

    $result = evim_content_importer_import_url($url, $title);

    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg('evim_error', rawurlencode($result->get_error_message()), admin_url('tools.php?page=evim-content-importer')));
        exit;
    }

    wp_safe_redirect(add_query_arg(array(
        'evim_imported' => '1',
        'post_id' => intval($result),
    ), admin_url('tools.php?page=evim-content-importer')));
    exit;
}
add_action('admin_post_evim_content_import', 'evim_content_importer_handle_post');
