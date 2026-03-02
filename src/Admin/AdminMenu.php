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

        // 3. Maintenance Actions
        $maintenance = new MaintenanceService();
        $maintenance->init();

        // 4. UI Fragments
        add_action('admin_footer', [$this, 'maybe_render_footer_audit']);
        add_filter('admin_footer_text', [$this, 'maybe_remove_footer_text'], 11);
        add_filter('update_footer', [$this, 'maybe_remove_footer_text'], 11);

        // Error Log actions — delegated to ErrorLogScreen
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

        // Robust detection: Content Core pages, settings, or our specific post types/taxonomies
        $is_cc = (strpos($screen->id, 'content-core') !== false
            || strpos($screen->id, 'cc_') !== false
            || (isset($screen->post_type) && strpos($screen->post_type, 'cc_') === 0));

        if (!$is_cc) {
            return;
        }

        wp_enqueue_style('cc-admin-modern');

        // Branding Accent Color
        $plugin = \ContentCore\Plugin::get_instance();
        $branding = $plugin->get_module('branding');
        $accent_color = '#2271b1'; // Default WP Blue

        if ($branding instanceof \ContentCore\Modules\Branding\BrandingModule) {
            $settings = $branding->get_settings();
            if (!empty($settings['custom_accent_color'])) {
                $accent_color = $settings['custom_accent_color'];
            }
        }

        // Inject as CSS Variable
        $custom_css = sprintf(':root { --cc-accent-color: %s; }', esc_attr($accent_color));
        wp_add_inline_style('cc-admin-modern', $custom_css);

        wp_enqueue_script('cc-admin-js');
        wp_enqueue_script('jquery-ui-sortable');
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
}