<?php
/**
 * Plugin Page Template: Newsletter Landing (WP PK Newsletter)
 *
 * This template lives in the plugin so the site does not need a theme file upload.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) {
    the_post();
    the_content();
}

get_footer();

