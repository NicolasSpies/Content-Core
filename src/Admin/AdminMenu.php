<?php
namespace ContentCore\Admin;

/**
 * Class AdminMenu
 *
 * Thin orchestrator for the Content Core admin navigation.
 * Delegates menu registration, rendering, and maintenance to specialized services.
 */
class AdminMenu
{
    /**
     * Initialize the admin menu hooks
     */
    public function init(): void
    {
        // 1. Menu Registration & Redirects
        $menu_registry = new MenuRegistry($this);
        $menu_registry->init();

        // 2. Assets (Delegates to centralized Assets class registration)
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('admin_body_class', [$this, 'add_admin_theme_body_class']);
        add_action('load-upload.php', [$this, 'enforce_media_grid_view']);

        // 3. Maintenance Actions
        $maintenance = new MaintenanceService();
        $maintenance->init();

        // 4. Standard WordPress dashboard replacement for client roles
        $dashboard_customizer = new StandardDashboardCustomizer();
        $dashboard_customizer->init();

        // 5. UI Fragments
        add_action('admin_footer', [$this, 'maybe_render_footer_audit']);
        add_action('admin_footer', [$this, 'render_sidebar_branding']);
        add_action('admin_footer', [$this, 'render_sidebar_account']);
        add_filter('admin_footer_text', [$this, 'maybe_remove_footer_text'], 11);
        add_filter('update_footer', [$this, 'maybe_remove_footer_text'], 11);

        // 6. Error Log actions — delegated to ErrorLogScreen
        $logger = \ContentCore\Plugin::get_instance()->get_error_logger();
        if ($logger instanceof \ContentCore\Admin\ErrorLogger) {
            $error_log_screen = new \ContentCore\Admin\ErrorLogScreen($logger);
            $error_log_screen->init();
        }
    }

