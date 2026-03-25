<?php
/**
 * Template Name: Newsletter Landing
 * Description: Landing page dédiée à l'inscription newsletter.
 */

if (!defined('ABSPATH')) {
    exit;
}

$newsletter_settings = get_option('wppk_newsletter_settings', []);
$brand_name = !empty($newsletter_settings['brand_name']) ? (string) $newsletter_settings['brand_name'] : get_bloginfo('name');
$brand_name = trim(str_replace('📌', '', $brand_name));
$subscribe_action = esc_url(admin_url('admin-post.php'));
$status = sanitize_key($_GET['wppk_status'] ?? '');
$notice = '';
$notice_class = 'is-neutral';

if ($status === 'confirmation_sent') {
    $notice = 'Vérifie ta boîte mail pour confirmer ton inscription.';
    $notice_class = 'is-success';
} elseif ($status === 'confirmation_resent') {
    $notice = 'Un nouveau mail de confirmation vient d’être envoyé.';
    $notice_class = 'is-success';
} elseif ($status === 'subscribed') {
    $notice = 'Inscription confirmée. Bienvenue.';
    $notice_class = 'is-success';
} elseif ($status === 'exists') {
    $notice = 'Cette adresse est déjà inscrite.';
} elseif ($status === 'invalid') {
    $notice = 'Adresse email invalide.';
    $notice_class = 'is-error';
} elseif ($status === 'confirmation_failed') {
    $notice = 'Impossible d’envoyer le mail de confirmation pour le moment.';
    $notice_class = 'is-error';
} elseif ($status === 'confirmation_invalid') {
    $notice = 'Lien de confirmation invalide ou expiré.';
    $notice_class = 'is-error';
}
get_header();
?>
<style>
    :root {
      --wppk-bg: #ffffff;
      --wppk-panel: #ffffff;
      --wppk-text: #111111;
      --wppk-muted: #666666;
      --wppk-line: rgba(17, 17, 17, 0.08);
      --wppk-accent: #2f80ed;
      --wppk-accent-dark: #1f6fdc;
      --wppk-radius-xl: 28px;
      --wppk-radius-lg: 20px;
      --wppk-radius-md: 14px;
      --wppk-shadow: 0 18px 40px rgba(17, 17, 17, 0.06);
    }

    html, body {
      margin: 0;
      padding: 0;
      background: var(--wppk-bg);
      color: var(--wppk-text);
    }

    body.page-template-newsletter-landing,
    body.page-template-newsletter-landing .site,
    body.page-template-newsletter-landing .site-content,
    body.page-template-newsletter-landing .content-area {
      background: #ffffff;
    }

    .wppk-newsletter-page,
    .wppk-newsletter-page * {
      box-sizing: border-box;
    }

    .wppk-newsletter-page {
      min-height: 100vh;
      padding: 48px 20px;
      background: #ffffff;
      color: var(--wppk-text);
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .wppk-newsletter-wrap {
      width: min(1080px, 100%);
      margin: 0 auto;
    }

    .wppk-newsletter-shell {
      width: min(760px, 100%);
      margin: 0 auto;
    }

    .wppk-newsletter-panel {
      border: 1px solid var(--wppk-line);
      border-radius: var(--wppk-radius-xl);
      background: var(--wppk-panel);
      box-shadow: var(--wppk-shadow);
      padding: 48px;
    }

    .wppk-newsletter-kicker {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 22px;
      color: var(--wppk-muted);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .14em;
      text-transform: uppercase;
    }

    .wppk-newsletter-kicker::before {
      content: "";
      width: 28px;
      height: 1px;
      background: currentColor;
      opacity: .55;
    }

    .wppk-newsletter-title {
      margin: 0 0 20px;
      max-width: 620px;
      font-family: Georgia, "Times New Roman", serif;
      font-size: clamp(46px, 7vw, 86px);
      line-height: .94;
      letter-spacing: -.05em;
      font-weight: 700;
    }

    .wppk-newsletter-lead {
      max-width: 640px;
      margin: 0;
      color: var(--wppk-muted);
      font-size: 19px;
      line-height: 1.65;
    }

    .wppk-newsletter-metrics {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-top: 34px;
    }

    .wppk-newsletter-metric {
      padding: 18px;
      border: 1px solid var(--wppk-line);
      border-radius: var(--wppk-radius-lg);
      background: #ffffff;
    }

    .wppk-newsletter-metric strong {
      display: block;
      margin-bottom: 6px;
      font-size: 30px;
      line-height: 1;
      font-weight: 700;
      letter-spacing: -.04em;
    }

    .wppk-newsletter-metric span {
      color: var(--wppk-muted);
      font-size: 12px;
      line-height: 1.5;
      text-transform: uppercase;
      letter-spacing: .12em;
      font-weight: 700;
    }

    .wppk-newsletter-signup-card {
      margin-top: 28px;
      padding: 0;
    }

    .wppk-newsletter-signup-card p {
      margin: 0 0 18px;
      color: var(--wppk-muted);
      font-size: 15px;
      line-height: 1.7;
    }

    .wppk-newsletter-notice {
      margin-bottom: 16px;
      padding: 14px 16px;
      border: 1px solid var(--wppk-line);
      border-radius: var(--wppk-radius-md);
      font-size: 13px;
      line-height: 1.55;
      font-weight: 600;
    }

    .wppk-newsletter-notice.is-success {
      color: #0b8a4d;
      background: rgba(0, 182, 94, 0.08);
      border-color: rgba(0, 182, 94, 0.16);
    }

    .wppk-newsletter-notice.is-error {
      color: #9b1c1c;
      background: rgba(185, 28, 28, 0.06);
      border-color: rgba(185, 28, 28, 0.15);
    }

    .wppk-newsletter-form {
      display: grid;
      gap: 12px;
      margin-top: 40px;
    }

    .wppk-newsletter-form input[type="email"] {
      width: 100%;
      min-height: 58px;
      padding: 0 18px;
      border: 1px solid var(--wppk-line);
      border-radius: var(--wppk-radius-md);
      background: #ffffff;
      color: var(--wppk-text);
      font-size: 16px;
    }

    .wppk-newsletter-form input[type="email"]:focus {
      outline: none;
      border-color: rgba(47, 128, 237, 0.45);
      box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.10);
    }

    .wppk-newsletter-form button {
      min-height: 58px;
      padding: 0 18px;
      border: 0;
      border-radius: var(--wppk-radius-md);
      background: var(--wppk-accent);
      color: #ffffff;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: background .18s ease, transform .18s ease;
    }

    .wppk-newsletter-form button:hover {
      background: var(--wppk-accent-dark);
      transform: translateY(-1px);
    }

    .wppk-newsletter-benefits {
      display: grid;
      gap: 10px;
      margin-top: 18px;
    }

    .wppk-newsletter-benefit {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      color: var(--wppk-muted);
      font-size: 13px;
      line-height: 1.6;
      font-weight: 500;
    }

    .wppk-newsletter-benefit::before {
      content: "";
      width: 8px;
      height: 8px;
      margin-top: 7px;
      border-radius: 999px;
      background: var(--wppk-accent);
      flex: 0 0 auto;
    }

    .wppk-newsletter-fineprint {
      margin-top: 14px;
      color: var(--wppk-muted);
      font-size: 12px;
      line-height: 1.65;
    }

    @media (max-width: 980px) {
      .wppk-newsletter-panel {
        min-height: auto;
        padding: 32px 26px;
      }

      .wppk-newsletter-metrics {
        grid-template-columns: 1fr;
      }
    }
  </style>
