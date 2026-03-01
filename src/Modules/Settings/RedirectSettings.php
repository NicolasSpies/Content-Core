<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles redirect-related settings and logic.
 */
class RedirectSettings
{
    /**
     * @var SettingsModule
     */
    private $module;

    /**
     * @param SettingsModule $module
     */
    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Get default redirect settings.
     *
     * @return array
     */
    public static function get_defaults(): array
    {
        return [
            'enabled' => false,
            'from_path' => '/',
            'target' => '/wp-admin',
            'status_code' => '302',
            'pass_query' => false,
            'exclusions' => [
                'admin' => true,
                'ajax' => true,
                'rest' => true,
                'cron' => true,
                'cli' => true,
            ]
        ];
    }

    /**
     * Intercept legacy Admin URL Hashes and redirect to new native pages.
     *
     * @return void
     */
    public function handle_legacy_admin_redirects(): void
    {
        if (!is_admin()) {
            return;
        }

        $page = sanitize_text_field($_GET['page'] ?? '');
        if ($page !== 'cc-settings' && $page !== 'cc-site-settings') {
            return;
        }

        $target = ($page === 'cc-site-settings') ? 'cc-multilingual' : 'cc-visibility';

        ?>
        <script>
            var hashMap = {
                '#menu': 'cc-visibility',
                '#media': 'cc-media',
                '#redirect': 'cc-redirect',
                '#multilingual': 'cc-multilingual',
                '#seo': 'cc-seo',
                '#site_images': 'cc-site-images',
                '#site_options': 'cc-site-options',
                '#cookie': 'cc-cookie-banner'
            };

            var hash = window.location.hash;
            var redirectPage = '<?php echo esc_js($target); ?>';

            if (hash && hashMap[hash]) {
                redirectPage = hashMap[hash];
            }

            window.location.replace('admin.php?page=' + redirectPage);
        </script>
        <?php
        exit;
    }

    /**
     * Handle frontend redirects based on settings.
     *
     * @return void
     */
    public function handle_frontend_redirect(): void
    {
        // Absolute early exits for core system paths
        if (is_admin())
            return;
        if (wp_doing_ajax() || strpos($_SERVER['REQUEST_URI'], 'admin-ajax.php') !== false)
            return;
        if (wp_doing_cron())
            return;
        if (defined('WP_CLI') && WP_CLI)
            return;

        // Force exclude critical system paths from redirection
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (
            strpos($request_uri, '/wp-json/') !== false ||
            strpos($request_uri, 'rest_route=') !== false ||
            strpos($request_uri, 'admin-ajax.php') !== false ||
            strpos($request_uri, 'wp-login.php') !== false ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (function_exists('rest_get_url_prefix') && strpos($request_uri, '/' . rest_get_url_prefix() . '/') !== false)
        ) {
            return;
        }

        $settings = $this->module->get_registry()->get(SettingsModule::REDIRECT_KEY);
        if (empty($settings['enabled'])) {
            return;
        }

        // ── Exclusions ──
        $excl = $settings['exclusions'] ?? [];
        if (!empty($excl['admin']) && is_admin())
            return;
        if (!empty($excl['ajax']) && wp_doing_ajax())
            return;
        if (!empty($excl['cron']) && wp_doing_cron())
            return;
        if (!empty($excl['rest']) && defined('REST_REQUEST') && REST_REQUEST)
            return;
        if (!empty($excl['cli']) && defined('WP_CLI') && WP_CLI)
            return;

        // ── Path Matching (Portable / Subdirectory support) ──
        $from_path = $settings['from_path'] ?? '/';
        $target = $settings['target'] ?? '/wp-admin';
        $status = intval($settings['status_code'] ?? 302);

        // Calculate absolute matches based on the WordPress home path
        $home_path = parse_url(home_url(), PHP_URL_PATH) ?: '';
        $home_path = untrailingslashit($home_path);

        $abs_from = $home_path . '/' . ltrim($from_path, '/');
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if ($current_path !== $abs_from) {
            return;
        }

        // Prevent redirect loop
        if ($target === $from_path) {
            return;
        }

        // Map target to absolute if it starts with a slash
        if (strpos($target, '/') === 0) {
            $target = $home_path . '/' . ltrim($target, '/');
        }

        // ── Query String Pass Through ──
        if (!empty($settings['pass_query']) && !empty($_SERVER['QUERY_STRING'])) {
            $separator = (strpos($target, '?') !== false) ? '&' : '?';
            $target .= $separator . $_SERVER['QUERY_STRING'];
        }

        wp_safe_redirect($target, $status);
        exit;
    }

    /**
     * Sanitize custom redirect path to be relative and start with a slash.
     *
     * @param string $path
     * @return string
     */
    private function sanitize_redirect_path(string $path): string
    {
        $path = trim($path);
        if (empty($path)) {
            return '';
        }

        // Reject full URLs
        if (preg_match('/^https?:\/\//i', $path) || strpos($path, '//') === 0) {
            return '';
        }

        // Ensure starts with /
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return sanitize_text_field($path);
    }
}
