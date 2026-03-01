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
        'content-core',
        'cc-settings-hub',
        'cc-structure',
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

    /** @var Data\SettingsRegistry */
    private $registry;

    /** @var Rest\SettingsRestController */
    private $rest_controller;

    /** @var SeoSettings */
    private $seo_settings;

    /** @var MediaSettings */
    private $media_settings;

    /** @var RedirectSettings */
    private $redirect_settings;

    /** @var VisibilitySettings */
    private $visibility_settings;

    /** @var MultilingualSettings */
    private $multilingual_settings;

    /** @var CookieSettings */
    private $cookie_settings;

    /** @var SiteOptionsSettings */
    private $site_options_settings;

    public function init(): void
    {
        $this->registry = new Data\SettingsRegistry();

        // Initialize sub-modules
        $this->seo_settings = new SeoSettings($this);
        $this->media_settings = new MediaSettings($this);
        $this->redirect_settings = new RedirectSettings($this);
        $this->visibility_settings = new VisibilitySettings($this);
        $this->multilingual_settings = new MultilingualSettings($this);
        $this->cookie_settings = new CookieSettings($this);
        $this->site_options_settings = new SiteOptionsSettings($this);

        // Initialize REST controller
        if (class_exists('ContentCore\\Modules\\Settings\\Rest\\SettingsRestController')) {
            $this->rest_controller = new Rest\SettingsRestController($this);
        }

        // Register core settings in the registry
        $this->register_core_settings();

        // Register REST routes
        if ($this->rest_controller) {
            add_action('rest_api_init', [$this->rest_controller, 'register_routes']);
        }

        // Intercept legacy Admin URL Hashes early enough to run but late enough to allow permissions
        add_action('admin_page_access_denied', [$this->redirect_settings, 'handle_legacy_admin_redirects'], 1);

        // Redirect logic runs on frontend init
        add_action('init', [$this->redirect_settings, 'handle_frontend_redirect']);

        // Upload size limit — runs everywhere (admin uploads go through here)
        add_filter('upload_size_limit', [$this->media_settings, 'apply_upload_size_limit']);

        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this->visibility_settings, 'apply_menu_visibility'], 9999);
        add_action('admin_head', [$this->visibility_settings, 'apply_menu_visibility']);
        add_action('admin_menu', [$this->visibility_settings, 'apply_menu_order'], 999);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);

        // Admin bar: visibility + site-name link
        add_action('wp_before_admin_bar_render', [$this->visibility_settings, 'apply_admin_bar_visibility']);
        add_action('admin_bar_menu', [$this->visibility_settings, 'apply_admin_bar_site_link'], 999);
    }

    public function enqueue_settings_assets(string $hook): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml = $plugin->get_module('multilingual');
        $catalog = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml::get_language_catalog() : [];

        $screen = get_current_screen();
        if (!$screen)
            return;

        // More robust check: use screen ID instead of hook name which can vary
        $is_cc_settings = (strpos($screen->id, 'cc-') !== false || strpos($screen->id, 'content-core') !== false);

        if (!$is_cc_settings) {
            return;
        }

        // Generic settings UI assets (shared)
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

        $rest_base = rest_url('content-core/v1/settings');
        wp_localize_script('cc-settings-js', 'CC_SETTINGS', [
            'restUrl' => $rest_base,
            'nonce' => wp_create_nonce('wp_rest'),
            'catalog' => $catalog,
            'strings' => [
                'langAdded' => __('Language already added.', 'content-core'),
                'confirmRemoveLang' => __('Remove this language?', 'content-core'),
                'selectFlag' => __('Select Flag Image', 'content-core'),
                'useImage' => __('Use this image', 'content-core'),
                'selectOGImage' => __('Select Default OG Image', 'content-core'),
            ]
        ]);

        // Enqueue the React Application for Site Settings, Multilingual, SEO, etc.
        // We ensure it is loaded on any page where the React Root is present.
        $react_pages = [
            'cc-site-settings',
            'cc-multilingual',
            'cc-seo',
            'cc-site-images',
            'cc-cookie-banner',
            'cc-branding',
            'cc-diagnostics',
            'cc-site-options',
            'cc-visibility',
            'cc-media',
            'cc-redirect',
            'cc-manage-terms'
        ];

        $should_load_react = false;
        foreach ($react_pages as $page) {
            if (strpos($hook, $page) !== false) {
                $should_load_react = true;
                break;
            }
        }

        if ($should_load_react) {
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
            } elseif ($page_slug === 'cc-branding') {
                $active_tab = 'branding';
            }

            wp_localize_script('cc-site-settings-app', 'CC_SITE_SETTINGS', [
                'nonce' => wp_create_nonce('wp_rest'),
                'restBase' => $rest_base . '/site',
                'diagnosticsRestBase' => rest_url('content-core/v1/diagnostics'),
                'siteUrl' => untrailingslashit(home_url()),
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

    public function get_settings(): array
    {
        return $this->registry->get(self::OPTION_KEY);
    }

    public function get_registry(): Data\SettingsRegistry
    {
        return $this->registry;
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
        $defaults = $this->registry->get_defaults(self::MEDIA_KEY);
        $media = get_option(self::MEDIA_KEY, $defaults);

        $limit = $media['max_upload_size'] ?? 0;

        if ($limit < 1) {
            return $size;
        }

        $limit_bytes = (int) $limit * 1048576;
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
            'cc-cookie-banner',
            'cc-branding',
            'cc-diagnostics',
            'cc-site-options',
            'cc-manage-terms'
        ];
        return $pagenow === 'admin.php' && in_array($page, $valid_pages, true);
    }

    // Replaced by sub-modules

    // ─── Save Handler ──────────────────────────────────────────────

    // ─── Categorization ────────────────────────────────────────────

    // Replaced by sub-modules

    // ─── Categorization ────────────────────────────────────────────

    public function get_all_menu_items(): array
    {
        $menu = $this->visibility_settings->get_full_menu_cache();
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

            <!-- ── React Shell (SEO, Site Images, Cookie Banner, Site Options tab nav) ── -->
            <div id="cc-site-settings-react-root" style="margin-top: 24px;"></div>

            <!-- ── Site Options Schema — PHP form, shown/hidden by React tab ── -->
            <div id="cc-site-options-schema-section" style="display:none; margin-top: 0;">
                <?php \ContentCore\Modules\Settings\Partials\SiteOptionsSchemaRenderer::render(); ?>
            </div>

        </div>
        <?php
    }




    /**
     * Renders the Multilingual configuration form section for Site Settings.
     */
    private function maybe_render_multilingual_form_section(): void
    {
        \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render($this);
    }

    public function render_settings_page(): void
    {
        $page_slug = $_GET['page'] ?? '';

        // Ensure consistent <h1> across all these forms
        $title = get_admin_page_title();

        ?>
        <div class="wrap content-core-admin cc-settings-single-page">
            <div class="cc-header">
                <h1>
                    <?php echo esc_html($title); ?>
                </h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>
                <?php
                $submit_name = 'cc_save_general';
                if ($page_slug === 'cc-multilingual') {
                    $submit_name = 'cc_save_multilingual';
                }
                ?>
                <input type="hidden" name="cc_submit_id" value="<?php echo esc_attr($submit_name); ?>">

                <?php
                if ($page_slug === 'cc-visibility') {
                    \ContentCore\Modules\Settings\Partials\General\VisibilityTabRenderer::render($this);
                } elseif ($page_slug === 'cc-media') {
                    \ContentCore\Modules\Settings\Partials\General\MediaTabRenderer::render($this);
                } elseif ($page_slug === 'cc-redirect') {
                    \ContentCore\Modules\Settings\Partials\General\RedirectTabRenderer::render($this);
                } elseif ($page_slug === 'cc-multilingual') {
                    \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render($this);
                }
                ?>

                <div style="display: flex; gap: 12px; align-items: center; margin-top: 24px;">
                    <?php submit_button(__('Save Settings', 'content-core'), 'primary', $submit_name, false); ?>
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

    // Replaced by sub-modules

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
    public function update_merged_option(string $key, array $new_data): void
    {
        $this->registry->save($key, $new_data);
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

    /**
     * Register all core settings in the registry.
     */
    private function register_core_settings(): void
    {
        // 1. Visibility Settings
        $this->registry->register(self::OPTION_KEY, [
            'default' => [
                'admin' => [],
                'client' => array_combine(self::DEFAULT_HIDDEN, array_fill(0, count(self::DEFAULT_HIDDEN), false))
            ]
        ]);

        // 1.1 Admin Bar Settings
        $this->registry->register(self::ADMIN_BAR_KEY, [
            'default' => [
                'hide_wp_logo' => false,
                'hide_comments' => false,
                'hide_new_content' => false,
                'enabled' => false,
                'url' => home_url(),
                'new_tab' => false,
            ]
        ]);

        // 2. Redirect Settings
        if (class_exists('ContentCore\\Modules\\Settings\\RedirectSettings')) {
            $this->registry->register(self::REDIRECT_KEY, [
                'default' => RedirectSettings::get_defaults(),
                'sanitize_callback' => function ($data) {
                    if (isset($data['from_path'])) {
                        $data['from_path'] = $this->sanitize_redirect_path($data['from_path']);
                    }
                    return $data;
                }
            ]);
        }

        // 3. Media Settings
        $this->registry->register(self::MEDIA_KEY, [
            'default' => [
                'max_upload_size' => 25,
                'disable_rest_api' => false
            ]
        ]);

        // 4. Multilingual Settings
        $ml = \ContentCore\Plugin::get_instance()->get_module('multilingual');
        if ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) {
            $this->registry->register($ml::SETTINGS_KEY, [
                'default' => $ml->get_settings()
            ]);
        }

        // 5. SEO Settings
        $this->registry->register(self::SEO_KEY, [
            'default' => [
                'site_title' => '',
                'default_description' => ''
            ]
        ]);

        // 6. Site Images
        $this->registry->register('cc_site_images', [
            'default' => [
                'social_icon_id' => '',
                'social_icon_id_url' => '',
                'og_default_id' => '',
                'og_default_id_url' => ''
            ],
            'sanitize_callback' => function ($data) {
                // 64x64px validation
                if (!empty($data['social_icon_id'])) {
                    $meta = wp_get_attachment_metadata((int) $data['social_icon_id']);
                    if (!$meta || empty($meta['width']) || empty($meta['height']) || $meta['width'] !== 64 || $meta['height'] !== 64) {
                        set_transient('cc_settings_error', __('Favicon must be exactly 64x64px. Image was rejected.', 'content-core'), 45);
                        $data['social_icon_id'] = '';
                        $data['social_icon_id_url'] = '';
                    }
                }

                // 1200x630px validation  
                if (!empty($data['og_default_id'])) {
                    $meta = wp_get_attachment_metadata((int) $data['og_default_id']);
                    if (!$meta || empty($meta['width']) || empty($meta['height']) || $meta['width'] !== 1200 || $meta['height'] !== 630) {
                        set_transient('cc_settings_error', __('Social Preview must be exactly 1200x630px. Image was rejected.', 'content-core'), 45);
                        $data['og_default_id'] = '';
                        $data['og_default_id_url'] = '';
                    }
                }

                return $data;
            }
        ]);

        // 7. Cookie Banner
        $this->registry->register(self::COOKIE_KEY, [
            'default' => [
                'enabled' => false,
                'bannerTitle' => '',
                'bannerText' => '',
                'policyUrl' => '',
                'labels' => [
                    'acceptAll' => __('Accept All', 'content-core'),
                    'rejectAll' => __('Reject All', 'content-core'),
                    'save' => __('Save Settings', 'content-core'),
                    'settings' => __('Preferences', 'content-core'),
                ],
                'categories' => [
                    'analytics' => true,
                    'marketing' => true,
                    'preferences' => true,
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
            ]
        ]);
    }
}
