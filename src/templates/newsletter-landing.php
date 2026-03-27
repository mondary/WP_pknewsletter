<?php
/**
 * Plugin Page Template: Newsletter Landing (WP PK Newsletter)
 *
 * This template lives in the plugin so the site does not need a theme file upload.
 */

if (!defined('ABSPATH')) {
    exit;
}

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_the_title()); ?></title>
</head>
<body class="wppknewsletter-standalone-landing">
<?php
// Fully standalone on purpose: no wp_head()/wp_footer() so the theme and plugins
// cannot inject headers/footers/ads/widgets into this landing page.
echo do_shortcode('[wppk_newsletter_landing]');
?>
</body>
</html>
