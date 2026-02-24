<?php
namespace ContentCore\Modules\Settings;

use ContentCore\Modules\ModuleInterface;

class SettingsModule implements ModuleInterface
{
    const OPTION_KEY = 'content_core_admin_menu_settings';
    const ORDER_KEY = 'content_core_admin_menu_order';
    const MEDIA_KEY = 'cc_media_settings';
    const REDIRECT_KEY = 'cc_redirect_settings';

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
        'users.php',
        'tools.php',
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
        add_action('admin_menu', [$this, 'apply_menu_visibility'], 998);
        add_action('admin_menu', [$this, 'apply_menu_order'], 999);
        add_action('admin_init', [$this, 'handle_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
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

        // Safe Mode: Only active on the actual settings page to allow recovery
        return $pagenow === 'admin.php' && $page === 'cc-settings';
    }

    public function apply_menu_visibility(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Settings Page Safe Mode: Never hide menus while on the CC settings screen
        if ($this->is_cc_settings_screen()) {
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

    /**
     * Helper to merge new values into an existing option array.
     * Starts from the existing stored option and only overwrites sub-keys 
     * that are present in the current payload.
     */
    private function update_merged_option(string $option_key, array $new_values): void
    {
        $existing = get_option($option_key, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        // We only want to merge keys that are actually provided in this payload.
        // array_replace_recursive is good, but we must ensure we don't pass empty arrays for missing POST sections.
        $merged = array_replace_recursive($existing, $new_values);
        update_option($option_key, $merged);
    }

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

        // Reset
        if (isset($_POST['cc_reset_menu'])) {
            if (!current_user_can('manage_options')) {
                return;
            }
            delete_option(self::OPTION_KEY);
            delete_option(self::ORDER_KEY);
            add_settings_error('cc_settings', 'reset', __('Menu visibility and ordering have been reset to defaults.', 'content-core'), 'updated');
            return;
        }

        // ── Visibility ──
        // Only trigger update if at least one visibility section is present in POST
        if (isset($_POST['cc_menu_admin']) || isset($_POST['cc_menu_client'])) {
            $visibility_payload = [];

            if (isset($_POST['cc_menu_admin']) && is_array($_POST['cc_menu_admin'])) {
                $visibility_payload['admin'] = array_map(function ($v) {
                    return (bool)$v;
                }, $_POST['cc_menu_admin']);

                // Safety locks for Admin
                $visibility_payload['admin']['options-general.php'] = true;
                $visibility_payload['admin']['plugins.php'] = true;
                $visibility_payload['admin']['content-core'] = true;
            }

            if (isset($_POST['cc_menu_client']) && is_array($_POST['cc_menu_client'])) {
                $visibility_payload['client'] = array_map(function ($v) {
                    return (bool)$v;
                }, $_POST['cc_menu_client']);

                // Safety locks for Client
                $visibility_payload['client']['content-core'] = true;
            }

            if (!empty($visibility_payload)) {
                $this->update_merged_option(self::OPTION_KEY, $visibility_payload);
            }
        }

        // ── Redirection ──
        if (isset($_POST['cc_redirect'])) {
            $redirect_post = (array)$_POST['cc_redirect'];
            $redirect_payload = [
                'enabled' => !empty($redirect_post['enabled']),
                'from_path' => $this->sanitize_redirect_path($redirect_post['from_path'] ?? '/'),
                'target' => $this->sanitize_redirect_path($redirect_post['target'] ?? '/wp-admin'),
                'status_code' => in_array($redirect_post['status_code'] ?? '302', ['301', '302']) ? $redirect_post['status_code'] : '302',
                'pass_query' => !empty($redirect_post['pass_query']),
                'exclusions' => [
                    'admin' => !empty($redirect_post['exclusions']['admin']),
                    'ajax' => !empty($redirect_post['exclusions']['ajax']),
                    'rest' => !empty($redirect_post['exclusions']['rest']),
                    'cron' => !empty($redirect_post['exclusions']['cron']),
                    'cli' => !empty($redirect_post['exclusions']['cli']),
                ]
            ];
            update_option(self::REDIRECT_KEY, $redirect_payload);
        }

        // ── Ordering ──
        // Only trigger update if order strings are provided and not empty
        $admin_order_raw = $_POST['cc_core_order_admin'] ?? '';
        $client_order_raw = $_POST['cc_core_order_client'] ?? '';

        if (!empty($admin_order_raw) || !empty($client_order_raw)) {
            $order_payload = [];

            if (!empty($admin_order_raw)) {
                $admin_order = $this->parse_order_input($admin_order_raw);
                if (!empty($admin_order)) {
                    $order_payload['admin'] = $admin_order;
                }
            }

            if (!empty($client_order_raw)) {
                $client_order = $this->parse_order_input($client_order_raw);
                if (!empty($client_order)) {
                    $order_payload['client'] = $client_order;
                }
            }

            if (!empty($order_payload)) {
                $this->update_merged_option(self::ORDER_KEY, $order_payload);
            }
        }

        // ── Multilingual Settings ──
        if (isset($_POST['cc_languages'])) {
            $raw_langs = $_POST['cc_languages']['languages'] ?? [];
            $structured_langs = [];
            $seen_codes = [];

            foreach ($raw_langs as $lang) {
                if (empty($lang['code']))
                    continue;

                $code = strtolower(sanitize_text_field($lang['code']));
                if (in_array($code, $seen_codes))
                    continue;

                $structured_langs[] = [
                    'code' => $code,
                    'label' => sanitize_text_field($lang['label'] ?: strtoupper($code)),
                    'flag_id' => intval($lang['flag_id'] ?? 0),
                ];
                $seen_codes[] = $code;
            }

            $active_codes = array_column($structured_langs, 'code');
            $submitted_default = sanitize_text_field($_POST['cc_languages']['default_lang'] ?? 'de');
            $submitted_fallback = sanitize_text_field($_POST['cc_languages']['fallback_lang'] ?? 'de');

            // ── Validation: Block deleting default language or all languages ──
            $current_settings = get_option('cc_languages_settings', []);
            $old_default = $current_settings['default_lang'] ?? 'de';

            if (empty($active_codes)) {
                add_settings_error('cc_settings', 'ml_empty_error', __('You must have at least one active language.', 'content-core'), 'error');
                return; // Abort save for ML
            }

            // If the user tries to remove the default language, block it unless they changed the default first
            if (!in_array($old_default, $active_codes, true)) {
                add_settings_error('cc_settings', 'ml_default_delete_error', sprintf(__('The default language (%s) cannot be deleted. Please change the default language first.', 'content-core'), strtoupper($old_default)), 'error');
                return;
            }

            // If the user changed the default language choice to something that IS in active_codes, use that.
            if (!in_array($submitted_default, $active_codes, true)) {
                $submitted_default = $active_codes[0];
            }

            if (!empty($_POST['cc_languages']['fallback_enabled']) && !in_array($submitted_fallback, $active_codes, true)) {
                $submitted_fallback = $submitted_default;
                add_settings_error('cc_settings', 'ml_fallback_warning', __('Fallback language was removed. It has been reset to the Default Language.', 'content-core'), 'warning');
            }

            $ml_settings = [
                'enabled' => !empty($_POST['cc_languages']['enabled']),
                'default_lang' => $submitted_default,
                'active_langs' => $active_codes,
                'languages' => $structured_langs,
                'fallback_enabled' => !empty($_POST['cc_languages']['fallback_enabled']),
                'fallback_lang' => $submitted_fallback,
                'permalink_enabled' => !empty($_POST['cc_languages']['permalink_enabled']),
                'permalink_bases' => $_POST['cc_languages']['permalink_bases'] ?? [],
                'enable_rest_seo' => !empty($_POST['cc_languages']['enable_rest_seo']),
                'enable_headless_fallback' => !empty($_POST['cc_languages']['enable_headless_fallback']),
                'enable_localized_taxonomies' => !empty($_POST['cc_languages']['enable_localized_taxonomies']),
                'enable_sitemap_endpoint' => !empty($_POST['cc_languages']['enable_sitemap_endpoint']),
                'taxonomy_bases' => $_POST['cc_languages']['taxonomy_bases'] ?? [],
            ];
            $this->update_merged_option('cc_languages_settings', $ml_settings);

            // Flush rewrite rules if permalinks were toggled or bases changed
            if ($ml_settings['permalink_enabled']) {
                set_transient('cc_flush_rewrites', 1, 3600);
            }
            else {
                flush_rewrite_rules();
            }
        }

        // ── Media Settings ──
        if (isset($_POST['cc_media'])) {
            $media_settings = [
                'enabled' => !empty($_POST['cc_media']['enabled']),
                'max_width_px' => intval($_POST['cc_media']['max_width_px'] ?: 2000),
                'output_format' => 'webp',
                'quality' => intval($_POST['cc_media']['quality'] ?: 70),
                'png_mode' => sanitize_text_field($_POST['cc_media']['png_mode'] ?: 'lossless'),
                'delete_original' => !empty($_POST['cc_media']['delete_original']),
            ];
            $this->update_merged_option(self::MEDIA_KEY, $media_settings);
        }

        add_settings_error('cc_settings', 'saved', __('Settings saved.', 'content-core'), 'updated');
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
            }
            elseif (in_array($slug, $appearance_slugs, true)) {
                $appearance[$slug] = $title;
            }
            elseif (in_array($slug, $system_slugs, true)) {
                $system[$slug] = $title;
            }
            else {
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
            <?php _e('Content Core Settings', 'content-core'); ?>
        </h1>
        <p style="color: #646970; margin-top: 4px;">
            <?php _e('Control admin sidebar visibility and ordering per role.', 'content-core'); ?>
        </p>
    </div>

    <?php settings_errors('cc_settings'); ?>

    <h2 class="nav-tab-wrapper cc-settings-tabs" style="margin-bottom: 20px; display: none;">
        <a href="#menu" class="nav-tab nav-tab-active" data-tab="menu">
            <?php _e('Menu', 'content-core'); ?>
        </a>
        <a href="#media" class="nav-tab" data-tab="media">
            <?php _e('Media', 'content-core'); ?>
        </a>
        <a href="#multilingual" class="nav-tab" data-tab="multilingual">
            <?php _e('Multilingual', 'content-core'); ?>
        </a>
        <a href="#redirect" class="nav-tab" data-tab="redirect">
            <?php _e('Redirect', 'content-core'); ?>
        </a>
    </h2>

    <form method="post">
        <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>

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
                }
                else {
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
                                        value="1" <?php checked($a_checked || $a_locked); ?>
                                    <?php if ($a_locked)
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
        $media_settings = array_merge($media_defaults, get_option(self::MEDIA_KEY, []));
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
        $red_settings = array_merge($red_defaults, get_option(self::REDIRECT_KEY, []));
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
        </div> <!-- End #cc-tab-redirect -->

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

            var activeTab = localStorage.getItem('cc_active_settings_tab') || 'menu';
            switchTab(activeTab);

            $tabs.on('click', 'a', function (e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                switchTab(tab);
                localStorage.setItem('cc_active_settings_tab', tab);
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
    });
</script>

<?php
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