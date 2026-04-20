<?php
/**
 * Plugin Name: PK Newsletter
 * Plugin URI: https://mondary.design
 * Description: Lightweight daily digest newsletter with subscriber management, follow.it-inspired email cards, and unsubscribe handling.
 * Version: 2.40
 * Author: cmondary
 * Author URI: https://github.com/mondary
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPKNEWSLETTER_VERSION', '2.40');
define('WPPKNEWSLETTER_FILE', __FILE__);
define('WPPKNEWSLETTER_PATH', plugin_dir_path(__FILE__));
define('WPPKNEWSLETTER_URL', plugin_dir_url(__FILE__));

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if ($hook !== 'plugins.php') {
        return;
    }

    $icon_rel = is_readable(WPPKNEWSLETTER_PATH . 'icon.png') ? 'icon.png' : '';
    if ($icon_rel === '') {
        return;
    }

    $plugin_basename = plugin_basename(WPPKNEWSLETTER_FILE);
    $icon_url = plugins_url($icon_rel, WPPKNEWSLETTER_FILE);
    $handle = 'wppknewsletter-plugins';

    wp_register_style($handle, false, [], WPPKNEWSLETTER_VERSION);
    wp_enqueue_style($handle);

    $row_sel = 'tr[data-plugin="' . esc_attr($plugin_basename) . '"]';
    $css = $row_sel . ' .plugin-icon{'
        . 'background-image:url("' . esc_url($icon_url) . '") !important;'
        . 'background-repeat:no-repeat !important;'
        . 'background-position:center !important;'
        . 'background-size:contain !important;'
        . 'color:transparent !important;}'
        . $row_sel . ' .plugin-icon img{opacity:0 !important;}'
        . $row_sel . ' .plugin-icon svg{opacity:0 !important;}';
    wp_add_inline_style($handle, $css);
});

add_filter('all_plugins', static function (array $plugins): array {
    $plugin_basename = plugin_basename(WPPKNEWSLETTER_FILE);
    if (!isset($plugins[$plugin_basename])) {
        return $plugins;
    }
    if (!is_readable(WPPKNEWSLETTER_PATH . 'icon.png')) {
        return $plugins;
    }

    $icon = plugins_url('icon.png', WPPKNEWSLETTER_FILE);
    $plugins[$plugin_basename]['icons'] = [
        '1x' => $icon,
        '2x' => $icon,
        'default' => $icon,
    ];

    return $plugins;
}, 20);

require_once WPPKNEWSLETTER_PATH . 'includes/class-wppk-newsletter.php';
require_once WPPKNEWSLETTER_PATH . 'includes/class-wppknewsletter-sync.php';

WPPK_Newsletter::boot();
WPPK_Newsletter_Sync::boot();
