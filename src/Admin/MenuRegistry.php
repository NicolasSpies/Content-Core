<?php
namespace ContentCore\Admin;

/**
 * Class MenuRegistry
 *
 * Handles the registration of the Content Core admin menu and submenus.
 */
class MenuRegistry
{
    private $admin_menu_handler;

    public function __construct($admin_menu_handler)
    {
        $this->admin_menu_handler = $admin_menu_handler;
    }

    /**
     * Initialize menu hooks
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_menu_redirects']);
    }

    /**
     * Redirect empty top-level menus to exactly their first real section
     */
    public function handle_menu_redirects(): void
    {
        global $pagenow;
        if ($pagenow !== 'admin.php' || empty($_GET['page'])) {
            return;
        }

        if ($_GET['page'] === 'cc-structure') {
            wp_redirect(admin_url('edit.php?post_type=cc_post_type_def'));
            exit;
        }

        if ($_GET['page'] === 'cc-settings-hub') {
            wp_redirect(admin_url('admin.php?page=cc-site-options'));
            exit;
        }
    }

    /**
     * Register the main menu and submenus
     */
    public function register_menu(): void
    {
        // Legacy redirect for mapping screen
        if (isset($_GET['page']) && $_GET['page'] === 'content-core-language-mapping') {
            wp_safe_redirect(admin_url('admin.php?page=cc-manage-terms'));
            exit;
        }

        // --- Main Menu Registration ---
        add_menu_page(
            __('Content Core', 'content-core'),
            __('Content Core', 'content-core'),
            'manage_options',
            'content-core',
            [$this->admin_menu_handler, 'render_main_dashboard'],
            'dashicons-layout',
            30
        );

        $plugin = \ContentCore\Plugin::get_instance();

        // 1) Dashboard (First submenu entry)
        add_submenu_page(
            'content-core',
            __('Dashboard', 'content-core'),
            __('Dashboard', 'content-core'),
            'manage_options',
            'content-core',
            [$this->admin_menu_handler, 'render_main_dashboard']
        );

        // --- Structure Sub-hierarchy ---
        add_submenu_page(
            'content-core',
            __('Structure', 'content-core'),
            __('Structure', 'content-core'),
            'manage_options',
            'cc-structure-root',
            '__return_null'
        );

        if ($plugin->is_module_active('content_types')) {
            add_submenu_page('content-core', __('Post Types', 'content-core'), __('Post Types', 'content-core'), 'manage_options', 'edit.php?post_type=cc_post_type_def');
            add_submenu_page('content-core', __('Taxonomies', 'content-core'), __('Taxonomies', 'content-core'), 'manage_options', 'edit.php?post_type=cc_taxonomy_def');
        }
        if ($plugin->is_module_active('custom_fields')) {
            add_submenu_page('content-core', __('Field Groups', 'content-core'), __('Field Groups', 'content-core'), 'manage_options', 'edit.php?post_type=cc_field_group');
        }
        if ($plugin->is_module_active('multilingual')) {
            add_submenu_page('content-core', __('Manage Terms', 'content-core'), __('Manage Terms', 'content-core'), 'manage_options', 'cc-manage-terms', [$this->admin_menu_handler, 'render_manage_terms_page']);
        }

        // --- Settings Sub-hierarchy ---
        add_submenu_page(
            'content-core',
            __('Settings', 'content-core'),
            __('Settings', 'content-core'),
            'manage_options',
            'cc-settings-root',
            '__return_null'
        );

        $settings_module = $plugin->get_module('settings');
        $site_options_module = $plugin->get_module('site_options');
        $site_options_admin = ($site_options_module instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule) ? $site_options_module->get_admin() : null;

        if ($site_options_admin) {
            add_submenu_page('content-core', __('Site Options', 'content-core'), __('Site Options', 'content-core'), 'manage_options', 'cc-site-options', [$site_options_admin, 'render_page']);
        }

        if ($settings_module) {
            $pages = [
                'cc-multilingual' => __('Multilingual', 'content-core'),
                'cc-visibility' => __('Visibility', 'content-core'),
                'cc-media' => __('Media', 'content-core'),
                'cc-redirect' => __('Redirect', 'content-core'),
                'cc-seo' => __('SEO', 'content-core'),
                'cc-site-images' => __('Site Images', 'content-core'),
                'cc-branding' => __('Branding', 'content-core'),
                'cc-cookie-banner' => __('Cookie Banner', 'content-core'),
            ];
            foreach ($pages as $slug => $label) {
                $callback = (in_array($slug, ['cc-seo', 'cc-branding', 'cc-cookie-banner', 'cc-site-images'])) ? [$settings_module, 'render_site_settings_page'] : [$settings_module, 'render_settings_page'];
                add_submenu_page('content-core', $label, $label, 'manage_options', $slug, $callback);
            }
        }

        // --- Utilities ---
        add_submenu_page(
            'content-core',
            __('System', 'content-core'),
            __('System', 'content-core'),
            'manage_options',
            'cc-system-root',
            '__return_null'
        );

        add_submenu_page('content-core', __('Diagnostics', 'content-core'), __('Diagnostics', 'content-core'), 'manage_options', 'cc-diagnostics', [$this->admin_menu_handler, 'render_diagnostics_page']);
        if ($plugin->is_module_active('rest_api')) {
            add_submenu_page('content-core', __('REST API', 'content-core'), __('REST API', 'content-core'), 'manage_options', 'cc-api-info', [$this->admin_menu_handler, 'render_api_page']);
        }

        // Remove the default duplicate top-level submenus created by WordPress
        remove_submenu_page('cc-structure', 'cc-structure');
        remove_submenu_page('cc-settings-hub', 'cc-settings-hub');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            global $submenu;
            if (isset($submenu['cc-settings-hub'])) {
                $slugs = array_column($submenu['cc-settings-hub'], 2);
                $duplicates = array_diff_assoc($slugs, array_unique($slugs));
                if (class_exists('\ContentCore\Logger')) {
                    \ContentCore\Logger::debug('[CC Settings] Menu entries registered for cc-settings-hub: ' . print_r($submenu['cc-settings-hub'], true));
                    if (!empty($duplicates)) {
                        \ContentCore\Logger::debug('[CC Settings] Duplicate slugs detected: ' . print_r($duplicates, true));
                    }
                }
            }
        }
    }
}
