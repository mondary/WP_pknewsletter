<?php
if (!defined('ABSPATH')) {
    exit;
}

$layout = $settings['email_layout'] ?? 'reading_list';
$page_bg = $theme['page_bg'] ?? '#f5f5f2';
$panel_bg = $theme['panel_bg'] ?? '#ffffff';
$panel_border = $theme['panel_border'] ?? '#ece8df';
$text = $theme['text'] ?? '#111827';
$muted = $theme['muted'] ?? '#6b7280';
$soft_text = $theme['soft_text'] ?? '#4b5563';
$hero_bg = $theme['hero_bg'] ?? '#ffffff';
$dark_panel_bg = $theme['dark_panel_bg'] ?? '#111827';
$dark_panel_text = $theme['dark_panel_text'] ?? '#f9fafb';
$digest_context = $digest_context ?? [
    'timestamp' => current_time('timestamp'),
    'header_title' => 'Le digest du jour',
    'selection_title' => 'Sélection du jour',
    'empty_text' => 'Aucun nouvel article aujourd\'hui.',
];
$featured = $posts[0] ?? null;
$rest_posts = $featured ? array_slice($posts, 1) : [];

$render_post_data = static function ($post): array {
    $title = get_the_title($post);
    $permalink = get_permalink($post);
    $excerpt = wp_trim_words(wp_strip_all_tags(get_the_excerpt($post) ?: $post->post_content), 26);
    $thumb = get_the_post_thumbnail_url($post, 'large');
    $cats = get_the_category($post->ID);
    $label = $cats ? $cats[0]->name : 'Article';

    return [
        'title' => $title,
        'permalink' => $permalink,
        'excerpt' => $excerpt,
        'thumb' => $thumb,
        'label' => $label,
        'date' => get_the_date('d M Y', $post),
    ];
};

