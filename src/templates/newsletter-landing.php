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
?>
<style>
    /* Keep the theme header, but hide all the heavy stuff below for this landing page. */
    body.page-template-wppknewsletternewsletter-landing-php .entry-header,
    body.page-template-wppknewsletternewsletter-landing-php .entry-title,
    body.page-template-wppknewsletternewsletter-landing-php .breadcrumb,
    body.page-template-wppknewsletternewsletter-landing-php .kadence-breadcrumbs {
        display: none !important;
    }

    body.page-template-wppknewsletternewsletter-landing-php footer,
    body.page-template-wppknewsletternewsletter-landing-php #colophon,
    body.page-template-wppknewsletternewsletter-landing-php .site-footer,
    body.page-template-wppknewsletternewsletter-landing-php .footer,
    body.page-template-wppknewsletternewsletter-landing-php .footer-area,
    body.page-template-wppknewsletternewsletter-landing-php .footer-widgets,
    body.page-template-wppknewsletternewsletter-landing-php .site-info,
    body.page-template-wppknewsletternewsletter-landing-php .custom-footer-container,
    body.page-template-wppknewsletternewsletter-landing-php .copyright-footer {
        display: none !important;
    }

    /* Remove theme vertical margins around the content so the landing sits tighter. */
    body.page-template-wppknewsletternewsletter-landing-php .content-area {
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
</style>
<?php
echo do_shortcode('[wppk_newsletter_landing]');
?>
<?php get_footer(); ?>
