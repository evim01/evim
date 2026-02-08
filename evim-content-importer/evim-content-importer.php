<?php
/**
 * Plugin Name: EVIM Content Importer
 * Description: Imports full content from external URLs, removes common ad blocks, downloads images, and creates WordPress posts.
 * Version: 0.1.0
 * Author: EVIM
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/importer.php';

function evim_content_importer_activate() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
}

register_activation_hook(__FILE__, 'evim_content_importer_activate');
