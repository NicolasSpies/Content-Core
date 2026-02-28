<?php
namespace ContentCore\Modules\Settings;

use ContentCore\Modules\ModuleInterface;

class SettingsModule implements ModuleInterface
{
    public const OPTION_KEY = 'content_core_admin_menu_settings';
    public const ORDER_KEY = 'content_core_admin_menu_order';
    public const MEDIA_KEY = 'cc_media_settings';
    public const REDIRECT_KEY = 'cc_redirect_settings';
    public const ADMIN_BAR_KEY = 'cc_admin_bar_settings';
    public const SEO_KEY = 'cc_site_seo';
    public const COOKIE_KEY = 'cc_cookie_settings';

    public const DEFAULT_HIDDEN = [
        'edit-comments.php',
        'themes.php',
        'plugins.php',
        'tools.php',
        'options-general.php',
    ];

    public const ADMIN_SAFETY_SLUGS = [
        'options-general.php',
        'plugins.php',
        'content-core',
        'cc-settings-hub',
        'cc-site-options',
        'cc-site-settings',
        'cc-settings',
        'cc-multilingual',
        'cc-visibility',
        'cc-media',
        'cc-redirect',
        'cc-seo',
        'cc-site-images',
        'cc-cookie-banner'
    ];

    public const CORE_WP_SLUGS = [
        'index.php',
        'edit.php',
        'upload.php',
        'edit.php?post_type=page',
        'edit-comments.php',
        'themes.php',
        'plugins.php',
        'users.php',
        'tools.php',
        'options-general.php'
    ];

