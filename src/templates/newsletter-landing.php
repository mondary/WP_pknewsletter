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
?>
<style>
    .wppk-landing-header,
    .wppk-landing-header * { box-sizing: border-box; }
    .wppk-landing-header {
        position: sticky;
        top: 0;
        z-index: 50;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid rgba(17, 17, 17, 0.08);
    }
    .wppk-landing-header__inner {
        width: min(1080px, 100%);
        margin: 0 auto;
        padding: 14px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }
    .wppk-landing-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        text-decoration: none;
        color: inherit;
    }
    .wppk-landing-brand__logo {
        width: 34px;
        height: 34px;
        border-radius: 999px;
        background: rgba(17,17,17,0.06);
        display: grid;
        place-items: center;
        overflow: hidden;
        flex: 0 0 auto;
    }
    .wppk-landing-brand__logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .wppk-landing-brand__text {
        display: flex;
        flex-direction: column;
        gap: 1px;
        min-width: 0;
    }
    .wppk-landing-brand__name {
        font: 700 14px/1.2 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        letter-spacing: -0.01em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .wppk-landing-brand__tagline {
        font: 500 12px/1.2 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: rgba(17,17,17,0.6);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .wppk-landing-nav ul {
        list-style: none;
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        padding: 0;
    }
    .wppk-landing-nav a {
        display: inline-flex;
        align-items: center;
        padding: 8px 10px;
        border-radius: 999px;
        text-decoration: none;
        color: rgba(17,17,17,0.75);
        font: 650 12px/1 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        letter-spacing: .06em;
        text-transform: uppercase;
        border: 1px solid rgba(17,17,17,0.10);
        background: rgba(255,255,255,0.8);
    }
    .wppk-landing-nav a:hover { color: rgba(17,17,17,0.95); border-color: rgba(17,17,17,0.18); }

    @media (max-width: 720px) {
        .wppk-landing-brand__tagline { display: none; }
        .wppk-landing-nav a { padding: 8px 9px; }
    }
</style>
<header class="wppk-landing-header" role="banner">
    <div class="wppk-landing-header__inner">
        <a class="wppk-landing-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <span class="wppk-landing-brand__logo" aria-hidden="true">
                <?php
                $logo_id = (int) get_theme_mod('custom_logo', 0);
                $logo_src = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '';
                if (is_string($logo_src) && $logo_src !== '') {
                    echo '<img src="' . esc_url($logo_src) . '" alt="">';
                } else {
                    $name = (string) get_bloginfo('name');
                    $initial = '';
                    if (function_exists('mb_substr')) {
                        $initial = mb_substr($name, 0, 1);
                        if (function_exists('mb_strtoupper')) {
                            $initial = mb_strtoupper($initial);
                        }
                    } else {
                        $initial = strtoupper(substr($name, 0, 1));
                    }
                    echo '<span style="font:700 12px/1 ui-sans-serif,system-ui; color: rgba(17,17,17,0.65);">' . esc_html($initial !== '' ? $initial : 'N') . '</span>';
                }
                ?>
            </span>
            <span class="wppk-landing-brand__text">
                <span class="wppk-landing-brand__name"><?php echo esc_html(get_bloginfo('name')); ?></span>
                <span class="wppk-landing-brand__tagline"><?php echo esc_html(get_bloginfo('description')); ?></span>
            </span>
        </a>
        <nav class="wppk-landing-nav" aria-label="<?php echo esc_attr__('Navigation', 'wppknewsletter'); ?>">
            <?php
            if (has_nav_menu('primary')) {
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'fallback_cb' => false,
                    'depth' => 1,
                    'items_wrap' => '<ul>%3$s</ul>',
                ]);
            } else {
                ?>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/')); ?>">Accueil</a></li>
                    <li><a href="<?php echo esc_url(get_bloginfo('rss2_url')); ?>">RSS</a></li>
                </ul>
                <?php
            }
            ?>
        </nav>
    </div>
</header>
<?php
echo do_shortcode('[wppk_newsletter_landing]');
?>
</body>
</html>
