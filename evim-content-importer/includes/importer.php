<?php

if (!defined('ABSPATH')) {
    exit;
}

function evim_content_importer_import_url($url, $fallback_title = '') {
    $response = wp_remote_get($url, array(
        'timeout' => 20,
        'redirection' => 5,
        'user-agent' => 'EVIM Content Importer/0.1 (+WordPress)'
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('evim_http_error', __('Failed to fetch the URL. HTTP status not OK.', 'evim-content-importer'));
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return new WP_Error('evim_empty_body', __('The fetched page was empty.', 'evim-content-importer'));
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($body);
    libxml_clear_errors();

    evim_content_importer_strip_ads($doc);

    $title = $fallback_title;
    if (empty($title)) {
        $title_nodes = $doc->getElementsByTagName('title');
        if ($title_nodes->length > 0) {
            $title = trim($title_nodes->item(0)->textContent);
        }
    }

    if (empty($title)) {
        $title = __('Imported content', 'evim-content-importer');
    }

    $post_id = wp_insert_post(array(
        'post_title' => $title,
        'post_status' => 'draft',
        'post_type' => 'post',
    ), true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    evim_content_importer_process_images($doc, $url, $post_id);

    $content = evim_content_importer_get_body_html($doc);
    $content = wp_kses_post($content);

    wp_update_post(array(
        'ID' => $post_id,
        'post_content' => $content,
    ));

    return $post_id;
}

function evim_content_importer_strip_ads(DOMDocument $doc) {
    $xpath = new DOMXPath($doc);

    $selectors = array(
        '//script',
        '//style',
        '//noscript',
        '//iframe',
        '//aside',
    );

    foreach ($selectors as $selector) {
        foreach ($xpath->query($selector) as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    $ad_xpath = '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ad")
        or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ads")
        or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "sponsor")
        or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "promo")
        or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ad")
        or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ads")
        or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "sponsor")
        or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "promo")
        or @role="advertisement"]';

    foreach ($xpath->query($ad_xpath) as $node) {
        $node->parentNode->removeChild($node);
    }
}

function evim_content_importer_get_body_html(DOMDocument $doc) {
    $body = $doc->getElementsByTagName('body');
    if ($body->length === 0) {
        return '';
    }

    $html = '';
    foreach ($body->item(0)->childNodes as $child) {
        $html .= $doc->saveHTML($child);
    }

    return $html;
}

function evim_content_importer_process_images(DOMDocument $doc, $source_url, $post_id) {
    $images = $doc->getElementsByTagName('img');
    if ($images->length === 0) {
        return;
    }

    $base_url = evim_content_importer_get_base_url($doc, $source_url);

    foreach ($images as $image) {
        $src = $image->getAttribute('src');
        if (empty($src)) {
            continue;
        }

        $absolute = evim_content_importer_make_absolute_url($src, $base_url);
        if (!$absolute) {
            continue;
        }

        $media_url = evim_content_importer_sideload_image($absolute, $post_id);
        if (is_wp_error($media_url)) {
            continue;
        }

        $image->setAttribute('src', $media_url);
    }
}

function evim_content_importer_get_base_url(DOMDocument $doc, $fallback_url) {
    $base_tags = $doc->getElementsByTagName('base');
    if ($base_tags->length > 0) {
        $href = $base_tags->item(0)->getAttribute('href');
        if (!empty($href)) {
            return $href;
        }
    }

    return $fallback_url;
}

function evim_content_importer_make_absolute_url($src, $base_url) {
    if (wp_http_validate_url($src)) {
        return $src;
    }

    $base_parts = wp_parse_url($base_url);
    if (empty($base_parts['scheme']) || empty($base_parts['host'])) {
        return false;
    }

    if (strpos($src, '//') === 0) {
        return $base_parts['scheme'] . ':' . $src;
    }

    if (strpos($src, '/') === 0) {
        return $base_parts['scheme'] . '://' . $base_parts['host'] . $src;
    }

    $path = isset($base_parts['path']) ? $base_parts['path'] : '/';
    $path = preg_replace('#/[^/]*$#', '/', $path);

    return $base_parts['scheme'] . '://' . $base_parts['host'] . $path . ltrim($src, '/');
}

function evim_content_importer_sideload_image($url, $post_id) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_array = array(
        'name' => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    );

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return $attachment_id;
    }

    $image_url = wp_get_attachment_url($attachment_id);
    if (!$image_url) {
        return new WP_Error('evim_media_error', __('Image uploaded but URL missing.', 'evim-content-importer'));
    }

    return $image_url;
}