    public function init(): void
    {
        // Intercept legacy Admin URL Hashes early enough to run but late enough to allow permissions
        add_action('admin_page_access_denied', [$this, 'handle_legacy_admin_redirects'], 1);

        // Redirect logic runs on frontend init
        add_action('init', [$this, 'handle_frontend_redirect']);

        // Upload size limit — runs everywhere (admin uploads go through here)
        add_filter('upload_size_limit', [$this, 'apply_upload_size_limit']);

        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'apply_menu_visibility'], 9999);
        add_action('admin_head', [$this, 'apply_menu_visibility']);
        add_action('admin_menu', [$this, 'apply_menu_order'], 999);
        add_action('admin_init', [$this, 'handle_save']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);

        // Admin bar: visibility + site-name link
        add_action('wp_before_admin_bar_render', [$this, 'apply_admin_bar_visibility']);
        add_action('admin_bar_menu', [$this, 'apply_admin_bar_site_link'], 999);
    }

    public function enqueue_settings_assets(string $hook): void
    {
        if (strpos($hook, 'cc-settings') !== false || strpos($hook, 'cc-site-settings') !== false || strpos($hook, 'cc-visibility') !== false || strpos($hook, 'cc-media') !== false || strpos($hook, 'cc-redirect') !== false || strpos($hook, 'cc-multilingual') !== false || strpos($hook, 'cc-seo') !== false || strpos($hook, 'cc-site-images') !== false || strpos($hook, 'cc-cookie-banner') !== false) {
            wp_enqueue_media();
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_style(
                'cc-settings-css',
                CONTENT_CORE_PLUGIN_URL . 'assets/css/settings.css',
                [],
                \CONTENT_CORE_VERSION
            );

            wp_enqueue_script(
                'cc-settings-js',
                CONTENT_CORE_PLUGIN_URL . 'assets/js/settings.js',
                ['jquery', 'jquery-ui-sortable'],
                \CONTENT_CORE_VERSION,
                true
            );

            $ml = \ContentCore\Plugin::get_instance()->get_module('multilingual');
            $catalog = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml::get_language_catalog() : [];

            wp_localize_script('cc-settings-js', 'CC_SETTINGS', [
                'catalog' => $catalog,
                'strings' => [
                    'langAdded' => __('Language already added.', 'content-core'),
                    'confirmRemoveLang' => __('Remove this language?', 'content-core'),
                    'selectFlag' => __('Select Flag Image', 'content-core'),
                    'useImage' => __('Use this image', 'content-core'),
                    'selectOGImage' => __('Select Default OG Image', 'content-core'),
                ]
            ]);
        }

        if (strpos($hook, 'cc-site-settings') !== false || strpos($hook, 'cc-multilingual') !== false || strpos($hook, 'cc-seo') !== false || strpos($hook, 'cc-site-images') !== false || strpos($hook, 'cc-cookie-banner') !== false) {
            // Enqueue wp-element (React), wp-api-fetch, wp-i18n — all bundled with WordPress
            wp_enqueue_script(
                'cc-site-settings-app',
                plugins_url('assets/js/site-settings-app.js', \CONTENT_CORE_PLUGIN_FILE),
                ['wp-element', 'wp-api-fetch', 'wp-i18n'],
                \CONTENT_CORE_VERSION,
                true
            );

            $page_slug = sanitize_text_field($_GET['page'] ?? '');

            $active_tab = 'seo';
            if ($page_slug === 'cc-site-images') {
                $active_tab = 'images';
            } elseif ($page_slug === 'cc-cookie-banner') {
                $active_tab = 'cookie';
            } elseif ($page_slug === 'cc-multilingual') {
                $active_tab = 'multilingual';
            } elseif ($page_slug === 'cc-site-options') {
                $active_tab = 'site-options';
            }

            $rest_base = rest_url('content-core/v1/settings/site');

            wp_localize_script('cc-site-settings-app', 'CC_SITE_SETTINGS', [
                'nonce' => wp_create_nonce('wp_rest'),
                'restBase' => $rest_base,
                'siteUrl' => home_url('/'),
                'defaultTitle' => get_bloginfo('name'),
                'defaultDesc' => get_bloginfo('description'),
                'siteOptionsUrl' => admin_url('admin.php?page=cc-site-options'),
                'activeTab' => $active_tab,
            ]);

            // Register and localize the Site Options Schema Editor JS
            wp_enqueue_script(
                'cc-schema-editor',
                CONTENT_CORE_PLUGIN_URL . 'assets/js/schema-editor.js',
                ['jquery', 'jquery-ui-sortable'],
                \CONTENT_CORE_VERSION,
                true
            );

            $languages = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml->get_settings()['languages'] : [];

            wp_localize_script('cc-schema-editor', 'ccSchemaEditorConfig', [
                'languages' => $languages,
                'strings' => [
                    'sectionTitle' => __('Group Title', 'content-core'),
                    'addField' => __('+ Add Field', 'content-core'),
                    'confirmRemoveSection' => __('Remove this entire section and all its fields?', 'content-core'),
                    'stableKey' => __('Stable Key', 'content-core'),
                    'type' => __('Type', 'content-core'),
                    'visible' => __('Visible', 'content-core'),
                    'editable' => __('Editable', 'content-core'),
                    'label' => __('Label', 'content-core'),
                    'confirmRemoveField' => __('Remove this field?', 'content-core'),
                ]
            ]);
        }
    }


    public function register_settings_page(): void
    {
        // Pages are now natively registered via AdminMenu class
        // This function is kept intentionally empty to satisfy action hooks during migration
    }

    public function handle_legacy_admin_redirects(): void
    {
        if (!is_admin())
            return;

        $page = sanitize_text_field($_GET['page'] ?? '');
        if ($page !== 'cc-settings' && $page !== 'cc-site-settings') {
            return;
        }

        // Without a hash in PHP, we can't reliably intercept JS hashes serverside during a strict GET
        // We will output a small inline JS snippet ONLY on the legacy catch-all page.
        // If a user hits the base slug, it redirects to the default native slug.
        $target = ($page === 'cc-site-settings') ? 'cc-multilingual' : 'cc-visibility';

        // Output inline JS to read the hash and redirect to the correct page immediately and kill the die() process
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

    public function handle_frontend_redirect(): void
    {
        $settings = get_option(self::REDIRECT_KEY, []);
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

        // ── Path Matching ──
        $from_path = $settings['from_path'] ?? '/';
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if ($current_path !== $from_path) {
            return;
        }

        $target = $settings['target'] ?? '/wp-admin';
        $status = intval($settings['status_code'] ?? 302);

        // Prevent redirect loop
        if ($target === $from_path) {
            return;
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
     * Filter: upload_size_limit
     *
     * Returns the lower of the current WordPress upload limit and the
     * Content Core configured limit (in MB, stored as 'upload_limit_mb').
     * Can only reduce the limit — never raise it above server PHP limits.
     *
     * @param  int $size  Current max upload size in bytes (WordPress-calculated).
     * @return int
     */
    public function apply_upload_size_limit(int $size): int
    {
        $media = get_option(self::MEDIA_KEY, []);

        // Use saved value, or fall back to 25 MB default so the limit is
        // active immediately without requiring an explicit Settings save.
        if (isset($media['upload_limit_mb']) && $media['upload_limit_mb'] !== '') {
            $limit = $media['upload_limit_mb'];
        } else {
            $limit = 25; // default: 25 MB
        }

        if (!is_numeric($limit) || (int) $limit < 1) {
            return $size; // invalid — leave WordPress limit unchanged
        }

        $limit_bytes = (int) $limit * 1048576;

        // Safety: only reduce, never raise above what WordPress/PHP allows.
        return min($size, $limit_bytes);
    }

    private function is_cc_settings_screen(): bool
    {
        global $pagenow;
        $page = $_GET['page'] ?? '';

        $valid_pages = [
            'cc-settings',
            'cc-site-settings',
            'cc-multilingual',
            'cc-visibility',
            'cc-media',
            'cc-redirect',
            'cc-seo',
            'cc-site-images',
            'cc-cookie-banner'
        ];
        return $pagenow === 'admin.php' && in_array($page, $valid_pages, true);
    }

    public function apply_menu_visibility(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }


        $settings = get_option(self::OPTION_KEY, []);
        $hidden = $this->get_hidden_slugs($settings, true);

        foreach ($hidden as $slug) {
            // Admin Safety Override: Always retain these regardless of toggle.
            if (in_array($slug, self::ADMIN_SAFETY_SLUGS, true)) {
                continue;
            }

            remove_menu_page($slug);
        }
    }

    private function get_hidden_slugs(array $settings, bool $is_admin): array
    {
        if (empty($settings)) {
            return [];
        }

        $items = $settings['admin'] ?? [];
        $hidden = [];

        foreach ($items as $slug => $visible) {
            if (!$visible) {
                $hidden[] = $slug;
            }
        }

        return $hidden;
    }

    // ─── Ordering ──────────────────────────────────────────────────

    public function apply_menu_order(): void
    {
        global $menu;

        if (!is_array($menu) || empty($menu)) {
            return;
        }

        $saved_order = get_option(self::ORDER_KEY, []);
        if (empty($saved_order)) {
            return;
        }

        $is_admin = current_user_can('manage_options');
        $role_key = $is_admin ? 'admin' : 'client';
        $order = $saved_order[$role_key] ?? [];

        if (empty($order)) {
            return;
        }

        // Build map: slug => menu item
        $slug_map = [];
        foreach ($menu as $key => $item) {
            $slug = $item[2] ?? '';
            if (!empty($slug)) {
                $slug_map[$slug] = $item;
            }
        }

        // Rebuild $menu: ordered items first, then unordered items, preserving separators
        $new_menu = [];
        $position = 1;

        // Place ordered items first
        foreach ($order as $slug) {
            if (isset($slug_map[$slug])) {
                $new_menu[$position] = $slug_map[$slug];
                unset($slug_map[$slug]);
                $position++;
            }
        }

        // Append remaining items (preserves items added by other plugins)
        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            if (isset($slug_map[$slug])) {
                $new_menu[$position] = $item;
                unset($slug_map[$slug]);
                $position++;
            }
        }

        $menu = $new_menu;
    }

    // ─── Save Handler ──────────────────────────────────────────────

    public function handle_save(): void
    {
        if (!isset($_POST['cc_menu_settings_nonce']) || !wp_verify_nonce($_POST['cc_menu_settings_nonce'], 'cc_save_menu_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings_group = $_POST['settings_group'] ?? 'general';
        $redirect_to = $_POST['_wp_http_referer'] ?? admin_url('admin.php?page=' . ($_GET['page'] ?? 'cc-settings'));

        try {
            if ($settings_group === 'general') {
                if (isset($_POST['cc_reset_menu'])) {
                    $this->handle_reset_general_settings();
                } else {
                    $this->save_visibility_settings();
                    $this->save_ordering_settings();
                    $this->save_media_settings();
                    $this->save_redirect_settings();
                    $this->save_admin_bar_settings();
                }
            }

            if ($settings_group === 'site_settings' || $settings_group === 'site') {
                $this->save_seo_settings();
                $this->save_site_images_settings();
                $this->save_multilingual_settings();
                $this->save_cookie_settings();
                $this->save_site_options_schema_settings();
            }

            set_transient('cc_settings_success', __('Settings saved successfully.', 'content-core'), 30);
        } catch (\Throwable $e) {
            set_transient('cc_settings_error', __('Failed to save settings: ', 'content-core') . $e->getMessage(), 30);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    private function handle_reset_general_settings(): void
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::ORDER_KEY);
        set_transient('cc_settings_success', __('Menu visibility and ordering have been reset to defaults.', 'content-core'), 30);
    }

    private function save_visibility_settings(): void
    {
        if (isset($_POST['cc_menu_admin']) || isset($_POST['cc_menu_client'])) {
            $visibility_payload = [];
            if (isset($_POST['cc_menu_admin']) && is_array($_POST['cc_menu_admin'])) {
                $visibility_payload['admin'] = array_map(function ($v) {
                    return (bool) $v;
                }, $_POST['cc_menu_admin']);
                $visibility_payload['admin']['options-general.php'] = true;
                $visibility_payload['admin']['plugins.php'] = true;
                $visibility_payload['admin']['content-core'] = true;
            }
            if (isset($_POST['cc_menu_client']) && is_array($_POST['cc_menu_client'])) {
                $visibility_payload['client'] = array_map(function ($v) {
                    return (bool) $v;
                }, $_POST['cc_menu_client']);
                $visibility_payload['client']['content-core'] = true;
            }
            if (!empty($visibility_payload)) {
                $this->update_merged_option(self::OPTION_KEY, $visibility_payload);
            }
        }
    }

    private function save_ordering_settings(): void
    {
        $admin_order_raw = $_POST['cc_core_order_admin'] ?? '';
        $client_order_raw = $_POST['cc_core_order_client'] ?? '';
        if (!empty($admin_order_raw) || !empty($client_order_raw)) {
            $order_payload = [];
            if (!empty($admin_order_raw)) {
                $admin_order = $this->parse_order_input($admin_order_raw);
                if (!empty($admin_order))
                    $order_payload['admin'] = $admin_order;
            }
            if (!empty($client_order_raw)) {
                $client_order = $this->parse_order_input($client_order_raw);
                if (!empty($client_order))
                    $order_payload['client'] = $client_order;
            }
            if (!empty($order_payload)) {
                $this->update_merged_option(self::ORDER_KEY, $order_payload);
            }
        }
    }

    private function save_media_settings(): void
    {
        $media_raw = $_POST['cc_media'] ?? null;
        if ($media_raw !== null) {
            if (!is_array($media_raw)) {
                $media_raw = [];
            }

            $upload_limit_raw = trim($media_raw['upload_limit_mb'] ?? '');
            if ($upload_limit_raw !== '' && is_numeric($upload_limit_raw)) {
                $upload_limit_mb = max(1, min(300, intval($upload_limit_raw)));
            } else {
                $upload_limit_mb = '';
            }

            $media_settings = [
                'enabled' => !empty($media_raw['enabled']),
                'max_width_px' => intval(($media_raw['max_width_px'] ?? 2000) ?: 2000),
                'output_format' => 'webp',
                'quality' => intval(($media_raw['quality'] ?? 70) ?: 70),
                'png_mode' => sanitize_text_field($media_raw['png_mode'] ?? 'lossless'),
                'delete_original' => !empty($media_raw['delete_original']),
                'upload_limit_mb' => $upload_limit_mb,
            ];
            $this->update_merged_option(self::MEDIA_KEY, $media_settings);
        }
    }

    private function save_redirect_settings(): void
    {
        $redirect_raw = $_POST['cc_redirect'] ?? null;
        if ($redirect_raw !== null) {
            if (!is_array($redirect_raw)) {
                $redirect_raw = [];
            }
            $redirect_payload = [
                'enabled' => !empty($redirect_raw['enabled']),
                'from_path' => $this->sanitize_redirect_path($redirect_raw['from_path'] ?? '/'),
                'target' => $this->sanitize_redirect_path($redirect_raw['target'] ?? '/wp-admin'),
                'status_code' => in_array($redirect_raw['status_code'] ?? '302', ['301', '302']) ? $redirect_raw['status_code'] : '302',
                'pass_query' => !empty($redirect_raw['pass_query']),
                'exclusions' => [
                    'admin' => !empty($redirect_raw['exclusions']['admin'] ?? false),
                    'ajax' => !empty($redirect_raw['exclusions']['ajax'] ?? false),
                    'rest' => !empty($redirect_raw['exclusions']['rest'] ?? false),
                    'cron' => !empty($redirect_raw['exclusions']['cron'] ?? false),
                    'cli' => !empty($redirect_raw['exclusions']['cli'] ?? false),
                ]
            ];
            $this->update_merged_option(self::REDIRECT_KEY, $redirect_payload);
        }
    }

    private function save_admin_bar_settings(): void
    {
        $ab_raw = $_POST['cc_admin_bar'] ?? null;
        if ($ab_raw !== null) {
            if (!is_array($ab_raw)) {
                $ab_raw = [];
            }
            $ab_payload = [
                'hide_wp_logo' => !empty($ab_raw['hide_wp_logo']),
                'hide_comments' => !empty($ab_raw['hide_comments']),
                'hide_new_content' => !empty($ab_raw['hide_new_content']),
            ];
            $this->update_merged_option(self::ADMIN_BAR_KEY, $ab_payload);
        }

        $ablink_raw = $_POST['cc_admin_bar_link'] ?? null;
        if ($ablink_raw !== null) {
            if (!is_array($ablink_raw)) {
                $ablink_raw = [];
            }
            $ablink_payload = [
                'enabled' => !empty($ablink_raw['enabled']),
                'url' => sanitize_text_field(wp_unslash($ablink_raw['url'] ?? '')),
                'new_tab' => !empty($ablink_raw['new_tab']),
            ];
            $this->update_merged_option(self::ADMIN_BAR_KEY, $ablink_payload);
        }
    }

    private function save_seo_settings(): void
    {
        if (isset($_POST['cc_seo'])) {
            $seo_post = (array) $_POST['cc_seo'];
            $seo_payload = [
                'site_title' => sanitize_text_field($seo_post['site_title'] ?? ''),
                'default_description' => sanitize_textarea_field($seo_post['default_description'] ?? ''),
            ];
            $this->update_merged_option(self::SEO_KEY, $seo_payload);
        }
    }

    private function save_site_images_settings(): void
    {
        if (isset($_POST['cc_site_images'])) {
            $img_post = (array) $_POST['cc_site_images'];
            $img_payload = [
                'social_icon_id' => !empty($img_post['social_icon_id']) ? absint($img_post['social_icon_id']) : null,
                'social_id' => !empty($img_post['social_id']) ? absint($img_post['social_id']) : null,
            ];
            update_option('cc_site_images', $img_payload);
        }
    }

    private function save_multilingual_settings(): void
    {
        $ml_raw = $_POST['cc_languages'] ?? null;
        if ($ml_raw !== null) {
            if (!is_array($ml_raw)) {
                $ml_raw = [];
            }
            $raw_langs = $ml_raw['languages'] ?? [];
            $structured_langs = [];
            $seen_codes = [];

            if (is_array($raw_langs)) {
                foreach ($raw_langs as $lang) {
                    if (empty($lang['code']))
                        continue;
                    $code = strtolower(sanitize_text_field($lang['code']));
                    if (in_array($code, $seen_codes))
                        continue;
                    $structured_langs[] = [
                        'code' => $code,
                        'label' => sanitize_text_field(($lang['label'] ?? '') ?: strtoupper($code)),
                        'flag_id' => intval($lang['flag_id'] ?? 0),
                    ];
                    $seen_codes[] = $code;
                }
            }

            $active_codes = array_column($structured_langs, 'code');
            $submitted_default = sanitize_text_field($ml_raw['default_lang'] ?? 'de');
            $submitted_fallback = sanitize_text_field($ml_raw['fallback_lang'] ?? 'de');

            $current_settings = get_option('cc_languages_settings', []);
            $old_default = $current_settings['default_lang'] ?? 'de';

            if (empty($active_codes)) {
                throw new \Exception(__('You must have at least one active language.', 'content-core'));
            } elseif (!in_array($old_default, $active_codes, true)) {
                throw new \Exception(sprintf(__('The default language (%s) cannot be deleted. Please change the default language first.', 'content-core'), strtoupper($old_default)));
            } else {
                if (!in_array($submitted_default, $active_codes, true)) {
                    $submitted_default = $active_codes[0];
                }

                if (!empty($ml_raw['fallback_enabled']) && !in_array($submitted_fallback, $active_codes, true)) {
                    $submitted_fallback = $submitted_default;
                }

                $ml_settings = [
                    'enabled' => !empty($ml_raw['enabled']),
                    'default_lang' => $submitted_default,
                    'active_langs' => $active_codes,
                    'languages' => $structured_langs,
                    'fallback_enabled' => !empty($ml_raw['fallback_enabled']),
                    'fallback_lang' => $submitted_fallback,
                    'permalink_enabled' => !empty($ml_raw['permalink_enabled']),
                    'permalink_bases' => $ml_raw['permalink_bases'] ?? [],
                    'enable_rest_seo' => !empty($ml_raw['enable_rest_seo']),
                    'enable_headless_fallback' => !empty($ml_raw['enable_headless_fallback']),
                    'enable_localized_taxonomies' => !empty($ml_raw['enable_localized_taxonomies']),
                    'enable_sitemap_endpoint' => !empty($ml_raw['enable_sitemap_endpoint']),
                    'taxonomy_bases' => $ml_raw['taxonomy_bases'] ?? [],
                ];
                $this->update_merged_option('cc_languages_settings', $ml_settings);

                if ($ml_settings['permalink_enabled']) {
                    set_transient('cc_flush_rewrites', 1, 3600);
                } else {
                    flush_rewrite_rules();
                }
            }
        }
    }

    private function save_cookie_settings(): void
    {
        $cookie_raw = $_POST['cc_cookie_settings'] ?? null;
        if ($cookie_raw !== null) {
            if (!is_array($cookie_raw)) {
                $cookie_raw = [];
            }
            $cookie_raw = wp_unslash($cookie_raw);
            $cookie_settings = [
                'enabled' => !empty($cookie_raw['enabled']),
                'bannerTitle' => sanitize_text_field($cookie_raw['bannerTitle'] ?? ''),
                'bannerText' => sanitize_textarea_field($cookie_raw['bannerText'] ?? ''),
                'policyUrl' => esc_url_raw($cookie_raw['policyUrl'] ?? ''),
                'labels' => [
                    'acceptAll' => sanitize_text_field($cookie_raw['labels']['acceptAll'] ?? __('Accept All', 'content-core')),
                    'rejectAll' => sanitize_text_field($cookie_raw['labels']['rejectAll'] ?? __('Reject All', 'content-core')),
                    'save' => sanitize_text_field($cookie_raw['labels']['save'] ?? __('Save Settings', 'content-core')),
                    'settings' => sanitize_text_field($cookie_raw['labels']['settings'] ?? __('Preferences', 'content-core')),
                ],
                'categories' => [
                    'analytics' => !empty($cookie_raw['categories']['analytics']),
                    'marketing' => !empty($cookie_raw['categories']['marketing']),
                    'preferences' => !empty($cookie_raw['categories']['preferences']),
                ],
                'integrations' => [
                    'ga4MeasurementId' => sanitize_text_field($cookie_raw['integrations']['ga4MeasurementId'] ?? ''),
                    'gtmContainerId' => sanitize_text_field($cookie_raw['integrations']['gtmContainerId'] ?? ''),
                    'metaPixelId' => sanitize_text_field($cookie_raw['integrations']['metaPixelId'] ?? ''),
                ],
                'behavior' => [
                    'regionMode' => in_array(($cookie_raw['regionMode'] ?? 'eu_only'), ['eu_only', 'global'], true) ? $cookie_raw['regionMode'] : 'eu_only',
                    'storage' => in_array(($cookie_raw['storage'] ?? 'localStorage'), ['localStorage', 'cookie'], true) ? $cookie_raw['storage'] : 'localStorage',
                    'ttlDays' => max(1, min(3650, absint($cookie_raw['ttlDays'] ?? 365))),
                ]
            ];
            $this->update_merged_option(self::COOKIE_KEY, $cookie_settings);
        }
    }

    private function save_site_options_schema_settings(): void
    {
        if (isset($_POST['cc_site_options_schema']) || isset($_POST['cc_reset_site_options_schema'])) {
            $plugin = \ContentCore\Plugin::get_instance();
            $site_mod = $plugin->get_module('site_options');

            if ($site_mod instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule) {
                if (isset($_POST['cc_reset_site_options_schema'])) {
                    $site_mod->reset_schema();
                } else {
                    $schema_raw = $_POST['cc_site_options_schema'];
                    if (is_array($schema_raw)) {
                        $site_mod->update_schema($schema_raw);
                    }
                }
            }
        }
    }

    private function parse_order_input(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode(stripslashes($raw), true);
        return is_array($decoded) ? $decoded : [];
    }

    // ─── Categorization ────────────────────────────────────────────

    public function get_all_menu_items(): array
    {
        global $menu;
        $items = [];

        if (!is_array($menu)) {
            return $items;
        }

        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            $title = $item[0] ?? '';

            if (empty($slug) || empty($title))
                continue;
            if (strpos($item[4] ?? '', 'wp-menu-separator') !== false)
                continue;

            $clean = wp_strip_all_tags($title);
            if (empty($clean))
                continue;

            $items[$slug] = $clean;
        }

        return $items;
    }

    /**
     * Determine if a slug represents a content / post-type list screen.
     */
    private function is_content_slug(string $slug): bool
    {
        // Built-in content screens
        if (in_array($slug, ['index.php', 'edit.php', 'upload.php', 'edit-comments.php'], true)) {
            return true;
        }

        // Pages
        if ($slug === 'edit.php?post_type=page') {
            return true;
        }

        // Custom post type list screens (edit.php?post_type=…)
        if (strpos($slug, 'edit.php?post_type=') === 0) {
            $pt = str_replace('edit.php?post_type=', '', $slug);
            // Exclude Content Core internal CPTs
            if (strpos($pt, 'cc_') === 0) {
                return false;
            }
            // Check if it's a real public CPT
            $obj = get_post_type_object($pt);
            if ($obj && $obj->public && $obj->show_in_menu) {
                return true;
            }
        }

        return false;
    }

    public function categorize_items(array $items): array
    {
        $core = [];
        $appearance = [];
        $system = [];
        $other = [];

        $appearance_slugs = ['themes.php'];
        $system_slugs = ['plugins.php', 'users.php', 'tools.php', 'options-general.php'];
        $skip_slugs = ['content-core'];

        foreach ($items as $slug => $title) {
            if (in_array($slug, $skip_slugs, true)) {
                continue;
            }
            if ($this->is_content_slug($slug)) {
                $core[$slug] = $title;
            } elseif (in_array($slug, $appearance_slugs, true)) {
                $appearance[$slug] = $title;
            } elseif (in_array($slug, $system_slugs, true)) {
                $system[$slug] = $title;
            } else {
                $other[$slug] = $title;
            }
        }

        return [
            'Core' => $core,
            'Appearance' => $appearance,
            'System' => $system,
            'Other / Third Party' => $other,
        ];
    }

    // ─── Render ─────────────────────────────────────────────────────

    /**
     * Site Settings page — PHP outputs header + multilingual PHP form,
     * then the React root div that hosts SEO, Images, Cookie tabs.
     */
    public function render_site_settings_page(): void
    {
        $title = get_admin_page_title();
        ?>
        <div class="wrap content-core-admin cc-settings-single-page">
            <div class="cc-header">
                <h1>
                    <?php echo esc_html($title); ?>
                </h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <?php if ($_GET['page'] === 'cc-multilingual' || (!isset($_GET['page']) && strpos($_SERVER['REQUEST_URI'], 'cc-multilingual') !== false)): ?>
                <div id="cc-multilingual-wrapper">
                    <?php
                    // ── Multilingual section (PHP-rendered; unchanged from original) ──
                    $this->maybe_render_multilingual_form_section();
                    ?>
                </div>
            <?php else: ?>
                <!-- ── React Shell (SEO, Site Images, Cookie Banner, Site Options tab nav) ── -->
                <div id="cc-site-settings-react-root" style="margin-top: 24px;"></div>
            <?php endif; ?>

            <!-- ── Site Options Schema — PHP form, shown/hidden by React tab ── -->
            <div id="cc-site-options-schema-section" style="display:none; margin-top: 0;">
                <?php \ContentCore\Modules\Settings\Partials\SiteOptionsSchemaRenderer::render(); ?>
            </div>

        </div>
        <?php
    }




    public function render_tab_multilingual(): void
    {
        \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render();
    }

    /**
     * Renders the Multilingual configuration form section for Site Settings.
     */
    private function maybe_render_multilingual_form_section(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml_mod = $plugin->get_module('multilingual');
        if (!$ml_mod) {
            return;
        }

        $this->render_tab_multilingual();
    }

    public function render_settings_page(): void
    {
        $page_slug = $_GET['page'] ?? '';

        // Ensure consistent <h1> across all these forms
        $title = get_admin_page_title();

        ?>
        <div class="wrap content-core-admin cc-settings-single-page">
            <div class="cc-header">
                <h1><?php echo esc_html($title); ?></h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>
                <input type="hidden" name="settings_group" value="general">

                <?php
                if ($page_slug === 'cc-visibility') {
                    \ContentCore\Modules\Settings\Partials\General\VisibilityTabRenderer::render($this);
                } elseif ($page_slug === 'cc-media') {
                    \ContentCore\Modules\Settings\Partials\General\MediaTabRenderer::render();
                } elseif ($page_slug === 'cc-redirect') {
                    \ContentCore\Modules\Settings\Partials\General\RedirectTabRenderer::render($this);
                }
                ?>

                <div style="display: flex; gap: 12px; align-items: center; margin-top: 24px;">
                    <?php submit_button(__('Save Settings', 'content-core'), 'primary', 'submit', false); ?>
                    <button type="submit" name="cc_reset_menu" class="button button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Reset this setting module to defaults?', 'content-core'); ?>');">
                        <?php _e('Reset to Defaults', 'content-core'); ?>
                    </button>
                </div>
            </form>

            <div class="cc-card" style="margin-top: 24px; background: #fcf9e8; border-color: #dba617;">
                <h3 style="margin-top: 0; color: #826200;">
                    <span class="dashicons dashicons-warning" style="margin-right: 4px;"></span>
                    <?php _e('Safety', 'content-core'); ?>
                </h3>
                <p style="color: #826200;">
                    <?php _e('Admins always keep Settings, Plugins, and Content Core. Direct URL:', 'content-core'); ?>
                    <code><?php echo esc_url(admin_url('admin.php?page=' . urlencode($page_slug))); ?></code>
                </p>
            </div>
            <?php
    }

    public function render_settings_styles(): void
    {
        ?>
            <style>
                /* Hide all sub-tabs because we use the WP admin menu for navigation now */
                .nav-tab-wrapper.cc-settings-tabs,
                .content-core-admin .cc-react-tabs,
                .content-core-admin .nav-tab-wrapper {
                    display: none !important;
                }

                .cc-tab-content {
                    display: block;
                }

                .cc-toggle {
                    position: relative;
                    display: inline-block;
                    width: 44px;
                    height: 24px;
                }

                .cc-toggle input {
                    opacity: 0;
                    width: 0;
                    height: 0;
                }

                .cc-toggle-slider {
                    position: absolute;
                    cursor: pointer;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: #ccc;
                    border-radius: 24px;
                    transition: .3s;
                }

                .cc-toggle-slider:before {
                    position: absolute;
                    content: "";
                    height: 18px;
                    width: 18px;
                    left: 3px;
                    bottom: 3px;
                    background-color: white;
                    border-radius: 50%;
                    transition: .3s;
                }

                .cc-toggle input:checked+.cc-toggle-slider {
                    background-color: #2271b1;
                }

                .cc-toggle input:checked+.cc-toggle-slider:before {
                    transform: translateX(20px);
                }

                .cc-toggle input:disabled+.cc-toggle-slider {
                    opacity: 0.5;
                    cursor: not-allowed;
                }

                .cc-sortable-list {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }

                .cc-sortable-list li {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 12px 14px;
                    margin-bottom: 4px;
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    cursor: grab;
                    box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                    user-select: none;
                }

                .cc-sortable-list li:active {
                    cursor: grabbing;
                }

                .cc-sortable-list li .dashicons-menu {
                    color: #a0a5aa;
                }

                .cc-sortable-list li .cc-order-title {
                    font-weight: 600;
                    font-size: 13px;
                }

                .cc-sortable-list li .cc-order-slug {
                    font-size: 11px;
                    color: #646970;
                    margin-left: auto;
                }

                .cc-sortable-list .ui-sortable-helper {
                    box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
                }

                .cc-sortable-list .ui-sortable-placeholder {
                    visibility: visible !important;
                    background: #f0f6fc;
                    border: 2px dashed #2271b1;
                    border-radius: 4px;
                    margin-bottom: 4px;
                }
            </style>

            <script>
                jQuery(function ($) {
                    // ── Ordering Logic ──
                    function serializeVisibilityOrder() {
                        var order = [];
                        $('.cc-visibility-sortable tbody tr[data-slug]').each(function () {
                            var slug = $(this).data('slug');
                            if (slug) order.push(slug);
                        });
                        $('#cc-core-order-admin-input').val(JSON.stringify(order));
                        $('#cc-core-order-client-input').val(JSON.stringify(order));
                    }

                    if ($.fn.sortable) {
                        $('.cc-visibility-sortable tbody').sortable({
                            handle: '.cc-drag-handle',
                            items: 'tr[data-slug]',
                            placeholder: 'ui-sortable-placeholder', update: function (event, ui) {
                                serializeVisibilityOrder();
                            }
                        });
                        serializeVisibilityOrder();
                    }
                });
            </script>
            <?php
    }

    // ─── Admin Bar ──────────────────────────────────────────────────

    /**
     * Remove admin bar nodes based on settings.
     * Runs on wp_before_admin_bar_render (global $wp_admin_bar available).
     * Applies to everyone; admins are included — all three items are safe to hide
     * because the Safety slugs concept only covers the sidebar menu, not the toolbar.
     */
    public function apply_admin_bar_visibility(): void
    {
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof \WP_Admin_Bar)) {
            return;
        }

        $settings = get_option(self::ADMIN_BAR_KEY, []);
        if (empty($settings)) {
            return;
        }

        if (!empty($settings['hide_wp_logo'])) {
            $wp_admin_bar->remove_node('wp-logo');
        }
        if (!empty($settings['hide_comments'])) {
            $wp_admin_bar->remove_node('comments');
        }
        if (!empty($settings['hide_new_content'])) {
            $wp_admin_bar->remove_node('new-content');
        }
    }

    /**
     * Override the admin bar site-name node href (and optionally target).
     * Priority 999 ensures we run after WordPress has added the node.
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public function apply_admin_bar_site_link(\WP_Admin_Bar $wp_admin_bar): void
    {
        $settings = get_option(self::ADMIN_BAR_KEY, []);
        if (empty($settings['enabled']) || empty($settings['url'])) {
            return;
        }

        $node = $wp_admin_bar->get_node('site-name');
        if (!$node) {
            return;
        }

        $args = [
            'id' => 'site-name',
            'href' => $settings['url'],
        ];

        if (!empty($settings['new_tab'])) {
            // WP_Admin_Bar does not expose a meta/target API, so we store extra
            // attributes via the 'meta' key and apply them via inline JS / CSS
            // instead. The most reliable approach for targeting is a small inline
            // script and is safe since this only runs inside wp-admin.
            add_action('admin_footer', function () use ($settings) {
                ?>
                    <script>
                        (function () {
                            var el = document.querySelector('#wp-admin-bar-site-name > a');
                            if (el) {
                                el.setAttribute('target', '_blank');
                                el.setAttribute('rel', 'noopener');
                            }
                        })();
                    </script>
                    <?php
            });
        }

        $wp_admin_bar->add_node($args);
    }

    /**
     * Helper to render the draggable order list items.
     */
    private function render_order_list(array $categories, array $saved_order, bool $is_admin): void
    {
        $all_items = [];
        foreach ($categories as $group_items) {
            foreach ($group_items as $slug => $title) {
                $all_items[$slug] = $title;
            }
        }

        $role_key = $is_admin ? 'admin' : 'client';
        $ordered_slugs = $saved_order[$role_key] ?? [];
        $final_order = [];

        // 1. Add items that have a saved position
        foreach ($ordered_slugs as $slug) {
            if (isset($all_items[$slug])) {
                $final_order[$slug] = $all_items[$slug];
            }
        }

        // 2. Add remaining items at the end
        foreach ($all_items as $slug => $title) {
            if (!isset($final_order[$slug])) {
                $final_order[$slug] = $title;
            }
        }

        foreach ($final_order as $slug => $title):
            ?>
                <li data-slug="<?php echo esc_attr($slug); ?>">
                    <span class="dashicons dashicons-menu cc-drag-handle"></span>
                    <span class="cc-order-title">
                        <?php echo esc_html($title); ?>
                    </span>
                    <span class="cc-order-slug">
                        <?php echo esc_html($slug); ?>
                    </span>
                </li>
                <?php
        endforeach;
    }

    /**
     * Safely merge and update a project option.
     */
    private function update_merged_option(string $key, array $new_data): void
    {
        $existing = get_option($key, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $merged = array_replace_recursive($existing, $new_data);
        update_option($key, $merged, false);
    }

    /**
     * Render success/error notices after redirection.
     */
    public function render_admin_notices(): void
    {
        if (!$this->is_cc_settings_screen()) {
            return;
        }

        $error = get_transient('cc_settings_error');
        if ($error) {
            delete_transient('cc_settings_error');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
        }

        $success = get_transient('cc_settings_success');
        if ($success) {
            delete_transient('cc_settings_success');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
        }
    }

    /**
     * Sanitize custom redirect path to be relative and start with a slash.
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