<main class="wppk-newsletter-page">
  <div class="wppk-newsletter-wrap">
    <section class="wppk-newsletter-shell">
      <section class="wppk-newsletter-panel">
        <div>
          <div class="wppk-newsletter-kicker">Newsletter</div>
          <h1 class="wppk-newsletter-title">Le meilleur du site, sans le bruit.</h1>
          <p class="wppk-newsletter-lead">
            Un digest éditorial pour retrouver les meilleurs outils, apps, ressources et idées publiés sur le site, dans un format clair, lisible et agréable à ouvrir.
          </p>
        </div>

        <div class="wppk-newsletter-metrics">
          <div class="wppk-newsletter-metric">
            <strong>1 100+</strong>
            <span>abonnés actifs</span>
          </div>
          <div class="wppk-newsletter-metric">
            <strong>Tous les jours</strong>
            <span>à 17h</span>
          </div>
          <div class="wppk-newsletter-metric">
            <strong>Le résumé</strong>
            <span>de la journée</span>
          </div>
        </div>

        <section class="wppk-newsletter-signup-card">
          <p>
            Entre ton email pour recevoir le prochain digest. Tu recevras d’abord un mail de confirmation avant d’être activé.
          </p>

          <?php if ($notice) : ?>
            <div class="wppk-newsletter-notice <?php echo esc_attr($notice_class); ?>">
              <?php echo esc_html($notice); ?>
            </div>
          <?php endif; ?>

          <form class="wppk-newsletter-form" method="post" action="<?php echo $subscribe_action; ?>">
            <input type="hidden" name="action" value="wppk_subscribe">
            <?php wp_nonce_field('wppk_subscribe'); ?>
            <input type="email" name="email" placeholder="vous@exemple.com" required>
            <button type="submit">S’abonner</button>
          </form>

          <div class="wppk-newsletter-benefits">
            <div class="wppk-newsletter-benefit">Une sélection éditoriale, pas un flux brut.</div>
            <div class="wppk-newsletter-benefit">Tous les jours à 17h, dans un format rapide à lire.</div>
            <div class="wppk-newsletter-benefit">Désinscription immédiate en un clic.</div>
          </div>

          <div class="wppk-newsletter-fineprint">
            En t’inscrivant, tu acceptes de recevoir le digest quotidien. Tu peux te désinscrire à tout moment via le lien présent dans chaque email.
          </div>
        </section>
      </section>
    </section>
  </div>
</main>
<?php
get_footer();
