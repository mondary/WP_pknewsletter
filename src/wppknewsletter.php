<?php
/**
 * Plugin Name: WP PK Newsletter
 * Plugin URI: https://mondary.design
 * Description: Lightweight daily digest newsletter with subscriber management, follow.it-inspired email cards, and unsubscribe handling.
 * Version: 1.44
 * Author: Clement Mondary
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPKNEWSLETTER_VERSION', '1.44');
define('WPPKNEWSLETTER_FILE', __FILE__);
define('WPPKNEWSLETTER_PATH', plugin_dir_path(__FILE__));
define('WPPKNEWSLETTER_URL', plugin_dir_url(__FILE__));

require_once WPPKNEWSLETTER_PATH . 'includes/class-wppk-newsletter.php';

WPPK_Newsletter::boot();
