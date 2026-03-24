<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WPPK_Newsletter
{
    private const OPTION_KEY = 'wppk_newsletter_settings';
    private const CRON_HOOK = 'wppk_newsletter_digest_event';
    private const SUBSCRIBERS_TABLE = 'wppk_newsletter_subscribers';
    private const LOG_TABLE = 'wppk_newsletter_logs';
    private const IMPORT_REPORT_TRANSIENT = 'wppk_import_report';
    private const AWS_STATS_TRANSIENT = 'wppk_aws_ses_stats';
    private const EVENT_LOG_OPTION = 'wppk_newsletter_event_logs';

    public static function boot(): void
    {
        $instance = new self();

        register_activation_hook(WPPKNEWSLETTER_FILE, [$instance, 'activate']);
        register_deactivation_hook(WPPKNEWSLETTER_FILE, [$instance, 'deactivate']);

        add_action('init', [$instance, 'register_shortcode']);
        add_action('init', [$instance, 'handle_unsubscribe']);
        add_action('init', [$instance, 'handle_confirmation']);
        add_action('init', [$instance, 'maybe_send_scheduled_digest_on_request'], 20);
        add_action('admin_init', [$instance, 'maybe_upgrade']);
        add_action('admin_init', [$instance, 'register_settings']);
        add_action('admin_menu', [$instance, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$instance, 'enqueue_admin_assets']);
        add_action('admin_post_wppk_subscribe', [$instance, 'handle_subscribe']);
        add_action('admin_post_nopriv_wppk_subscribe', [$instance, 'handle_subscribe']);
        add_action('admin_post_wppk_add_subscriber', [$instance, 'handle_admin_add_subscriber']);
        add_action('admin_post_wppk_import_subscribers', [$instance, 'handle_import_subscribers']);
        add_action('admin_post_wppk_export_subscribers', [$instance, 'handle_export_subscribers']);
        add_action('admin_post_wppk_clear_all_subscribers', [$instance, 'handle_clear_all_subscribers']);
        add_action('admin_post_wppk_subscriber_action', [$instance, 'handle_subscriber_action']);
        add_action('admin_post_wppk_bulk_subscriber_action', [$instance, 'handle_bulk_subscriber_action']);
        add_action('admin_post_wppk_update_subscriber', [$instance, 'handle_update_subscriber']);
        add_action('admin_post_wppk_clear_event_logs', [$instance, 'handle_clear_event_logs']);
        add_action('admin_post_wppk_toggle_digest_pause', [$instance, 'handle_toggle_digest_pause']);
        add_action('admin_post_wppk_switch_audience', [$instance, 'handle_switch_audience']);
        add_action('admin_post_wppk_resend_confirmation', [$instance, 'handle_resend_confirmation']);
        add_action('admin_post_wppk_send_test_digest', [$instance, 'handle_send_test_digest']);
        add_action('admin_post_wppk_send_digest_now', [$instance, 'handle_manual_send']);
        add_action(self::CRON_HOOK, [$instance, 'maybe_send_scheduled_digest']);
        add_action('phpmailer_init', [$instance, 'configure_phpmailer']);
        add_filter('cron_schedules', [$instance, 'add_cron_schedule']);

        wp_register_style(
            'wppk-newsletter-form',
            WPPKNEWSLETTER_URL . 'assets/form.css',
            [],
            WPPKNEWSLETTER_VERSION
        );
        wp_register_style(
            'wppk-newsletter-admin',
            WPPKNEWSLETTER_URL . 'assets/admin.css',
            [],
            WPPKNEWSLETTER_VERSION
        );
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_wppk-newsletter') {
            return;
        }

        wp_enqueue_style('wppk-newsletter-admin');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script(
            'wp-color-picker',
            "jQuery(function($){
                $('.wppk-color-field').wpColorPicker();
                const presets = {
                    gmail: { host: 'smtp.gmail.com', port: '465', secure: 'ssl' },
                    ovh: { host: 'ssl0.ovh.net', port: '587', secure: 'tls' },
                    ses: { host: 'email-smtp.eu-north-1.amazonaws.com', port: '587', secure: 'tls' },
                    custom: { host: '', port: '', secure: 'tls' }
                };
                $('#wppk_smtp_preset').on('change', function() {
                    const preset = presets[$(this).val()];
                    if (!preset) return;
                    $('#smtp_host').val(preset.host);
                    $('#smtp_port').val(preset.port);
                    $('#smtp_secure').val(preset.secure);
                });
                $('#email_layout, #email_theme').on('change', function() {
                    const form = $(this).closest('form');
                    if (form.length) {
                        form.trigger('submit');
                    }
                });
            });"
        );
    }

    public function activate(): void
    {
        $this->create_tables();
        $this->register_settings();
        update_option('wppk_newsletter_db_version', WPPKNEWSLETTER_VERSION, false);
        $this->ensure_cron_schedule();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function maybe_upgrade(): void
    {
        $installed = get_option('wppk_newsletter_db_version', '');
        if ($installed !== WPPKNEWSLETTER_VERSION) {
            $this->create_tables();
            $this->migrate_subscribers_schema();
            update_option('wppk_newsletter_db_version', WPPKNEWSLETTER_VERSION, false);
        }

        $this->ensure_cron_schedule();
    }

    public function add_cron_schedule(array $schedules): array
    {
        $schedules['wppk_digest_check'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __('WP PK Newsletter digest check every minute', 'wppknewsletter'),
        ];

        return $schedules;
    }

    public function register_shortcode(): void
    {
        add_shortcode('wppk_newsletter_form', [$this, 'render_form_shortcode']);
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => $this->default_settings(),
        ]);
    }

    public function register_admin_page(): void
    {
        add_menu_page(
            __('WP PK Newsletter', 'wppknewsletter'),
            __('WP PK Newsletter', 'wppknewsletter'),
            'manage_options',
            'wppk-newsletter',
            [$this, 'render_admin_page'],
            'dashicons-email-alt2',
            58
        );
    }

    public function sanitize_settings(array $input): array
    {
        $defaults = $this->default_settings();
        $existing = $this->get_settings();
        $subject = $this->normalize_subject_template($input['subject'] ?? $defaults['subject'], $defaults['subject']);
        $smtpPassword = sanitize_text_field($input['smtp_password'] ?? '');
        if ($smtpPassword === '') {
            $smtpPassword = (string) ($existing['smtp_password'] ?? $defaults['smtp_password']);
        }
        $awsSecretKey = sanitize_text_field($input['aws_ses_secret_access_key'] ?? '');
        if ($awsSecretKey === '') {
            $awsSecretKey = (string) ($existing['aws_ses_secret_access_key'] ?? $defaults['aws_ses_secret_access_key']);
        }

        $brandName = sanitize_text_field($input['brand_name'] ?? $defaults['brand_name']);
        $senderName = sanitize_text_field($input['sender_name'] ?? '');
        if ($senderName === '') {
            $senderName = $brandName;
        }

        $previewEmail = sanitize_email($input['preview_email'] ?? '');
        if ($previewEmail === '') {
            $previewEmail = (string) ($existing['preview_email'] ?? $defaults['preview_email']);
        }

        $postsPerDigest = absint($input['posts_per_digest'] ?? 0);
        if ($postsPerDigest <= 0) {
            $postsPerDigest = (int) ($existing['posts_per_digest'] ?? $defaults['posts_per_digest']);
        }

        $introText = wp_kses_post($input['intro_text'] ?? $defaults['intro_text']);
        $introText = $this->normalize_intro_text($introText);

        return [
            'brand_name' => $brandName,
            'sender_name' => $senderName,
            'sender_email' => sanitize_email($input['sender_email'] ?? $defaults['sender_email']),
            'reply_to' => sanitize_email($input['reply_to'] ?? $defaults['reply_to']),
            'audience_mode' => $this->sanitize_audience_mode($input['audience_mode'] ?? $defaults['audience_mode']),
            'accent_color' => sanitize_hex_color($input['accent_color'] ?? $defaults['accent_color']) ?: $defaults['accent_color'],
            'logo_url' => esc_url_raw($input['logo_url'] ?? $defaults['logo_url']),
            'email_layout' => $this->sanitize_email_layout($input['email_layout'] ?? $defaults['email_layout']),
            'email_theme' => $this->sanitize_email_theme($input['email_theme'] ?? $defaults['email_theme']),
            'daily_hour' => min(23, max(0, absint($input['daily_hour'] ?? $defaults['daily_hour']))),
            'posts_per_digest' => min(20, max(1, $postsPerDigest)),
            'subject' => $subject,
            'intro_text' => $introText,
            'footer_text' => wp_kses_post($input['footer_text'] ?? $defaults['footer_text']),
            'preview_email' => $previewEmail,
            'smtp_enabled' => !empty($input['smtp_enabled']) ? 1 : 0,
            'smtp_host' => sanitize_text_field($input['smtp_host'] ?? $defaults['smtp_host']),
            'smtp_port' => min(65535, max(1, absint($input['smtp_port'] ?? $defaults['smtp_port']))),
            'smtp_secure' => in_array(($input['smtp_secure'] ?? $defaults['smtp_secure']), ['none', 'ssl', 'tls'], true) ? $input['smtp_secure'] : 'none',
            'smtp_username' => sanitize_text_field($input['smtp_username'] ?? $defaults['smtp_username']),
            'smtp_password' => $smtpPassword,
            'aws_ses_region' => sanitize_text_field($input['aws_ses_region'] ?? $defaults['aws_ses_region']),
            'aws_ses_access_key_id' => sanitize_text_field($input['aws_ses_access_key_id'] ?? $defaults['aws_ses_access_key_id']),
            'aws_ses_secret_access_key' => $awsSecretKey,
        ];
    }

    private function sanitize_audience_mode(string $value): string
    {
        return in_array($value, ['prod', 'dev'], true) ? $value : 'prod';
    }

    private function sanitize_email_layout(string $value): string
    {
        $allowed = array_keys($this->get_email_layout_options());
        return in_array($value, $allowed, true) ? $value : 'cards';
    }

    private function sanitize_email_theme(string $value): string
    {
        $allowed = array_keys($this->get_email_theme_options());
        return in_array($value, $allowed, true) ? $value : 'paper';
    }

    private function normalize_subject_template(string $subject, string $fallback = ''): string
    {
        $subject = sanitize_text_field(trim($subject));

        if ($subject === '') {
            return $fallback !== '' ? $fallback : 'Digest du jour - %date%';
        }

        if (stripos($subject, '%date%') !== false) {
            return preg_replace('/%date%/i', '%date%', $subject) ?? $subject;
        }

        $subject = preg_replace('/(?:^|\\s)te%$/i', ' %date%', $subject) ?? $subject;
        $subject = preg_replace('/(?<!%)date%(?!%)/i', '%date%', $subject) ?? $subject;
        $subject = preg_replace('/(?<!%)%date(?!%)/i', '%date%', $subject) ?? $subject;
        $subject = preg_replace('/\{date\}|\[\[date\]\]/i', '%date%', $subject) ?? $subject;

        return trim($subject);
    }

    private function normalize_intro_text(string $introText): string
    {
        $normalized = trim(wp_strip_all_tags($introText));
        $legacy = [
            'Les derniers articles du jour, regroupes dans un email clair et compact.',
            'Les derniers articles du jour, mis en forme comme une vraie newsletter éditoriale.',
        ];

        if ($normalized === '' || in_array($normalized, $legacy, true)) {
            return 'Une sélection éditoriale pensée pour aller droit à l’essentiel, sans perdre le relief des bons sujets.';
        }

        return $introText;
    }

    private function render_subject(string $subjectTemplate, bool $isTest = false, ?int $reference_timestamp = null): string
    {
        $subject = $this->normalize_subject_template($subjectTemplate, $this->default_settings()['subject']);
        $subject = str_replace('%date%', wp_date('d/m/Y', $reference_timestamp), $subject);

        return $isTest ? '[TEST] ' . $subject : $subject;
    }

    public function render_form_shortcode(array $atts = []): string
    {
        wp_enqueue_style('wppk-newsletter-form');

        $action = esc_url(admin_url('admin-post.php'));
        $status = sanitize_key($_GET['wppk_status'] ?? '');
        $message = '';

        if ($status === 'subscribed') {
            $message = __('Inscription confirmée. Merci.', 'wppknewsletter');
        } elseif ($status === 'confirmation_sent') {
            $message = __('Confirme ton inscription via le mail que nous venons de t’envoyer.', 'wppknewsletter');
        } elseif ($status === 'confirmation_resent') {
            $message = __('Un nouveau mail de confirmation vient d’être envoyé.', 'wppknewsletter');
        } elseif ($status === 'confirmation_invalid') {
            $message = __('Lien de confirmation invalide ou expiré.', 'wppknewsletter');
        } elseif ($status === 'confirmation_failed') {
            $message = __('Impossible d’envoyer le mail de confirmation pour le moment.', 'wppknewsletter');
        } elseif ($status === 'exists') {
            $message = __('Cette adresse est déjà inscrite.', 'wppknewsletter');
        } elseif ($status === 'invalid') {
            $message = __('Adresse email invalide.', 'wppknewsletter');
        }

        ob_start();
        ?>
        <div class="wppk-card">
            <div class="wppk-card__eyebrow"><?php echo esc_html($this->get_settings()['brand_name']); ?></div>
            <h3 class="wppk-card__title"><?php esc_html_e('Recevoir le digest quotidien', 'wppknewsletter'); ?></h3>
            <p class="wppk-card__copy"><?php esc_html_e('Un email propre, compact et visuel avec les publications du jour.', 'wppknewsletter'); ?></p>
            <?php if ($message) : ?>
                <div class="wppk-card__notice"><?php echo esc_html($message); ?></div>
            <?php endif; ?>
            <form method="post" action="<?php echo $action; ?>" class="wppk-form">
                <input type="hidden" name="action" value="wppk_subscribe">
                <?php wp_nonce_field('wppk_subscribe'); ?>
                <label class="screen-reader-text" for="wppk_email"><?php esc_html_e('Email', 'wppknewsletter'); ?></label>
                <input id="wppk_email" type="email" name="email" class="wppk-form__input" placeholder="vous@exemple.com" required>
                <button type="submit" class="wppk-form__button"><?php esc_html_e('S’abonner', 'wppknewsletter'); ?></button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function handle_subscribe(): void
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wppk_subscribe')) {
            wp_die(__('Nonce invalide.', 'wppknewsletter'));
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $redirect = wp_get_referer() ?: home_url('/');

        if (!$email || !is_email($email)) {
            wp_safe_redirect(add_query_arg('wppk_status', 'invalid', $redirect));
            exit;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, status, confirmed FROM {$table} WHERE email = %s", $email), ARRAY_A);

        if (!empty($row['id'])) {
            if (($row['status'] ?? '') === 'active' && !empty($row['confirmed'])) {
                $this->log_event('subscriber', 'ok', sprintf('Inscription ignorée, déjà actif : %s', $email));
                wp_safe_redirect(add_query_arg('wppk_status', 'exists', $redirect));
                exit;
            }

            $token = $this->generate_confirmation_token();
            $now = current_time('mysql', true);
            $wpdb->update(
                $table,
                [
                    'status' => 'pending',
                    'confirmed' => 0,
                    'confirmation_token' => $token,
                    'confirmation_sent_at' => $now,
                    'confirmed_at' => null,
                    'unsubscribed_at' => null,
                ],
                ['id' => (int) $row['id']],
                ['%s', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $sent = $this->send_confirmation_email($email, $token);
            $this->log_event('subscriber', $sent ? 'ok' : 'warn', sprintf('Confirmation renvoyée : %s', $email));
            wp_safe_redirect(add_query_arg('wppk_status', $sent ? 'confirmation_resent' : 'confirmation_failed', $redirect));
            exit;
        }

        $token = $this->generate_confirmation_token();
        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'email' => $email,
            'status' => 'pending',
            'unsubscribe_token' => $token,
            'confirmation_token' => $token,
            'source' => 'Your site',
            'signup_process' => 'Form',
            'delivery_channel' => 'Daily digest',
            'content_mode' => 'Full stories',
            'preferred_hour' => (int) $this->get_settings()['daily_hour'],
            'confirmed' => 0,
            'created_at' => $now,
            'confirmation_sent_at' => $now,
        ]);

        if (!empty($wpdb->last_error)) {
            $this->log_event('subscriber', 'error', sprintf('Échec inscription : %s', $email));
            wp_safe_redirect(add_query_arg('wppk_status', 'invalid', $redirect));
            exit;
        }

        $sent = $this->send_confirmation_email($email, $token);
        $this->log_event('subscriber', $sent ? 'ok' : 'warn', sprintf('Nouvel abonné en attente : %s', $email));
        wp_safe_redirect(add_query_arg('wppk_status', $sent ? 'confirmation_sent' : 'confirmation_failed', $redirect));
        exit;
    }

    public function handle_confirmation(): void
    {
        $token = sanitize_text_field(wp_unslash($_GET['wppk_confirm'] ?? ''));
        if ($token === '') {
            return;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $subscriber = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$table} WHERE confirmation_token = %s", $token),
            ARRAY_A
        );

        if (empty($subscriber['id'])) {
            wp_safe_redirect(add_query_arg('wppk_status', 'confirmation_invalid', home_url('/')));
            exit;
        }

        $this->confirm_subscriber((int) $subscriber['id']);
        wp_safe_redirect(add_query_arg('wppk_status', 'subscribed', home_url('/')));
        exit;
    }

    public function handle_unsubscribe(): void
    {
        $token = sanitize_text_field(wp_unslash($_GET['wppk_unsubscribe'] ?? ''));
        if (!$token) {
            return;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $subscriber = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE unsubscribe_token = %s", $token), ARRAY_A);
        if (!empty($subscriber['id'])) {
            $this->update_subscriber_status((int) $subscriber['id'], 'unsubscribed');
        }

        wp_die(
            esc_html__('Votre désinscription a bien été prise en compte.', 'wppknewsletter'),
            esc_html__('Désinscription', 'wppknewsletter'),
            ['response' => 200]
        );
    }

    public function handle_resend_confirmation(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_resend_confirmation');

        $subscriber_id = absint($_POST['subscriber_id'] ?? 0);
        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        if (!$subscriber_id) {
            $redirect_args['wppk_debug'] = 'Abonné invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $subscriber = $wpdb->get_row(
            $wpdb->prepare("SELECT email, confirmed FROM {$table} WHERE id = %d", $subscriber_id),
            ARRAY_A
        );

        if (empty($subscriber['email'])) {
            $redirect_args['wppk_debug'] = 'Abonné introuvable.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if (!empty($subscriber['confirmed'])) {
            $redirect_args['wppk_debug'] = 'Cet abonné est déjà confirmé.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $token = $this->generate_confirmation_token();
        $now = current_time('mysql', true);
        $wpdb->update(
            $table,
            [
                'status' => 'pending',
                'confirmation_token' => $token,
                'confirmation_sent_at' => $now,
            ],
            ['id' => $subscriber_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $this->send_confirmation_email($subscriber['email'], $token);
        $redirect_args['wppk_debug'] = 'Email de confirmation renvoyé.';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_manual_send(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_send_digest_now');
        $result = $this->send_digest_to_active_subscribers(true);
        $args = [
            'page' => 'wppk-newsletter',
            'wppk_sent' => $result['sent'],
        ];

        if (!empty($result['reason'])) {
            $args['wppk_debug'] = $result['reason'];
        }

        $redirect = add_query_arg($args, admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_send_test_digest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_send_test_digest');

        $settings = $this->get_settings();
        $recipient = sanitize_email(wp_unslash($_POST['test_recipient'] ?? ''));
        $digest_window = $this->sanitize_test_digest_window(wp_unslash($_POST['digest_window'] ?? 'today'));
        $test_layout = $this->sanitize_email_layout(wp_unslash($_POST['test_email_layout'] ?? $settings['email_layout']));
        $return_tab = sanitize_key(wp_unslash($_POST['return_tab'] ?? 'settings'));
        if (!in_array($return_tab, ['dashboard', 'settings'], true)) {
            $return_tab = 'settings';
        }
        if (!$recipient) {
            $recipient = $settings['preview_email'] ?: get_option('admin_email');
        }
        $args = [
            'page' => 'wppk-newsletter',
            'tab' => $return_tab,
        ];

        if (!$recipient || !is_email($recipient)) {
            $args['wppk_debug'] = 'Renseigne d’abord un email de preview valide.';
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }

        $result = $this->send_test_digest($recipient, $digest_window, $test_layout);
        $args['wppk_debug'] = $result['reason'];
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function configure_phpmailer($phpmailer): void
    {
        $settings = $this->get_settings();
        if (empty($settings['smtp_enabled']) || empty($settings['smtp_host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $settings['smtp_host'];
        $phpmailer->Port = (int) $settings['smtp_port'];
        $phpmailer->SMTPAuth = !empty($settings['smtp_username']);
        $phpmailer->Username = $settings['smtp_username'];
        $phpmailer->Password = $settings['smtp_password'];
        $phpmailer->CharSet = 'UTF-8';

        if ($settings['smtp_secure'] === 'ssl') {
            $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($settings['smtp_secure'] === 'tls') {
            $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }
    }

    public function handle_admin_add_subscriber(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_add_subscriber');

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $hour = min(23, max(0, absint($_POST['preferred_hour'] ?? $this->get_settings()['daily_hour'])));
        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        if (!$email || !is_email($email)) {
            $redirect_args['wppk_debug'] = 'Adresse email invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE email = %s", $email), ARRAY_A);

        if (!empty($existing['id'])) {
            if (($existing['status'] ?? '') === 'unsubscribed') {
                $wpdb->update(
                    $table,
                    [
                        'source' => 'Admin',
                        'signup_process' => 'Manual',
                        'delivery_channel' => $this->sanitize_delivery_channel($_POST['delivery_channel'] ?? 'Daily digest'),
                        'content_mode' => $this->sanitize_content_mode($_POST['content_mode'] ?? 'Full stories'),
                        'preferred_hour' => $hour,
                        'confirmed' => 1,
                    ],
                    ['id' => (int) $existing['id']],
                    ['%s', '%s', '%s', '%s', '%d', '%d'],
                    ['%d']
                );
                $this->update_subscriber_status((int) $existing['id'], 'active');
                $redirect_args['wppk_debug'] = 'Abonne reactive.';
            } else {
                $redirect_args['wppk_debug'] = 'Cette adresse existe deja.';
            }
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'email' => $email,
            'status' => 'active',
            'unsubscribe_token' => wp_generate_password(32, false, false),
            'source' => 'Admin',
            'signup_process' => 'Manual',
            'delivery_channel' => $this->sanitize_delivery_channel($_POST['delivery_channel'] ?? 'Daily digest'),
            'content_mode' => $this->sanitize_content_mode($_POST['content_mode'] ?? 'Full stories'),
            'preferred_hour' => $hour,
            'confirmed' => 1,
            'created_at' => $now,
            'subscribed_at' => $now,
        ]);

        if (!empty($wpdb->last_error)) {
            $redirect_args['wppk_debug'] = 'Erreur SQL: ' . $wpdb->last_error;
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $redirect_args['wppk_debug'] = 'Abonne ajoute.';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_import_subscribers(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_import_subscribers');

        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $redirect_args['wppk_debug'] = 'Aucun fichier CSV fourni.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $file = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$file) {
            $redirect_args['wppk_debug'] = 'Impossible de lire le fichier CSV.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $firstLine = fgets($file);
        if ($firstLine === false) {
            fclose($file);
            $redirect_args['wppk_debug'] = 'Fichier CSV vide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }
        $delimiter = $this->detect_csv_delimiter($firstLine);
        rewind($file);

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $imported = 0;
        $skipped = 0;
        $header = null;
        $lineNumber = 0;
        $report = [
            'delimiter' => $delimiter,
            'imported' => 0,
            'skipped' => 0,
            'invalid' => 0,
            'existing' => 0,
            'sql' => 0,
            'empty' => 0,
            'examples' => [],
        ];

        while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
            $lineNumber++;
            if ($header === null) {
                $normalized = array_map([$this, 'normalize_csv_cell'], $row);
                $header = array_map([$this, 'normalize_import_header'], $normalized);
                if (in_array('email', $header, true)) {
                    continue;
                }
                $header = ['email'];
            }

            $data = $this->map_import_row($header, $row);
            $email = sanitize_email($data['email'] ?? '');

            if ($this->is_empty_csv_row($row)) {
                $skipped++;
                $report['empty']++;
                $this->push_import_example($report['examples'], $lineNumber, 'Ligne vide');
                continue;
            }

            if (!$email || !is_email($email)) {
                $skipped++;
                $report['invalid']++;
                $this->push_import_example($report['examples'], $lineNumber, 'Email invalide', $data['email'] ?? '');
                continue;
            }

            $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE email = %s", $email), ARRAY_A);
            $status = $this->normalize_import_status($data);
            $now = current_time('mysql', true);

            if (!empty($existing['id'])) {
                if (($existing['status'] ?? '') === 'unsubscribed' && $status === 'active') {
                    $wpdb->update(
                        $table,
                        [
                            'source' => sanitize_text_field($data['source'] ?? 'Import'),
                            'signup_process' => sanitize_text_field($data['signup_process'] ?? 'Import'),
                            'delivery_channel' => $this->sanitize_delivery_channel($data['delivery_channel'] ?? 'Daily digest'),
                            'content_mode' => $this->sanitize_content_mode($data['content_mode'] ?? 'Full stories'),
                            'preferred_hour' => min(23, max(0, absint($data['preferred_hour'] ?? $this->get_settings()['daily_hour']))),
                            'confirmed' => isset($data['confirmed']) ? (int) (bool) $data['confirmed'] : 1,
                        ],
                        ['id' => (int) $existing['id']],
                        ['%s', '%s', '%s', '%s', '%d', '%d'],
                        ['%d']
                    );
                    $this->update_subscriber_status((int) $existing['id'], 'active');
                    $imported++;
                    continue;
                }

                $skipped++;
                $report['existing']++;
                $this->push_import_example($report['examples'], $lineNumber, 'Déjà présent', $email);
                continue;
            }

            $wpdb->insert($table, [
                'email' => $email,
                'status' => $status,
                'unsubscribe_token' => wp_generate_password(32, false, false),
                'source' => sanitize_text_field($data['source'] ?? 'Import'),
                'signup_process' => sanitize_text_field($data['signup_process'] ?? 'Import'),
                'delivery_channel' => $this->sanitize_delivery_channel($data['delivery_channel'] ?? 'Daily digest'),
                'content_mode' => $this->sanitize_content_mode($data['content_mode'] ?? 'Full stories'),
                'preferred_hour' => min(23, max(0, absint($data['preferred_hour'] ?? $this->get_settings()['daily_hour']))),
                'confirmed' => isset($data['confirmed']) ? (int) (bool) $data['confirmed'] : 1,
                'created_at' => $now,
                'subscribed_at' => $status === 'active' ? $now : null,
                'unsubscribed_at' => $status === 'unsubscribed' ? $now : null,
            ]);

            if (!empty($wpdb->last_error)) {
                $skipped++;
                $report['sql']++;
                $this->push_import_example($report['examples'], $lineNumber, 'Erreur SQL', $wpdb->last_error);
                continue;
            }

            $imported++;
        }

        fclose($file);

        $report['imported'] = $imported;
        $report['skipped'] = $skipped;
        set_transient(self::IMPORT_REPORT_TRANSIENT, $report, 15 * MINUTE_IN_SECONDS);

        $redirect_args['wppk_debug'] = sprintf(
            'Import terminé: %d ajoutés, %d ignorés. Délimiteur détecté: %s. Invalides: %d, déjà présents: %d, SQL: %d, vides: %d.',
            $imported,
            $skipped,
            $delimiter === ';' ? ';' : ',',
            $report['invalid'],
            $report['existing'],
            $report['sql'],
            $report['empty']
        );
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_export_subscribers(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_export_subscribers');

        $rows = $this->get_subscribers('', '', '', 1, 1000000)['rows'];
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wppk-subscribers-' . gmdate('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['email', 'status', 'source', 'signup_process', 'delivery_channel', 'content_mode', 'preferred_hour', 'confirmed', 'created_at', 'subscribed_at', 'unsubscribed_at', 'resubscribed_at']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['email'],
                $row['status'],
                $row['source'],
                $row['signup_process'],
                $row['delivery_channel'],
                $row['content_mode'],
                $row['preferred_hour'],
                $row['confirmed'],
                $row['created_at'],
                $row['subscribed_at'] ?? '',
                $row['unsubscribed_at'] ?? '',
                $row['resubscribed_at'] ?? '',
            ]);
        }

        fclose($output);
        exit;
    }

    public function handle_clear_all_subscribers(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_clear_all_subscribers');

        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $deleted = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $wpdb->query("DELETE FROM {$table}");

        if (!empty($wpdb->last_error)) {
            $redirect_args['wppk_debug'] = 'Erreur SQL: ' . $wpdb->last_error;
        } else {
            $redirect_args['wppk_debug'] = sprintf('%d abonnés supprimés.', max(0, $deleted));
            $this->log_event('subscriber', 'warn', sprintf('Suppression totale de la base %s : %d abonnés', strtoupper($this->get_active_audience()), max(0, $deleted)));
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_clear_event_logs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_clear_event_logs');
        update_option(self::EVENT_LOG_OPTION, [], false);
        wp_safe_redirect(add_query_arg([
            'page' => 'wppk-newsletter',
            'tab' => 'settings',
            'wppk_debug' => 'Logs vidés.',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_toggle_digest_pause(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_toggle_digest_pause');

        $paused = !$this->is_digest_paused();
        update_option('wppk_newsletter_paused', $paused ? 1 : 0, false);
        $this->log_event('cron', $paused ? 'warn' : 'ok', $paused ? 'Digest mis en pause.' : 'Digest repris.');

        wp_safe_redirect(add_query_arg([
            'page' => 'wppk-newsletter',
            'tab' => 'dashboard',
            'wppk_debug' => $paused ? 'Digest en pause.' : 'Digest repris.',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_switch_audience(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_switch_audience');

        $target = $this->sanitize_audience_mode((string) ($_POST['audience_mode'] ?? 'prod'));
        $settings = $this->get_settings();
        $settings['audience_mode'] = $target;
        update_option(self::OPTION_KEY, $settings, false);
        $this->log_event('system', 'ok', sprintf('Audience active basculée sur %s.', strtoupper($target)));

        wp_safe_redirect(add_query_arg([
            'page' => 'wppk-newsletter',
            'tab' => 'dashboard',
            'wppk_debug' => sprintf('Audience active : %s.', strtoupper($target)),
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_subscriber_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_subscriber_action');

        $subscriber_id = absint($_POST['subscriber_id'] ?? 0);
        $action_name = sanitize_key($_POST['subscriber_action_name'] ?? '');
        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        if (!$subscriber_id || !in_array($action_name, ['activate', 'deactivate'], true)) {
            $redirect_args['wppk_debug'] = 'Action abonne invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $status = $action_name === 'activate' ? 'active' : 'unsubscribed';
        $this->update_subscriber_status($subscriber_id, $status);
        $redirect_args['wppk_debug'] = $action_name === 'activate' ? 'Abonne reactive.' : 'Abonne desactive.';

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_bulk_subscriber_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_bulk_subscriber_action');

        $ids = array_map('absint', (array) ($_POST['subscriber_ids'] ?? []));
        $bulk_action = sanitize_key($_POST['bulk_action_name'] ?? '');
        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        $ids = array_filter($ids);
        if (!$ids || !in_array($bulk_action, ['activate', 'deactivate'], true)) {
            $redirect_args['wppk_debug'] = 'Action groupée invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $status = $bulk_action === 'activate' ? 'active' : 'unsubscribed';
        foreach ($ids as $id) {
            $this->update_subscriber_status((int) $id, $status);
        }
        $redirect_args['wppk_debug'] = $bulk_action === 'activate'
            ? sprintf('%d abonnés réactivés.', count($ids))
            : sprintf('%d abonnés désactivés.', count($ids));

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_update_subscriber(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'wppknewsletter'));
        }

        check_admin_referer('wppk_update_subscriber');

        $subscriber_id = absint($_POST['subscriber_id'] ?? 0);
        $redirect_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
        ];

        if (!$subscriber_id) {
            $redirect_args['wppk_debug'] = 'Abonné invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) {
            $redirect_args['wppk_debug'] = 'Email invalide.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $current_row = $wpdb->get_row($wpdb->prepare("SELECT status, confirmed, content_mode FROM {$table} WHERE id = %d", $subscriber_id), ARRAY_A);
        if (isset($_POST['resend_confirmation']) && empty($current_row['confirmed'])) {
            $token = $this->generate_confirmation_token();
            $now = current_time('mysql', true);
            $wpdb->update(
                $table,
                [
                    'status' => 'pending',
                    'confirmation_token' => $token,
                    'confirmation_sent_at' => $now,
                ],
                ['id' => $subscriber_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            $sent = $this->send_confirmation_email($email, $token);
            $redirect_args['wppk_debug'] = $sent ? 'Email de confirmation renvoyé.' : 'Échec de l’envoi de confirmation.';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $confirmed = isset($_POST['confirmed']) ? 1 : 0;
        $new_status = in_array(($_POST['status'] ?? 'active'), ['active', 'pending', 'unsubscribed'], true) ? $_POST['status'] : 'active';
        if ($new_status === 'pending') {
            $confirmed = 0;
        } elseif ($new_status === 'active') {
            $confirmed = 1;
        }
        $current_status = $current_row['status'] ?? '';
        $update_data = [
            'email' => $email,
            'delivery_channel' => $this->sanitize_delivery_channel($_POST['delivery_channel'] ?? 'Daily digest'),
            'content_mode' => $this->sanitize_content_mode($_POST['content_mode'] ?? ($current_row['content_mode'] ?? 'Full stories')),
            'preferred_hour' => min(23, max(0, absint($_POST['preferred_hour'] ?? 17))),
            'confirmed' => $confirmed,
        ];
        $update_format = ['%s', '%s', '%s', '%d', '%d'];
        if ($confirmed && empty($current_row['confirmed'])) {
            $update_data['confirmed_at'] = current_time('mysql', true);
            $update_format[] = '%s';
        } elseif (!$confirmed) {
            $update_data['confirmed_at'] = null;
            $update_format[] = '%s';
        }
        $wpdb->update(
            $table,
            $update_data,
            ['id' => $subscriber_id],
            $update_format,
            ['%d']
        );
        if ($current_status !== $new_status) {
            $this->update_subscriber_status($subscriber_id, $new_status);
        }

        $redirect_args['wppk_debug'] = 'Abonné mis à jour.';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function maybe_send_scheduled_digest(): void
    {
        $source = current_filter() === self::CRON_HOOK ? 'cron' : 'request';
        if ($this->is_digest_paused()) {
            $this->log_event('cron', 'skip', sprintf('Déclenchement %s ignoré : digest en pause', $source));
            return;
        }
        $today = wp_date('Y-m-d');
        $last_sent = get_option('wppk_newsletter_last_sent_date', '');
        $now_timestamp = current_time('timestamp');
        $current_hm = (int) wp_date('Hi', $now_timestamp);
        $lock_key = 'wppk_digest_send_lock';

        if ($current_hm < 1410 || $current_hm > 2000) {
            $this->log_event('cron', 'skip', sprintf('Déclenchement %s ignoré : hors fenêtre (%s)', $source, wp_date('H:i', $now_timestamp)));
            return;
        }

        if ($last_sent === $today) {
            $this->log_event('cron', 'skip', sprintf('Déclenchement %s ignoré : digest déjà envoyé aujourd’hui', $source));
            return;
        }

        if (get_transient($lock_key)) {
            $this->log_event('cron', 'skip', sprintf('Déclenchement %s ignoré : verrou actif', $source));
            return;
        }

        $this->log_event('cron', 'ok', sprintf('Déclenchement %s OK, tentative d’envoi', $source));
        set_transient($lock_key, '1', 15 * MINUTE_IN_SECONDS);
        update_option('wppk_newsletter_last_sent_date', $today, false);

        $result = $this->send_digest_to_active_subscribers(false);
        if (($result['sent'] ?? 0) <= 0) {
            update_option('wppk_newsletter_last_sent_date', '', false);
            $this->log_event('cron', 'warn', sprintf('Envoi stoppé : %s', (string) ($result['reason'] ?? 'raison inconnue')));
        } else {
            $this->log_event('cron', 'ok', sprintf('Envoi exécuté : %d emails', (int) ($result['sent'] ?? 0)));
        }
        delete_transient($lock_key);
    }

    public function maybe_send_scheduled_digest_on_request(): void
    {
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
            return;
        }

        $lock_key = 'wppk_digest_request_lock';
        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, '1', 55);
        $this->maybe_send_scheduled_digest();
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $stats = $this->get_stats();
        $posts = $this->get_daily_posts_overview();
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        $tabs = [
            'dashboard' => __('Dashboard', 'wppknewsletter'),
            'subscribers' => __('Abonnés', 'wppknewsletter'),
            'stats' => __('Statistiques', 'wppknewsletter'),
            'settings' => __('Reglages', 'wppknewsletter'),
        ];
        $tab_icons = [
            'dashboard' => 'dashboard',
            'subscribers' => 'groups',
            'stats' => 'chart-bar',
            'settings' => 'admin-generic',
        ];
        $page_titles = [
            'dashboard' => __('Dashboard', 'wppknewsletter'),
            'settings' => __('Settings', 'wppknewsletter'),
            'subscribers' => __('Abonnés', 'wppknewsletter'),
            'stats' => __('Statistics', 'wppknewsletter'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'dashboard';
        }
        $preview_layout = $this->sanitize_email_layout(wp_unslash($_GET['dashboard_preview_layout'] ?? $settings['email_layout']));
        $preview_settings = $settings;
        $preview_settings['email_layout'] = $preview_layout;
        $preview = $this->build_email_html($posts, $preview_settings, $settings['preview_email'] ?: get_option('admin_email'));
        ?>
        <div class="wrap">
            <div class="wppk-admin-shell">
                <header class="wppk-topbar">
                    <div class="wppk-topbar__intro">
                        <h1 class="wppk-admin-title"><?php echo esc_html($page_titles[$tab] ?? 'Newsletter'); ?></h1>
                        <div class="wppk-admin-audience-badge">Audience active : <?php echo esc_html(strtoupper($this->get_active_audience())); ?></div>
                    </div>
                    <nav class="wppk-topbar__nav" aria-label="Navigation newsletter">
                        <?php foreach ($tabs as $key => $label) : ?>
                            <?php
                            $url = add_query_arg(
                                [
                                    'page' => 'wppk-newsletter',
                                    'tab' => $key,
                                ],
                                admin_url('admin.php')
                            );
                            $classes = 'wppk-topbar__link' . ($tab === $key ? ' is-active' : '');
                            $icon = $tab_icons[$key] ?? 'admin-generic';
                            ?>
                            <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($classes); ?>" aria-current="<?php echo $tab === $key ? 'page' : 'false'; ?>">
                                <span class="wppk-topbar__icon dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                                <?php echo esc_html($label); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </header>

                <?php if (isset($_GET['wppk_sent'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(__('Digest envoye a %d abonnes.', 'wppknewsletter'), absint($_GET['wppk_sent']))); ?></p></div>
                <?php endif; ?>
                <?php if (isset($_GET['wppk_debug'])) : ?>
                    <div class="notice notice-warning"><p><?php echo esc_html(wp_unslash($_GET['wppk_debug'])); ?></p></div>
                <?php endif; ?>

                <?php if ($tab !== 'dashboard' && $tab !== 'stats') : ?>
                <?php $context_cards = $this->get_contextual_stat_cards($tab, $settings, $stats, $posts); ?>
                <div class="wppk-stat-grid <?php echo $tab === 'stats' ? 'wppk-stat-grid--compact' : ''; ?>">
                    <?php foreach ($context_cards as $card) : ?>
                        <?php echo $this->render_stat_card($card['title'], $card['value'], $card['meta']); ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'dashboard') : ?>
                <div class="wppk-page-stack">
                    <?php $this->render_dashboard_panel($settings, $stats, $posts, $preview, $preview_layout); ?>
                </div>
                <?php elseif ($tab === 'settings') : ?>
                <div class="wppk-page-stack">
                    <section class="wppk-panel">
                        <div class="wppk-panel__header">
                            <div>
                                <h2 class="wppk-panel__title"><?php esc_html_e('Reglages generaux', 'wppknewsletter'); ?></h2>
                            </div>
                        </div>
                        <form method="post" action="options.php" class="wppk-settings-form">
                            <?php settings_fields(self::OPTION_KEY); ?>
                            <div class="wppk-settings-sections">
                                <section class="wppk-settings-section wppk-settings-section--identity">
                                    <div class="wppk-settings-section__header">
                                        <h3 class="wppk-settings-section__title">Identité</h3>
                                    </div>
                                    <div class="wppk-settings-grid">
                                        <div class="wppk-field"><label for="audience_mode">Audience active</label><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[audience_mode]" id="audience_mode"><option value="prod" <?php selected($settings['audience_mode'], 'prod'); ?>>Prod</option><option value="dev" <?php selected($settings['audience_mode'], 'dev'); ?>>Dev</option></select><p class="description">Prod envoie à la vraie base. Dev utilise une base isolée pour tester avec quelques emails.</p></div>
                                        <div class="wppk-field"><label for="brand_name">Nom affiché</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[brand_name]" id="brand_name" type="text" value="<?php echo esc_attr($settings['brand_name']); ?>"><p class="description">Utilisé à la fois comme nom de marque dans le digest et comme nom d’expéditeur par défaut.</p></div>
                                        <div class="wppk-field"><label for="sender_email">Email expediteur</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[sender_email]" id="sender_email" type="email" value="<?php echo esc_attr($settings['sender_email']); ?>"></div>
                                        <div class="wppk-field"><label for="reply_to">Reply-To</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[reply_to]" id="reply_to" type="email" value="<?php echo esc_attr($settings['reply_to']); ?>"></div>
                                        <div class="wppk-field"><label for="accent_color">Couleur accent</label><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[accent_color]" id="accent_color" class="wppk-color-field" value="<?php echo esc_attr($settings['accent_color']); ?>" data-default-color="#2f80ed"><p class="description">Utilisée pour les tags, les CTA et les accents de l’email.</p></div>
                                        <div class="wppk-field wppk-field--span-2">
                                            <label for="logo_url">URL logo</label>
                                            <div class="wppk-logo-field">
                                                <?php if (!empty($settings['logo_url'])) : ?>
                                                    <div class="wppk-logo-preview"><img src="<?php echo esc_url($settings['logo_url']); ?>" alt="<?php echo esc_attr($settings['brand_name']); ?>"></div>
                                                <?php endif; ?>
                                                <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[logo_url]" id="logo_url" type="text" value="<?php echo esc_attr($settings['logo_url']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wppk-settings-section__footer">
                                        <?php submit_button(__('Enregistrer', 'wppknewsletter'), 'primary', 'submit', false); ?>
                                    </div>
                                </section>

                                <section class="wppk-settings-section wppk-settings-section--digest">
                                    <div class="wppk-settings-section__header">
                                        <h3 class="wppk-settings-section__title">Digest</h3>
                                    </div>
                                    <div class="wppk-settings-grid">
                                        <div class="wppk-field"><label for="email_layout">Mise en forme du digest</label><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_layout]" id="email_layout"><?php foreach ($this->get_email_layout_options() as $layout_key => $layout_label) : ?><option value="<?php echo esc_attr($layout_key); ?>" <?php selected($settings['email_layout'], $layout_key); ?>><?php echo esc_html($layout_label); ?></option><?php endforeach; ?></select><p class="description">Choisit la structure visuelle de l’email. Le changement recharge la preview.</p></div>
                                        <div class="wppk-field"><label for="email_theme">Thème de l’email</label><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_theme]" id="email_theme"><?php foreach ($this->get_email_theme_options() as $theme_key => $theme_label) : ?><option value="<?php echo esc_attr($theme_key); ?>" <?php selected($settings['email_theme'], $theme_key); ?>><?php echo esc_html($theme_label); ?></option><?php endforeach; ?></select><p class="description">Affecte les fonds, bordures et contrastes dans la preview et l’envoi réel. Le changement recharge la preview.</p></div>
                                        <div class="wppk-field wppk-field--span-2"><label>&nbsp;</label><p class="description wppk-settings-guide">Reading List = hero éditorial inspiré de ton mock. Cards = grandes cartes. List + thumbnails = liste compacte illustrée. Editorial = premier article hero puis liste. Compact = digest dense. Briefing = version plus textuelle premium. Magazine grid = grille 2 colonnes.</p></div>
                                        <div class="wppk-field wppk-field--span-2"><label for="subject">Sujet</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[subject]" id="subject" type="text" value="<?php echo esc_attr($settings['subject']); ?>"><p class="description">Utilise %date% pour afficher la date du jour dans l’objet.</p></div>
                                        <div class="wppk-field"><label for="daily_hour">Heure quotidienne</label><input type="number" min="0" max="23" name="<?php echo esc_attr(self::OPTION_KEY); ?>[daily_hour]" id="daily_hour" value="<?php echo esc_attr((string) $settings['daily_hour']); ?>"></div>
                                        <div class="wppk-field wppk-field--span-3 wppk-field-group">
                                            <label>Contenu du digest</label>
                                            <div class="wppk-field-group__grid">
                                                <div class="wppk-field"><label for="intro_text">Texte intro</label><textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[intro_text]" id="intro_text" rows="4"><?php echo esc_textarea($settings['intro_text']); ?></textarea></div>
                                                <div class="wppk-field"><label for="footer_text">Texte footer</label><textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[footer_text]" id="footer_text" rows="4"><?php echo esc_textarea($settings['footer_text']); ?></textarea></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wppk-settings-section__footer">
                                        <?php submit_button(__('Enregistrer', 'wppknewsletter'), 'primary', 'submit', false); ?>
                                    </div>
                                </section>

                                <section class="wppk-settings-section wppk-settings-section--smtp">
                                    <div class="wppk-settings-section__header">
                                        <h3 class="wppk-settings-section__title">SMTP</h3>
                                    </div>
                                    <div class="wppk-settings-grid">
                                        <div class="wppk-field wppk-field--span-3"><label class="wppk-field__checkbox"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_enabled]" id="smtp_enabled" value="1" <?php checked((int) $settings['smtp_enabled'], 1); ?>> <span>Utiliser la configuration SMTP ci-dessous pour les envois du plugin</span></label></div>
                                        <div class="wppk-field"><label for="wppk_smtp_preset">Preset SMTP</label><select id="wppk_smtp_preset"><option value="custom">Custom</option><option value="ses">AWS SES</option><option value="gmail">Gmail</option><option value="ovh">OVH / Zimbra / Spacemail</option></select><p class="description">Préremplit automatiquement host, port et sécurité.</p><div class="wppk-help-links"><a href="https://myaccount.google.com/security" target="_blank" rel="noreferrer">Google Security</a><a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer">Google App Passwords</a><a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noreferrer">Aide Google</a></div><div class="wppk-help-links wppk-help-links--providers"><a href="https://workspace.google.com/pricing.html" target="_blank" rel="noreferrer">Offres Gmail</a><a href="https://www.ovhcloud.com/fr/emails/" target="_blank" rel="noreferrer">Offres OVH</a><a href="https://aws.amazon.com/fr/ses/pricing/" target="_blank" rel="noreferrer">Tarifs AWS SES</a></div></div>
                                        <div class="wppk-field"><label for="smtp_host">SMTP host</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_host]" id="smtp_host" type="text" value="<?php echo esc_attr($settings['smtp_host']); ?>"></div>
                                        <div class="wppk-field"><label for="smtp_port">SMTP port</label><input type="number" min="1" max="65535" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_port]" id="smtp_port" value="<?php echo esc_attr((string) $settings['smtp_port']); ?>"></div>
                                        <div class="wppk-field"><label for="smtp_secure">Sécurité SMTP</label><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_secure]" id="smtp_secure"><option value="none" <?php selected($settings['smtp_secure'], 'none'); ?>>Aucune</option><option value="ssl" <?php selected($settings['smtp_secure'], 'ssl'); ?>>SSL</option><option value="tls" <?php selected($settings['smtp_secure'], 'tls'); ?>>TLS</option></select></div>
                                        <div class="wppk-field"><label for="smtp_username">SMTP username</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_username]" id="smtp_username" type="text" value="<?php echo esc_attr($settings['smtp_username']); ?>"></div>
                                        <div class="wppk-field"><label for="smtp_password">SMTP password / app password</label><input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_password]" id="smtp_password" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['smtp_password']) ? '••••••••••••••••' : 'Laisser vide pour conserver le mot de passe actuel'; ?>"><p class="description">Pour Gmail, utilise un mot de passe d’application Google, pas ton mot de passe principal.</p></div>
                                        <div class="wppk-field wppk-field--span-3"><label style="margin-bottom:8px;display:block;">AWS SES API</label><p class="description" style="margin-top:0;">Utilisé pour lire les vraies stats SES facturées dans l’onglet Statistiques.</p></div>
                                        <div class="wppk-field wppk-field--span-3"><label for="aws_ses_region">AWS SES region</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[aws_ses_region]" id="aws_ses_region" type="text" value="<?php echo esc_attr($settings['aws_ses_region']); ?>" placeholder="eu-north-1"></div>
                                        <div class="wppk-field"><label for="aws_ses_access_key_id">AWS Access Key ID</label><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[aws_ses_access_key_id]" id="aws_ses_access_key_id" type="text" value="<?php echo esc_attr($settings['aws_ses_access_key_id']); ?>" placeholder="AKIA..."></div>
                                        <div class="wppk-field"><label for="aws_ses_secret_access_key">AWS Secret Access Key</label><input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[aws_ses_secret_access_key]" id="aws_ses_secret_access_key" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['aws_ses_secret_access_key']) ? '••••••••••••••••' : 'Laisser vide pour conserver la secret key actuelle'; ?>"><p class="description">Crée un user IAM lecture seule SES pour ces stats.</p></div>
                                    </div>
                                    <div class="wppk-settings-section__footer">
                                        <?php submit_button(__('Enregistrer', 'wppknewsletter'), 'primary', 'submit', false); ?>
                                    </div>
                                </section>
                            </div>
                        </form>
                    </section>

                    <section class="wppk-panel">
                        <div class="wppk-panel__header">
                            <div>
                                <h2 class="wppk-panel__title">Logs internes</h2>
                                <p class="wppk-panel__copy">Nouveaux abonnés, désinscriptions et décisions du scheduler minute par minute.</p>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="wppk_clear_event_logs">
                                <?php wp_nonce_field('wppk_clear_event_logs'); ?>
                                <?php submit_button(__('Vider les logs', 'wppknewsletter'), 'secondary', '', false); ?>
                            </form>
                        </div>
                        <?php echo $this->render_event_logs_panel(); ?>
                    </section>

                </div>
            <?php elseif ($tab === 'subscribers') : ?>
                <section class="wppk-panel">
                    <div class="wppk-panel__header">
                        <div>
                            <h2 class="wppk-panel__title"><?php esc_html_e('Abonnés', 'wppknewsletter'); ?></h2>
                        </div>
                    </div>
                    <?php $this->render_subscribers_table(); ?>
                </section>
            <?php elseif ($tab === 'stats') : ?>
                <section class="wppk-panel">
                    <div class="wppk-panel__header">
                        <div>
                            <h2 class="wppk-panel__title"><?php esc_html_e('Statistiques d\'envoi', 'wppknewsletter'); ?></h2>
                        </div>
                    </div>
                    <?php $this->render_logs_table(); ?>
                </section>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_subscribers_table(): void
    {
        $search = sanitize_text_field(wp_unslash($_GET['subscriber_s'] ?? ''));
        $status_filter = sanitize_key($_GET['subscriber_status'] ?? '');
        $channel_filter = sanitize_text_field(wp_unslash($_GET['subscriber_channel'] ?? ''));
        $page_num = max(1, absint($_GET['subscriber_page_num'] ?? 1));
        $per_page_options = [25, 50, 100, 250];
        $per_page = absint($_GET['subscriber_per_page'] ?? 100);
        if (!in_array($per_page, $per_page_options, true)) {
            $per_page = 100;
        }
        $result = $this->get_subscribers($search, $status_filter, $channel_filter, $page_num, $per_page);
        $rows = $result['rows'];
        $total = $result['total'];
        $edit_id = absint($_GET['edit_subscriber'] ?? 0);
        $channel_options = $this->get_distinct_channels();
        $importReport = get_transient(self::IMPORT_REPORT_TRANSIENT);

        ?>
        <?php if (is_array($importReport)) : ?>
            <div class="wppk-import-report">
                <div class="wppk-import-report__title">Dernier rapport d’import</div>
                <div class="wppk-import-report__meta">
                    Délimiteur: <strong><?php echo esc_html($importReport['delimiter']); ?></strong> ·
                    Ajoutés: <strong><?php echo esc_html((string) $importReport['imported']); ?></strong> ·
                    Ignorés: <strong><?php echo esc_html((string) $importReport['skipped']); ?></strong> ·
                    Invalides: <strong><?php echo esc_html((string) $importReport['invalid']); ?></strong> ·
                    Déjà présents: <strong><?php echo esc_html((string) $importReport['existing']); ?></strong> ·
                    SQL: <strong><?php echo esc_html((string) $importReport['sql']); ?></strong>
                </div>
                <?php if (!empty($importReport['examples'])) : ?>
                    <ul class="wppk-import-report__list">
                        <?php foreach ($importReport['examples'] as $example) : ?>
                            <li>Ligne <?php echo esc_html((string) $example['line']); ?> · <?php echo esc_html($example['reason']); ?><?php if ($example['value'] !== '') : ?> · <code><?php echo esc_html($example['value']); ?></code><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php delete_transient(self::IMPORT_REPORT_TRANSIENT); ?>
        <?php endif; ?>

        <?php
        $growth = $this->get_subscriber_growth_data('day');
        echo $this->render_dual_line_chart_card(
            'Progression des abonnes',
            'Volume cumulé sur les 30 derniers jours',
            $growth['subscribed_series'],
            $growth['unsubscribed_series'],
            'Actifs',
            'Désinscrits',
            'wppk-chart-card--mini'
        );
        ?>

        <div class="wppk-subscriber-actions">
            <section class="wppk-subscriber-card">
                <div class="wppk-subscriber-card__header">
                    <h3 class="wppk-subscriber-card__title">Ajout manuel</h3>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppk-subscriber-form wppk-subscriber-form--add">
                    <input type="hidden" name="action" value="wppk_add_subscriber">
                    <?php wp_nonce_field('wppk_add_subscriber'); ?>
                    <div class="wppk-subscriber-form__grid">
                        <div class="wppk-subscriber-field wppk-subscriber-field--wide">
                            <label for="wppk_add_email">Adresse email</label>
                            <input id="wppk_add_email" type="email" name="email" required placeholder="email@exemple.com">
                        </div>
                        <div class="wppk-subscriber-field">
                            <label for="wppk_preferred_hour">Heure</label>
                            <input id="wppk_preferred_hour" type="number" min="0" max="23" name="preferred_hour" value="<?php echo esc_attr((string) $this->get_settings()['daily_hour']); ?>">
                        </div>
                        <div class="wppk-subscriber-field">
                            <label for="wppk_add_channel">Canal</label>
                            <select id="wppk_add_channel" name="delivery_channel">
                                <?php foreach ($this->get_delivery_channel_options() as $option) : ?>
                                    <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php submit_button(__('Ajouter un abonne', 'wppknewsletter'), 'primary', '', false); ?>
                </form>
            </section>

            <section class="wppk-subscriber-card">
                <div class="wppk-subscriber-card__header">
                    <h3 class="wppk-subscriber-card__title">Import / export</h3>
                </div>
                <div class="wppk-subscriber-form-stack">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppk-subscriber-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="wppk_import_subscribers">
                        <?php wp_nonce_field('wppk_import_subscribers'); ?>
                        <div class="wppk-subscriber-field wppk-subscriber-field--wide">
                            <label for="wppk_import_file">Fichier CSV</label>
                            <label class="wppk-file-picker" for="wppk_import_file">
                                <span class="wppk-file-picker__button">Sélectionner un CSV</span>
                                <span class="wppk-file-picker__name" id="wppk_import_file_name">Aucun fichier choisi</span>
                            </label>
                            <input id="wppk_import_file" class="wppk-file-input" type="file" name="import_file" accept=".csv,text/csv">
                        </div>
                        <?php submit_button(__('Importer CSV', 'wppknewsletter'), 'secondary', '', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppk-subscriber-form wppk-subscriber-form--inline">
                        <input type="hidden" name="action" value="wppk_export_subscribers">
                        <?php wp_nonce_field('wppk_export_subscribers'); ?>
                        <?php submit_button(__('Exporter CSV', 'wppknewsletter'), 'secondary', '', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppk-subscriber-form wppk-subscriber-form--inline" onsubmit="return window.confirm('Supprimer tous les abonnés ? Cette action est irreversible.');">
                        <input type="hidden" name="action" value="wppk_clear_all_subscribers">
                        <?php wp_nonce_field('wppk_clear_all_subscribers'); ?>
                        <?php submit_button(__('Supprimer tous les abonnés', 'wppknewsletter'), 'delete wppk-button-danger', '', false); ?>
                    </form>
                </div>
                <p class="wppk-subscriber-card__hint">Audience active : <strong><?php echo esc_html(strtoupper($this->get_active_audience())); ?></strong> · CSV : <code>email,status,source,signup_process,delivery_channel,content_mode,preferred_hour,confirmed</code></p>
            </section>

            <section class="wppk-subscriber-card">
                <div class="wppk-subscriber-card__header">
                    <h3 class="wppk-subscriber-card__title">Recherche et filtres</h3>
                </div>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wppk-subscriber-form">
                    <input type="hidden" name="page" value="wppk-newsletter">
                    <input type="hidden" name="tab" value="subscribers">
                    <div class="wppk-subscriber-form__grid">
                        <div class="wppk-subscriber-field wppk-subscriber-field--wide">
                            <label for="wppk_search_email">Recherche email</label>
                            <input id="wppk_search_email" type="search" name="subscriber_s" value="<?php echo esc_attr($search); ?>" placeholder="Rechercher un email">
                        </div>
                        <div class="wppk-subscriber-field">
                            <label for="wppk_filter_status">Statut</label>
                            <select id="wppk_filter_status" name="subscriber_status">
                                <option value="">Tous statuts</option>
                                <option value="active" <?php selected($status_filter, 'active'); ?>>Active</option>
                                <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                                <option value="unsubscribed" <?php selected($status_filter, 'unsubscribed'); ?>>Unsubscribed</option>
                            </select>
                        </div>
                        <div class="wppk-subscriber-field">
                            <label for="wppk_filter_channel">Canal</label>
                            <select id="wppk_filter_channel" name="subscriber_channel">
                                <option value="">Tous canaux</option>
                                <?php foreach ($channel_options as $channel) : ?>
                                    <option value="<?php echo esc_attr($channel); ?>" <?php selected($channel_filter, $channel); ?>><?php echo esc_html($channel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wppk-subscriber-field">
                            <label for="wppk_per_page">Afficher</label>
                            <select id="wppk_per_page" name="subscriber_per_page">
                                <?php foreach ($per_page_options as $option) : ?>
                                    <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($per_page, $option); ?>><?php echo esc_html((string) $option); ?> / page</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php submit_button(__('Rechercher', 'wppknewsletter'), 'secondary', '', false); ?>
                </form>
            </section>
        </div>
        <?php

        if (!$rows) {
            echo '<p>Aucun abonne pour le moment.</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wppk_bulk_subscriber_action">';
        wp_nonce_field('wppk_bulk_subscriber_action');
        echo '<div class="wppk-bulkbar">';
        echo '<select name="bulk_action_name"><option value="">Action groupée</option><option value="activate">Réactiver</option><option value="deactivate">Désactiver</option></select>';
        submit_button(__('Appliquer', 'wppknewsletter'), 'secondary', '', false);
        echo '<div class="wppk-bulkbar__count">' . esc_html(sprintf('%d abonnés', $total)) . '</div>';
        echo '</div>';
        echo '<div class="wppk-table-shell"><table class="widefat striped wppk-table"><thead><tr><th><input type="checkbox" onclick="jQuery(\'.wppk-subscriber-check\').prop(\'checked\', this.checked)"></th><th>Email address</th><th>1st subscription</th><th>Unsubscribed</th><th>Resubscribed</th><th>Status</th><th>Source</th><th>Confirmation</th><th>Channels</th><th>Email delivery time</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $status_badge = $row['status'] === 'active'
                ? '<span class="wppk-status-badge is-active">🟢 Active</span>'
                : (($row['status'] === 'pending')
                    ? '<span class="wppk-status-badge is-pending">🟡 Pending</span>'
                    : '<span class="wppk-status-badge is-unsubscribed">🟠 Unsubscribed</span>');
            $confirmation_badge = !empty($row['confirmed'])
                ? '<span class="wppk-status-badge is-confirmed">✅ Confirmed</span>'
                : '<span class="wppk-status-badge is-pending">🟡 Pending</span>';
            $delivery_time = sprintf('%02d:00', (int) $row['preferred_hour']);
            $resubscribed_at = $row['status'] === 'unsubscribed' ? '—' : ($row['resubscribed_at'] ?: '—');
            echo '<tr>';
            echo '<td><input type="checkbox" class="wppk-subscriber-check" name="subscriber_ids[]" value="' . esc_attr((string) $row['id']) . '"></td>';
            echo '<td>' . esc_html($row['email']) . '</td>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>' . esc_html($row['unsubscribed_at'] ?: '—') . '</td>';
            echo '<td>' . esc_html($resubscribed_at) . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . esc_html($row['source']) . '</td>';
            echo '<td>' . $confirmation_badge . '</td>';
            echo '<td>' . esc_html($row['delivery_channel']) . '</td>';
            echo '<td>' . esc_html($delivery_time) . '</td>';
            echo '<td>' . $this->render_subscriber_actions((int) $row['id']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var input=document.getElementById("wppk_import_file");var label=document.getElementById("wppk_import_file_name");if(!input||!label)return;input.addEventListener("change",function(){label.textContent=input.files&&input.files[0]?input.files[0].name:"Aucun fichier choisi";});});</script>';
        echo $this->render_subscriber_pagination($page_num, $per_page, $total, $search, $status_filter, $channel_filter);
        if ($edit_id) {
            $edit_row = null;
            foreach ($rows as $row) {
                if ((int) $row['id'] === $edit_id) {
                    $edit_row = $row;
                    break;
                }
            }
            if ($edit_row === null) {
                global $wpdb;
                $table = $this->table_name(self::SUBSCRIBERS_TABLE);
                $edit_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id), ARRAY_A) ?: null;
            }
            if (is_array($edit_row)) {
                $this->render_subscriber_edit_drawer($edit_row, $search, $status_filter, $channel_filter, $page_num, $per_page);
            }
        }
    }

    private function render_dashboard_panel(array $settings, array $stats, array $posts, string $preview, string $preview_layout): void
    {
        $recent_subscribers = $this->get_recent_subscribers(6);
        $test_recipient = $settings['preview_email'] ?: get_option('admin_email');
        $growth = $this->get_subscriber_growth_data('day');
        $sending_summary = $this->get_stats_dashboard_data('day');
        $aws_sending_data = $this->get_aws_ses_sending_data('day');
        $overview_metrics = $this->get_dashboard_overview_metrics();
        $is_paused = $this->is_digest_paused();
        $active_audience = $this->get_active_audience();

        echo '<div class="wppk-dashboard-grid">';

        echo '<section class="wppk-panel wppk-dashboard-card wppk-dashboard-card--hero">';
        echo '<div class="wppk-panel__header"><div><h2 class="wppk-panel__title">Vue d’ensemble</h2><p class="wppk-panel__copy">Le point d’entrée rapide pour ton digest, tes abonnés et les derniers contenus.</p></div><div class="wppk-dashboard-hero-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wppk-dashboard-hero-form">';
        echo '<input type="hidden" name="action" value="wppk_switch_audience">';
        wp_nonce_field('wppk_switch_audience');
        echo '<input type="hidden" name="audience_mode" value="' . esc_attr($active_audience === 'prod' ? 'dev' : 'prod') . '">';
        echo '<button type="submit" class="button button-secondary">' . esc_html($active_audience === 'prod' ? 'Passer en DEV' : 'Passer en PROD') . '</button>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wppk-dashboard-hero-form">';
        echo '<input type="hidden" name="action" value="wppk_toggle_digest_pause">';
        wp_nonce_field('wppk_toggle_digest_pause');
        echo '<button type="submit" class="button ' . esc_attr($is_paused ? 'button-primary' : 'button-secondary') . '">' . esc_html($is_paused ? 'Reprendre' : 'Mettre en pause') . '</button>';
        echo '</form>';
        echo '<div class="wppk-version-badge">v' . esc_html(WPPKNEWSLETTER_VERSION) . '</div></div></div>';
        echo '<div class="wppk-stat-grid wppk-stat-grid--dashboard">';
        echo $this->render_stat_card(__('Abonnes actifs', 'wppknewsletter'), $overview_metrics['active_value'], $overview_metrics['active_meta']);
        echo $this->render_stat_card(__('Emails envoyes', 'wppknewsletter'), $overview_metrics['sent_value'], $overview_metrics['sent_meta']);
        echo $this->render_stat_card($overview_metrics['third_title'], $overview_metrics['third_value'], $overview_metrics['third_meta']);
        echo '</div>';
        echo '</section>';

        echo '<section class="wppk-panel wppk-dashboard-card wppk-dashboard-card--test">';
        echo '<div class="wppk-panel__header"><div><h2 class="wppk-panel__title">Envoyer un test</h2><p class="wppk-panel__copy">Déclenche uniquement un digest de test vers l’adresse de ton choix.</p></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wppk-inline-form wppk-inline-form--stack">';
        echo '<input type="hidden" name="action" value="wppk_send_test_digest">';
        echo '<input type="hidden" name="return_tab" value="dashboard">';
        wp_nonce_field('wppk_send_test_digest');
        echo '<input type="email" name="test_recipient" value="' . esc_attr($test_recipient) . '" placeholder="destinataire@test.com">';
        echo '<select name="digest_window">';
        foreach ($this->get_test_digest_window_options() as $window_key => $window_label) {
            echo '<option value="' . esc_attr($window_key) . '">' . esc_html($window_label) . '</option>';
        }
        echo '</select>';
        echo '<select name="test_email_layout" id="wppk-dashboard-test-layout">';
        foreach ($this->get_email_layout_options() as $layout_key => $layout_label) {
            echo '<option value="' . esc_attr($layout_key) . '"' . selected($preview_layout, $layout_key, false) . '>' . esc_html($layout_label) . '</option>';
        }
        echo '</select>';
        submit_button(__('Envoyer un digest de test', 'wppknewsletter'), 'primary', 'submit', false);
        echo '</form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("wppk-dashboard-test-layout");if(!s)return;s.addEventListener("change",function(){var u=new URL(window.location.href);u.searchParams.set("page","wppk-newsletter");u.searchParams.set("tab","dashboard");u.searchParams.set("dashboard_preview_layout",s.value);window.location=u.toString();});});</script>';
        echo '</section>';

        echo '<section class="wppk-panel wppk-dashboard-card wppk-dashboard-card--charts">';
        echo '<div class="wppk-panel__header"><div><h2 class="wppk-panel__title">Charts</h2><p class="wppk-panel__copy">Les métriques clés visibles directement depuis le dashboard.</p></div></div>';
        echo '<div class="wppk-dashboard-chart-grid">';
        echo $this->render_dual_line_chart_card(
            'Progression des abonnes',
            'Volume cumulé sur les 30 derniers jours',
            $growth['subscribed_series'] ?? [],
            $growth['unsubscribed_series'] ?? [],
            'Actifs',
            'Désinscrits',
            'wppk-chart-card--mini'
        );
        echo $this->render_line_chart_card(
            'Sending messages',
            is_array($aws_sending_data) ? 'Emails envoyés par AWS SES' : 'Emails envoyés par période',
            is_array($aws_sending_data) ? $aws_sending_data['sending_series'] : $sending_summary['sending_series'],
            'wppk-chart-card--mini'
        );
        echo '</div>';
        echo '</section>';

        echo '<section class="wppk-panel wppk-dashboard-card wppk-dashboard-card--subscribers">';
        echo '<div class="wppk-panel__header"><div><h2 class="wppk-panel__title">Abonnés récents</h2><p class="wppk-panel__copy">Les derniers inscrits visibles sans ouvrir la table complète.</p></div></div>';
        if (!$recent_subscribers) {
            echo '<p class="wppk-empty-copy">Aucun abonné récent.</p>';
        } else {
            echo '<div class="wppk-dashboard-subscribers">';
            foreach ($recent_subscribers as $subscriber) {
                $subscriber_date = $subscriber['subscribed_at'] ?: $subscriber['created_at'];
                echo '<div class="wppk-dashboard-subscriber">';
                echo '<div class="wppk-dashboard-subscriber__line"><span class="wppk-dashboard-subscriber__email">' . esc_html($subscriber['email']) . '</span><span class="wppk-dashboard-subscriber__meta">' . esc_html($subscriber['status']) . ' · ' . esc_html($subscriber_date) . '</span></div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="wppk-panel wppk-dashboard-card wppk-dashboard-card--preview">';
        echo '<div class="wppk-panel__header"><div><h2 class="wppk-panel__title">Preview email</h2><p class="wppk-panel__copy">La preview suit le template sélectionné dans le bloc de test.</p></div></div>';
        echo '<div class="wppk-preview-shell">';
        echo '<iframe class="wppk-preview-frame" title="Preview email" scrolling="no" sandbox="allow-same-origin" srcdoc="' . esc_attr($preview) . '"></iframe>';
        echo '</div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".wppk-preview-frame").forEach(function(frame){var resize=function(){try{var win=frame.contentWindow;var doc=frame.contentDocument||win.document;if(!doc||!doc.body)return;var body=doc.body;var html=doc.documentElement;var height=Math.max(body.scrollHeight,body.offsetHeight,body.clientHeight,html?html.scrollHeight:0,html?html.offsetHeight:0,html?html.clientHeight:0,820);frame.style.height=Math.min(height+24,1280)+"px";}catch(e){}};var bind=function(){resize();setTimeout(resize,80);setTimeout(resize,240);setTimeout(resize,600);try{var win=frame.contentWindow;if(win){win.addEventListener("resize",resize);}}catch(e){}};frame.addEventListener("load",bind);bind();});});</script>';
        echo '</section>';

        echo '</div>';
    }

    private function get_recent_subscribers(int $limit = 6): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $limit = max(1, $limit);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT email, status, created_at, subscribed_at FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    private function get_dashboard_overview_metrics(): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $active_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND confirmed = 1");
        $total_contacts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $seven_days_ago = $now->modify('-6 days')->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $subscribed_7d = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE subscribed_at >= %s", $seven_days_ago));
        $unsubscribed_7d = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE unsubscribed_at >= %s", $seven_days_ago));
        $net_growth_7d = $subscribed_7d - $unsubscribed_7d;

        $active_value = number_format_i18n($active_count, 0) . ' / ' . number_format_i18n($total_contacts, 0);
        $active_meta = sprintf(__('Base active · %+d / 7j', 'wppknewsletter'), $net_growth_7d);

        $sent_value = '—';
        $sent_meta = __('AWS SES, dernières 24h', 'wppknewsletter');
        $third_title = __('Desinscriptions', 'wppknewsletter');
        $third_value = number_format_i18n($unsubscribed_7d, 0);
        $third_meta = __('7 derniers jours', 'wppknewsletter');

        $aws_quota = $this->get_aws_ses_stats();

        if (is_array($aws_quota)) {
            $sent_value = number_format_i18n($aws_quota['sent_last_24_hours'], 0);
        } else {
            $summary = $this->get_stats_dashboard_data('day');
            $sent_value = number_format_i18n((int) ($summary['emails_total'] ?? 0), 0);
            $sent_meta = __('Historique plugin, dernières 24h', 'wppknewsletter');
        }

        if (is_array($aws_quota)) {
            $quota_remaining = max(0, (int) round($aws_quota['max_24_hour_send'] - $aws_quota['sent_last_24_hours']));
            $third_title = __('Quota restant', 'wppknewsletter');
            $third_value = number_format_i18n($quota_remaining, 0);
            $third_meta = __('AWS SES, 24h glissantes', 'wppknewsletter');
        }

        return [
            'active_value' => $active_value,
            'active_meta' => $active_meta,
            'sent_value' => $sent_value,
            'sent_meta' => $sent_meta,
            'third_title' => $third_title,
            'third_value' => $third_value,
            'third_meta' => $third_meta,
        ];
    }

    private function get_delivery_service_label(array $settings): string
    {
        if (empty($settings['smtp_enabled']) || empty($settings['smtp_host'])) {
            return 'wp_mail()';
        }

        $host = strtolower((string) $settings['smtp_host']);

        if (str_contains($host, 'amazonses.com')) {
            return 'AWS SES';
        }

        if (str_contains($host, 'gmail.com') || str_contains($host, 'google')) {
            return 'Gmail';
        }

        if (str_contains($host, 'ovh.net') || str_contains($host, 'ovhcloud')) {
            return 'OVH';
        }

        return (string) $settings['smtp_host'];
    }

    private function get_aws_ses_config(): ?array
    {
        $settings = $this->get_settings();
        $region = trim((string) ($settings['aws_ses_region'] ?? ''));
        $access_key = trim((string) ($settings['aws_ses_access_key_id'] ?? ''));
        $secret_key = trim((string) ($settings['aws_ses_secret_access_key'] ?? ''));
        $session_token = defined('WPPK_AWS_SES_SESSION_TOKEN') ? trim((string) WPPK_AWS_SES_SESSION_TOKEN) : '';

        if ($region === '' && defined('WPPK_AWS_SES_REGION')) {
            $region = trim((string) WPPK_AWS_SES_REGION);
        }
        if ($access_key === '' && defined('WPPK_AWS_SES_ACCESS_KEY_ID')) {
            $access_key = trim((string) WPPK_AWS_SES_ACCESS_KEY_ID);
        }
        if ($secret_key === '' && defined('WPPK_AWS_SES_SECRET_ACCESS_KEY')) {
            $secret_key = trim((string) WPPK_AWS_SES_SECRET_ACCESS_KEY);
        }

        if ($region === '' && !empty($settings['smtp_host']) && preg_match('/email-smtp\.([a-z0-9-]+)\.amazonaws\.com/i', (string) $settings['smtp_host'], $matches)) {
            $region = strtolower($matches[1]);
        }

        if ($region === '' || $access_key === '' || $secret_key === '') {
            return null;
        }

        return [
            'region' => $region,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'session_token' => $session_token,
        ];
    }

    private function get_aws_ses_stats(): ?array
    {
        $config = $this->get_aws_ses_config();
        if ($config === null) {
            return null;
        }

        $cache_key = self::AWS_STATS_TRANSIENT . '_' . md5($config['region'] . '|' . $config['access_key']);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->aws_ses_query_request($config, 'GetSendQuota');
        if (!is_array($result)) {
            return null;
        }

        $stats = [
            'sent_last_24_hours' => isset($result['SentLast24Hours']) ? (float) $result['SentLast24Hours'] : 0.0,
            'max_24_hour_send' => isset($result['Max24HourSend']) ? (float) $result['Max24HourSend'] : 0.0,
            'max_send_rate' => isset($result['MaxSendRate']) ? (float) $result['MaxSendRate'] : 0.0,
            'region' => $config['region'],
        ];

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    private function get_aws_ses_send_statistics_raw(): ?array
    {
        $config = $this->get_aws_ses_config();
        if ($config === null) {
            return null;
        }

        $cache_key = self::AWS_STATS_TRANSIENT . '_history_' . md5($config['region'] . '|' . $config['access_key']);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->aws_ses_query_request($config, 'GetSendStatistics');
        if (!is_array($result) || empty($result['SendDataPoints']) || !is_array($result['SendDataPoints'])) {
            return null;
        }

        set_transient($cache_key, $result['SendDataPoints'], 5 * MINUTE_IN_SECONDS);

        return $result['SendDataPoints'];
    }

    private function get_aws_ses_sending_data(string $range): ?array
    {
        $raw_points = $this->get_aws_ses_send_statistics_raw();
        if (!is_array($raw_points) || !$raw_points) {
            return null;
        }

        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $buckets = [];
        $recent_logs = [];

        if ($range === 'week') {
            for ($i = 6; $i >= 0; $i--) {
                $bucket_time = $now->setTime(0, 0)->modify("-{$i} days");
                $bucket_key = $bucket_time->format('Y-m-d');
                $buckets[$bucket_key] = [
                    'label' => $bucket_time->format('M d'),
                    'start' => $bucket_time,
                    'end' => $bucket_time->setTime(23, 59, 59),
                    'value' => 0,
                ];
            }
        } elseif ($range === 'month') {
            for ($i = 13; $i >= 0; $i--) {
                $bucket_time = $now->setTime(0, 0)->modify("-{$i} days");
                $bucket_key = $bucket_time->format('Y-m-d');
                $buckets[$bucket_key] = [
                    'label' => $bucket_time->format('M d'),
                    'start' => $bucket_time,
                    'end' => $bucket_time->setTime(23, 59, 59),
                    'value' => 0,
                ];
            }
        } else {
            for ($i = 23; $i >= 0; $i--) {
                $bucket_time = $now->setTime((int) $now->format('H'), 0, 0)->modify("-{$i} hours");
                $bucket_key = $bucket_time->format('Y-m-d H:00');
                $buckets[$bucket_key] = [
                    'label' => $bucket_time->format('H:i'),
                    'start' => $bucket_time,
                    'end' => $bucket_time->setTime((int) $bucket_time->format('H'), 59, 59),
                    'value' => 0,
                ];
            }
        }

        $daily_rollup = [];
        foreach ($raw_points as $point) {
            if (empty($point['Timestamp'])) {
                continue;
            }

            $timestamp = new DateTimeImmutable((string) $point['Timestamp']);
            $local_time = $timestamp->setTimezone($tz);
            $delivery_attempts = (int) round((float) ($point['DeliveryAttempts'] ?? 0));

            $day_key = $local_time->format('Y-m-d');
            if (!isset($daily_rollup[$day_key])) {
                $daily_rollup[$day_key] = [
                    'sent_at' => $local_time->setTime(0, 0)->format('Y-m-d H:i:s'),
                    'emails_sent' => 0,
                    'posts_count' => '—',
                ];
            }
            $daily_rollup[$day_key]['emails_sent'] += $delivery_attempts;

            $bucket_key = $range === 'day'
                ? $local_time->format('Y-m-d H:00')
                : $day_key;

            if (isset($buckets[$bucket_key])) {
                $buckets[$bucket_key]['value'] += $delivery_attempts;
            }
        }

        foreach (array_reverse($daily_rollup, true) as $row) {
            $recent_logs[] = $row;
            if (count($recent_logs) >= 12) {
                break;
            }
        }

        $sending_series = [];
        foreach ($buckets as $bucket) {
            $sending_series[] = [
                'label' => $bucket['label'],
                'value' => (int) $bucket['value'],
            ];
        }

        return [
            'sending_series' => $sending_series,
            'recent_logs' => $recent_logs,
        ];
    }

    private function aws_ses_query_request(array $config, string $action): ?array
    {
        $service = 'ses';
        $host = 'email.' . $config['region'] . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/';
        $content_type = 'application/x-www-form-urlencoded; charset=utf-8';
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        $body = http_build_query(
            [
                'Action' => $action,
                'Version' => '2010-12-01',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $payload_hash = hash('sha256', $body);

        $canonical_headers = 'content-type:' . $content_type . "\n" . 'host:' . $host . "\n" . 'x-amz-date:' . $amz_date . "\n";
        $signed_headers = 'content-type;host;x-amz-date';

        if (!empty($config['session_token'])) {
            $canonical_headers .= 'x-amz-security-token:' . trim((string) $config['session_token']) . "\n";
            $signed_headers .= ';x-amz-security-token';
        }

        $canonical_request = implode(
            "\n",
            [
                'POST',
                '/',
                '',
                $canonical_headers,
                $signed_headers,
                $payload_hash,
            ]
        );

        $credential_scope = $date_stamp . '/' . $config['region'] . '/' . $service . '/aws4_request';
        $string_to_sign = implode(
            "\n",
            [
                'AWS4-HMAC-SHA256',
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request),
            ]
        );
        $signing_key = $this->aws_signing_key($config['secret_key'], $date_stamp, $config['region'], $service);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

        $headers = [
            'Content-Type' => $content_type,
            'Host' => $host,
            'X-Amz-Date' => $amz_date,
            'Authorization' => $authorization,
        ];

        if (!empty($config['session_token'])) {
            $headers['X-Amz-Security-Token'] = $config['session_token'];
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 12,
                'headers' => $headers,
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return null;
        }

        $xml_string = wp_remote_retrieve_body($response);
        if ($xml_string === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);
        if (!$xml instanceof SimpleXMLElement) {
            return null;
        }

        $result_node = $xml->{$action . 'Response'}->{$action . 'Result'} ?? null;
        if (!$result_node instanceof SimpleXMLElement && isset($xml->{$action . 'Result'})) {
            $result_node = $xml->{$action . 'Result'};
        }

        if (!$result_node instanceof SimpleXMLElement) {
            return null;
        }

        $data = [];
        foreach ($result_node->children() as $child) {
            if ($child->getName() === 'SendDataPoints') {
                $points = [];
                foreach ($child->children() as $member) {
                    $point = [];
                    foreach ($member->children() as $item) {
                        $point[$item->getName()] = (string) $item;
                    }
                    if ($point) {
                        $points[] = $point;
                    }
                }
                $data['SendDataPoints'] = $points;
                continue;
            }
            $data[$child->getName()] = (string) $child;
        }

        return $data ?: null;
    }

    private function aws_signing_key(string $secret_key, string $date_stamp, string $region, string $service): string
    {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    private function render_subscriber_actions(int $subscriber_id): string
    {
        $search = sanitize_text_field(wp_unslash($_GET['subscriber_s'] ?? ''));
        $status_filter = sanitize_key($_GET['subscriber_status'] ?? '');
        $channel_filter = sanitize_text_field(wp_unslash($_GET['subscriber_channel'] ?? ''));
        $page_num = max(1, absint($_GET['subscriber_page_num'] ?? 1));
        $per_page = max(1, absint($_GET['subscriber_per_page'] ?? 100));
        $edit_url = add_query_arg(
            [
                'page' => 'wppk-newsletter',
                'tab' => 'subscribers',
                'edit_subscriber' => $subscriber_id,
                'subscriber_s' => $search,
                'subscriber_status' => $status_filter,
                'subscriber_channel' => $channel_filter,
                'subscriber_page_num' => $page_num,
                'subscriber_per_page' => $per_page,
            ],
            admin_url('admin.php')
        );
        ob_start();
        ?>
        <div class="wppk-row-actions">
            <a href="<?php echo esc_url($edit_url); ?>" class="button button-secondary">Editer</a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_subscriber_edit_drawer(array $row, string $search, string $status_filter, string $channel_filter, int $page_num, int $per_page): void
    {
        $cancel_url = add_query_arg(
            [
                'page' => 'wppk-newsletter',
                'tab' => 'subscribers',
                'subscriber_s' => $search,
                'subscriber_status' => $status_filter,
                'subscriber_channel' => $channel_filter,
                'subscriber_page_num' => $page_num,
                'subscriber_per_page' => $per_page,
            ],
            admin_url('admin.php')
        );
        ?>
        <div class="wppk-drawer-backdrop">
            <a class="wppk-drawer-backdrop__scrim" href="<?php echo esc_url($cancel_url); ?>" aria-label="Fermer l’édition"></a>
            <aside class="wppk-subscriber-drawer" aria-label="Édition de l’abonné">
                <div class="wppk-subscriber-drawer__header">
                    <div>
                        <h3 class="wppk-subscriber-drawer__title">Éditer l’abonné</h3>
                        <p class="wppk-subscriber-drawer__copy"><?php echo esc_html($row['email']); ?></p>
                    </div>
                </div>
                <?php $this->render_subscriber_edit_form($row, $cancel_url); ?>
            </aside>
        </div>
        <?php
    }

    private function render_subscriber_edit_form(array $row, string $cancel_url = ''): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppk-edit-form">
            <input type="hidden" name="action" value="wppk_update_subscriber">
            <input type="hidden" name="subscriber_id" value="<?php echo esc_attr((string) $row['id']); ?>">
            <?php wp_nonce_field('wppk_update_subscriber'); ?>
            <div class="wppk-edit-grid">
                <div><label>Email</label><input type="email" name="email" value="<?php echo esc_attr($row['email']); ?>" required></div>
                <div><label>Statut</label><select name="status"><option value="active" <?php selected($row['status'], 'active'); ?>>Active</option><option value="pending" <?php selected($row['status'], 'pending'); ?>>Pending</option><option value="unsubscribed" <?php selected($row['status'], 'unsubscribed'); ?>>Unsubscribed</option></select></div>
                <div><label>Canal</label><select name="delivery_channel"><?php foreach ($this->get_delivery_channel_options() as $option) : ?><option value="<?php echo esc_attr($option); ?>" <?php selected($row['delivery_channel'], $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?></select></div>
                <div><label>Heure</label><input type="number" min="0" max="23" name="preferred_hour" value="<?php echo esc_attr((string) $row['preferred_hour']); ?>"></div>
                <div class="wppk-edit-check">
                    <label class="wppk-edit-toggle">
                        <input type="checkbox" name="confirmed" value="1" <?php checked((int) $row['confirmed'], 1); ?>>
                        <span class="wppk-edit-toggle__ui">
                            <span class="wppk-edit-toggle__label">Confirmation</span>
                            <span class="wppk-edit-toggle__value"><?php echo !empty($row['confirmed']) ? 'Confirmé' : 'En attente'; ?></span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="wppk-edit-actions">
                <?php submit_button(__('Sauvegarder les changements', 'wppknewsletter'), 'primary', '', false); ?>
                <?php if (empty($row['confirmed'])) : ?>
                    <button type="submit" name="resend_confirmation" value="1" class="button button-secondary">Renvoyer confirmation</button>
                <?php endif; ?>
                <?php if ($cancel_url !== '') : ?>
                    <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
        <?php
    }

    private function render_subscriber_pagination(int $page_num, int $per_page, int $total, string $search, string $status_filter, string $channel_filter): string
    {
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($total_pages <= 1) {
            return '';
        }

        $base_args = [
            'page' => 'wppk-newsletter',
            'tab' => 'subscribers',
            'subscriber_s' => $search,
            'subscriber_status' => $status_filter,
            'subscriber_channel' => $channel_filter,
            'subscriber_per_page' => $per_page,
        ];

        $prev_url = add_query_arg(array_merge($base_args, ['subscriber_page_num' => max(1, $page_num - 1)]), admin_url('admin.php'));
        $next_url = add_query_arg(array_merge($base_args, ['subscriber_page_num' => min($total_pages, $page_num + 1)]), admin_url('admin.php'));
        $links = '';
        $start = max(1, $page_num - 2);
        $end = min($total_pages, $page_num + 2);

        if ($start > 1) {
            $first_url = add_query_arg(array_merge($base_args, ['subscriber_page_num' => 1]), admin_url('admin.php'));
            $links .= '<a class="wppk-pagination__item" href="' . esc_url($first_url) . '">1</a>';
            if ($start > 2) {
                $links .= '<span class="wppk-pagination__ellipsis">…</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $url = add_query_arg(array_merge($base_args, ['subscriber_page_num' => $i]), admin_url('admin.php'));
            $class = 'wppk-pagination__item' . ($i === $page_num ? ' is-active' : '');
            $links .= '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html((string) $i) . '</a>';
        }

        if ($end < $total_pages) {
            if ($end < $total_pages - 1) {
                $links .= '<span class="wppk-pagination__ellipsis">…</span>';
            }
            $last_url = add_query_arg(array_merge($base_args, ['subscriber_page_num' => $total_pages]), admin_url('admin.php'));
            $links .= '<a class="wppk-pagination__item" href="' . esc_url($last_url) . '">' . esc_html((string) $total_pages) . '</a>';
        }

        return '<div class="wppk-pagination"><a class="button button-secondary" href="' . esc_url($prev_url) . '">Précédent</a><div class="wppk-pagination__pages">' . $links . '</div><span class="wppk-pagination__summary">Page ' . esc_html((string) $page_num) . ' / ' . esc_html((string) $total_pages) . '</span><a class="button button-secondary" href="' . esc_url($next_url) . '">Suivant</a></div>';
    }

    private function render_stat_card(string $title, string $value, string $meta): string
    {
        return sprintf(
            '<div class="wppk-stat-card">
                <div class="wppk-stat-card__label">%1$s</div>
                <div class="wppk-stat-card__value">%2$s</div>
                <div class="wppk-stat-card__meta">%3$s</div>
            </div>',
            esc_html($title),
            esc_html($value),
            esc_html($meta)
        );
    }

    private function get_contextual_stat_cards(string $tab, array $settings, array $stats, array $posts): array
    {
        global $wpdb;

        if ($tab === 'settings') {
            return [
                [
                    'title' => __('Template', 'wppknewsletter'),
                    'value' => (string) ($this->get_email_layout_options()[$settings['email_layout']] ?? $settings['email_layout']),
                    'meta' => __('Mise en forme sélectionnée', 'wppknewsletter'),
                ],
                [
                    'title' => __('Thème', 'wppknewsletter'),
                    'value' => (string) ($this->get_email_theme_options()[$settings['email_theme']] ?? $settings['email_theme']),
                    'meta' => __('Palette active pour la preview', 'wppknewsletter'),
                ],
                [
                    'title' => __('Service d’expédition', 'wppknewsletter'),
                    'value' => $this->get_delivery_service_label($settings),
                    'meta' => __('Transport utilisé pour les envois du plugin', 'wppknewsletter'),
                ],
            ];
        }

        if ($tab === 'subscribers') {
            $table = $this->table_name(self::SUBSCRIBERS_TABLE);
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $unsubscribed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unsubscribed'");

            return [
                [
                    'title' => __('Abonnés actifs', 'wppknewsletter'),
                    'value' => (string) $stats['active'],
                    'meta' => __('Base de diffusion active', 'wppknewsletter'),
                ],
                [
                    'title' => __('Total contacts', 'wppknewsletter'),
                    'value' => (string) $total,
                    'meta' => __('Tous statuts confondus', 'wppknewsletter'),
                ],
                [
                    'title' => __('Désinscrits', 'wppknewsletter'),
                    'value' => (string) $unsubscribed,
                    'meta' => __('Historique conservé', 'wppknewsletter'),
                ],
            ];
        }

        if ($tab === 'stats') {
            $summary = $this->get_stats_dashboard_data('day');
            $aws_ses_stats = $this->get_aws_ses_stats();

            if (is_array($aws_ses_stats)) {
                $quota_remaining = max(0, (int) round($aws_ses_stats['max_24_hour_send'] - $aws_ses_stats['sent_last_24_hours']));

                return [
                    [
                        'title' => __('Emails envoyés', 'wppknewsletter'),
                        'value' => number_format_i18n($aws_ses_stats['sent_last_24_hours'], 0),
                        'meta' => __('AWS SES, dernières 24h', 'wppknewsletter'),
                    ],
                    [
                        'title' => __('Quota restant', 'wppknewsletter'),
                        'value' => number_format_i18n($quota_remaining, 0),
                        'meta' => __('AWS SES, fenêtre glissante 24h', 'wppknewsletter'),
                    ],
                    [
                        'title' => __('Débit max', 'wppknewsletter'),
                        'value' => number_format_i18n($aws_ses_stats['max_send_rate'], 0) . '/s',
                        'meta' => sprintf(__('AWS SES, region %s', 'wppknewsletter'), strtoupper($aws_ses_stats['region'])),
                    ],
                ];
            }

            return [
                [
                    'title' => __('Emails envoyés', 'wppknewsletter'),
                    'value' => (string) $summary['emails_total'],
                    'meta' => __('Historique interne du plugin', 'wppknewsletter'),
                ],
                [
                    'title' => __('Campagnes', 'wppknewsletter'),
                    'value' => (string) $summary['campaigns_total'],
                    'meta' => __('Déclenchements enregistrés localement', 'wppknewsletter'),
                ],
                [
                    'title' => __('Dernier envoi', 'wppknewsletter'),
                    'value' => get_option('wppk_newsletter_last_sent_date', 'jamais'),
                    'meta' => __('Dernière date de campagne', 'wppknewsletter'),
                ],
            ];
        }

        return [
            [
                'title' => __('Abonnés actifs', 'wppknewsletter'),
                'value' => (string) $stats['active'],
                'meta' => __('Base de diffusion active', 'wppknewsletter'),
            ],
            [
                'title' => __('Posts du jour', 'wppknewsletter'),
                'value' => (string) $stats['posts_today'],
                'meta' => __('Contenu injecte dans le prochain digest', 'wppknewsletter'),
            ],
            [
                'title' => __('Dernier envoi', 'wppknewsletter'),
                'value' => get_option('wppk_newsletter_last_sent_date', 'jamais'),
                'meta' => __('Dernière date de campagne', 'wppknewsletter'),
            ],
        ];
    }

    private function render_posts_table(array $posts): void
    {
        if (!$posts) {
            echo '<p>Aucun post publie ou planifie aujourd\'hui.</p>';
            return;
        }
        echo '<div class="wppk-post-preview-grid">';
        foreach ($posts as $post) {
            $cats = get_the_category($post->ID);
            $label = $cats ? $cats[0]->name : 'Article';
            $thumb = get_the_post_thumbnail_url($post, 'medium_large');
            $excerpt = wp_trim_words(wp_strip_all_tags(get_the_excerpt($post) ?: $post->post_content), 26);
            $status = get_post_status($post);
            $is_scheduled = $status === 'future';
            $status_label = $this->format_post_status_label($status);
            $status_meta = $is_scheduled
                ? 'Planifié · ' . wp_date('H:i', get_post_timestamp($post, 'date'))
                : 'Publié';
            $link = $is_scheduled ? get_preview_post_link($post) : get_permalink($post);

            echo '<article class="wppk-post-card">';
            if ($thumb) {
                echo '<div class="wppk-post-card__media"><img src="' . esc_url($thumb) . '" alt="" /></div>';
            } else {
                echo '<div class="wppk-post-card__media is-empty">Aucune image</div>';
            }
            echo '<div class="wppk-post-card__body">';
            echo '<div class="wppk-post-card__meta">';
            echo '<span class="wppk-post-card__badge">' . esc_html($label) . '</span>';
            echo '<span class="wppk-post-card__date">' . esc_html(wp_date('d/m/Y H:i', get_post_timestamp($post, 'date'))) . '</span>';
            echo '</div>';
            echo '<h3 class="wppk-post-card__title"><a href="' . esc_url($link) . '" target="_blank" rel="noreferrer">' . esc_html(get_the_title($post)) . '</a></h3>';
            echo '<p class="wppk-post-card__excerpt">' . esc_html($excerpt) . '</p>';
            echo '<div class="wppk-post-card__footer">';
            echo '<span class="wppk-post-card__status' . ($is_scheduled ? ' is-scheduled' : '') . '">' . esc_html($status_label) . '</span>';
            echo '<a class="wppk-post-card__link" href="' . esc_url($link) . '" target="_blank" rel="noreferrer">' . esc_html($status_meta) . '</a>';
            echo '</div>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
    }

    private function render_logs_table(): void
    {
        $range = sanitize_key($_GET['stats_range'] ?? 'day');
        if (!in_array($range, ['day', 'week', 'month'], true)) {
            $range = 'day';
        }

        $summary = $this->get_stats_dashboard_data($range);
        $aws_ses_stats = $this->get_aws_ses_stats();
        $aws_sending_data = is_array($aws_ses_stats) ? $this->get_aws_ses_sending_data($range) : null;

        echo '<div class="wppk-stats-toolbar">';
        echo '<div class="wppk-stats-range">';
        echo $this->render_range_switch('day', 'Day', $range, 'overview');
        echo $this->render_range_switch('week', 'Week', $range, 'overview');
        echo $this->render_range_switch('month', 'Month', $range, 'overview');
        echo '</div>';
        echo '</div>';
        echo '<p class="description" style="margin:8px 0 12px;"><a href="https://eu-north-1.console.aws.amazon.com/ses/home?region=eu-north-1#/account" target="_blank" rel="noreferrer">Ouvrir le dashboard AWS SES</a></p>';

        echo '<div class="wppk-stat-grid wppk-stat-grid--compact" style="margin-top:8px;">';
        if (is_array($aws_ses_stats)) {
            $quota_remaining = max(0, (int) round($aws_ses_stats['max_24_hour_send'] - $aws_ses_stats['sent_last_24_hours']));
            $month_emails = $this->get_current_month_email_total();
            $estimated_cost = $this->format_estimated_ses_cost((float) $month_emails);
            echo $this->render_stat_card(__('Emails envoyes', 'wppknewsletter'), number_format_i18n($aws_ses_stats['sent_last_24_hours'], 0), __('AWS SES, dernieres 24h', 'wppknewsletter'));
            echo $this->render_stat_card(__('Quota restant', 'wppknewsletter'), number_format_i18n($quota_remaining, 0), __('AWS SES, fenetre glissante 24h', 'wppknewsletter'));
            echo $this->render_stat_card(__('Debit max', 'wppknewsletter'), number_format_i18n($aws_ses_stats['max_send_rate'], 0) . '/s', sprintf(__('AWS SES, region %s', 'wppknewsletter'), strtoupper($aws_ses_stats['region'])));
            echo $this->render_stat_card(__('Cout estime', 'wppknewsletter'), $estimated_cost, sprintf(__('Mois en cours (%s) · Base SES 0,10 USD / 1 000 emails', 'wppknewsletter'), number_format_i18n($month_emails, 0)));
        } else {
            $month_emails = $this->get_current_month_email_total();
            $estimated_cost = $this->format_estimated_ses_cost((float) $month_emails);
            echo $this->render_stat_card(__('Emails envoyes', 'wppknewsletter'), (string) $summary['emails_total'], __('Historique interne du plugin', 'wppknewsletter'));
            echo $this->render_stat_card(__('Campagnes', 'wppknewsletter'), (string) $summary['campaigns_total'], __('Declenchements enregistres localement', 'wppknewsletter'));
            echo $this->render_stat_card(__('Dernier envoi', 'wppknewsletter'), get_option('wppk_newsletter_last_sent_date', 'jamais'), __('Derniere date de campagne', 'wppknewsletter'));
            echo $this->render_stat_card(__('Cout estime', 'wppknewsletter'), $estimated_cost, sprintf(__('Mois en cours (%s) · Base SES 0,10 USD / 1 000 emails', 'wppknewsletter'), number_format_i18n($month_emails, 0)));
        }
        echo '</div>';

        echo '<h3 class="wppk-stats-section-title">Sending messages</h3>';
        if (!is_array($aws_ses_stats)) {
            echo '<p class="description" style="margin:8px 0 0;">' . esc_html__('Stats AWS SES indisponibles. Renseigne la region, l’Access Key ID et la Secret Access Key dans Reglages > SMTP.', 'wppknewsletter') . '</p>';
        }

        echo $this->render_line_chart_card(
            'Sending messages',
            is_array($aws_sending_data) ? 'Emails envoyes par AWS SES' : 'Emails envoyes par periode',
            is_array($aws_sending_data) ? $aws_sending_data['sending_series'] : $summary['sending_series']
        );

        $recent_logs = is_array($aws_sending_data) ? ($aws_sending_data['recent_logs'] ?? []) : $summary['recent_logs'];
        if (!empty($recent_logs)) {
            echo '<h3 class="wppk-stats-section-title">Historique recent</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Emails envoyes</th><th>Posts inclus</th></tr></thead><tbody>';
            foreach ($recent_logs as $row) {
                $date_only = !empty($row['sent_at']) ? wp_date('Y-m-d', strtotime((string) $row['sent_at'])) : '—';
                echo '<tr>';
                echo '<td>' . esc_html($date_only) . '</td>';
                echo '<td>' . esc_html($row['emails_sent']) . '</td>';
                echo '<td>' . esc_html($row['posts_count']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function get_digest_posts(string $digest_window = 'today'): array
    {
        $settings = $this->get_settings();
        $digest_context = $this->get_digest_context($digest_window);

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => (int) $settings['posts_per_digest'],
            'date_query' => [[
                'after' => $digest_context['start'],
                'before' => $digest_context['end'],
                'inclusive' => true,
            ]],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return $query->posts;
    }

    private function get_daily_posts_overview(): array
    {
        $digest_context = $this->get_digest_context('today');

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => ['publish', 'future'],
            'posts_per_page' => -1,
            'date_query' => [[
                'after' => $digest_context['start'],
                'before' => $digest_context['end'],
                'inclusive' => true,
                'column' => 'post_date',
            ]],
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        return $query->posts;
    }

    private function send_digest_to_active_subscribers(bool $force): array
    {
        $posts = $this->get_digest_posts();
        if (!$posts && !$force) {
            return ['sent' => 0, 'reason' => 'Aucun post publie aujourd\'hui.'];
        }

        $settings = $this->get_settings();
        $subscribers = $this->get_active_subscribers();
        if (!$subscribers) {
            return ['sent' => 0, 'reason' => 'Aucun abonne actif. Inscris d\'abord ton email via le formulaire.'];
        }

        $sent = 0;

        foreach ($subscribers as $subscriber) {
            $html = $this->build_email_html($posts, $settings, $subscriber['email'], $subscriber['unsubscribe_token']);
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>',
            ];

            if (!empty($settings['reply_to'])) {
                $headers[] = 'Reply-To: ' . $settings['reply_to'];
            }

            $subject = $this->render_subject($settings['subject']);
            if (wp_mail($subscriber['email'], $subject, $html, $headers)) {
                $sent++;
            }
        }

        $this->log_send($sent, count($posts));

        if ($sent === 0) {
            return ['sent' => 0, 'reason' => 'Aucun email envoye. Verifie wp_mail(), le spam ou la config serveur.'];
        }

        return ['sent' => $sent, 'reason' => ''];
    }

    private function send_test_digest(string $recipient, string $digest_window = 'today', ?string $test_layout = null): array
    {
        $digest_context = $this->get_digest_context($digest_window);
        $posts = $this->get_digest_posts($digest_window);
        $settings = $this->get_settings();
        if ($test_layout) {
            $settings['email_layout'] = $test_layout;
        }
        $subscriber = $this->get_subscriber_by_email($recipient);
        $unsubscribe_token = $subscriber['unsubscribe_token'] ?? '';
        $html = $this->build_email_html($posts, $settings, $recipient, $unsubscribe_token, $digest_context);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>',
        ];

        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . $settings['reply_to'];
        }

        $subject = $this->render_subject($settings['subject'], true, $digest_context['timestamp']);

        if (wp_mail($recipient, $subject, $html, $headers)) {
            $layout_label = $this->get_email_layout_options()[$settings['email_layout']] ?? $settings['email_layout'];
            return ['reason' => sprintf('Digest de test (%s, %s) envoyé à %s.', $digest_context['label'], $layout_label, $recipient)];
        }

        return ['reason' => 'Échec de l’envoi test. Vérifie wp_mail(), le spam ou la config serveur.'];
    }

    private function build_email_html(array $posts, array $settings, string $recipient, string $unsubscribe_token = '', ?array $digest_context = null): string
    {
        $site_name = $settings['brand_name'];
        $accent = $settings['accent_color'];
        $theme = $this->get_email_theme_palette($settings['email_theme'] ?? 'paper');
        $unsubscribe_url = $unsubscribe_token ? add_query_arg('wppk_unsubscribe', rawurlencode($unsubscribe_token), home_url('/')) : '';
        $digest_context = $digest_context ?? $this->get_digest_context('today');

        ob_start();
        include WPPKNEWSLETTER_PATH . 'templates/email-digest.php';
        return (string) ob_get_clean();
    }

    private function sanitize_test_digest_window(string $value): string
    {
        return in_array($value, ['today', 'yesterday'], true) ? $value : 'today';
    }

    private function get_test_digest_window_options(): array
    {
        return [
            'today' => 'Aujourd’hui',
            'yesterday' => 'Hier',
        ];
    }

    private function get_digest_context(string $digest_window = 'today'): array
    {
        $digest_window = $this->sanitize_test_digest_window($digest_window);
        $timestamp = $digest_window === 'yesterday'
            ? strtotime('-1 day', current_time('timestamp'))
            : current_time('timestamp');

        return [
            'window' => $digest_window,
            'timestamp' => $timestamp,
            'start' => wp_date('Y-m-d 00:00:00', $timestamp),
            'end' => wp_date('Y-m-d 23:59:59', $timestamp),
            'label' => $digest_window === 'yesterday' ? 'hier' : 'aujourd’hui',
            'header_title' => $digest_window === 'yesterday' ? 'Le digest d’hier' : 'Le digest du jour',
            'selection_title' => $digest_window === 'yesterday' ? 'Sélection d’hier' : 'Sélection du jour',
            'empty_text' => $digest_window === 'yesterday' ? 'Aucun nouvel article hier.' : 'Aucun nouvel article aujourd\'hui.',
        ];
    }

    private function get_active_subscribers(): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        return $wpdb->get_results("SELECT email, unsubscribe_token FROM {$table} WHERE status = 'active' AND confirmed = 1 ORDER BY id ASC", ARRAY_A) ?: [];
    }

    private function get_subscriber_by_email(string $email): ?array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, email, status, unsubscribe_token, confirmed FROM {$table} WHERE email = %s LIMIT 1", $email),
            ARRAY_A
        );

        return $row ?: null;
    }

    private function get_subscribers(string $search = '', string $status_filter = '', string $channel_filter = '', int $page_num = 1, int $per_page = 100): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = 'email LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($status_filter !== '') {
            $where[] = 'status = %s';
            $params[] = $status_filter;
        }
        if ($channel_filter !== '') {
            $where[] = 'delivery_channel = %s';
            $params[] = $channel_filter;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = max(0, ($page_num - 1) * $per_page);

        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT id, email, status, created_at, subscribed_at, unsubscribed_at, resubscribed_at, source, signup_process, delivery_channel, content_mode, preferred_hour, confirmed, confirmation_token, confirmation_sent_at, confirmed_at
                FROM {$table}
                {$where_sql}
                ORDER BY id DESC
                LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$query_params), ARRAY_A) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    private function get_distinct_channels(): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        return $wpdb->get_col("SELECT DISTINCT delivery_channel FROM {$table} WHERE delivery_channel <> '' ORDER BY delivery_channel ASC") ?: [];
    }

    private function get_delivery_channel_options(): array
    {
        return ['Daily digest', 'Weekly digest', 'Single email'];
    }

    private function get_content_mode_options(): array
    {
        return ['Full stories', 'Headlines only'];
    }

    private function sanitize_delivery_channel($value): string
    {
        $value = sanitize_text_field((string) $value);
        return in_array($value, $this->get_delivery_channel_options(), true) ? $value : 'Daily digest';
    }

    private function sanitize_content_mode($value): string
    {
        $value = sanitize_text_field((string) $value);
        return in_array($value, $this->get_content_mode_options(), true) ? $value : 'Full stories';
    }

    private function map_import_row(array $header, array $row): array
    {
        $data = [];
        foreach ($row as $index => $value) {
            $key = $header[$index] ?? 'email';
            $data[$key] = trim((string) $value);
        }

        if (!isset($data['email']) && isset($row[0])) {
            $data['email'] = trim((string) $row[0]);
        }

        return $data;
    }

    private function normalize_csv_cell(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = str_replace("\xC2\xA0", ' ', $value);

        return strtolower(trim($value));
    }

    private function normalize_import_header(string $value): string
    {
        $value = str_replace(['é', 'è', 'ê', 'ë'], 'e', $value);
        $value = str_replace(['à', 'â'], 'a', $value);
        $value = str_replace(['î', 'ï'], 'i', $value);
        $value = str_replace(['ô', 'ö'], 'o', $value);
        $value = str_replace(['û', 'ü', 'ù'], 'u', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        $aliases = [
            'e_mail' => 'email',
            'mail' => 'email',
            'email_subscriber' => 'subscriber_status',
            'categories' => 'categories',
            'categories_' => 'categories',
        ];

        return $aliases[$value] ?? $value;
    }

    private function normalize_import_status(array $data): string
    {
        $raw = strtolower(trim((string) ($data['status'] ?? $data['subscriber_status'] ?? 'active')));

        if ($raw === '' || in_array($raw, ['subscribed', 'active'], true)) {
            return 'active';
        }

        if (in_array($raw, ['pending', 'unconfirmed'], true)) {
            return 'pending';
        }

        if (in_array($raw, ['not subscribed', 'unsubscribed', 'inactive'], true)) {
            return 'unsubscribed';
        }

        return 'active';
    }

    private function detect_csv_delimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function is_empty_csv_row(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function push_import_example(array &$examples, int $lineNumber, string $reason, string $value = ''): void
    {
        if (count($examples) >= 8) {
            return;
        }

        $examples[] = [
            'line' => $lineNumber,
            'reason' => $reason,
            'value' => $value,
        ];
    }

    private function log_send(int $emails_sent, int $posts_count): void
    {
        global $wpdb;
        $table = $this->table_name(self::LOG_TABLE);
        $wpdb->insert($table, [
            'sent_at' => current_time('mysql', true),
            'emails_sent' => $emails_sent,
            'posts_count' => $posts_count,
        ]);
    }

    private function get_stats(): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND confirmed = 1");
        $unsubscribed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unsubscribed'");

        return [
            'active' => $active,
            'unsubscribed' => $unsubscribed,
            'posts_today' => count($this->get_daily_posts_overview()),
        ];
    }

    private function format_post_status_label(string $status): string
    {
        if ($status === 'future') {
            return 'Planifié';
        }

        if ($status === 'publish') {
            return 'Publié';
        }

        return ucfirst($status);
    }

    private function get_stats_dashboard_data(string $range): array
    {
        global $wpdb;
        $subscriber_table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $log_table = $this->table_name(self::LOG_TABLE);
        $periods = $this->build_periods($range);

        $follower_series = [];
        $subscribed_series = [];
        $unsubscribed_series = [];
        $sending_series = [];
        $emails_total = 0;
        $campaigns_total = 0;
        $posts_total = 0;

        foreach ($periods as $period) {
            $followers_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$subscriber_table} WHERE subscribed_at <= %s AND (unsubscribed_at IS NULL OR unsubscribed_at > %s)",
                    $period['end']
                    ,$period['end']
                )
            );
            $subscribed_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$subscriber_table} WHERE subscribed_at >= %s AND subscribed_at <= %s",
                    $period['start'],
                    $period['end']
                )
            );
            $unsubscribed_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$subscriber_table} WHERE unsubscribed_at >= %s AND unsubscribed_at <= %s",
                    $period['start'],
                    $period['end']
                )
            );
            $follower_series[] = [
                'label' => $period['label'],
                'value' => $followers_count,
            ];
            $subscribed_series[] = [
                'label' => $period['label'],
                'value' => $subscribed_count,
            ];
            $unsubscribed_series[] = [
                'label' => $period['label'],
                'value' => $unsubscribed_count,
            ];

            $send_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(emails_sent), 0) AS emails_sent, COUNT(*) AS campaigns, COALESCE(SUM(posts_count), 0) AS posts_count
                     FROM {$log_table}
                     WHERE sent_at >= %s AND sent_at <= %s",
                    $period['start'],
                    $period['end']
                ),
                ARRAY_A
            );

            $emails = (int) ($send_row['emails_sent'] ?? 0);
            $campaigns = (int) ($send_row['campaigns'] ?? 0);
            $posts = (int) ($send_row['posts_count'] ?? 0);

            $sending_series[] = [
                'label' => $period['label'],
                'value' => $emails,
            ];

            $emails_total += $emails;
            $campaigns_total += $campaigns;
            $posts_total += $posts;
        }

        $recent_logs = $wpdb->get_results(
            "SELECT sent_at, emails_sent, posts_count FROM {$log_table} ORDER BY id DESC LIMIT 12",
            ARRAY_A
        ) ?: [];

        return [
            'follower_series' => $follower_series,
            'subscribed_series' => $subscribed_series,
            'unsubscribed_series' => $unsubscribed_series,
            'sending_series' => $sending_series,
            'emails_total' => $emails_total,
            'campaigns_total' => $campaigns_total,
            'posts_total' => $posts_total,
            'current_active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$subscriber_table} WHERE status = 'active' AND confirmed = 1"),
            'recent_logs' => $recent_logs,
        ];
    }

    private function get_current_month_email_total(): int
    {
        $aws_sending_data = $this->get_aws_ses_sending_data('month');
        if (is_array($aws_sending_data) && !empty($aws_sending_data['sending_series'])) {
            $total = 0;
            foreach ($aws_sending_data['sending_series'] as $point) {
                $total += (int) ($point['value'] ?? 0);
            }
            return $total;
        }

        global $wpdb;
        $log_table = $this->table_name(self::LOG_TABLE);
        $tz = wp_timezone();
        $start = (new DateTimeImmutable('now', $tz))
            ->modify('first day of this month')
            ->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable('now', $tz))
            ->setTime(23, 59, 59)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(emails_sent), 0) FROM {$log_table} WHERE sent_at >= %s AND sent_at <= %s",
                $start,
                $end
            )
        );
    }

    private function build_periods(string $range): array
    {
        $periods = [];
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);

        if ($range === 'week') {
            for ($i = 11; $i >= 0; $i--) {
                $start = $now->modify('monday this week')->setTime(0, 0)->modify("-{$i} weeks");
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
                $periods[] = [
                    'label' => $start->format('M d'),
                    'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ];
            }
            return $periods;
        }

        if ($range === 'month') {
            for ($i = 11; $i >= 0; $i--) {
                $start = $now->modify('first day of this month')->setTime(0, 0)->modify("-{$i} months");
                $end = $start->modify('last day of this month')->setTime(23, 59, 59);
                $periods[] = [
                    'label' => $start->format('M Y'),
                    'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ];
            }
            return $periods;
        }

        for ($i = 29; $i >= 0; $i--) {
            $start = $now->setTime(0, 0)->modify("-{$i} days");
            $end = $start->setTime(23, 59, 59);
            $periods[] = [
                'label' => $start->format('M d'),
                'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];
        }

        return $periods;
    }

    private function get_channel_counts(): array
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $rows = $wpdb->get_results(
            "SELECT delivery_channel, COUNT(*) AS total
             FROM {$table}
             WHERE status = 'active'
             GROUP BY delivery_channel
             ORDER BY total DESC",
            ARRAY_A
        ) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['delivery_channel']] = (int) $row['total'];
        }

        return $counts;
    }

    private function format_estimated_ses_cost(float $emails): string
    {
        $cost = max(0, $emails) * 0.10 / 1000;

        if ($cost < 0.01) {
            return '$' . number_format($cost, 4, '.', '');
        }

        return '$' . number_format($cost, 2, '.', '');
    }

    private function render_stats_switch(string $target, string $label, string $current, string $range): string
    {
        $url = add_query_arg(
            [
                'page' => 'wppk-newsletter',
                'tab' => 'stats',
                'stats_view' => $target,
                'stats_range' => $range,
            ],
            admin_url('admin.php')
        );
        $classes = 'wppk-mini-tab' . ($target === $current ? ' is-active' : '');
        return '<a href="' . esc_url($url) . '" class="' . esc_attr($classes) . '">' . esc_html($label) . '</a>';
    }

    private function render_range_switch(string $target, string $label, string $current, string $view): string
    {
        $url = add_query_arg(
            [
                'page' => 'wppk-newsletter',
                'tab' => 'stats',
                'stats_view' => $view,
                'stats_range' => $target,
            ],
            admin_url('admin.php')
        );
        $classes = 'wppk-range-pill' . ($target === $current ? ' is-active' : '');
        return '<a href="' . esc_url($url) . '" class="' . esc_attr($classes) . '">' . esc_html($label) . '</a>';
    }

    private function render_channel_card(string $label, int $count, bool $active): string
    {
        $classes = 'wppk-channel-card' . ($active ? ' is-active' : '');
        return '<div class="' . esc_attr($classes) . '"><div class="wppk-channel-card__label">' . esc_html($label) . '</div><div class="wppk-channel-card__count">(' . esc_html((string) $count) . ')</div></div>';
    }

    private function render_line_chart_card(string $title, string $caption, array $series, string $card_class = ''): string
    {
        $classes = trim('wppk-chart-card ' . $card_class);
        return '<div class="' . esc_attr($classes) . '"><div class="wppk-chart-card__header"><div><h3 class="wppk-stats-section-title" style="margin:0;">' . esc_html($title) . '</h3><p class="wppk-chart-card__caption">' . esc_html($caption) . '</p></div></div>' . $this->render_svg_line_chart($series, $card_class === 'wppk-chart-card--mini') . '</div>';
    }

    private function render_dual_line_chart_card(string $title, string $caption, array $primarySeries, array $secondarySeries, string $primaryLabel, string $secondaryLabel, string $card_class = ''): string
    {
        $legend = '<div class="wppk-chart-legend"><span class="wppk-chart-legend__item"><span class="wppk-chart-legend__dot is-primary"></span>' . esc_html($primaryLabel) . '</span><span class="wppk-chart-legend__item"><span class="wppk-chart-legend__dot is-secondary"></span>' . esc_html($secondaryLabel) . '</span></div>';
        $classes = trim('wppk-chart-card ' . $card_class);
        return '<div class="' . esc_attr($classes) . '"><div class="wppk-chart-card__header"><div><h3 class="wppk-stats-section-title" style="margin:0;">' . esc_html($title) . '</h3><p class="wppk-chart-card__caption">' . esc_html($caption) . '</p></div>' . $legend . '</div>' . $this->render_svg_dual_line_chart($primarySeries, $secondarySeries, $card_class === 'wppk-chart-card--mini') . '</div>';
    }

    private function render_svg_line_chart(array $series, bool $compact = false): string
    {
        if (!$series) {
            return '<p>Aucune donnee.</p>';
        }

        $width = 960;
        $height = $compact ? 190 : 320;
        $paddingLeft = 48;
        $paddingRight = 16;
        $paddingTop = $compact ? 10 : 16;
        $paddingBottom = $compact ? 28 : 40;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $values = array_map(static fn($point) => (int) $point['value'], $series);
        $max = max($values);
        $max = $max > 0 ? $max : 1;
        $count = count($series);

        $points = [];
        $areaPoints = [];
        foreach ($series as $index => $point) {
            $x = $paddingLeft + ($count > 1 ? ($plotWidth / ($count - 1)) * $index : $plotWidth / 2);
            $y = $paddingTop + $plotHeight - (($point['value'] / $max) * $plotHeight);
            $points[] = round($x, 2) . ',' . round($y, 2);
            $areaPoints[] = round($x, 2) . ',' . round($y, 2);
        }

        $area = $paddingLeft . ',' . ($paddingTop + $plotHeight) . ' ' . implode(' ', $areaPoints) . ' ' . ($paddingLeft + $plotWidth) . ',' . ($paddingTop + $plotHeight);
        $ticks = [];
        for ($i = 0; $i < 5; $i++) {
            $value = ($max / 4) * (4 - $i);
            $y = $paddingTop + ($plotHeight / 4) * $i;
            $ticks[] = ['label' => number_format($value, $max > 10 ? 0 : 1, ',', ' '), 'y' => $y];
        }

        ob_start();
        ?>
        <div class="wppk-chart-shell">
            <svg viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" class="wppk-chart" role="img" aria-label="Courbe de statistiques">
                <?php foreach ($ticks as $tick) : ?>
                    <line x1="<?php echo esc_attr((string) $paddingLeft); ?>" y1="<?php echo esc_attr((string) $tick['y']); ?>" x2="<?php echo esc_attr((string) ($paddingLeft + $plotWidth)); ?>" y2="<?php echo esc_attr((string) $tick['y']); ?>" class="wppk-chart__grid" />
                    <text x="8" y="<?php echo esc_attr((string) ($tick['y'] + 4)); ?>" class="wppk-chart__axis"><?php echo esc_html((string) $tick['label']); ?></text>
                <?php endforeach; ?>
                <polygon points="<?php echo esc_attr($area); ?>" class="wppk-chart__area" />
                <polyline points="<?php echo esc_attr(implode(' ', $points)); ?>" class="wppk-chart__line" />
                <?php foreach ($series as $index => $point) : ?>
                    <?php
                    $x = $paddingLeft + ($count > 1 ? ($plotWidth / ($count - 1)) * $index : $plotWidth / 2);
                    $y = $paddingTop + $plotHeight - (($point['value'] / $max) * $plotHeight);
                    ?>
                    <circle cx="<?php echo esc_attr((string) $x); ?>" cy="<?php echo esc_attr((string) $y); ?>" r="4" class="wppk-chart__point" />
                    <?php if ($index < count($series) && ($index % max(1, (int) floor($count / 8)) === 0 || $index === $count - 1)) : ?>
                        <text x="<?php echo esc_attr((string) $x); ?>" y="<?php echo esc_attr((string) ($height - 10)); ?>" text-anchor="middle" class="wppk-chart__axis"><?php echo esc_html($point['label']); ?></text>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_svg_dual_line_chart(array $primarySeries, array $secondarySeries, bool $compact = false): string
    {
        if (!$primarySeries || !$secondarySeries) {
            return '<p>Aucune donnee.</p>';
        }

        $width = 960;
        $height = $compact ? 190 : 280;
        $paddingLeft = 48;
        $paddingRight = 16;
        $paddingTop = $compact ? 10 : 16;
        $paddingBottom = $compact ? 28 : 40;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $allValues = array_merge(
            array_map(static fn($point) => (int) $point['value'], $primarySeries),
            array_map(static fn($point) => (int) $point['value'], $secondarySeries)
        );
        $max = max($allValues);
        $max = $max > 0 ? $max : 1;
        $count = count($primarySeries);

        $buildPoints = static function (array $series) use ($count, $paddingLeft, $plotWidth, $paddingTop, $plotHeight, $max): array {
            $points = [];
            foreach ($series as $index => $point) {
                $x = $paddingLeft + ($count > 1 ? ($plotWidth / ($count - 1)) * $index : $plotWidth / 2);
                $y = $paddingTop + $plotHeight - (($point['value'] / $max) * $plotHeight);
                $points[] = [
                    'coords' => round($x, 2) . ',' . round($y, 2),
                    'x' => $x,
                    'y' => $y,
                    'label' => $point['label'],
                ];
            }
            return $points;
        };

        $primaryPoints = $buildPoints($primarySeries);
        $secondaryPoints = $buildPoints($secondarySeries);
        $ticks = [];
        for ($i = 0; $i < 5; $i++) {
            $value = ($max / 4) * (4 - $i);
            $y = $paddingTop + ($plotHeight / 4) * $i;
            $ticks[] = ['label' => number_format($value, $max > 10 ? 0 : 1, ',', ' '), 'y' => $y];
        }

        ob_start();
        ?>
        <div class="wppk-chart-shell">
            <svg viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" class="wppk-chart" role="img" aria-label="Courbe de progression des abonnés et désabonnés">
                <?php foreach ($ticks as $tick) : ?>
                    <line x1="<?php echo esc_attr((string) $paddingLeft); ?>" y1="<?php echo esc_attr((string) $tick['y']); ?>" x2="<?php echo esc_attr((string) ($paddingLeft + $plotWidth)); ?>" y2="<?php echo esc_attr((string) $tick['y']); ?>" class="wppk-chart__grid" />
                    <text x="8" y="<?php echo esc_attr((string) ($tick['y'] + 4)); ?>" class="wppk-chart__axis"><?php echo esc_html((string) $tick['label']); ?></text>
                <?php endforeach; ?>
                <polyline points="<?php echo esc_attr(implode(' ', array_column($primaryPoints, 'coords'))); ?>" class="wppk-chart__line" />
                <polyline points="<?php echo esc_attr(implode(' ', array_column($secondaryPoints, 'coords'))); ?>" class="wppk-chart__line wppk-chart__line--secondary" />
                <?php foreach ($primaryPoints as $index => $point) : ?>
                    <circle cx="<?php echo esc_attr((string) $point['x']); ?>" cy="<?php echo esc_attr((string) $point['y']); ?>" r="<?php echo esc_attr($compact ? '2.5' : '3.5'); ?>" class="wppk-chart__point" />
                    <?php if ($index % max(1, (int) floor($count / 8)) === 0 || $index === $count - 1) : ?>
                        <text x="<?php echo esc_attr((string) $point['x']); ?>" y="<?php echo esc_attr((string) ($height - 10)); ?>" text-anchor="middle" class="wppk-chart__axis"><?php echo esc_html($point['label']); ?></text>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($secondaryPoints as $point) : ?>
                    <circle cx="<?php echo esc_attr((string) $point['x']); ?>" cy="<?php echo esc_attr((string) $point['y']); ?>" r="<?php echo esc_attr($compact ? '2.5' : '3.5'); ?>" class="wppk-chart__point wppk-chart__point--secondary" />
                <?php endforeach; ?>
            </svg>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $logs = $this->table_name(self::LOG_TABLE);
        foreach (['prod', 'dev'] as $audience) {
            $subscribers = $this->get_subscribers_table_name($audience);
            dbDelta("CREATE TABLE {$subscribers} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                unsubscribe_token VARCHAR(64) NOT NULL,
                confirmation_token VARCHAR(64) NOT NULL DEFAULT '',
                source VARCHAR(50) NOT NULL DEFAULT 'Your site',
                signup_process VARCHAR(50) NOT NULL DEFAULT 'Form',
                delivery_channel VARCHAR(50) NOT NULL DEFAULT 'Daily digest',
                content_mode VARCHAR(50) NOT NULL DEFAULT 'Full stories',
                preferred_hour TINYINT UNSIGNED NOT NULL DEFAULT 17,
                confirmed TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                confirmation_sent_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                subscribed_at DATETIME NULL,
                unsubscribed_at DATETIME NULL,
                resubscribed_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY email (email),
                KEY status (status)
            ) {$charset};");
        }

        dbDelta("CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sent_at DATETIME NOT NULL,
            emails_sent INT UNSIGNED NOT NULL DEFAULT 0,
            posts_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset};");
    }

    private function migrate_subscribers_schema(): void
    {
        global $wpdb;
        foreach (['prod', 'dev'] as $audience) {
            $table = $this->get_subscribers_table_name($audience);
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            if (!is_array($columns) || !$columns) {
                continue;
            }

            $required = [
                'confirmation_token' => "ALTER TABLE {$table} ADD COLUMN confirmation_token VARCHAR(64) NOT NULL DEFAULT '' AFTER unsubscribe_token",
                'subscribed_at' => "ALTER TABLE {$table} ADD COLUMN subscribed_at DATETIME NULL AFTER created_at",
                'confirmation_sent_at' => "ALTER TABLE {$table} ADD COLUMN confirmation_sent_at DATETIME NULL AFTER created_at",
                'confirmed_at' => "ALTER TABLE {$table} ADD COLUMN confirmed_at DATETIME NULL AFTER confirmation_sent_at",
                'unsubscribed_at' => "ALTER TABLE {$table} ADD COLUMN unsubscribed_at DATETIME NULL AFTER subscribed_at",
                'resubscribed_at' => "ALTER TABLE {$table} ADD COLUMN resubscribed_at DATETIME NULL AFTER unsubscribed_at",
            ];

            foreach ($required as $column => $sql) {
                if (!in_array($column, $columns, true)) {
                    $wpdb->query($sql);
                }
            }

            $wpdb->query("UPDATE {$table} SET confirmation_token = unsubscribe_token WHERE confirmation_token = ''");
            $wpdb->query("UPDATE {$table} SET confirmed_at = created_at WHERE confirmed = 1 AND confirmed_at IS NULL");
            $wpdb->query("UPDATE {$table} SET subscribed_at = created_at WHERE subscribed_at IS NULL");
            $wpdb->query("UPDATE {$table} SET unsubscribed_at = created_at WHERE status = 'unsubscribed' AND unsubscribed_at IS NULL");
        }
    }

    private function update_subscriber_status(int $subscriber_id, string $status): void
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $current = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status, confirmed, subscribed_at, unsubscribed_at, resubscribed_at FROM {$table} WHERE id = %d", $subscriber_id),
            ARRAY_A
        );

        if (!$current) {
            return;
        }

        $status = in_array($status, ['active', 'pending', 'unsubscribed'], true) ? $status : 'active';
        if (($current['status'] ?? '') === $status) {
            return;
        }

        $now = current_time('mysql', true);
        $data = ['status' => $status];
        $format = ['%s'];

        if ($status === 'unsubscribed') {
            $data['unsubscribed_at'] = $now;
            $format[] = '%s';
        } elseif ($status === 'pending') {
            $data['confirmed'] = 0;
            $data['confirmed_at'] = null;
            $format[] = '%d';
            $format[] = '%s';
        } else {
            if (empty($current['subscribed_at'])) {
                $data['subscribed_at'] = $now;
                $format[] = '%s';
            }
            if (!empty($current['unsubscribed_at'])) {
                $data['resubscribed_at'] = $now;
                $format[] = '%s';
            }
            if (empty($current['confirmed'])) {
                $data['confirmed'] = 1;
                $data['confirmed_at'] = $now;
                $format[] = '%d';
                $format[] = '%s';
            }
        }

        $wpdb->update($table, $data, ['id' => $subscriber_id], $format, ['%d']);
        $email = $wpdb->get_var($wpdb->prepare("SELECT email FROM {$table} WHERE id = %d", $subscriber_id));
        $this->log_event('subscriber', 'ok', sprintf('Statut abonné : %s → %s (%s)', (string) ($current['status'] ?? 'unknown'), $status, (string) $email));
    }

    private function get_subscriber_growth_data(string $range = 'day'): array
    {
        global $wpdb;
        $subscriber_table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $periods = $this->build_periods($range);
        $subscribed_series = [];
        $unsubscribed_series = [];

        foreach ($periods as $period) {
            $subscribed_series[] = [
                'label' => $period['label'],
                'value' => (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$subscriber_table} WHERE subscribed_at <= %s AND (unsubscribed_at IS NULL OR unsubscribed_at > %s)",
                        $period['end'],
                        $period['end']
                    )
                ),
            ];
            $unsubscribed_series[] = [
                'label' => $period['label'],
                'value' => (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$subscriber_table} WHERE unsubscribed_at IS NOT NULL AND unsubscribed_at <= %s",
                        $period['end']
                    )
                ),
            ];
        }

        return [
            'subscribed_series' => $subscribed_series,
            'unsubscribed_series' => $unsubscribed_series,
        ];
    }

    private function generate_confirmation_token(): string
    {
        return wp_generate_password(32, false, false);
    }

    private function get_confirmation_url(string $token): string
    {
        return add_query_arg('wppk_confirm', rawurlencode($token), home_url('/'));
    }

    private function send_confirmation_email(string $email, string $token): bool
    {
        $settings = $this->get_settings();
        $confirm_url = $this->get_confirmation_url($token);
        $subject = sprintf('Confirme ton inscription - %s', $settings['brand_name']);
        $message = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:32px;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;"><tr><td style="padding:28px;"><div style="font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">' . esc_html($settings['brand_name']) . '</div><h1 style="margin:0 0 12px;font-size:28px;line-height:1.1;color:#0f172a;">Confirme ton inscription</h1><p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#475569;">Clique sur le bouton ci-dessous pour activer ton inscription au digest quotidien.</p><p style="margin:0 0 22px;"><a href="' . esc_url($confirm_url) . '" style="display:inline-block;padding:12px 18px;background:' . esc_attr($settings['accent_color']) . ';color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">Confirmer mon inscription</a></p><p style="margin:0;font-size:13px;line-height:1.7;color:#64748b;">Si tu n’es pas à l’origine de cette demande, ignore simplement cet email.</p></td></tr></table></td></tr></table></body></html>';
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>',
        ];
        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . $settings['reply_to'];
        }

        return wp_mail($email, $subject, $message, $headers);
    }

    private function confirm_subscriber(int $subscriber_id): void
    {
        global $wpdb;
        $table = $this->table_name(self::SUBSCRIBERS_TABLE);
        $now = current_time('mysql', true);
        $wpdb->update(
            $table,
            [
                'status' => 'active',
                'confirmed' => 1,
                'confirmed_at' => $now,
                'subscribed_at' => $now,
                'confirmation_token' => '',
            ],
            ['id' => $subscriber_id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
        $email = $wpdb->get_var($wpdb->prepare("SELECT email FROM {$table} WHERE id = %d", $subscriber_id));
        $this->log_event('subscriber', 'ok', sprintf('Confirmation validée : %s', (string) $email));
    }

    private function log_event(string $type, string $status, string $message): void
    {
        $logs = get_option(self::EVENT_LOG_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        array_unshift($logs, [
            'time' => current_time('mysql'),
            'type' => sanitize_key($type),
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
        ]);

        $logs = array_slice($logs, 0, 200);
        update_option(self::EVENT_LOG_OPTION, $logs, false);
    }

    private function render_event_logs_panel(): string
    {
        $logs = get_option(self::EVENT_LOG_OPTION, []);
        if (!is_array($logs) || !$logs) {
            return '<p class="wppk-empty-copy">Aucun log pour le moment.</p>';
        }

        ob_start();
        echo '<div class="wppk-event-logs"><table class="widefat striped"><thead><tr><th>Heure</th><th>Type</th><th>Statut</th><th>Message</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            $status = (string) ($log['status'] ?? 'info');
            echo '<tr>';
            echo '<td>' . esc_html((string) ($log['time'] ?? '')) . '</td>';
            echo '<td>' . esc_html(strtoupper((string) ($log['type'] ?? 'log'))) . '</td>';
            echo '<td><span class="wppk-log-badge wppk-log-badge--' . esc_attr($status) . '">' . esc_html(strtoupper($status)) . '</span></td>';
            echo '<td>' . esc_html((string) ($log['message'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        return (string) ob_get_clean();
    }

    private function ensure_cron_schedule(): void
    {
        $scheduled = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;

        if ($scheduled && isset($scheduled->schedule) && $scheduled->schedule !== 'wppk_digest_check') {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $scheduled = null;
        }

        if (!$scheduled && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wppk_digest_check', self::CRON_HOOK);
        }
    }

    private function get_settings(): array
    {
        $settings = wp_parse_args(get_option(self::OPTION_KEY, []), $this->default_settings());
        $settings['intro_text'] = $this->normalize_intro_text((string) ($settings['intro_text'] ?? ''));

        return $settings;
    }

    private function default_settings(): array
    {
        return [
            'brand_name' => get_bloginfo('name'),
            'sender_name' => get_bloginfo('name'),
            'sender_email' => get_option('admin_email'),
            'reply_to' => get_option('admin_email'),
            'audience_mode' => 'prod',
            'accent_color' => '#2f80ed',
            'logo_url' => '',
            'email_layout' => 'reading_list',
            'email_theme' => 'paper',
            'daily_hour' => 18,
            'posts_per_digest' => 8,
            'subject' => 'Digest du jour - %date%',
            'intro_text' => 'Une sélection éditoriale pensée pour aller droit à l’essentiel, sans perdre le relief des bons sujets.',
            'footer_text' => 'Vous recevez cet email car vous etes inscrit au digest quotidien.',
            'preview_email' => get_option('admin_email'),
            'smtp_enabled' => 0,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_secure' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'aws_ses_region' => 'eu-north-1',
            'aws_ses_access_key_id' => '',
            'aws_ses_secret_access_key' => '',
        ];
    }

    private function get_email_layout_options(): array
    {
        return [
            'reading_list' => 'Reading List',
            'cards' => 'Cards',
            'list_thumbs' => 'List + thumbnails',
            'editorial' => 'Editorial',
            'compact' => 'Compact',
            'briefing' => 'Briefing',
            'magazine' => 'Magazine grid',
        ];
    }

    private function get_email_theme_options(): array
    {
        return [
            'paper' => 'Paper',
            'ocean' => 'Ocean',
            'slate' => 'Slate',
            'warm' => 'Warm',
        ];
    }

    private function get_email_theme_palette(string $theme): array
    {
        $themes = [
            'paper' => [
                'page_bg' => '#f5f5f2',
                'panel_bg' => '#ffffff',
                'panel_border' => '#ece8df',
                'text' => '#111827',
                'muted' => '#6b7280',
                'soft_text' => '#4b5563',
                'hero_bg' => '#ffffff',
                'dark_panel_bg' => '#111827',
                'dark_panel_text' => '#f9fafb',
            ],
            'ocean' => [
                'page_bg' => '#eef6ff',
                'panel_bg' => '#ffffff',
                'panel_border' => '#d7e7fb',
                'text' => '#10233f',
                'muted' => '#58708f',
                'soft_text' => '#425b78',
                'hero_bg' => '#ffffff',
                'dark_panel_bg' => '#16324f',
                'dark_panel_text' => '#eff6ff',
            ],
            'slate' => [
                'page_bg' => '#f3f4f6',
                'panel_bg' => '#ffffff',
                'panel_border' => '#dfe3e8',
                'text' => '#0f172a',
                'muted' => '#64748b',
                'soft_text' => '#475569',
                'hero_bg' => '#ffffff',
                'dark_panel_bg' => '#0f172a',
                'dark_panel_text' => '#f8fafc',
            ],
            'warm' => [
                'page_bg' => '#fbf6ef',
                'panel_bg' => '#fffdf9',
                'panel_border' => '#eadfce',
                'text' => '#2f241c',
                'muted' => '#7b6858',
                'soft_text' => '#665447',
                'hero_bg' => '#fffdf9',
                'dark_panel_bg' => '#3d2f24',
                'dark_panel_text' => '#fff8ef',
            ],
        ];

        return $themes[$theme] ?? $themes['paper'];
    }

    private function table_name(string $suffix): string
    {
        global $wpdb;
        if ($suffix === self::SUBSCRIBERS_TABLE) {
            return $this->get_subscribers_table_name();
        }
        return $wpdb->prefix . $suffix;
    }

    private function get_active_audience(): string
    {
        $settings = $this->get_settings();
        return $this->sanitize_audience_mode((string) ($settings['audience_mode'] ?? 'prod'));
    }

    private function is_digest_paused(): bool
    {
        return (bool) get_option('wppk_newsletter_paused', 0);
    }

    private function get_subscribers_table_name(?string $audience = null): string
    {
        global $wpdb;
        $mode = $this->sanitize_audience_mode((string) ($audience ?? $this->get_active_audience()));
        $suffix = self::SUBSCRIBERS_TABLE . ($mode === 'dev' ? '_dev' : '');
        return $wpdb->prefix . $suffix;
    }
}
