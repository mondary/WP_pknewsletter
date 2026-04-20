<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WPPK_Newsletter_Sync
{
    private const REST_NAMESPACE = 'wppknewsletter/v1';
    private const MANIFEST_ROUTE = '/sync-plugin/manifest';
    private const SYNC_ROUTE = '/sync-plugin';
    private const DIAG_ROUTE = '/diag';
    private const DIGEST_CRON_HOOK = 'wppk_newsletter_digest_event';

    public static function boot(): void
    {
        $instance = new self();
        add_action('rest_api_init', [$instance, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::MANIFEST_ROUTE, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_manifest'],
            'permission_callback' => [$this, 'permission_manage_options'],
        ]);

        register_rest_route(self::REST_NAMESPACE, self::SYNC_ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_sync'],
            'permission_callback' => [$this, 'permission_manage_options'],
            'args' => [
                'files' => [
                    'type' => 'array',
                    'required' => false,
                ],
                'delete_paths' => [
                    'type' => 'array',
                    'required' => false,
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ],
                'activate' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                ],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, self::DIAG_ROUTE, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_diag'],
            'permission_callback' => [$this, 'permission_manage_options'],
        ]);
    }

    public function permission_manage_options(): bool
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public function handle_manifest(WP_REST_Request $request): WP_REST_Response
    {
        $root = rtrim(WPPKNEWSLETTER_PATH, '/');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!($file instanceof SplFileInfo) || !$file->isFile()) {
                continue;
            }

            $abs = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
            if ($rel === '') {
                continue;
            }

            // Skip common local artifacts.
            if ($rel === 'vendor/autoload.php' || str_starts_with($rel, 'vendor/')) {
                continue;
            }
            if (str_starts_with($rel, '.git/') || str_starts_with($rel, '.github/') || str_starts_with($rel, '.build/')) {
                continue;
            }
            if (str_starts_with($rel, '.DS_Store') || str_contains($rel, '/.DS_Store')) {
                continue;
            }

            $content = @file_get_contents($abs);
            if (!is_string($content)) {
                continue;
            }

            $files[] = [
                'path' => $rel,
                'size' => strlen($content),
                'sha1' => sha1($content),
            ];
        }

        return new WP_REST_Response([
            'plugin' => 'WPpknewsletter',
            'version' => defined('WPPKNEWSLETTER_VERSION') ? WPPKNEWSLETTER_VERSION : '',
            'root' => basename($root),
            'files' => $files,
        ]);
    }

    public function handle_sync(WP_REST_Request $request): WP_REST_Response
    {
        $dry_run = (bool) $request->get_param('dry_run');
        $activate = (bool) $request->get_param('activate');
        $files = $request->get_param('files');
        $delete_paths = $request->get_param('delete_paths');

        $files = is_array($files) ? $files : [];
        $delete_paths = is_array($delete_paths) ? $delete_paths : [];

        $root = rtrim(WPPKNEWSLETTER_PATH, '/');
        $touched = [];
        $deleted = [];
        $errors = [];

        foreach ($files as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rel = isset($item['path']) ? (string) $item['path'] : '';
            $b64 = isset($item['content_b64']) ? (string) $item['content_b64'] : '';

            $rel = $this->sanitize_relative_path($rel);
            if ($rel === '') {
                $errors[] = 'Chemin invalide dans files[].path';
                continue;
            }
            if ($b64 === '') {
                $errors[] = sprintf('Contenu manquant: %s', $rel);
                continue;
            }

            $abs = $root . '/' . $rel;
            $dir = dirname($abs);
            if (!$dry_run && !is_dir($dir)) {
                if (!wp_mkdir_p($dir)) {
                    $errors[] = sprintf('Impossible de creer le dossier: %s', $rel);
                    continue;
                }
            }

            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                $errors[] = sprintf('Base64 invalide: %s', $rel);
                continue;
            }

            $touched[] = $rel;
            if ($dry_run) {
                continue;
            }

            if (@file_put_contents($abs, $decoded) === false) {
                $errors[] = sprintf('Ecriture echouee: %s', $rel);
                continue;
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($abs, true);
            }
        }

        foreach ($delete_paths as $rel) {
            $rel = $this->sanitize_relative_path((string) $rel);
            if ($rel === '') {
                $errors[] = 'Chemin invalide dans delete_paths[]';
                continue;
            }

            $abs = $root . '/' . $rel;
            if (!file_exists($abs)) {
                continue;
            }

            $deleted[] = $rel;
            if ($dry_run) {
                continue;
            }

            if (is_dir($abs)) {
                $errors[] = sprintf('Refus de supprimer un dossier: %s', $rel);
                continue;
            }

            if (!@unlink($abs)) {
                $errors[] = sprintf('Suppression echouee: %s', $rel);
                continue;
            }
        }

        $activated = false;
        if (!$dry_run && $activate) {
            $plugin = plugin_basename(WPPKNEWSLETTER_FILE);
            if (!is_plugin_active($plugin)) {
                $result = activate_plugin($plugin);
                if (!is_wp_error($result)) {
                    $activated = true;
                } else {
                    $errors[] = 'Activation echouee: ' . $result->get_error_message();
                }
            }
        }

        $status = empty($errors) ? 200 : 400;
        return new WP_REST_Response([
            'dry_run' => $dry_run,
            'touched' => $touched,
            'deleted' => $deleted,
            'activated' => $activated,
            'errors' => $errors,
        ], $status);
    }

    public function handle_diag(WP_REST_Request $request): WP_REST_Response
    {
        $cron_count = $this->count_scheduled_hook_occurrences(self::DIGEST_CRON_HOOK);
        $next_ts = (int) wp_next_scheduled(self::DIGEST_CRON_HOOK);

        return new WP_REST_Response([
            'plugin' => 'WPpknewsletter',
            'version' => defined('WPPKNEWSLETTER_VERSION') ? WPPKNEWSLETTER_VERSION : '',
            'wp_timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
            'server_time_utc' => gmdate('Y-m-d H:i:s'),
            'digest_cron_hook' => self::DIGEST_CRON_HOOK,
            'digest_cron_occurrences' => $cron_count,
            'digest_cron_next_ts' => $next_ts ?: null,
            'digest_cron_next_local' => $next_ts > 0 ? wp_date('Y-m-d H:i:s', $next_ts) : null,
        ]);
    }

    private function sanitize_relative_path(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.' || $path === '..') {
            return '';
        }

        if (str_contains($path, "\0")) {
            return '';
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return '';
            }
        }

        return $path;
    }

    private function count_scheduled_hook_occurrences(string $hook): int
    {
        if (!function_exists('_get_cron_array')) {
            return 0;
        }

        $cron = _get_cron_array();
        if (!is_array($cron) || !$cron) {
            return 0;
        }

        $count = 0;
        foreach ($cron as $timestamp => $events) {
            if (!is_array($events) || empty($events[$hook]) || !is_array($events[$hook])) {
                continue;
            }

            foreach ($events[$hook] as $payload) {
                if (is_array($payload)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