    /**
     * Enqueue modern assets using centralized registration
     */
    public function enqueue_admin_assets($hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if (!$this->is_unified_theme_scope($screen)) {
            return;
        }

        // Load unified admin style with a single stylesheet entry point.
        wp_enqueue_style('cc-admin-ui');
        $this->apply_branding_accent_tokens();

        wp_enqueue_script('cc-admin-js');
        wp_localize_script('cc-admin-js', 'ccAdmin', [
            'menuState' => get_user_meta(get_current_user_id(), 'cc_menu_state', true) ?: [],
            'darkMode' => get_user_meta(get_current_user_id(), 'cc_dark_mode', true) === '1',
            'restUrl' => esc_url_raw(rest_url('content-core/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'screenId' => $screen->id,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        ]);

        $plugin = \ContentCore\Plugin::get_instance();

        // Robust detection: Content Core pages, settings, or our specific post types/taxonomies.
        // Also include standard post lists (edit.php) if the post type supports CC multilingual.
        $is_cc = (strpos($screen->id, 'content-core') !== false
            || strpos($screen->id, 'cc_') !== false
            || (isset($screen->post_type) && strpos($screen->post_type, 'cc_') === 0)
            || ($screen->base === 'edit' && !empty($screen->post_type) && post_type_supports($screen->post_type, 'cc-multilingual')));

        if (!$is_cc) {
            return;
        }

        // Multilingual Terms Manager assets
        if (strpos($screen->id, 'cc-manage-terms') !== false) {
            $ml_module = $plugin->get_module('multilingual');
            if ($ml_module instanceof \ContentCore\Modules\Multilingual\MultilingualModule) {
                $terms_admin = new \ContentCore\Modules\Multilingual\Admin\TermsManagerAdmin($ml_module);
                $terms_admin->enqueue_assets($hook);
            }
        }

        wp_enqueue_script('jquery-ui-sortable');
    }

    /**
     * Force Media Library into grid mode and prevent list mode access.
     */
    public function enforce_media_grid_view(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        update_user_option(get_current_user_id(), 'media_library_mode', 'grid', true);

        $requested_mode = isset($_GET['mode']) ? sanitize_key((string) wp_unslash($_GET['mode'])) : '';
        if ($requested_mode === 'grid') {
            return;
        }

        $target = add_query_arg('mode', 'grid', remove_query_arg('mode'));
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Render the main Dashboard
     */
    public function render_main_dashboard(): void
    {
        $renderer = new DashboardRenderer();
        $renderer->render();
    }

    /**
     * Render the Diagnostics page
     */
    public function render_diagnostics_page(): void
    {
        $renderer = new DiagnosticsRenderer();
        $renderer->render();
    }

    /**
     * Render the REST API Info page
     */
    public function render_api_page(): void
    {
        $renderer = new ApiReferenceRenderer();
        $renderer->render();
    }

    /**
     * Render the Manage Terms page
     */
    public function render_manage_terms_page(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml_module = $plugin->get_module('multilingual');
        if (!($ml_module instanceof \ContentCore\Modules\Multilingual\MultilingualModule)) {
            echo '<div class="wrap"><p>' . esc_html__('Multilingual module not active.', 'content-core') . '</p></div>';
            return;
        }
        $screen = new \ContentCore\Modules\Multilingual\Admin\TermsManagerAdmin($ml_module);
        $screen->render_page();
    }

    public function render_structure_root_page(): void
    {
        $links = [
            [
                'label' => __('Post Types', 'content-core'),
                'desc' => __('Manage custom post type definitions.', 'content-core'),
                'url' => admin_url('edit.php?post_type=cc_post_type_def'),
            ],
            [
                'label' => __('Taxonomies', 'content-core'),
                'desc' => __('Manage custom taxonomy definitions.', 'content-core'),
                'url' => admin_url('edit.php?post_type=cc_taxonomy_def'),
            ],
            [
                'label' => __('Field Groups', 'content-core'),
                'desc' => __('Manage custom field groups and assignment rules.', 'content-core'),
                'url' => admin_url('edit.php?post_type=cc_field_group'),
            ],
            [
                'label' => __('Manage Terms', 'content-core'),
                'desc' => __('Open multilingual term mapping and sync tools.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-manage-terms'),
            ],
        ];

        $this->render_root_landing(
            __('Structure', 'content-core'),
            __('Configure content architecture modules and open related structure screens.', 'content-core'),
            $links
        );
    }

    public function render_settings_root_page(): void
    {
        $links = [
            [
                'label' => __('Site Profile Fields', 'content-core'),
                'desc' => __('Configure global profile fields exposed across modules.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-site-profile-fields'),
            ],
            [
                'label' => __('Multilingual', 'content-core'),
                'desc' => __('Configure language setup and translation defaults.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-multilingual'),
            ],
            [
                'label' => __('Visibility', 'content-core'),
                'desc' => __('Control menu and admin surface visibility rules.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-visibility'),
            ],
            [
                'label' => __('Media', 'content-core'),
                'desc' => __('Configure site media and image defaults.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-media'),
            ],
            [
                'label' => __('Redirect', 'content-core'),
                'desc' => __('Manage redirect and target behavior.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-redirect'),
            ],
            [
                'label' => __('SEO', 'content-core'),
                'desc' => __('Adjust SEO defaults and metadata behavior.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-seo'),
            ],
            [
                'label' => __('Branding', 'content-core'),
                'desc' => __('Configure admin and site branding options.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-branding'),
            ],
            [
                'label' => __('Cookie Banner', 'content-core'),
                'desc' => __('Configure consent and cookie messaging.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-cookie-banner'),
            ],
        ];

        $this->render_root_landing(
            __('Settings', 'content-core'),
            __('Open consolidated settings modules from a single landing screen.', 'content-core'),
            $links
        );
    }

    public function render_system_root_page(): void
    {
        $links = [
            [
                'label' => __('Diagnostics', 'content-core'),
                'desc' => __('Inspect runtime health, cache status, and audits.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-diagnostics'),
            ],
            [
                'label' => __('REST API', 'content-core'),
                'desc' => __('View available Content Core REST API endpoints.', 'content-core'),
                'url' => admin_url('admin.php?page=cc-api-info'),
            ],
        ];

        $this->render_root_landing(
            __('System', 'content-core'),
            __('Access operational tools for diagnostics and API visibility.', 'content-core'),
            $links
        );
    }

    private function render_root_landing(string $title, string $description, array $links): void
    {
        ?>
        <div class="wrap content-core-admin cc-page">
            <div class="cc-header">
                <h1><?php echo esc_html($title); ?></h1>
                <p class="cc-header-desc"><?php echo esc_html($description); ?></p>
            </div>

            <div class="cc-card cc-root-landing">
                <div class="cc-card-body">
                    <div class="cc-root-landing-grid">
                        <?php foreach ($links as $link): ?>
                            <a class="cc-root-landing-link" href="<?php echo esc_url($link['url']); ?>">
                                <span class="cc-root-landing-link__title"><?php echo esc_html($link['label']); ?></span>
                                <span class="cc-root-landing-link__desc"><?php echo esc_html($link['desc']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Check if the current page is a Content Core page
     */
    private function is_cc_page(): bool
    {
        $screen = get_current_screen();
        return $screen && (strpos($screen->id, 'content-core') !== false || strpos($screen->id, 'cc_') !== false);
    }

    /**
     * Clear footer text on CC pages
     */
    public function maybe_remove_footer_text($text)
    {
        return $this->is_cc_page() ? '' : $text;
    }

    /**
     * Render the footer audit block if requested
     */
    public function maybe_render_footer_audit(): void
    {
        if (isset($_GET['cc_audit']) && current_user_can('manage_options')) {
            \ContentCore\Modules\Diagnostics\RuntimeAuditRenderer::render_footer();
        }
    }

    /**
     * Render account controls in the left sidebar footer.
     */
    public function render_sidebar_account(): void
    {
        $screen = get_current_screen();
        if (!$screen || !$this->is_unified_theme_scope($screen)) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return;
        }

        $profile_url = admin_url('profile.php');
        $logout_url = wp_logout_url(admin_url());
        $avatar_url = get_avatar_url($user->ID, ['size' => 44]);
        $display_name = (string) ($user->display_name ?: $user->user_login);
        $role_label = $this->resolve_primary_role_label($user);
        ?>
        <div id="cc-sidebar-account" class="cc-sidebar-account" aria-label="<?php esc_attr_e('Account', 'content-core'); ?>">
            <div class="cc-sidebar-account__top">
                <span class="cc-sidebar-account__darkmode-label"><?php esc_html_e('Dark Mode', 'content-core'); ?></span>
                <button type="button" class="cc-sidebar-account__switch"
                    aria-label="<?php esc_attr_e('Toggle dark mode', 'content-core'); ?>" aria-pressed="false"></button>
            </div>

            <div class="cc-sidebar-account__user">
                <a class="cc-sidebar-account__profile" href="<?php echo esc_url($profile_url); ?>">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="cc-sidebar-account__avatar">
                    <span class="cc-sidebar-account__meta">
                        <span class="cc-sidebar-account__name"><?php echo esc_html($display_name); ?></span>
                        <span class="cc-sidebar-account__role"><?php echo esc_html($role_label); ?></span>
                    </span>
                </a>
                <a class="cc-sidebar-account__logout" href="<?php echo esc_url($logout_url); ?>"
                    title="<?php esc_attr_e('Log Out', 'content-core'); ?>"
                    aria-label="<?php esc_attr_e('Log Out', 'content-core'); ?>">
                    <svg class="cc-sidebar-account__logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M10 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" />
                        <path d="M13 8l4 4-4 4" />
                        <path d="M17 12H9" />
                    </svg>
                </a>
            </div>
        </div>
        <script>
            (function () {
                var mount = function () {
                    var account = document.getElementById('cc-sidebar-account');
                    var menuWrap = document.getElementById('adminmenuwrap');
                    if (!account || !menuWrap) {
                        return;
                    }
                    if (account.parentElement !== menuWrap) {
                        menuWrap.appendChild(account);
                    }
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', mount);
                } else {
                    mount();
                }
            })();
        </script>
        <?php
    }

    /**
     * Render branding header at the top of the left sidebar.
     */
    public function render_sidebar_branding(): void
    {
        $screen = get_current_screen();
        if (!$screen || !$this->is_unified_theme_scope($screen)) {
            return;
        }

        $logo_url = $this->resolve_sidebar_brand_logo_url();
        $site_name = (string) get_bloginfo('name');
        if ($site_name === '') {
            $site_name = __('Content Core', 'content-core');
        }
        $dashboard_url = admin_url('admin.php?page=content-core');
        ?>
        <div id="cc-sidebar-branding" class="cc-sidebar-branding" aria-label="<?php esc_attr_e('Brand', 'content-core'); ?>">
            <div class="cc-sidebar-branding__row">
                <a class="cc-sidebar-branding__link" href="<?php echo esc_url($dashboard_url); ?>">
                    <?php if ($logo_url !== ''): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="" class="cc-sidebar-branding__logo">
                    <?php else: ?>
                        <span class="cc-sidebar-branding__fallback dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="cc-sidebar-branding__name"><?php echo esc_html($site_name); ?></span>
                </a>
                <button type="button" id="cc-sidebar-collapse-toggle" class="cc-sidebar-branding__collapse"
                    aria-label="<?php esc_attr_e('Toggle Menu', 'content-core'); ?>"
                    title="<?php esc_attr_e('Toggle Menu', 'content-core'); ?>">
                    <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                </button>
            </div>
        </div>
        <script>
            (function () {
                var mount = function () {
                    var brand = document.getElementById('cc-sidebar-branding');
                    var menuWrap = document.getElementById('adminmenuwrap');
                    var menu = document.getElementById('adminmenu');
                    if (!brand || !menuWrap || !menu) {
                        return;
                    }
                    if (brand.parentElement !== menuWrap) {
                        menuWrap.insertBefore(brand, menu);
                    } else if (brand.nextElementSibling !== menu) {
                        menuWrap.insertBefore(brand, menu);
                    }
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', mount);
                } else {
                    mount();
                }
            })();
        </script>
        <?php
    }

    /**
     * Apply unified admin theme class only on approved scope screens.
     */
    public function add_admin_theme_body_class(string $classes): string
    {
        $screen = get_current_screen();
        if (!$screen || !$this->is_unified_theme_scope($screen)) {
            return $classes;
        }

        $tokens = preg_split('/\s+/', trim($classes)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($token): bool => $token !== ''));

        $add_class = static function (string $class_name) use (&$tokens): void {
            if (!in_array($class_name, $tokens, true)) {
                $tokens[] = $class_name;
            }
        };

        $add_class('cc-admin-theme');

        if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'cc_dark_mode', true) === '1') {
            $add_class('cc-admin-theme-dark');
        }

        if ($this->is_list_table_screen($screen)) {
            $add_class('cc-list-table-screen');
        }

        if ($screen->id === 'upload') {
            $add_class('cc-upload-media-final');
            $add_class('cc-media-screen');
            $add_class('cc-media-native-inspector');
        }

        if (in_array($screen->base, ['post', 'site-editor'], true)) {
            $add_class('cc-post-edit-screen');
        }

        if (in_array($screen->base, ['options-general', 'options-writing', 'options-reading', 'options-discussion', 'options-media', 'options-permalink'], true)) {
            $add_class('cc-settings-screen');
        }

        return trim(implode(' ', $tokens));
    }

    /**
     * Detect common list-table admin screens so layout CSS can apply on first paint.
     */
    private function is_list_table_screen(\WP_Screen $screen): bool
    {
        if (in_array($screen->base, ['edit', 'upload', 'users', 'plugins', 'comments', 'edit-tags'], true)) {
            return true;
        }

        if (in_array($screen->id, ['edit', 'upload', 'users', 'plugins', 'edit-comments', 'edit-tags'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Apply unified theme to the full wp-admin surface.
     */
    private function is_unified_theme_scope(\WP_Screen $_screen): bool
    {
        if (is_network_admin()) {
            return false;
        }
        return true;
    }

    /**
     * Resolve a readable label for the user's primary role.
     */
    private function resolve_primary_role_label(\WP_User $user): string
    {
        $role = (string) ($user->roles[0] ?? '');
        if ($role === 'administrator') {
            return __('Admin Manager', 'content-core');
        }

        if ($role !== '') {
            $roles = wp_roles();
            if ($roles instanceof \WP_Roles && isset($roles->roles[$role]['name'])) {
                return (string) $roles->roles[$role]['name'];
            }
        }

        return __('User', 'content-core');
    }

    /**
     * Resolve the sidebar brand logo from branding login logo settings.
     */
    private function resolve_sidebar_brand_logo_url(): string
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $branding_module = $plugin->get_module('branding');
        if (!($branding_module instanceof \ContentCore\Modules\Branding\BrandingModule)) {
            return '';
        }

        $settings = $branding_module->get_settings();
        $logo = $settings['login_logo'] ?? '';

        if (is_numeric($logo) && (int) $logo > 0) {
            $resolved = wp_get_attachment_image_url((int) $logo, 'thumbnail');
            if (is_string($resolved) && $resolved !== '') {
                return esc_url_raw($resolved);
            }
            $fallback = wp_get_attachment_url((int) $logo);
            return is_string($fallback) ? esc_url_raw($fallback) : '';
        }

        if (is_string($logo) && $logo !== '') {
            return esc_url_raw($logo);
        }

        if (!empty($settings['login_logo_url']) && is_string($settings['login_logo_url'])) {
            return esc_url_raw($settings['login_logo_url']);
        }

        return '';
    }

    /**
     * Apply branding accent color as unified admin token overrides.
     */
    private function apply_branding_accent_tokens(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $branding_module = $plugin->get_module('branding');
        if (!($branding_module instanceof \ContentCore\Modules\Branding\BrandingModule)) {
            return;
        }

        $settings = $branding_module->get_settings();
        $accent_raw = (string) ($settings['custom_accent_color'] ?? '');
        $accent = sanitize_hex_color($accent_raw);
        if (!$accent) {
            return;
        }

        $accent_600 = $this->adjust_hex_color($accent, -0.12);
        $accent_100 = $this->mix_hex_colors($accent, '#ffffff', 0.78);
        $accent_50 = $this->mix_hex_colors($accent, '#ffffff', 0.9);

        $css = sprintf(
            'body.cc-admin-theme{--ui-color-accent-500:%1$s;--ui-color-accent-600:%2$s;--ui-color-accent-100:%3$s;--ui-color-accent-50:%4$s;}',
            esc_html($accent),
            esc_html($accent_600),
            esc_html($accent_100),
            esc_html($accent_50)
        );

        wp_add_inline_style('cc-admin-ui', $css);
    }

    /**
     * Lighten or darken a hex color by percentage.
     */
    private function adjust_hex_color(string $hex, float $amount): string
    {
        $rgb = $this->hex_to_rgb($hex);
        if ($rgb === null) {
            return $hex;
        }

        $adjust = function (int $channel) use ($amount): int {
            if ($amount >= 0) {
                $value = $channel + (255 - $channel) * $amount;
            } else {
                $value = $channel * (1 + $amount);
            }
            return max(0, min(255, (int) round($value)));
        };

        return $this->rgb_to_hex([
            $adjust($rgb[0]),
            $adjust($rgb[1]),
            $adjust($rgb[2]),
        ]);
    }

    /**
     * Mix two hex colors.
     */
    private function mix_hex_colors(string $hex_a, string $hex_b, float $weight_a): string
    {
        $rgb_a = $this->hex_to_rgb($hex_a);
        $rgb_b = $this->hex_to_rgb($hex_b);
        if ($rgb_a === null || $rgb_b === null) {
            return $hex_a;
        }

        $w = max(0.0, min(1.0, $weight_a));
        $mix = [
            (int) round($rgb_a[0] * $w + $rgb_b[0] * (1 - $w)),
            (int) round($rgb_a[1] * $w + $rgb_b[1] * (1 - $w)),
            (int) round($rgb_a[2] * $w + $rgb_b[2] * (1 - $w)),
        ];

        return $this->rgb_to_hex($mix);
    }

    /**
     * Convert hex color to rgb tuple.
     *
     * @return array<int,int>|null
     */
    private function hex_to_rgb(string $hex): ?array
    {
        $sanitized = ltrim((string) sanitize_hex_color($hex), '#');
        if (strlen($sanitized) !== 6) {
            return null;
        }

        return [
            hexdec(substr($sanitized, 0, 2)),
            hexdec(substr($sanitized, 2, 2)),
            hexdec(substr($sanitized, 4, 2)),
        ];
    }

    /**
     * Convert rgb tuple to hex color.
     *
     * @param array<int,int> $rgb
     */
    private function rgb_to_hex(array $rgb): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, (int) ($rgb[0] ?? 0))),
            max(0, min(255, (int) ($rgb[1] ?? 0))),
            max(0, min(255, (int) ($rgb[2] ?? 0)))
        );
    }
}
