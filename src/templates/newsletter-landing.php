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
    $content = get_the_content();
    $content = is_string($content) ? trim($content) : '';

    // Always render the plugin landing on this template, even if the page content is empty.
    echo do_shortcode('[wppk_newsletter_landing]');

    // Optional: if the editor added extra content (FAQ, legal, etc.), render it below.
    if ($content !== '' && stripos($content, 'wppk_newsletter_landing') === false) {
        echo '<div class="wppk-newsletter-page-content">';
        the_content();
        echo '</div>';
    }
}

get_footer();