$featured_data = $featured ? $render_post_data($featured) : null;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($site_name); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr($page_bg); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:<?php echo esc_attr($text); ?>;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($page_bg); ?>;">
        <tr>
            <td align="center" style="padding:36px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;">
                    <tr>
                        <td style="padding-bottom:18px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td valign="middle">
                                        <table role="presentation" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <?php if (!empty($settings['logo_url'])) : ?>
                                                    <td style="padding-right:14px;" valign="middle">
                                                        <img src="<?php echo esc_url($settings['logo_url']); ?>" alt="<?php echo esc_attr($site_name); ?>" style="display:block;width:60px;height:60px;border-radius:999px;object-fit:cover;">
                                                    </td>
                                                <?php endif; ?>
                                                <td valign="middle">
                                                    <div style="font-size:22px;line-height:1;font-weight:800;color:<?php echo esc_attr($text); ?>;">
                                                        <?php echo esc_html($site_name); ?>
                                                    </div>
                                                    <div style="margin-top:6px;font-size:12px;line-height:1.4;color:<?php echo esc_attr($muted); ?>;">
                                                        <?php echo esc_html(wp_date('d F Y', $digest_context['timestamp'])); ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" valign="middle" style="font-size:13px;line-height:1.5;color:<?php echo esc_attr($muted); ?>;">
                                        <a href="<?php echo esc_url(home_url('/')); ?>" style="color:<?php echo esc_attr($muted); ?>;text-decoration:underline;">Lire sur le blog</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <?php if ($layout === 'reading_list' && $featured_data) : ?>
                        <tr>
                            <td style="background:<?php echo esc_attr($hero_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:28px;padding:28px 28px 30px 28px;box-shadow:0 8px 24px rgba(15,23,42,.04);">
                                <div style="display:inline-block;background:<?php echo esc_attr($accent); ?>;color:#ffffff;font-weight:800;padding:4px 10px;border-radius:7px;font-size:12px;letter-spacing:.04em;">
                                    <?php echo esc_html(strtoupper($featured_data['label'])); ?>
                                </div>
                                <h1 style="margin:18px 0 12px 0;font-size:34px;line-height:1.08;font-weight:800;letter-spacing:-0.03em;color:<?php echo esc_attr($text); ?>;">
                                    <?php echo esc_html($featured_data['title']); ?>
                                </h1>
                                <div style="margin:0 0 18px 0;font-size:14px;line-height:1.6;color:<?php echo esc_attr($soft_text); ?>;">
                                    Par <strong style="color:<?php echo esc_attr($text); ?>;"><?php echo esc_html($settings['sender_name']); ?></strong> le <?php echo esc_html($featured_data['date']); ?>
                                </div>
                                <?php if ($featured_data['thumb']) : ?>
                                    <div style="margin-bottom:18px;border-radius:4px;overflow:hidden;border:1px solid #f1ede5;">
                                        <img src="<?php echo esc_url($featured_data['thumb']); ?>" alt="" style="display:block;width:100%;height:auto;max-height:360px;object-fit:cover;object-position:top center;">
                                    </div>
                                <?php endif; ?>
                                <p style="margin:0 0 22px 0;font-size:16px;line-height:1.8;color:<?php echo esc_attr($soft_text); ?>;">
                                    <?php echo esc_html($featured_data['excerpt']); ?>
                                </p>
                                <table role="presentation" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="border-radius:8px;background:<?php echo esc_attr($accent); ?>;">
                                            <a href="<?php echo esc_url($featured_data['permalink']); ?>" style="display:inline-block;padding:12px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">Lire la suite</a>
                                        </td>
                                        <td style="padding-left:16px;font-size:14px;">
                                            <a href="<?php echo esc_url($featured_data['permalink']); ?>" style="color:<?php echo esc_attr($accent); ?>;text-decoration:underline;font-weight:600;">Ouvrir l’article</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <?php if ($rest_posts) : ?>
                            <tr>
                                <td style="padding-top:16px;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($panel_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:24px;padding:20px 22px;">
                                        <tr>
                                            <td style="padding-bottom:8px;font-size:13px;line-height:1.5;color:<?php echo esc_attr($muted); ?>;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">
                                                À lire aussi
                                            </td>
                                        </tr>
                                        <?php foreach ($rest_posts as $post) : ?>
                                            <?php $item = $render_post_data($post); ?>
                                            <tr>
                                                <td style="padding:14px 0;border-top:1px solid #f1ede5;">
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                                        <tr>
                                                            <?php if ($item['thumb']) : ?>
                                                                <td width="88" valign="top" style="padding-right:14px;">
                                                                    <img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:88px;height:88px;border-radius:3px;object-fit:cover;object-position:top center;">
                                                                </td>
                                                            <?php endif; ?>
                                                            <td valign="top">
                                                                <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">
                                                                    <?php echo esc_html($item['label']); ?>
                                                                </div>
                                                                <div style="margin:0 0 6px 0;font-size:19px;line-height:1.3;font-weight:800;color:#111827;">
                                                                    <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                                </div>
                                                                <div style="font-size:14px;line-height:1.7;color:#4b5563;">
                                                                    <?php echo esc_html(wp_trim_words($item['excerpt'], 16)); ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php elseif ($layout === 'briefing') : ?>
                        <tr>
                            <td style="background:<?php echo esc_attr($dark_panel_bg); ?>;border-radius:28px;padding:28px 28px 24px 28px;color:<?php echo esc_attr($dark_panel_text); ?>;">
                                <div style="font-size:12px;line-height:1.4;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#a7f3d0;margin-bottom:14px;">
                                    Daily Briefing
                                </div>
                                <h1 style="margin:0 0 10px 0;font-size:32px;line-height:1.08;font-weight:800;color:<?php echo esc_attr($dark_panel_text); ?>;"><?php echo esc_html($digest_context['header_title']); ?></h1>
                                <p style="margin:0;font-size:15px;line-height:1.7;color:<?php echo esc_attr($dark_panel_text); ?>;opacity:.82;">Une lecture rapide, pensée comme un brief éditorial : les sujets à retenir, les liens à ouvrir, et l’essentiel sans bruit.</p>
                            </td>
                        </tr>
                        <?php foreach ($posts as $post) : ?>
                            <?php $item = $render_post_data($post); ?>
                            <tr>
                                <td style="padding-top:14px;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($panel_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:16px;overflow:hidden;">
                                        <tr>
                                            <td style="padding:18px 20px;">
                                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                                    <tr>
                                                        <td valign="top">
                                                            <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                                                                <?php echo esc_html($item['label']); ?> · <?php echo esc_html($item['date']); ?>
                                                            </div>
                                                            <div style="margin:0 0 8px 0;font-size:24px;line-height:1.22;font-weight:800;color:#111827;">
                                                                <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                            </div>
                                                            <div style="font-size:15px;line-height:1.75;color:#4b5563;">
                                                                <?php echo esc_html($item['excerpt']); ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php elseif ($layout === 'magazine') : ?>
                        <tr>
                            <td>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <?php foreach ($posts as $index => $post) : ?>
                                        <?php $item = $render_post_data($post); ?>
                                        <?php if ($index % 2 === 0) : ?><tr><?php endif; ?>
                                        <td width="50%" valign="top" style="padding:0 8px 16px 0;">
                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($panel_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:22px;overflow:hidden;height:100%;">
                                                <?php if ($item['thumb']) : ?>
                                                    <tr><td><img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:100%;height:180px;object-fit:cover;object-position:top center;"></td></tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td style="padding:18px;">
                                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="height:170px;">
                                                            <tr>
                                                                <td valign="top" style="height:24px;font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;padding:0 0 8px;">
                                                                    <?php echo esc_html($item['label']); ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td valign="top" style="height:78px;padding:0 0 8px;">
                                                                    <div style="margin:0;font-size:20px;line-height:1.25;font-weight:800;color:#111827;">
                                                                        <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td valign="top" style="height:60px;font-size:14px;line-height:1.7;color:#4b5563;">
                                                                    <?php echo esc_html(wp_trim_words($item['excerpt'], 16)); ?>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                        <?php if ($index % 2 === 1 || $index === count($posts) - 1) : ?></tr><?php endif; ?>
                                    <?php endforeach; ?>
                                </table>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($posts as $index => $post) : ?>
                            <?php $item = $render_post_data($post); ?>
                            <?php if ($layout === 'list_thumbs') : ?>
                                <tr>
                                    <td style="padding-top:14px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:22px;overflow:hidden;">
                                            <tr>
                                                <?php if ($item['thumb']) : ?>
                                                    <td width="220" valign="top" style="background:#f3f4f6;">
                                                        <img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:220px;height:220px;object-fit:cover;object-position:top center;">
                                                    </td>
                                                <?php endif; ?>
                                                <td valign="top" style="padding:18px 20px;">
                                                    <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                                                        <?php echo esc_html($item['label']); ?>
                                                    </div>
                                                    <div style="margin:0 0 8px 0;font-size:24px;line-height:1.22;font-weight:800;color:#111827;">
                                                        <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                    </div>
                                                    <div style="margin:0 0 14px 0;font-size:14px;line-height:1.75;color:#4b5563;">
                                                        <?php echo esc_html($item['excerpt']); ?>
                                                    </div>
                                                    <a href="<?php echo esc_url($item['permalink']); ?>" style="color:<?php echo esc_attr($accent); ?>;text-decoration:underline;font-size:14px;font-weight:700;">Lire l’article</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            <?php elseif ($layout === 'editorial') : ?>
                                <?php if ($index === 0) : ?>
                                    <tr>
                                        <td style="padding-top:6px;">
                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #ece8df;border-radius:26px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.04);">
                                                <?php if ($item['thumb']) : ?>
                                                    <tr><td><img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:100%;height:auto;max-height:340px;object-fit:cover;object-position:top center;"></td></tr>
                                                <?php endif; ?>
                                                <tr><td style="padding:24px;">
                                                    <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;"><?php echo esc_html($item['label']); ?></div>
                                                    <div style="margin:0 0 10px 0;font-size:30px;line-height:1.14;font-weight:800;color:#111827;">
                                                        <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                    </div>
                                                    <div style="margin:0 0 18px 0;font-size:15px;line-height:1.8;color:#4b5563;"><?php echo esc_html($item['excerpt']); ?></div>
                                                    <a href="<?php echo esc_url($item['permalink']); ?>" style="display:inline-block;background:<?php echo esc_attr($accent); ?>;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:12px;font-size:14px;font-weight:700;">Lire l'article</a>
                                                </td></tr>
                                            </table>
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <tr>
                                        <td style="padding-top:12px;">
                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #ece8df;border-radius:18px;">
                                                <tr><td style="padding:16px 18px;">
                                                    <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;"><?php echo esc_html($item['label']); ?></div>
                                                    <div style="margin:0 0 6px 0;font-size:20px;line-height:1.3;font-weight:800;color:#111827;">
                                                        <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                    </div>
                                                    <div style="font-size:14px;line-height:1.7;color:#4b5563;"><?php echo esc_html(wp_trim_words($item['excerpt'], 18)); ?></div>
                                                </td></tr>
                                            </table>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php elseif ($layout === 'compact') : ?>
                                <tr>
                                    <td style="padding-top:10px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">
                                            <tr>
                                                <td>
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                                        <tr>
                                                            <td valign="top" style="padding:14px 16px;">
                                                                <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;"><?php echo esc_html($item['label']); ?></div>
                                                                <div style="margin:0 0 6px 0;font-size:17px;line-height:1.35;font-weight:800;color:#111827;">
                                                                    <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                                </div>
                                                                <div style="font-size:13px;line-height:1.6;color:#6b7280;"><?php echo esc_html(wp_trim_words($item['excerpt'], 18)); ?></div>
                                                            </td>
                                                            <?php if ($item['thumb']) : ?>
                                                                <td width="144" valign="top" style="background:#f3f4f6;">
                                                                    <img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:144px;height:118px;object-fit:cover;object-position:top center;">
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <tr>
                                    <td style="padding-top:14px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #ece8df;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.04);">
                                            <?php if ($item['thumb']) : ?>
                                                <tr><td><img src="<?php echo esc_url($item['thumb']); ?>" alt="" style="display:block;width:100%;height:auto;max-height:280px;object-fit:cover;object-position:top center;"></td></tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td style="padding:22px;">
                                                    <div style="font-size:11px;line-height:1.4;color:<?php echo esc_attr($accent); ?>;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;"><?php echo esc_html($item['label']); ?></div>
                                                    <div style="margin:0 0 10px 0;font-size:26px;line-height:1.18;font-weight:800;color:#111827;">
                                                        <a href="<?php echo esc_url($item['permalink']); ?>" style="color:#111827;text-decoration:none;"><?php echo esc_html($item['title']); ?></a>
                                                    </div>
                                                    <div style="margin:0 0 18px 0;font-size:15px;line-height:1.75;color:#4b5563;"><?php echo esc_html($item['excerpt']); ?></div>
                                                    <a href="<?php echo esc_url($item['permalink']); ?>" style="display:inline-block;background:<?php echo esc_attr($accent); ?>;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:700;">Lire l'article</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!$posts) : ?>
                        <tr>
                            <td style="padding-top:14px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($panel_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:24px;">
                                    <tr><td style="padding:24px;font-size:15px;line-height:1.7;color:<?php echo esc_attr($soft_text); ?>;"><?php echo esc_html($digest_context['empty_text']); ?></td></tr>
                                </table>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td style="padding-top:18px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr($panel_bg); ?>;border:1px solid <?php echo esc_attr($panel_border); ?>;border-radius:22px;">
                                <tr>
                                    <td style="padding:20px 22px;text-align:center;font-size:13px;line-height:1.8;color:<?php echo esc_attr($muted); ?>;">
                                        <?php echo wp_kses_post(wpautop($settings['footer_text'])); ?>
                                        <?php if ($unsubscribe_url) : ?>
                                            <p style="margin:12px 0 0 0;"><a href="<?php echo esc_url($unsubscribe_url); ?>" style="color:<?php echo esc_attr($muted); ?>;text-decoration:underline;">Se desinscrire</a></p>
                                        <?php endif; ?>
                                        <p style="margin:10px 0 0 0;"><?php echo esc_html($recipient); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
