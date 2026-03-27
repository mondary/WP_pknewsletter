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
    <?php wp_head(); ?>
</head>
<body <?php body_class('wppknewsletter-standalone-landing'); ?>>
<?php
wp_body_open();

// Render only the landing itself. This intentionally bypasses the theme's header/footer
// to avoid extra menus/widgets/sections on the newsletter landing page.
echo do_shortcode('[wppk_newsletter_landing]');

wp_footer();
?>
</body>
</html>
