<?php
namespace ContentCore\Modules\Settings;

use ContentCore\Modules\ModuleInterface;

class SettingsModule implements ModuleInterface
{
    const OPTION_KEY = 'content_core_admin_menu_settings';
    const ORDER_KEY = 'content_core_admin_menu_order';
    const MEDIA_KEY = 'cc_media_settings';
    const REDIRECT_KEY = 'cc_redirect_settings';
    const ADMIN_BAR_KEY = 'cc_admin_bar_settings';
    const SEO_KEY = 'cc_site_seo';
    const COOKIE_KEY = 'cc_cookie_settings';

    const DEFAULT_HIDDEN = [
        'edit-comments.php',
        'themes.php',
        'plugins.php',
        'tools.php',
        'options-general.php',
    ];

    const ADMIN_SAFETY_SLUGS = [
        'options-general.php',
        'plugins.php',
        'content-core'
    ];

    const CORE_WP_SLUGS = [
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
        // Redirect logic runs on frontend init
        add_action('init', [$this, 'handle_frontend_redirect']);

        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'register_settings_page']);
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
        if (strpos($hook, 'cc-settings') !== false) {
            wp_enqueue_media();
            wp_enqueue_script('jquery-ui-sortable');
        }
    }

    public function register_settings_page(): void
    {
        // 1. Site Settings (Multilingual, SEO)
        add_submenu_page(
            'content-core',
            __('Site Settings', 'content-core'),
            __('Site Settings', 'content-core'),
            'manage_options',
            'cc-site-settings',
            [$this, 'render_settings_page']
        );

        // 2. Settings (Menu, Media, Redirect)
        add_submenu_page(
            'content-core',
            __('Settings', 'content-core'),
            __('Settings', 'content-core'),
            'manage_options',
            'cc-settings',
            [$this, 'render_settings_page']
        );
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

    private function is_cc_settings_screen(): bool
    {
        global $pagenow;
        $page = $_GET['page'] ?? '';

        // Safe Mode: Only active on the actual settings pages to allow recovery
        return $pagenow === 'admin.php' && ($page === 'cc-settings' || $page === 'cc-site-settings');
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
        if (!isset($_POST['cc_menu_settings_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['cc_menu_settings_nonce'], 'cc_save_menu_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings_group = $_POST['settings_group'] ?? 'general';
        $redirect_to = $_POST['_wp_http_referer'] ?? admin_url('admin.php?page=' . ($_GET['page'] ?? 'cc-settings'));

        try {
            // ── Reset ──
            if (isset($_POST['cc_reset_menu']) && $settings_group === 'general') {
                delete_option(self::OPTION_KEY);
                delete_option(self::ORDER_KEY);
                set_transient('cc_settings_success', __('Menu visibility and ordering have been reset to defaults.', 'content-core'), 30);
                wp_safe_redirect($redirect_to);
                exit;
            }

            if ($settings_group === 'general') {
                // ── Visibility ──
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

                // ── Ordering ──
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

                // ── Media Settings ──
                $media_raw = $_POST['cc_media'] ?? null;
                if ($media_raw !== null) {
                    if (!is_array($media_raw)) {
                        $media_raw = [];
                    }
                    $media_settings = [
                        'enabled' => !empty($media_raw['enabled']),
                        'max_width_px' => intval(($media_raw['max_width_px'] ?? 2000) ?: 2000),
                        'output_format' => 'webp',
                        'quality' => intval(($media_raw['quality'] ?? 70) ?: 70),
                        'png_mode' => sanitize_text_field($media_raw['png_mode'] ?? 'lossless'),
                        'delete_original' => !empty($media_raw['delete_original']),
                    ];
                    $this->update_merged_option(self::MEDIA_KEY, $media_settings);
                }

                // ── Redirection ──
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

                // ── Admin Bar visibility ──
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

                // ── Admin Bar site link ──
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

            if ($settings_group === 'site_settings' || $settings_group === 'site') {
                // ── SEO ──
                if (isset($_POST['cc_seo'])) {
                    $seo_post = (array) $_POST['cc_seo'];
                    $seo_payload = [
                        'site_title' => sanitize_text_field($seo_post['site_title'] ?? ''),
                        'default_description' => sanitize_textarea_field($seo_post['default_description'] ?? ''),
                        'default_og_image_id' => !empty($seo_post['default_og_image_id']) ? intval($seo_post['default_og_image_id']) : null,
                    ];
                    $this->update_merged_option(self::SEO_KEY, $seo_payload);
                }

                // ── Multilingual Settings ──
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
                // ── Cookie Banner ──
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

                // ── Site Options Schema ──
                if (isset($_POST['cc_site_options_schema']) || isset($_POST['cc_reset_site_options_schema'])) {
                    $plugin = \ContentCore\Plugin::get_instance();
                    $site_mod = $plugin->get_module('site_options');

                    if ($site_mod instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule) {
                        if (isset($_POST['cc_reset_site_options_schema'])) {
                            $site_mod->reset_schema();
                            set_transient('cc_settings_success', __('Site Options schema has been reset to defaults.', 'content-core'), 30);
                        } else {
                            $schema_raw = $_POST['cc_site_options_schema'];
                            // Simple structure validation and sanitization could be deeper, but we expect array here
                            if (is_array($schema_raw)) {
                                $site_mod->update_schema($schema_raw);
                                set_transient('cc_settings_success', __('Site Options schema saved successfully.', 'content-core'), 30);
                            }
                        }
                    }
                }
            }

            set_transient('cc_settings_success', __('Settings saved successfully.', 'content-core'), 30);
        } catch (\Throwable $e) {
            set_transient('cc_settings_error', __('Failed to save settings: ', 'content-core') . $e->getMessage(), 30);
        }

        wp_safe_redirect($redirect_to);
        exit;
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

    private function get_all_menu_items(): array
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

    private function categorize_items(array $items): array
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

    public function render_settings_page(): void
    {
        $page_slug = $_GET['page'] ?? '';
        $is_site_settings = ($page_slug === 'cc-site-settings');

        $vis_settings = get_option(self::OPTION_KEY, []);
        $order_settings = get_option(self::ORDER_KEY, []);
        $all_items = $this->get_all_menu_items();

        $admin_vis = $vis_settings['admin'] ?? [];
        $client_vis = $vis_settings['client'] ?? [];
        $has_vis = !empty($vis_settings);

        // Sort items by existing order if available
        $items_by_slug = $all_items;
        $ordered_items = [];
        $admin_order = $order_settings['admin'] ?? [];

        if (!empty($admin_order)) {
            foreach ($admin_order as $slug) {
                if (isset($items_by_slug[$slug])) {
                    $ordered_items[$slug] = $items_by_slug[$slug];
                    unset($items_by_slug[$slug]);
                }
            }
        }
        // Append remaining
        foreach ($items_by_slug as $slug => $title) {
            $ordered_items[$slug] = $title;
        }

        $categories = $this->categorize_items($ordered_items);

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1>
                    <?php echo $is_site_settings ? __('Site Settings', 'content-core') : __('Settings', 'content-core'); ?>
                </h1>
                <p style="color: #646970; margin-top: 4px;">
                    <?php echo $is_site_settings
                        ? __('Manage high-level project configurations like languages and SEO.', 'content-core')
                        : __('Configure sidebar visibility, media optimization, and redirects.', 'content-core'); ?>
                </p>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <h2 class="nav-tab-wrapper cc-settings-tabs" style="margin-bottom: 20px; display: none;">
                <?php if ($is_site_settings): ?>
                    <a href="#multilingual" class="nav-tab nav-tab-active" data-tab="multilingual">
                        <?php _e('Multilingual', 'content-core'); ?>
                    </a>
                    <a href="#seo" class="nav-tab" data-tab="seo">
                        <?php _e('SEO', 'content-core'); ?>
                    </a>
                    <a href="#cookie" class="nav-tab" data-tab="cookie">
                        <?php _e('Cookie Banner', 'content-core'); ?>
                    </a>
                    <a href="#site-options" class="nav-tab" data-tab="site-options">
                        <?php _e('Site Options', 'content-core'); ?>
                    </a>
                    <?php
                else: ?>
                    <a href="#menu" class="nav-tab nav-tab-active" data-tab="menu">
                        <?php _e('Visibility', 'content-core'); ?>
                    </a>
                    <a href="#media" class="nav-tab" data-tab="media">
                        <?php _e('Media', 'content-core'); ?>
                    </a>
                    <a href="#redirect" class="nav-tab" data-tab="redirect">
                        <?php _e('Redirect', 'content-core'); ?>
                    </a>
                    <?php
                endif; ?>
            </h2>

            <form method="post">
                <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>
                <input type="hidden" name="settings_group"
                    value="<?php echo $is_site_settings ? 'site_settings' : 'general'; ?>">

                <div id="cc-tab-menu" class="cc-tab-content active">

                    <!-- ═══ Visibility Table ═══ -->
                    <div class="cc-card" style="margin-bottom: 24px;">
                        <h2 style="margin-top: 0;">
                            <?php _e('Menu Visibility', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Toggle sidebar items on or off. Admins always keep Settings, Plugins, and Content Core.', 'content-core'); ?>
                        </p>

                        <table class="wp-list-table widefat fixed striped cc-visibility-sortable" style="margin-top: 16px;">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th style="width: 35%;">
                                        <?php _e('Menu Item', 'content-core'); ?>
                                    </th>
                                    <th style="width: 30%; text-align: center;">
                                        <?php _e('Administrators', 'content-core'); ?>
                                    </th>
                                    <th style="width: 30%; text-align: center;">
                                        <?php _e('Editors / Clients', 'content-core'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <input type="hidden" name="cc_core_order_admin" id="cc-core-order-admin-input" value="">
                                <input type="hidden" name="cc_core_order_client" id="cc-core-order-client-input" value="">
                                <?php foreach ($categories as $group_name => $group_items): ?>
                                    <?php if (empty($group_items))
                                        continue; ?>
                                    <tr class="cc-category-header" style="background: #f0f0f1;">
                                        <td colspan="4"><strong>
                                                <?php echo esc_html($group_name); ?>
                                            </strong></td>
                                    </tr>
                                    <?php foreach ($group_items as $slug => $title):
                                        $a_checked = true;
                                        $c_checked = true;

                                        if ($has_vis) {
                                            $a_checked = $admin_vis[$slug] ?? true;
                                            $c_checked = $client_vis[$slug] ?? true;
                                        } else {
                                            $a_locked = in_array($slug, self::ADMIN_SAFETY_SLUGS, true);
                                            $c_checked = !in_array($slug, self::DEFAULT_HIDDEN, true);
                                        }

                                        $a_locked = in_array($slug, ['options-general.php', 'plugins.php', 'content-core'], true);
                                        ?>
                                        <tr data-slug="<?php echo esc_attr($slug); ?>">
                                            <td style="text-align: center; vertical-align: middle; cursor: grab;">
                                                <span class="dashicons dashicons-menu cc-drag-handle" style="color: #a0a5aa;"></span>
                                            </td>
                                            <td>
                                                <strong>
                                                    <?php echo esc_html($title); ?>
                                                </strong>
                                                <br><code style="font-size: 11px; color: #646970;"><?php echo esc_html($slug); ?></code>
                                            </td>
                                            <td style="text-align: center;">
                                                <label class="cc-toggle">
                                                    <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]" value="0">
                                                    <input type="checkbox" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]"
                                                        value="1" <?php checked($a_checked || $a_locked); ?>                 <?php if ($a_locked)
                                                                                 echo 'disabled'; ?>>
                                                    <span class="cc-toggle-slider"></span>
                                                </label>
                                                <?php if ($a_locked): ?>
                                                    <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]" value="1">
                                                    <?php
                                                endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <label class="cc-toggle">
                                                    <input type="hidden" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                        value="0">
                                                    <input type="checkbox" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                        value="1" <?php checked($c_checked); ?>>
                                                    <span class="cc-toggle-slider"></span>
                                                </label>
                                            </td>
                                        </tr>
                                        <?php
                                    endforeach; ?>
                                    <?php
                                endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ═══ Admin Bar Visibility ═══ -->
                    <div class="cc-card" style="margin-bottom: 24px;">
                        <h2 style="margin-top: 0;">
                            <?php _e('Admin Bar', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Hide specific items from the WordPress admin bar for Editors and Clients.', 'content-core'); ?>
                        </p>

                        <?php
                        $ab_defaults = [
                            'hide_wp_logo' => false,
                            'hide_comments' => false,
                            'hide_new_content' => false,
                        ];
                        $saved_ab = get_option(self::ADMIN_BAR_KEY, []);
                        $ab_settings = array_merge($ab_defaults, is_array($saved_ab) ? $saved_ab : []);
                        ?>

                        <table class="form-table" style="margin-top: 16px;">
                            <tr>
                                <th scope="row"><?php _e('Hide WordPress Logo Menu', 'content-core'); ?></th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar[hide_wp_logo]" value="0">
                                        <input type="checkbox" name="cc_admin_bar[hide_wp_logo]" value="1" <?php checked($ab_settings['hide_wp_logo']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Removes the WordPress logo dropdown (id: wp-logo) from the admin bar.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Hide Comments Bubble', 'content-core'); ?></th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar[hide_comments]" value="0">
                                        <input type="checkbox" name="cc_admin_bar[hide_comments]" value="1" <?php checked($ab_settings['hide_comments']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Removes the comments bubble icon (id: comments) from the admin bar.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Hide "+ New" Menu', 'content-core'); ?></th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar[hide_new_content]" value="0">
                                        <input type="checkbox" name="cc_admin_bar[hide_new_content]" value="1" <?php checked($ab_settings['hide_new_content']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Removes the "+&nbsp;New" quick-create menu (id: new-content) from the admin bar.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- End #cc-tab-menu -->

                <div id="cc-tab-media" class="cc-tab-content">
                    <?php
                    $media_defaults = [
                        'enabled' => true,
                        'max_width_px' => 2000,
                        'output_format' => 'webp',
                        'quality' => 70,
                        'png_mode' => 'lossless',
                        'delete_original' => true,
                    ];
                    $saved_media = get_option(self::MEDIA_KEY, []);
                    $media_settings = array_merge($media_defaults, is_array($saved_media) ? $saved_media : []);
                    ?>
                    <div class="cc-card" style="margin-bottom: 24px;">
                        <h2 style="margin-top: 0;">
                            <?php _e('Media Optimization', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Automatically optimize images on upload. Converts to WebP and resizes if necessary.', 'content-core'); ?>
                        </p>

                        <table class="form-table" style="margin-top: 16px;">
                            <tr>
                                <th scope="row">
                                    <?php _e('Enable Optimization', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_media[enabled]" value="0">
                                        <input type="checkbox" name="cc_media[enabled]" value="1" <?php
                                        checked($media_settings['enabled']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Max Width (px)', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="number" name="cc_media[max_width_px]"
                                        value="<?php echo esc_attr($media_settings['max_width_px']); ?>" class="regular-text"
                                        step="1" min="100">
                                    <p class="description">
                                        <?php _e('Images wider than this will be resized down.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Output Format', 'content-core'); ?>
                                </th>
                                <td>
                                    <select name="cc_media[output_format]" disabled class="regular-text">
                                        <option value="webp" selected>WebP</option>
                                    </select>
                                    <p class="description">
                                        <?php _e('Standardized to WebP for modern performance.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Quality', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="number" name="cc_media[quality]"
                                        value="<?php echo esc_attr($media_settings['quality']); ?>" class="small-text" min="1"
                                        max="100">
                                    <p class="description">
                                        <?php _e('1-100. Lower is more compressed. Default: 70.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('PNG Conversion Mode', 'content-core'); ?>
                                </th>
                                <td>
                                    <select name="cc_media[png_mode]" class="regular-text">
                                        <option value="lossless" <?php selected($media_settings['png_mode'], 'lossless'); ?>>
                                            <?php _e('Lossless (High Quality)', 'content-core'); ?>
                                        </option>
                                        <option value="lossy" <?php selected($media_settings['png_mode'], 'lossy'); ?>>
                                            <?php _e('Lossy (Lower Size)', 'content-core'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Delete Original', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_media[delete_original]" value="0">
                                        <input type="checkbox" name="cc_media[delete_original]" value="1" <?php
                                        checked($media_settings['delete_original']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('If enabled, the original source file (jpg/png/gif) is deleted after successful conversion to WebP.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- End #cc-tab-media -->

                <div id="cc-tab-multilingual" class="cc-tab-content">

                    <!-- ═══ Languages / Multilingual ═══ -->
                    <?php
                    $ml_instance = new \ContentCore\Modules\Multilingual\MultilingualModule();
                    $ml_settings = $ml_instance->get_settings();
                    $catalog = \ContentCore\Modules\Multilingual\MultilingualModule::get_language_catalog();
                    ?>
                    <div class="cc-card" style="margin-bottom: 24px;">
                        <h2 style="margin-top: 0;">
                            <?php _e('Multilingual Settings', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Configure languages and translation behavior. One post is created per language.', 'content-core'); ?>
                        </p>

                        <table class="form-table" style="margin-top: 16px;">
                            <tr>
                                <th scope="row">
                                    <?php _e('Enable Multilingual', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[enabled]" value="0">
                                        <input type="checkbox" name="cc_languages[enabled]" value="1" <?php
                                        checked($ml_settings['enabled']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Active Languages', 'content-core'); ?>
                                </th>
                                <td>
                                    <table class="widefat fixed striped" id="cc-ml-languages-table"
                                        style="margin-bottom: 12px;">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px;">
                                                    <?php _e('Flag', 'content-core'); ?>
                                                </th>
                                                <th style="width: 80px;">
                                                    <?php _e('Code', 'content-core'); ?>
                                                </th>
                                                <th>
                                                    <?php _e('Label', 'content-core'); ?>
                                                </th>
                                                <th style="width: 150px;">
                                                    <?php _e('Custom Flag', 'content-core'); ?>
                                                </th>
                                                <th style="width: 50px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ml_settings['languages'] as $index => $lang): ?>
                                                <tr data-index="<?php echo $index; ?>"
                                                    data-code="<?php echo esc_attr($lang['code']); ?>">
                                                    <td class="flag-col" style="vertical-align: middle;">
                                                        <?php echo $ml_instance->get_flag_html($lang['code'], $lang['flag_id'] ?? 0); ?>
                                                    </td>
                                                    <td>
                                                        <code
                                                            class="language-code-display"><?php echo esc_html($lang['code']); ?></code>
                                                        <input type="hidden"
                                                            name="cc_languages[languages][<?php echo $index; ?>][code]"
                                                            value="<?php echo esc_attr($lang['code']); ?>" class="language-code">
                                                    </td>
                                                    <td>
                                                        <span style="font-weight: 500; font-size: 13px;">
                                                            <?php echo esc_html($lang['label']); ?>
                                                        </span>
                                                        <input type="hidden"
                                                            name="cc_languages[languages][<?php echo $index; ?>][label]"
                                                            value="<?php echo esc_attr($lang['label']); ?>" class="language-label">
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 4px; align-items: center;">
                                                            <input type="hidden"
                                                                name="cc_languages[languages][<?php echo $index; ?>][flag_id]"
                                                                value="<?php echo esc_attr($lang['flag_id']); ?>"
                                                                class="flag-id-input">
                                                            <button type="button" class="button button-small select-custom-flag">
                                                                <?php _e('Select', 'content-core'); ?>
                                                            </button>
                                                            <button type="button" class="button button-small remove-custom-flag"
                                                                style="<?php echo empty($lang['flag_id']) ? 'display:none;' : ''; ?>"><span
                                                                    class="dashicons dashicons-no-alt"
                                                                    style="margin-top: 2px;"></span></button>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: right;">
                                                        <button type="button" class="button button-link-delete remove-row"><span
                                                                class="dashicons dashicons-no-alt"
                                                                style="margin-top: 4px;"></span></button>
                                                    </td>
                                                </tr>
                                                <?php
                                            endforeach; ?>
                                        </tbody>
                                    </table>

                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <select id="cc-ml-add-selector" class="regular-text" style="width: auto;">
                                            <option value="">
                                                <?php _e('Select a language...', 'content-core'); ?>
                                            </option>
                                            <?php foreach ($catalog as $code => $data): ?>
                                                <option value="<?php echo esc_attr($code); ?>"
                                                    data-label="<?php echo esc_attr($data['label']); ?>">
                                                    <?php echo esc_html($data['label']); ?> (
                                                    <?php echo esc_html($code); ?>)
                                                </option>
                                                <?php
                                            endforeach; ?>
                                        </select>
                                        <button type="button" class="button add-language-row">
                                            <?php _e('Add Language', 'content-core'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Default Language', 'content-core'); ?>
                                </th>
                                <td>
                                    <select name="cc_languages[default_lang]" id="cc-default-lang-select" class="regular-text">
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <option value="<?php echo esc_attr($lang['code']); ?>" <?php
                                               selected($ml_settings['default_lang'], $lang['code']); ?>>
                                                <?php echo esc_html($lang['label']); ?> (
                                                <?php echo esc_html($lang['code']); ?>)
                                            </option>
                                            <?php
                                        endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('The primary language for your content.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Content Fallback', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[fallback_enabled]" value="0">
                                        <input type="checkbox" name="cc_languages[fallback_enabled]" id="cc-ml-fallback-toggle"
                                            value="1" <?php checked($ml_settings['fallback_enabled']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('If a translation is missing, return the fallback language in REST API.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Fallback Language', 'content-core'); ?>
                                </th>
                                <td>
                                    <select name="cc_languages[fallback_lang]" id="cc-fallback-lang-select" class="regular-text"
                                        <?php disabled(!$ml_settings['fallback_enabled']); ?>>
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <option value="<?php echo esc_attr($lang['code']); ?>" <?php
                                               selected($ml_settings['fallback_lang'], $lang['code']); ?>>
                                                <?php echo esc_html($lang['label']); ?> (
                                                <?php echo esc_html($lang['code']); ?>)
                                            </option>
                                            <?php
                                        endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Localized Permalinks', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[permalink_enabled]" value="0">
                                        <input type="checkbox" name="cc_languages[permalink_enabled]"
                                            id="cc-ml-permalink-toggle" value="1" <?php
                                            checked(!empty($ml_settings['permalink_enabled'])); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Enable prefixes for non-default languages and translated post type bases (e.g. /fr/references/slug).', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('REST SEO Signals', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[enable_rest_seo]" value="0">
                                        <input type="checkbox" name="cc_languages[enable_rest_seo]" value="1" <?php
                                        checked(!empty($ml_settings['enable_rest_seo'])); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Expose canonical, alternates (hreflang), and x-default in REST API responses.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Headless Fallback', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[enable_headless_fallback]" value="0">
                                        <input type="checkbox" name="cc_languages[enable_headless_fallback]" value="1" <?php
                                        checked(!empty($ml_settings['enable_headless_fallback'])); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Return default language content if requested translation is missing in REST.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Multilingual Taxonomies', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[enable_localized_taxonomies]" value="0">
                                        <input type="checkbox" name="cc_languages[enable_localized_taxonomies]"
                                            id="cc-ml-tax-toggle" value="1" <?php
                                            checked(!empty($ml_settings['enable_localized_taxonomies'])); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Enable language assignment and localized permalinks for Categories and Custom Taxonomies.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Sitemap Endpoint', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_languages[enable_sitemap_endpoint]" value="0">
                                        <input type="checkbox" name="cc_languages[enable_sitemap_endpoint]" value="1" <?php
                                        checked(!empty($ml_settings['enable_sitemap_endpoint'])); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Expose a sitemap-ready dataset at /wp-json/cc/v1/sitemap.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <div id="cc-ml-permalink-config"
                            style="<?php echo empty($ml_settings['permalink_enabled']) ? 'display:none;' : ''; ?>; margin-top: 20px;">
                            <h3 style="margin-bottom: 12px;">
                                <?php _e('Localized Bases', 'content-core'); ?>
                            </h3>
                            <p class="description" style="margin-bottom: 16px;">
                                <?php _e('Define the URL segment (base) for each post type per language. Leave empty to use the default post type slug.', 'content-core'); ?>
                            </p>

                            <table class="widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>
                                            <?php _e('Post Type', 'content-core'); ?>
                                        </th>
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <th style="width: 150px;">
                                                <?php echo esc_html($lang['label']); ?> (
                                                <?php echo esc_html(strtoupper($lang['code'])); ?>)
                                            </th>
                                            <?php
                                        endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $public_pts = get_post_types(['public' => true], 'objects');
                                    foreach ($public_pts as $pt):
                                        if ($pt->name === 'attachment')
                                            continue;
                                        $default_base = $pt->rewrite['slug'] ?? $pt->name;
                                        if ($pt->name === 'page' || $pt->name === 'post')
                                            $default_base = '';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php echo esc_html($pt->label); ?>
                                                </strong><br>
                                                <code style="font-size: 11px;"><?php echo esc_html($pt->name); ?></code>
                                            </td>
                                            <?php foreach ($ml_settings['languages'] as $lang): ?>
                                                <td>
                                                    <input type="text"
                                                        name="cc_languages[permalink_bases][<?php echo esc_attr($pt->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                        value="<?php echo esc_attr($ml_settings['permalink_bases'][$pt->name][$lang['code']] ?? ''); ?>"
                                                        placeholder="<?php echo esc_attr($default_base); ?>" class="regular-text"
                                                        style="width: 100%;">
                                                </td>
                                                <?php
                                            endforeach; ?>
                                        </tr>
                                        <?php
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div id="cc-ml-tax-config"
                            style="<?php echo empty($ml_settings['enable_localized_taxonomies']) ? 'display:none;' : ''; ?>; margin-top: 30px;">
                            <h3 style="margin-bottom: 12px;">
                                <?php _e('Localized Taxonomy Bases', 'content-core'); ?>
                            </h3>
                            <p class="description" style="margin-bottom: 16px;">
                                <?php _e('Define the URL segment (base) for each taxonomy per language.', 'content-core'); ?>
                            </p>

                            <table class="widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>
                                            <?php _e('Taxonomy', 'content-core'); ?>
                                        </th>
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <th style="width: 150px;">
                                                <?php echo esc_html($lang['label']); ?> (
                                                <?php echo esc_html(strtoupper($lang['code'])); ?>)
                                            </th>
                                            <?php
                                        endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $public_taxes = get_taxonomies(['public' => true], 'objects');
                                    foreach ($public_taxes as $tax):
                                        if ($tax->name === 'post_tag' || $tax->name === 'post_format')
                                            continue;
                                        $default_base = $tax->rewrite['slug'] ?? $tax->name;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php echo esc_html($tax->label); ?>
                                                </strong><br>
                                                <code style="font-size: 11px;"><?php echo esc_html($tax->name); ?></code>
                                            </td>
                                            <?php foreach ($ml_settings['languages'] as $lang): ?>
                                                <td>
                                                    <input type="text"
                                                        name="cc_languages[taxonomy_bases][<?php echo esc_attr($tax->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                        value="<?php echo esc_attr($ml_settings['taxonomy_bases'][$tax->name][$lang['code']] ?? ''); ?>"
                                                        placeholder="<?php echo esc_attr($default_base); ?>" class="regular-text"
                                                        style="width: 100%;">
                                                </td>
                                                <?php
                                            endforeach; ?>
                                        </tr>
                                        <?php
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <script type="text/template" id="cc-ml-row-template">
                                <tr data-index="{index}" data-code="{code}">
                                    <td class="flag-col" style="vertical-align: middle;">{flag}</td>
                                    <td>
                                        <code class="language-code-display">{code}</code>
                                        <input type="hidden" name="cc_languages[languages][{index}][code]" value="{code}" class="language-code">
                                    </td>
                                    <td>
                                        <span style="font-weight: 500; font-size: 13px;">{label}</span>
                                        <input type="hidden" name="cc_languages[languages][{index}][label]" value="{label}" class="language-label">
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 4px; align-items: center;">
                                            <input type="hidden" name="cc_languages[languages][{index}][flag_id]" value="0" class="flag-id-input">
                                            <button type="button" class="button button-small select-custom-flag"><?php _e('Select', 'content-core'); ?></button>
                                            <button type="button" class="button button-small remove-custom-flag" style="display:none;"><span class="dashicons dashicons-no-alt" style="margin-top: 2px;"></span></button>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="button button-link-delete remove-row"><span class="dashicons dashicons-no-alt" style="margin-top: 4px;"></span></button>
                                    </td>
                                </tr>
                            </script>
                    </div>
                </div> <!-- End #cc-tab-multilingual -->

                <div id="cc-tab-redirect" class="cc-tab-content">
                    <div class="cc-card">
                        <h2 style="margin-top: 0;">
                            <?php _e('Root Redirection', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Configure where users are sent when visiting the site root (e.g. your CMS subdomain).', 'content-core'); ?>
                        </p>

                        <?php
                        $red_defaults = [
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
                        $saved_red = get_option(self::REDIRECT_KEY, []);
                        $red_settings = array_merge($red_defaults, is_array($saved_red) ? $saved_red : []);
                        ?>

                        <table class="form-table" style="margin-top: 20px;">
                            <tr>
                                <th scope="row">
                                    <?php _e('Enable Root Redirect', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_redirect[enabled]" value="0">
                                        <input type="checkbox" name="cc_redirect[enabled]" value="1" <?php
                                        checked($red_settings['enabled']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Redirect From', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="text" name="cc_redirect[from_path]"
                                        value="<?php echo esc_attr($red_settings['from_path']); ?>" class="regular-text">
                                    <p class="description">
                                        <?php _e('Exact path to redirect from. Default is "/".', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Redirect To', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="text" name="cc_redirect[target]"
                                        value="<?php echo esc_attr($red_settings['target']); ?>" class="regular-text">
                                    <p class="description">
                                        <?php _e('Relative path only (e.g. /wp-admin). Overrides any dropdown choices.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Status Code', 'content-core'); ?>
                                </th>
                                <td>
                                    <select name="cc_redirect[status_code]" class="regular-text">
                                        <option value="301" <?php selected($red_settings['status_code'], '301'); ?>>301 Moved
                                            Permanently</option>
                                        <option value="302" <?php selected($red_settings['status_code'], '302'); ?>>302 Found
                                            (Temporary)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Allow Query String Pass Through', 'content-core'); ?>
                                </th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_redirect[pass_query]" value="0">
                                        <input type="checkbox" name="cc_redirect[pass_query]" value="1" <?php
                                        checked($red_settings['pass_query']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Exclusions', 'content-core'); ?>
                                </th>
                                <td>
                                    <fieldset>
                                        <label><input type="checkbox" name="cc_redirect[exclusions][admin]" value="1" <?php
                                        checked($red_settings['exclusions']['admin']); ?>>
                                            <?php _e('Admin Area', 'content-core'); ?>
                                        </label><br>
                                        <label><input type="checkbox" name="cc_redirect[exclusions][ajax]" value="1" <?php
                                        checked($red_settings['exclusions']['ajax']); ?>>
                                            <?php _e('AJAX Requests', 'content-core'); ?>
                                        </label><br>
                                        <label><input type="checkbox" name="cc_redirect[exclusions][rest]" value="1" <?php
                                        checked($red_settings['exclusions']['rest']); ?>>
                                            <?php _e('REST API', 'content-core'); ?>
                                        </label><br>
                                        <label><input type="checkbox" name="cc_redirect[exclusions][cron]" value="1" <?php
                                        checked($red_settings['exclusions']['cron']); ?>>
                                            <?php _e('WP Cron', 'content-core'); ?>
                                        </label><br>
                                        <label><input type="checkbox" name="cc_redirect[exclusions][cli]" value="1" <?php
                                        checked($red_settings['exclusions']['cli']); ?>>
                                            <?php _e('WP CLI', 'content-core'); ?>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ═══ Admin Bar Site Link ═══ -->
                    <div class="cc-card" style="margin-top: 24px;">
                        <h2 style="margin-top: 0;">
                            <?php _e('Admin Bar Site Link', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Override the site title link in the admin bar (the site name next to the home icon).', 'content-core'); ?>
                        </p>

                        <?php
                        $ablink_defaults = [
                            'enabled' => false,
                            'url' => '',
                            'new_tab' => false,
                        ];
                        $saved_ablink = get_option(self::ADMIN_BAR_KEY, []);
                        $ablink_settings = array_merge($ablink_defaults, is_array($saved_ablink) ? $saved_ablink : []);
                        ?>

                        <table class="form-table" style="margin-top: 16px;">
                            <tr>
                                <th scope="row"><?php _e('Enable Custom Site Link', 'content-core'); ?></th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar_link[enabled]" value="0">
                                        <input type="checkbox" name="cc_admin_bar_link[enabled]" value="1"
                                            id="cc-ablink-enabled" <?php checked($ablink_settings['enabled']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('When disabled, WordPress default behaviour is preserved.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Target URL', 'content-core'); ?></th>
                                <td>
                                    <input type="text" name="cc_admin_bar_link[url]" id="cc-ablink-url"
                                        value="<?php echo esc_attr($ablink_settings['url']); ?>" class="regular-text"
                                        placeholder="https://example.com or /path">
                                    <p class="description">
                                        <?php _e('Absolute URL or relative path. Stored exactly as entered.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Open in New Tab', 'content-core'); ?></th>
                                <td>
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar_link[new_tab]" value="0">
                                        <input type="checkbox" name="cc_admin_bar_link[new_tab]" value="1" <?php checked($ablink_settings['new_tab']); ?>>
                                        <span class="cc-toggle-slider"></span>
                                    </label>
                                    <p class="description">
                                        <?php _e('Adds target="_blank" and rel="noopener" to the site title link.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- End #cc-tab-redirect -->

                <div id="cc-tab-seo" class="cc-tab-content">
                    <div class="cc-card">
                        <h2 style="margin-top: 0;">
                            <?php _e('SEO Settings', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Configure global SEO defaults for your site.', 'content-core'); ?>
                        </p>

                        <?php
                        $seo_defaults = [
                            'site_title' => '',
                            'default_description' => '',
                            'default_og_image_id' => null,
                        ];
                        $saved_seo = get_option(self::SEO_KEY, []);
                        $seo_settings = array_merge($seo_defaults, is_array($saved_seo) ? $saved_seo : []);
                        ?>

                        <table class="form-table" style="margin-top: 20px;">
                            <tr>
                                <th scope="row">
                                    <?php _e('Site Title', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="text" name="cc_seo[site_title]"
                                        value="<?php echo esc_attr($seo_settings['site_title']); ?>" class="regular-text">
                                    <p class="description">
                                        <?php _e('The default title for your site.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Default Meta Description', 'content-core'); ?>
                                </th>
                                <td>
                                    <textarea name="cc_seo[default_description]" rows="4"
                                        class="large-text"><?php echo esc_textarea($seo_settings['default_description']); ?></textarea>
                                    <p class="description">
                                        <?php _e('The default meta description if a specific page does not have one.', 'content-core'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php _e('Default OG Image', 'content-core'); ?>
                                </th>
                                <td>
                                    <input type="hidden" name="cc_seo[default_og_image_id]" id="cc-seo-image-id"
                                        value="<?php echo esc_attr($seo_settings['default_og_image_id']); ?>">

                                    <div id="cc-seo-image-preview"
                                        style="margin-bottom: 10px; <?php echo empty($seo_settings['default_og_image_id']) ? 'display: none;' : ''; ?>">
                                        <?php
                                        if (!empty($seo_settings['default_og_image_id'])) {
                                            echo wp_get_attachment_image($seo_settings['default_og_image_id'], 'thumbnail', false, ['style' => 'max-width: 150px; height: auto; border: 1px solid #ddd; padding: 3px; border-radius: 4px;']);
                                        }
                                        ?>
                                    </div>

                                    <button type="button" class="button" id="cc-seo-image-button">
                                        <?php _e('Select Image', 'content-core'); ?>
                                    </button>
                                    <button type="button" class="button button-link-delete" id="cc-seo-image-remove"
                                        style="<?php echo empty($seo_settings['default_og_image_id']) ? 'display: none;' : ''; ?>">
                                        <?php _e('Remove Image', 'content-core'); ?>
                                    </button>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- End #cc-tab-seo -->
                <div id="cc-tab-cookie" class="cc-tab-content">
                    <?php
                    $cookie_defaults = [
                        'enabled' => false,
                        'policyUrl' => '',
                        'bannerTitle' => __('Cookie Consent', 'content-core'),
                        'bannerText' => __('We use cookies to improve experience.', 'content-core'),
                        'labels' => [
                            'acceptAll' => __('Accept All', 'content-core'),
                            'rejectAll' => __('Reject All', 'content-core'),
                            'save' => __('Save Settings', 'content-core'),
                            'settings' => __('Preferences', 'content-core'),
                        ],
                        'categories' => [
                            'analytics' => false,
                            'marketing' => false,
                            'preferences' => false,
                        ],
                        'integrations' => [
                            'ga4MeasurementId' => '',
                            'gtmContainerId' => '',
                            'metaPixelId' => '',
                        ],
                        'behavior' => [
                            'regionMode' => 'eu_only',
                            'storage' => 'localStorage',
                            'ttlDays' => 365,
                        ]
                    ];
                    $cookie_settings = array_replace_recursive($cookie_defaults, get_option(self::COOKIE_KEY, []));
                    ?>
                </div> <!-- End #cc-tab-cookies -->

                <div id="cc-tab-site-options" class="cc-tab-content">
                    <?php
                    $plugin = \ContentCore\Plugin::get_instance();
                    $site_mod = $plugin->get_module('site_options');
                    $schema = $site_mod->get_schema();
                    $ml = $plugin->get_module('multilingual');
                    $languages = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml->get_settings()['languages'] : [];
                    ?>
                    <div class="cc-card">
                        <div
                            style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                            <div>
                                <h2 style="margin-top: 0;">
                                    <?php _e('Site Options Schema', 'content-core'); ?>
                                </h2>
                                <p style="color: #646970;">
                                    <?php _e('Define groups and fields for global business information. These fields will appear on the Site Options page.', 'content-core'); ?>
                                </p>
                            </div>
                            <button type="submit" name="cc_reset_site_options_schema" class="button button-secondary"
                                onclick="return confirm('<?php echo esc_attr__('Reset Site Options schema to defaults? Your values will be preserved.', 'content-core'); ?>');">
                                <?php _e('Reset to Default Template', 'content-core'); ?>
                            </button>
                        </div>

                        <div id="cc-schema-editor" style="margin-top: 24px;">
                            <?php foreach ($schema as $section_id => $section): ?>
                                <div class="cc-schema-section cc-card"
                                    style="background: #f8f9fa; margin-bottom: 20px; border: 1px solid #dcdcde;"
                                    data-id="<?php echo esc_attr($section_id); ?>">
                                    <div
                                        style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #dcdcde; padding-bottom: 15px;">
                                        <span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab;"></span>
                                        <div style="flex-grow: 1;">
                                            <input type="text"
                                                name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][title]"
                                                value="<?php echo esc_attr($section['title']); ?>" class="large-text"
                                                style="font-weight: 600;"
                                                placeholder="<?php _e('Section Title', 'content-core'); ?>">
                                        </div>
                                        <button type="button" class="button button-link-delete cc-remove-section"><span
                                                class="dashicons dashicons-no-alt"></span></button>
                                    </div>

                                    <div class="cc-schema-fields" style="margin-left: 30px;">
                                        <?php foreach ($section['fields'] as $field_id => $field): ?>
                                            <div class="cc-schema-field"
                                                style="background: #fff; border: 1px solid #dcdcde; padding: 15px; border-radius: 4px; margin-bottom: 10px;"
                                                data-id="<?php echo esc_attr($field_id); ?>">
                                                <div style="display: flex; gap: 15px; align-items: start;">
                                                    <span class="dashicons dashicons-menu"
                                                        style="color: #a0a5aa; cursor: grab; margin-top: 8px;"></span>
                                                    <div style="flex-grow: 1;">
                                                        <div
                                                            style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                                            <div>
                                                                <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                                    <?php _e('Stable Key', 'content-core'); ?>
                                                                </label>
                                                                <input type="text" value="<?php echo esc_attr($field_id); ?>"
                                                                    class="regular-text" style="width: 100%; font-family: monospace;"
                                                                    readonly disabled>
                                                            </div>
                                                            <div>
                                                                <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                                    <?php _e('Type', 'content-core'); ?>
                                                                </label>
                                                                <select
                                                                    name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][type]"
                                                                    style="width: 100%;">
                                                                    <option value="text" <?php selected($field['type'], 'text'); ?>>Text
                                                                    </option>
                                                                    <option value="email" <?php selected($field['type'], 'email'); ?>>
                                                                        Email
                                                                    </option>
                                                                    <option value="url" <?php selected($field['type'], 'url'); ?>>URL
                                                                    </option>
                                                                    <option value="textarea" <?php selected(
                                                                        $field['type'],
                                                                        'textarea'
                                                                    ); ?>>Textarea</option>
                                                                    <option value="image" <?php selected($field['type'], 'image'); ?>>
                                                                        Image/Logo</option>
                                                                </select>
                                                            </div>
                                                            <div
                                                                style="display: flex; gap: 15px; align-items: center; padding-top: 20px;">
                                                                <label><input type="checkbox"
                                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_visible]"
                                                                        value="1" <?php checked(!empty($field['client_visible'])); ?>>
                                                                    <?php _e('Visible', 'content-core'); ?>
                                                                </label>
                                                                <label><input type="checkbox"
                                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_editable]"
                                                                        value="1" <?php checked(!empty($field['client_editable'])); ?>>
                                                                    <?php _e('Editable', 'content-core'); ?>
                                                                </label>
                                                            </div>
                                                        </div>

                                                        <div
                                                            style="display: grid; grid-template-columns: repeat(<?php echo count($languages) ?: 1; ?>, 1fr); gap: 10px;">
                                                            <?php foreach ($languages as $lang):
                                                                $label_val = is_array($field['label']) ? ($field['label'][$lang['code']] ?? '') : ($lang['code'] === 'de' ? $field['label'] : '');
                                                                ?>
                                                                <div>
                                                                    <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                                        <?php echo esc_html($lang['label']); ?>
                                                                        <?php _e('Label', 'content-core'); ?>
                                                                    </label>
                                                                    <input type="text"
                                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][label][<?php echo esc_attr($lang['code']); ?>]"
                                                                        value="<?php echo esc_attr($label_val); ?>" style="width: 100%;">
                                                                </div>
                                                                <?php
                                                            endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="button button-link-delete cc-remove-field"
                                                        style="margin-top: 5px;"><span
                                                            class="dashicons dashicons-no-alt"></span></button>
                                                </div>
                                            </div>
                                            <?php
                                        endforeach; ?>
                                        <button type="button" class="button button-secondary cc-add-field"
                                            data-section="<?php echo esc_attr($section_id); ?>">
                                            <?php _e('+ Add Field', 'content-core'); ?>
                                        </button>
                                    </div>
                                </div>
                                <?php
                            endforeach; ?>
                            <button type="button" class="button button-secondary cc-add-section">
                                <?php _e('+ Add Group', 'content-core'); ?>
                            </button>
                        </div>
                    </div>
                </div> <!-- End #cc-tab-site-options -->

                <div style="display: flex; gap: 12px; align-items: center; margin-top: 24px;">
                    <?php submit_button(__('Save Settings', 'content-core'), 'primary', 'submit', false); ?>
                    <button type="submit" name="cc_reset_menu" class="button button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Reset all settings to defaults?', 'content-core'); ?>');">
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
                    <code><?php echo esc_url(admin_url('admin.php?page=cc-settings')); ?></code>
                </p>
            </div>
        </div>

        <style>
            .nav-tab-wrapper.cc-settings-tabs {
                border-bottom: 1px solid #c3c4c7;
                margin-top: 20px;
            }

            .cc-tab-content {
                display: block;
                /* Default for non-JS */
            }

            body.js .cc-tab-content {
                display: none;
            }

            body.js .cc-tab-content.active {
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
                var $table = $('#cc-ml-languages-table tbody');
                var template = $('#cc-ml-row-template').html();
                var catalog = <?php echo json_encode($catalog); ?>;

                function updateSelects() {
                    var $defaultSelect = $('#cc-default-lang-select');
                    var $fallbackSelect = $('#cc-fallback-lang-select');
                    var currentDefault = $defaultSelect.val();
                    var currentFallback = $fallbackSelect.val();

                    $defaultSelect.empty();
                    $fallbackSelect.empty();

                    $table.find('tr').each(function () {
                        var code = $(this).find('.language-code').val();
                        var label = $(this).find('.language-label').val() || code;
                        if (code) {
                            $defaultSelect.append($('<option>', { value: code, text: label + ' (' + code + ')' }));
                            $fallbackSelect.append($('<option>', { value: code, text: label + ' (' + code + ')' }));
                        }
                    });

                    $defaultSelect.val(currentDefault);
                    $fallbackSelect.val(currentFallback);
                }

                $('.add-language-row').on('click', function () {
                    var $selector = $('#cc-ml-add-selector');
                    var code = $selector.val();
                    if (!code) return;

                    if ($table.find('tr[data-code="' + code + '"]').length) {
                        alert('<?php echo esc_js(__('Language already added.', 'content - core')); ?>');
                        return;
                    }

                    var index = $table.find('tr').length;
                    var langData = catalog[code];
                    var row = template
                        .replace(/{index}/g, index)
                        .replace(/{code}/g, code)
                        .replace(/{label}/g, langData.label)
                        .replace(/{flag}/g, langData.flag);
                    $table.append(row);
                    $selector.val('');
                    updateSelects();
                });

                $table.on('click', '.remove-row', function () {
                    if (confirm('<?php echo esc_js(__('Remove this language ? ', 'content - core')); ?>')) {
                        $(this).closest('tr').remove();
                        $table.find('tr').each(function (idx) {
                            $(this).attr('data-index', idx);
                            $(this).find('[name]').each(function () {
                                this.name = this.name.replace(/cc_languages\[languages\]\[\d+\]/, 'cc_languages[languages][' + idx + ']');
                            });
                        });
                        updateSelects();
                    }
                });

                $('#cc-ml-fallback-toggle').on('change', function () {
                    $('#cc-fallback-lang-select').prop('disabled', !$(this).is(':checked'));
                });

                $('#cc-ml-permalink-toggle').on('change', function () {
                    $('#cc-ml-permalink-config').toggle($(this).is(':checked'));
                });

                $('#cc-ml-tax-toggle').on('change', function () {
                    $('#cc-ml-tax-config').toggle($(this).is(':checked'));
                });

                var mediaFrame;
                $table.on('click', '.select-custom-flag', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var $input = $row.find('.flag-id-input');
                    var $removeBtn = $row.find('.remove-custom-flag');
                    mediaFrame = wp.media({
                        title: '<?php echo esc_js(__('Select Flag Image', 'content - core')); ?>',
                        button: { text: '<?php echo esc_js(__('Use this image', 'content - core')); ?>' },
                        multiple: false
                    });

                    mediaFrame.on('select', function () {
                        var attachment = mediaFrame.state().get('selection').first().toJSON();
                        $input.val(attachment.id);
                        $removeBtn.show();
                        var flagCol = $row.find('.flag-col');
                        flagCol.html('<img src="' + attachment.url + '" style="width:18px; height:12px; object-fit:cover; vertical-align:middle; border-radius:1px; margin-right:4px;" />');
                    });

                    mediaFrame.open();
                });

                $table.on('click', '.remove-custom-flag', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var code = $row.data('code');
                    $row.find('.flag-id-input').val('0');
                    $btn.hide();
                    if (catalog[code]) {
                        $row.find('.flag-col').html(catalog[code].flag);
                    }
                });

                // ── Tabs Logic ──
                var $tabs = $('.cc-settings-tabs');
                if ($tabs.length) {
                    $tabs.show();
                    $('body').addClass('js');

                    var pageSlug = new URLSearchParams(window.location.search).get('page');
                    var storageKey = 'cc_active_tab_' + pageSlug;

                    // Default tabs for each page
                    var defaultTab = (pageSlug === 'cc-site-settings') ? 'multilingual' : 'menu';
                    var activeTab = localStorage.getItem(storageKey) || defaultTab;

                    // Sanity check to ensure the tab exists on the current page
                    if ($tabs.find('[data-tab="' + activeTab + '"]').length === 0) {
                        activeTab = defaultTab;
                    }

                    switchTab(activeTab);

                    $tabs.on('click', 'a', function (e) {
                        e.preventDefault();
                        var tab = $(this).data('tab');
                        switchTab(tab);
                        localStorage.setItem(storageKey, tab);
                    });

                    function switchTab(tabId) {
                        $tabs.find('.nav-tab').removeClass('nav-tab-active');
                        $tabs.find('[data-tab="' + tabId + '"]').addClass('nav-tab-active');
                        $('.cc-tab-content').removeClass('active');

                        if (tabId === 'menu') {
                            $('#cc-tab-menu').addClass('active');
                        } else {
                            $('#cc-tab-' + tabId).addClass('active');
                        }
                    }
                }

                // ── SEO Media Uploader ──
                var seoMediaFrame;
                $('#cc-seo-image-button').on('click', function (e) {
                    e.preventDefault();
                    if (seoMediaFrame) {
                        seoMediaFrame.open();
                        return;
                    }
                    seoMediaFrame = wp.media({
                        title: '<?php echo esc_js(__('Select Default OG Image', 'content - core')); ?>',
                        button: { text: '<?php echo esc_js(__('Use this image', 'content - core')); ?>' },
                        multiple: false
                    });
                    seoMediaFrame.on('select', function () {
                        var attachment = seoMediaFrame.state().get('selection').first().toJSON();
                        $('#cc-seo-image-id').val(attachment.id);
                        var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                        $('#cc-seo-image-preview').html('<img src="' + imgUrl + '" style="max-width: 150px; height: auto; border: 1px solid #ddd; padding: 3px; border-radius: 4px;" />').show();
                        $('#cc-seo-image-remove').show();
                    });
                    seoMediaFrame.open();
                });

                $('#cc-seo-image-remove').on('click', function (e) {
                    e.preventDefault();
                    $('#cc-seo-image-id').val('');
                    $('#cc-seo-image-preview').hide().html('');
                    $(this).hide();
                });

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

                $('.cc-visibility-sortable tbody').sortable({
                    handle: '.cc-drag-handle',
                    items: 'tr[data-slug]',
                    placeholder: 'ui-sortable-placeholder', update: function (event, ui) {
                        serializeVisibilityOrder();
                    }
                });

                serializeVisibilityOrder();

                // ── Site Options Schema Editor ──
                var $schemaEditor = $('#cc-schema-editor');

                function generateId() {
                    return 'cc_' + Math.random().toString(36).substr(2, 9);
                }

                $schemaEditor.on('click', '.cc-add-section', function () {
                    var sectionId = generateId();
                    var titleLabel = '<?php _e('Section Title', 'content - core'); ?>';
                    var removeLabel = '<?php _e('Remove Group', 'content - core'); ?>';
                    var addFieldLabel = '<?php _e(' + Add Field', 'content - core'); ?>';

                    var html = '<div class="cc-schema-section cc-card" style="background: #f8f9fa; margin-bottom: 20px; border: 1px solid #dcdcde;" data-id="' + sectionId + '">' +
                        '<div style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #dcdcde; padding-bottom: 15px;">' +
                        '<span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab;"></span>' +
                        '<div style="flex-grow: 1;">' +
                        '<input type="text" name="cc_site_options_schema[' + sectionId + '][title]" value="" class="large-text" style="font-weight: 600;" placeholder="' + titleLabel + '">' +
                        '</div>' +
                        '<button type="button" class="button button-link-delete cc-remove-section"><span class="dashicons dashicons-no-alt"></span></button>' +
                        '</div>' +
                        '<div class="cc-schema-fields" style="margin-left: 30px;">' +
                        '<button type="button" class="button button-secondary cc-add-field" data-section="' + sectionId + '">' + addFieldLabel + '</button>' +
                        '</div>' +
                        '</div>';

                    $(this).before(html);
                });

                $schemaEditor.on('click', '.cc-remove-section', function () {
                    if (confirm('<?php echo esc_js(__('Remove this entire section and all its fields ? ', 'content - core')); ?>')) {
                        $(this).closest('.cc-schema-section').remove();
                    }
                });

                $schemaEditor.on('click', '.cc-add-field', function () {
                    var sectionId = $(this).data('section');
                    var fieldId = generateId();
                    var languages = <?php echo json_encode($languages); ?>;
                    var addBtn = $(this);

                    var html = '<div class="cc-schema-field" style="background: #fff; border: 1px solid #dcdcde; padding: 15px; border-radius: 4px; margin-bottom: 10px;" data-id="' + fieldId + '">' +
                        '<div style="display: flex; gap: 15px; align-items: start;">' +
                        '<span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab; margin-top: 8px;"></span>' +
                        '<div style="flex-grow: 1;">' +
                        '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">' +
                        '<div>' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 3px;"><?php _e('Stable Key', 'content - core'); ?></label>' +
                        '<input type="text" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][key_placeholder]" value="' + fieldId + '" class="regular-text" style="width: 100%; font-family: monospace;" readonly disabled>' +
                        '<input type="hidden" name="dummy" value="just so we have the key implied by the name structure">' +
                        '</div>' +
                        '<div>' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 3px;"><?php _e('Type', 'content - core'); ?></label>' +
                        '<select name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][type]" style="width: 100%;">' +
                        '<option value="text">Text</option>' +
                        '<option value="email">Email</option>' +
                        '<option value="url">URL</option>' +
                        '<option value="textarea">Textarea</option>' +
                        '<option value="image">Image/Logo</option>' +
                        '</select>' +
                        '</div>' +
                        '<div style="display: flex; gap: 15px; align-items: center; padding-top: 20px;">' +
                        '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_visible]" value="1" checked> <?php _e('Visible', 'content - core'); ?></label>' +
                        '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_editable]" value="1" checked> <?php _e('Editable', 'content - core'); ?></label>' +
                        '</div>' +
                        '</div>' +
                        '<div style="display: grid; grid-template-columns: repeat(' + (languages.length || 1) + ', 1fr); gap: 10px;">';

                    languages.forEach(function (lang) {
                        html += '<div>' +
                            '<label style="display: block; font-size: 11px; margin-bottom: 3px;">' + lang.label + ' <?php _e('Label', 'content - core'); ?></label>' +
                            '<input type="text" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][label][' + lang.code + ']" value="" style="width: 100%;">' +
                            '</div>';
                    });

                    html += '</div></div>' +
                        '<button type="button" class="button button-link-delete cc-remove-field" style="margin-top: 5px;"><span class="dashicons dashicons-no-alt"></span></button>' +
                        '</div></div>';

                    addBtn.before(html);
                });

                $schemaEditor.on('click', '.cc-remove-field', function () {
                    if (confirm('<?php echo esc_js(__('Remove this field ? ', 'content - core')); ?>')) {
                        $(this).closest('.cc-schema-field').remove();
                    }
                });

                // ── Schema Reordering ──
                $schemaEditor.sortable({
                    items: '.cc-schema-section',
                    handle: '.dashicons-menu',
                    placeholder: 'ui-sortable-placeholder',
                    axis: 'y'
                });

                $schemaEditor.on('mouseenter', '.cc-schema-fields', function () {
                    if (!$(this).data('sortable-init')) {
                        $(this).sortable({
                            items: '.cc-schema-field',
                            handle: '.dashicons-menu',
                            placeholder: 'ui-sortable-placeholder',
                            axis: 'y'
                        });
                        $(this).data('sortable-init', true);
                    }
                });
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