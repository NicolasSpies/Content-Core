<?php
namespace ContentCore\Admin;

/**
 * Class AdminMenu
 *
 * Centralizes the admin navigation for Content Core.
 * Ensures a consistent hierarchy and order of submenus.
 */
class AdminMenu
{
    /**
     * Initialize the admin menu hooks
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_cc_flush_rewrite_rules', [$this, 'handle_flush_rewrite_rules']);
        add_action('admin_post_cc_clear_expired_transients', [$this, 'handle_clear_expired_transients']);
        add_action('admin_post_cc_clear_all_transients', [$this, 'handle_clear_all_transients']);
        add_action('admin_post_cc_clear_plugin_caches', [$this, 'handle_clear_plugin_caches']);
        add_action('admin_post_cc_flush_object_cache', [$this, 'handle_flush_object_cache']);
        add_action('admin_post_cc_duplicate_site_options', [$this, 'handle_duplicate_site_options']);
        add_action('admin_post_cc_refresh_health', [$this, 'handle_refresh_health']);
        add_action('admin_post_cc_fix_missing_languages', [$this, 'handle_fix_missing_languages']);
        add_action('admin_post_cc_terms_manager_action', [$this, 'handle_terms_manager_action']);

        // Error Log actions — delegated to ErrorLogScreen
        $logger = $GLOBALS['cc_error_logger'] ?? null;
        if ($logger instanceof \ContentCore\Admin\ErrorLogger) {
            $error_log_screen = new \ContentCore\Admin\ErrorLogScreen($logger);
            $error_log_screen->init();
        }

        add_filter('admin_footer_text', [$this, 'maybe_remove_footer_text'], 11);
        add_filter('update_footer', [$this, 'maybe_remove_footer_text'], 11);
    }

    /**
     * Check if the current page is a Content Core page
     */
    private function is_cc_page(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        return (strpos($screen->id, 'content-core') !== false || strpos($screen->id, 'cc_') !== false);
    }

    /**
     * Clear footer text on CC pages
     */
    public function maybe_remove_footer_text($text)
    {
        return $this->is_cc_page() ? '' : $text;
    }

    /**
     * Enqueue modern assets
     */
    public function enqueue_admin_assets($hook): void
    {
        // Only enqueue the dashboard styles on Content Core pages
        if (strpos($hook, 'content-core') === false && strpos($hook, 'cc_') === false) {
            return;
        }

        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_script('cc-admin-js');
        wp_enqueue_script('jquery-ui-sortable');
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

        // Add the top-level "Parent" item
        add_menu_page(
            __('Content Core', 'content-core'),
            __('Content Core', 'content-core'),
            'manage_options',
            'content-core',
            [$this, 'render_main_dashboard'],
            'dashicons-layout',
            30
        );

        $plugin = \ContentCore\Plugin::get_instance();

        // Submenu: Field Groups (Standard WP route for the CPT)
        if ($plugin->is_module_active('custom_fields')) {
            add_submenu_page(
                'content-core',
                __('Field Groups', 'content-core'),
                __('Field Groups', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_field_group'
            );
        }

        // Submenu: Post Types (CPT Builder)
        if ($plugin->is_module_active('content_types')) {
            add_submenu_page(
                'content-core',
                __('Post Types', 'content-core'),
                __('Post Types', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_post_type_def'
            );

            // Submenu: Taxonomies (Taxonomy Builder)
            add_submenu_page(
                'content-core',
                __('Taxonomies', 'content-core'),
                __('Taxonomies', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_taxonomy_def'
            );
        }

        // Submenu: Manage Terms (Multilingual term grid)
        if ($plugin->is_module_active('multilingual')) {
            add_submenu_page(
                'content-core',
                __('Manage Terms', 'content-core'),
                __('Manage Terms', 'content-core'),
                'manage_options',
                'cc-manage-terms',
                [$this, 'render_manage_terms_page']
            );
        }

        // Submenu: API (Information & Documentation)
        if ($plugin->is_module_active('rest_api')) {
            add_submenu_page(
                'content-core',
                __('REST API', 'content-core'),
                __('REST API', 'content-core'),
                'manage_options',
                'cc-api-info',
                [$this, 'render_api_page']
            );
        }

        add_submenu_page(
            'content-core',
            __('Diagnostics', 'content-core'),
            __('Diagnostics', 'content-core'),
            'manage_options',
            'cc-diagnostics',
            [$this, 'render_diagnostics_page']
        );

        // Rename the first submenu item (which defaults to the same name as the top-level)
        global $submenu;
        if (isset($submenu['content-core'])) {
            $submenu['content-core'][0][0] = __('Dashboard', 'content-core');
        }
    }

    /**
     * Render the main Dashboard
     */
    public function render_main_dashboard(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $cache_service = new CacheService();

        $format_bytes = function ($bytes) {
            if ($bytes <= 0)
                return '0 B';
            $base = log($bytes, 1024);
            $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
            return round(pow(1024, $base - floor($base)), 2) . ' ' . $suffixes[floor($base)];
        };

        $snapshot = $cache_service->get_snapshot();
        $health_report = $cache_service->get_consolidated_health_report();
        $subsystems = $health_report['subsystems'];

        $last_expired = $cache_service->get_last_action_info('expired_transients');
        $last_all = $cache_service->get_last_action_info('all_transients');
        $last_cc = $cache_service->get_last_action_info('cc_caches');
        $last_obj = $cache_service->get_last_action_info('object_cache');

        $object_cache_active = $snapshot['object_cache']['enabled'];
        $wp_version = get_bloginfo('version');
        $plugin_version = $plugin->get_version();
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1>
                    <?php _e('Dashboard', 'content-core'); ?>
                </h1>
            </div>

            <?php
            settings_errors('cc_dashboard');

            if (isset($_GET['cc_action'])) {
                $bytes = isset($_GET['cc_bytes']) ? (int) $_GET['cc_bytes'] : 0;
                $count = isset($_GET['cc_count']) ? (int) $_GET['cc_count'] : 0;
                $msg = '';

                switch ($_GET['cc_action']) {
                    case 'expired_cleared':
                        $msg = sprintf(__('Cleared %d expired transients (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'all_cleared':
                        $msg = sprintf(__('Cleared ALL transients: %d items removed (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'cc_cleared':
                        $msg = sprintf(__('Cleared Content Core caches: %d items removed (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'obj_flushed':
                        $msg = __('Object cache flushed successfully.', 'content-core');
                        break;
                    case 'options_duplicated':
                        $msg = __('Site options duplicated successfully.', 'content-core');
                        break;
                    case 'health_refreshed':
                        $msg = __('Health status refreshed.', 'content-core');
                        break;
                    case 'rules_flushed':
                        $msg = __('Rewrite rules flushed successfully.', 'content-core');
                        break;
                    case 'meta_fixed':
                        $msg = sprintf(__('Fixed missing language meta for %d items.', 'content-core'), $count);
                        break;
                }

                if ($msg) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
                }
            }
            ?>

            <div class="cc-dashboard-grid">
                <!-- Header: Global Status -->
                <div class="cc-card cc-card-full" style="padding: 24px 32px;">
                    <div class="cc-system-overview"
                        style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
                        <div style="display:flex; align-items:center; gap:40px;">
                            <!-- Block 1: System Health Index -->
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="display:flex; flex-direction:column;">
                                    <div
                                        style="font-size:11px; color:var(--cc-text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">
                                        <?php _e('Core Health', 'content-core'); ?>
                                    </div>
                                    <div
                                        style="font-size:24px; font-weight:800; color:var(--cc-text); margin-top:2px; display:flex; align-items:baseline; gap:4px;">
                                        <?php echo $health_report['health_index'] ?? 100; ?>
                                        <span style="font-size:14px; font-weight:500; color:var(--cc-text-muted);">/ 100</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Block 2: REST API Connectivity -->
                            <div
                                style="display:flex; align-items:center; gap:16px; padding-left:40px; border-left:1px solid var(--cc-border-light);">
                                <?php
                                $rest_status = $subsystems['rest_api'] ?? ['status' => 'healthy', 'short_label' => 'Active'];
                                $rest_color_class = esc_attr($rest_status['status']);
                                ?>
                                <div style="display:flex; flex-direction:column;">
                                    <div
                                        style="font-size:11px; color:var(--cc-text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">
                                        <?php _e('REST Connectivity', 'content-core'); ?>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:10px; margin-top:6px;">
                                        <div class="cc-status-badge cc-status-<?php echo $rest_color_class; ?>"
                                            style="margin:0; padding:4px 12px; font-size:11px; border-radius:12px;">
                                            <span class="cc-status-icon" style="width:6px; height:6px;"></span>
                                            <span class="cc-status-label" style="font-weight:700;">
                                                <?php echo esc_html($rest_status['short_label']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:24px;">
                            <div style="text-align:right;">
                                <div style="font-size:13px; font-weight:600; color:var(--cc-text);">
                                    <?php echo esc_html(sprintf(__('v%s', 'content-core'), $plugin_version)); ?>
                                </div>
                                <div style="font-size:11px; color:var(--cc-text-muted); margin-top:2px;">
                                    <?php echo esc_html(sprintf(__('Checked: %s', 'content-core'), $health_report['checked_at'])); ?>
                                </div>
                            </div>

                            <div style="display:flex; gap:10px;">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="cc_refresh_health">
                                    <?php wp_nonce_field('cc_refresh_health_nonce'); ?>
                                    <button type="submit" class="button button-secondary"
                                        style="height:36px; display:flex; align-items:center; gap:6px;">
                                        <span class="dashicons dashicons-update"
                                            style="font-size:16px; width:16px; height:16px;"></span>
                                        <?php _e('Refresh', 'content-core'); ?>
                                    </button>
                                </form>

                                <a href="<?php echo admin_url('admin.php?page=cc-diagnostics'); ?>"
                                    class="button button-secondary"
                                    style="height:36px; display:flex; align-items:center; gap:6px;">
                                    <span class="dashicons dashicons-admin-tools"
                                        style="font-size:16px; width:16px; height:16px; margin-top:2px;"></span>
                                    <?php _e('Diagnostics', 'content-core'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Links Card (New in Phase 7) -->
                <div class="cc-card cc-card-full" style="padding: 20px 32px; background: var(--cc-bg-soft);">
                    <div style="display:flex; align-items:center; gap:24px; flex-wrap:wrap;">
                        <span
                            style="font-size:13px; font-weight:700; color:var(--cc-text-muted); text-transform:uppercase; letter-spacing:1px; margin-right:10px;">
                            <?php _e('Quick Access:', 'content-core'); ?>
                        </span>
                        <a href="<?php echo admin_url('admin.php?page=cc-site-options'); ?>" class="cc-quick-link"
                            style="display:flex; align-items:center; gap:8px; text-decoration:none; color:var(--cc-text); font-weight:600; font-size:14px;">
                            <span class="dashicons dashicons-admin-settings"
                                style="font-size:18px; color:var(--cc-primary);"></span>
                            <?php _e('Site Options', 'content-core'); ?>
                        </a>
                        <a href="<?php echo admin_url('edit.php?post_type=cc_form'); ?>" class="cc-quick-link"
                            style="display:flex; align-items:center; gap:8px; text-decoration:none; color:var(--cc-text); font-weight:600; font-size:14px;">
                            <span class="dashicons dashicons-feedback" style="font-size:18px; color:var(--cc-primary);"></span>
                            <?php _e('Forms', 'content-core'); ?>
                        </a>
                        <?php if ($plugin->is_module_active('rest_api')): ?>
                            <a href="<?php echo admin_url('admin.php?page=cc-api-info'); ?>" class="cc-quick-link"
                                style="display:flex; align-items:center; gap:8px; text-decoration:none; color:var(--cc-text); font-weight:600; font-size:14px;">
                                <span class="dashicons dashicons-rest-api" style="font-size:18px; color:var(--cc-primary);"></span>
                                <?php _e('REST API', 'content-core'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo admin_url('admin.php?page=cc-diagnostics'); ?>" class="cc-quick-link"
                            style="display:flex; align-items:center; gap:8px; text-decoration:none; color:var(--cc-text); font-weight:600; font-size:14px;">
                            <span class="dashicons dashicons-performance"
                                style="font-size:18px; color:var(--cc-primary);"></span>
                            <?php _e('Diagnostics', 'content-core'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=cc-site-settings#multilingual'); ?>" class="cc-quick-link"
                            style="display:flex; align-items:center; gap:8px; text-decoration:none; color:var(--cc-text); font-weight:600; font-size:14px;">
                            <span class="dashicons dashicons-translation"
                                style="font-size:18px; color:var(--cc-primary);"></span>
                            <?php _e('Multilingual Settings', 'content-core'); ?>
                        </a>
                    </div>
                </div>
                <?php if (!empty($health_report['issues'])): ?>
                    <div class="cc-health-issues">
                        <?php foreach ($health_report['issues'] as $issue): ?>
                            <div class="cc-health-issue"
                                style="color:<?php echo $issue['status'] === 'critical' ? '#d63638' : '#624a05'; ?>; display:flex; align-items:center; justify-content:space-between; gap:12px; background:<?php echo $issue['status'] === 'critical' ? 'rgba(214, 54, 56, 0.03)' : 'rgba(180, 140, 0, 0.03)'; ?>; padding:8px 16px; border-radius:6px; margin-bottom:8px; border-left:3px solid <?php echo $issue['status'] === 'critical' ? '#d63638' : '#b48c00'; ?>;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span
                                        class="dashicons dashicons-<?php echo $issue['status'] === 'critical' ? 'warning' : 'info'; ?>"
                                        style="font-size:16px; width:16px; height:16px;"></span>
                                    <span style="font-size:13px; font-weight:500;">
                                        <?php echo esc_html($issue['message']); ?>
                                    </span>
                                </div>
                                <?php if (!empty($issue['action_id'])): ?>
                                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="<?php echo esc_attr($issue['action_id']); ?>">
                                        <?php
                                        $nonce_action = $issue['action_id'] . '_nonce';
                                        if ($issue['action_id'] === 'cc_duplicate_site_options')
                                            $nonce_action = 'cc_duplicate_site_options_nonce';
                                        if ($issue['action_id'] === 'cc_fix_missing_languages')
                                            $nonce_action = 'cc_fix_languages_nonce';
                                        if ($issue['action_id'] === 'cc_flush_rewrite_rules')
                                            $nonce_action = 'cc_flush_rules_nonce';

                                        wp_nonce_field($nonce_action);
                                        ?>
                                        <button type="submit" class="button button-small"
                                            style="height:24px; line-height:22px; padding:0 10px; font-size:11px; font-weight:600;">
                                            <?php _e('Fix Now', 'content-core'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php
                        endforeach; ?>
                    </div>
                    <?php
                endif; ?>
            </div>

            <!-- BLOCK ONE: Environment & System -->
            <div class="cc-card cc-card-full">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:16px; font-weight:700;">
                        <?php _e('Environment & System', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo esc_attr($subsystems['system']['status']); ?>">
                        <?php echo esc_html($subsystems['system']['short_label']); ?>
                    </span>
                </div>
                <div class="cc-grid-3">
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Core Runtime', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value">
                            <strong>PHP:</strong>
                            <code><?php echo esc_html($subsystems['system']['data']['php']); ?></code><br>
                            <strong>WP:</strong> <code><?php echo esc_html($subsystems['system']['data']['wp']); ?></code>
                        </div>
                    </div>
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Module Status', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value">
                            <strong>
                                <?php _e('Active:', 'content-core'); ?>
                            </strong>
                            <?php echo count($plugin->get_active_modules()); ?><br>
                            <strong>
                                <?php _e('Failures:', 'content-core'); ?>
                            </strong>
                            <?php echo count($plugin->get_missing_modules()); ?>
                        </div>
                    </div>
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Assets & Runtime', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value">
                            <strong>JS Runtime:</strong> <span id="cc-js-status" style="color:#d63638;">
                                <?php _e('Detecting...', 'content-core'); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BLOCK TWO: Headless API Connectivity -->
            <div class="cc-card cc-card-full">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
                    <h2 style="margin:0; font-size:16px; font-weight:700;">
                        <?php _e('Headless API Connectivity', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo esc_attr($subsystems['rest_api']['status']); ?>">
                        <?php
                        $api_status_label = $subsystems['rest_api']['short_label'];
                        if ($subsystems['rest_api']['status'] === 'healthy')
                            $api_status_label = __('Operational', 'content-core');
                        echo esc_html($api_status_label);
                        ?>
                    </span>
                </div>

                <?php if ($subsystems['rest_api']['status'] !== 'healthy'): ?>
                    <div
                        style="background:rgba(214, 54, 56, 0.05); border-left:4px solid #d63638; padding:12px 16px; margin-bottom:24px; font-size:13px; color:#d63638;">
                        <strong>
                            <?php _e('Connectivity Issue:', 'content-core'); ?>
                        </strong>
                        <?php echo esc_html($subsystems['rest_api']['message']); ?>
                    </div>
                    <?php
                endif; ?>

                <div class="cc-grid-3">
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Verification Method', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value">
                            <span
                                style="font-size:24px; font-weight:700; color:<?php echo $subsystems['rest_api']['data']['reachable'] ? 'var(--cc-health-healthy-text)' : 'var(--cc-health-critical-text)'; ?>;">
                                <?php echo $subsystems['rest_api']['data']['reachable'] ? __('DIRECT', 'content-core') : __('FAIL', 'content-core'); ?>
                            </span>
                            <div style="font-size:11px; color:var(--cc-text-muted); margin-top:4px;">
                                <?php _e('Deterministic Internal Audit', 'content-core'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('API Protocol', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value">
                            <strong>
                                <?php _e('HTTP Status:', 'content-core'); ?>
                            </strong> <code><?php echo esc_html($subsystems['rest_api']['data']['http_code']); ?></code><br>
                            <strong>
                                <?php _e('Routes:', 'content-core'); ?>
                            </strong>
                            <?php echo (int) $subsystems['rest_api']['data']['route_count']; ?>
                        </div>
                    </div>
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Base Endpoint', 'content-core'); ?>
                        </span>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="cc-api-url"
                                value="<?php echo esc_attr($subsystems['rest_api']['data']['base_url'] ?? ''); ?>" readonly
                                style="font-size:12px; flex:1; background:var(--cc-bg-soft); height:32px; border:1px solid var(--cc-border);">
                            <button type="button" class="button button-small" onclick="copyToClipboard('cc-api-url')"
                                style="height:32px;">
                                <?php _e('Copy', 'content-core'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BLOCK THREE: Data Integrity -->
            <div class="cc-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:15px; font-weight:600;">
                        <?php _e('Multilingual Integrity', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo esc_attr($subsystems['multilingual']['status']); ?>">
                        <?php echo esc_html($subsystems['multilingual']['short_label']); ?>
                    </span>
                </div>
                <div class="cc-data-group" style="margin-bottom:16px;">
                    <span class="cc-data-label">
                        <?php _e('Configuration', 'content-core'); ?>
                    </span>
                    <div class="cc-data-value">
                        <strong>Default:</strong>
                        <?php echo strtoupper($subsystems['multilingual']['data']['default_lang']); ?><br>
                        <strong>Active:</strong>
                        <code><?php echo implode(', ', array_map('strtoupper', $subsystems['multilingual']['data']['enabled_languages'])); ?></code>
                    </div>
                </div>
                <div class="cc-divider"></div>
                <div class="cc-grid-2">
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Missing Meta', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value"
                            style="font-size:18px; font-weight:700; color:<?php echo $subsystems['multilingual']['data']['missing_lang_meta_count'] > 0 ? 'var(--cc-health-warning-text)' : 'var(--cc-health-healthy-text)'; ?>;">
                            <?php echo (int) $subsystems['multilingual']['data']['missing_lang_meta_count']; ?>
                        </div>
                    </div>
                    <div class="cc-data-group">
                        <span class="cc-data-label">
                            <?php _e('Collisions', 'content-core'); ?>
                        </span>
                        <div class="cc-data-value"
                            style="font-size:18px; font-weight:700; color:<?php echo $subsystems['multilingual']['data']['duplicate_collisions_count'] > 0 ? 'var(--cc-health-critical-text)' : 'var(--cc-health-healthy-text)'; ?>;">
                            <?php echo (int) $subsystems['multilingual']['data']['duplicate_collisions_count']; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cc-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:15px; font-weight:600;">
                        <?php _e('Site Options Integrity', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo esc_attr($subsystems['site_options']['status']); ?>">
                        <?php echo esc_html($subsystems['site_options']['short_label']); ?>
                    </span>
                </div>
                <div class="cc-data-group">
                    <span class="cc-data-label">
                        <?php _e('Translation Group', 'content-core'); ?>
                    </span>
                    <div class="cc-data-value">
                        <?php echo $subsystems['site_options']['data']['translation_group_id_present'] ?
                            '<span style="color:var(--cc-health-healthy-text); font-weight:600;">Operational</span>' :
                            '<span style="color:var(--cc-health-critical-text); font-weight:600;">Missing ID</span>'; ?>
                    </div>
                </div>
                <div class="cc-divider"></div>
                <div class="cc-data-group">
                    <span class="cc-data-label">
                        <?php _e('Languages Status', 'content-core'); ?>
                    </span>
                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
                        <?php foreach ($subsystems['site_options']['data']['languages_with_options'] as $lang): ?>
                            <span class="cc-status-pill cc-status-healthy" style="font-size:10px; padding:2px 8px; opacity:0.8;">
                                <?php echo strtoupper($lang); ?>
                            </span>
                        <?php endforeach; ?>
                        <?php foreach ($subsystems['site_options']['data']['languages_missing_options'] as $lang): ?>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span class="cc-status-pill cc-status-warning" style="font-size:10px; padding:2px 8px;">
                                    <?php echo strtoupper($lang); ?>
                                </span>
                                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="cc_duplicate_site_options">
                                    <input type="hidden" name="target_lang" value="<?php echo esc_attr($lang); ?>">
                                    <?php wp_nonce_field('cc_duplicate_site_options_nonce'); ?>
                                    <button type="submit" class="cc-btn-link"
                                        style="font-size:10px; color:var(--cc-primary); cursor:pointer; background:none; border:none; padding:0; text-decoration:underline;">
                                        <?php _e('Fix', 'content-core'); ?>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="cc-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:15px; font-weight:600;">
                        <?php _e('Forms Health', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo esc_attr($subsystems['forms']['status']); ?>">
                        <?php echo esc_html($subsystems['forms']['short_label']); ?>
                    </span>
                </div>
                <div class="cc-data-group">
                    <span class="cc-data-label">
                        <?php _e('Status Message', 'content-core'); ?>
                    </span>
                    <div class="cc-data-value" style="font-size:13px; line-height:1.5;">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:8px;">
                            <div style="background:var(--cc-bg-soft); padding:10px; border-radius:6px; text-align:center;">
                                <div style="font-size:10px; color:var(--cc-text-muted); text-transform:uppercase;">
                                    <?php _e('Forms', 'content-core'); ?>
                                </div>
                                <div style="font-size:18px; font-weight:700;">
                                    <?php echo (int) $subsystems['forms']['data']['total_forms']; ?>
                                </div>
                            </div>
                            <div style="background:var(--cc-bg-soft); padding:10px; border-radius:6px; text-align:center;">
                                <div style="font-size:10px; color:var(--cc-text-muted); text-transform:uppercase;">
                                    <?php _e('Entries', 'content-core'); ?>
                                </div>
                                <div style="font-size:18px; font-weight:700;">
                                    <?php echo (int) $subsystems['forms']['data']['total_entries']; ?>
                                </div>
                            </div>
                        </div>
                        <?php echo esc_html($subsystems['forms']['message']); ?>

                        <!-- Translation Overview -->
                        <?php if (!empty($subsystems['forms']['data']['forms_translations'])): ?>
                            <div style="margin-top:15px; border-top:1px solid var(--cc-border); padding-top:12px;">
                                <div
                                    style="font-size:11px; font-weight:600; color:var(--cc-text-muted); text-transform:uppercase; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                                    <?php _e('Translation Status', 'content-core'); ?>
                                    <span style="font-weight:400; text-transform:none; font-size:10px;">
                                        <?php printf(__('Default: %s', 'content-core'), strtoupper($subsystems['forms']['data']['default_lang'])); ?>
                                    </span>
                                </div>
                                <div
                                    style="display:flex; flex-direction:column; gap:6px; max-height:180px; overflow-y:auto; padding-right:6px;">
                                    <?php foreach ($subsystems['forms']['data']['forms_translations'] as $form): ?>
                                        <div
                                            style="display:flex; justify-content:space-between; align-items:center; background:rgba(0,0,0,0.02); padding:5px 8px; border-radius:4px; border:1px solid rgba(0,0,0,0.03);">
                                            <span
                                                style="font-size:11px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px;"
                                                title="<?php echo esc_attr($form['title']); ?>">
                                                <?php echo esc_html($form['title']); ?>
                                            </span>
                                            <div style="display:flex; gap:3px;">
                                                <?php foreach ($form['translations'] as $lang => $present): ?>
                                                    <span
                                                        style="font-size:8px; width:16px; height:16px; display:flex; align-items:center; justify-content:center; border-radius:2px; background:<?php echo $present ? 'rgba(34, 197, 94, 0.12)' : 'rgba(0, 0, 0, 0.04)'; ?>; color:<?php echo $present ? '#166534' : '#9ca3af'; ?>; font-weight:700; border:1px solid <?php echo $present ? 'rgba(34, 197, 94, 0.2)' : 'transparent'; ?>;"
                                                        title="<?php echo strtoupper($lang); ?>: <?php echo $present ? 'Translated' : 'Missing'; ?>">
                                                        <?php echo strtoupper($lang); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cc-divider" style="margin-top:0;"></div>
                <div class="cc-data-group">
                    <span class="cc-data-label">
                        <?php _e('Protection', 'content-core'); ?>
                    </span>
                    <div style="display:flex; gap:8px; margin-top:8px;">
                        <?php
                        $prot = $subsystems['forms']['data']['protection'];
                        $badges = [
                            'honeypot' => 'Honeypot',
                            'rate_limit' => 'Rate Limit',
                            'turnstile' => 'Turnstile'
                        ];
                        foreach ($badges as $key => $label): ?>
                            <span class="cc-status-pill <?php echo $prot[$key] ? 'cc-status-healthy' : 'cc-status-warning'; ?>"
                                style="font-size:9px; opacity:<?php echo $prot[$key] ? '1' : '0.5'; ?>;">
                                <?php echo esc_html($label); ?>
                            </span>
                            <?php
                        endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Section: Safe Actions & Maintenance -->
            <div class="cc-card cc-card-full">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:16px; font-weight:700;">
                        <?php _e('Safe Actions & Maintenance', 'content-core'); ?>
                    </h2>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:24px;">
                    <!-- Safe Action: CC Cache -->
                    <div
                        style="background:var(--cc-bg-soft); padding:20px; border-radius:8px; border:1px solid var(--cc-border);">
                        <span class="cc-data-label">
                            <?php _e('Core Cache', 'content-core'); ?>
                        </span>
                        <div style="margin:12px 0;">
                            <span style="font-size:20px; font-weight:700; color:var(--cc-text);">
                                <?php echo (int) ($snapshot['cc_cache']['count'] ?? 0); ?>
                            </span>
                            <span style="font-size:12px; color:var(--cc-text-muted); margin-left:4px;">(
                                <?php echo $format_bytes($snapshot['cc_cache']['bytes'] ?? 0); ?>)
                            </span>
                        </div>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                            <input type="hidden" name="action" value="cc_clear_plugin_caches">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="button button-primary" style="width:100%; justify-content:center;">
                                <?php _e('Flush CC Cache', 'content-core'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Safe Action: Expired Transients -->
                    <div
                        style="background:var(--cc-bg-soft); padding:20px; border-radius:8px; border:1px solid var(--cc-border);">
                        <span class="cc-data-label">
                            <?php _e('Expired Data', 'content-core'); ?>
                        </span>
                        <div style="margin:12px 0;">
                            <span style="font-size:20px; font-weight:700; color:var(--cc-text);">
                                <?php echo (int) ($snapshot['expired']['count'] ?? 0); ?>
                            </span>
                            <span style="font-size:12px; color:var(--cc-text-muted); margin-left:4px;">(
                                <?php echo $format_bytes($snapshot['expired']['bytes'] ?? 0); ?>)
                            </span>
                        </div>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                            <input type="hidden" name="action" value="cc_clear_expired_transients">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="button button-secondary" style="width:100%; justify-content:center;">
                                <?php _e('Clear Expired', 'content-core'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Dangerous Actions (Collapsible) -->
                    <div
                        style="background:rgba(214, 54, 56, 0.03); padding:20px; border-radius:8px; border:1px solid rgba(214, 54, 56, 0.15);">
                        <span class="cc-data-label" style="color:#d63638;">
                            <?php _e('System Repair', 'content-core'); ?>
                        </span>
                        <p style="font-size:12px; color:var(--cc-text-muted); margin:12px 0; line-height:1.4;">
                            <?php _e('Powerful actions for troubleshooting. Use with caution.', 'content-core'); ?>
                        </p>

                        <details style="margin-top:10px;">
                            <summary
                                style="font-size:13px; font-weight:600; cursor:pointer; color:#d63638; display:flex; align-items:center; gap:5px;">
                                <span class="dashicons dashicons-warning"
                                    style="font-size:18px; width:18px; height:18px;"></span>
                                <?php _e('Open Dangerous Actions', 'content-core'); ?>
                            </summary>
                            <div style="margin-top:15px; padding-top:15px; border-top:1px solid rgba(214, 54, 56, 0.1);">
                                <label
                                    style="font-size:12px; display:flex; align-items:flex-start; gap:8px; color:#d63638; margin-bottom:15px; cursor:pointer; line-height:1.4;">
                                    <input type="checkbox" id="cc_dangerous_confirm_check" style="margin-top:2px;">
                                    <span>
                                        <?php _e('I understand these actions may impact performance temporarily or clear all cached session data.', 'content-core'); ?>
                                    </span>
                                </label>

                                <div style="display:grid; gap:10px;">
                                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post"
                                        id="cc_clear_all_form">
                                        <input type="hidden" name="action" value="cc_clear_all_transients">
                                        <?php wp_nonce_field('cc_cache_nonce'); ?>
                                        <button type="submit" id="cc_clear_all_btn" class="button"
                                            style="width:100%; color:#d63638; border-color:#d63638;" disabled>
                                            <?php _e('Flush ALL Transients', 'content-core'); ?>
                                        </button>
                                    </form>

                                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                                        <input type="hidden" name="action" value="cc_flush_rewrite_rules">
                                        <?php wp_nonce_field('cc_flush_rules_nonce'); ?>
                                        <button type="submit" id="cc_flush_rules_btn" class="button" style="width:100%;"
                                            disabled>
                                            <?php _e('Regenerate Permalinks', 'content-core'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function copyToClipboard(id) {
                var copyText = document.getElementById(id);
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value).then(() => {
                    const btn = event.target || document.querySelector('[onclick="copyToClipboard(\'' + id + '\')"]');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<?php echo esc_js(__('Copied!', 'content - core')); ?>';
                    setTimeout(() => { btn.innerHTML = originalText; }, 2000);
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                // JS Detection
                const jsStatus = document.getElementById('cc-js-status');
                if (jsStatus) {
                    jsStatus.innerText = '<?php echo esc_js(__('Active', 'content-core')); ?>';
                    jsStatus.style.color = '#008a20';
                }

                var dangerousCheckbox = document.getElementById('cc_dangerous_confirm_check');
                var clearAllBtn = document.getElementById('cc_clear_all_btn');
                var flushRulesBtn = document.getElementById('cc_flush_rules_btn');

                if (dangerousCheckbox) {
                    dangerousCheckbox.addEventListener('change', function () {
                        var isChecked = this.checked;
                        if (clearAllBtn) clearAllBtn.disabled = !isChecked;
                        if (flushRulesBtn) flushRulesBtn.disabled = !isChecked;

                        if (isChecked) {
                            if (clearAllBtn) {
                                clearAllBtn.style.backgroundColor = '#d63638';
                                clearAllBtn.style.color = '#fff';
                            }
                        } else {
                            if (clearAllBtn) {
                                clearAllBtn.style.backgroundColor = '';
                                clearAllBtn.style.color = '#d63638';
                            }
                        }
                    });
                }
            });
        </script>

        <!-- Section: System Health (Error Logger) -->
        <?php
        $cc_logger = $GLOBALS['cc_error_logger'] ?? null;
        if ($cc_logger instanceof \ContentCore\Admin\ErrorLogger):
            $err_stats = $cc_logger->get_stats(86400);         // last 24h
            $err_last_ts = $cc_logger->get_last_error_time(86400); // newest entry ts
            $err_total = $err_stats['total'];
            $err_fatals = ($err_stats['by_severity']['fatal'] ?? 0) + ($err_stats['by_severity']['error'] ?? 0);
            $err_warnings = $err_stats['by_severity']['warning'] ?? 0;
            $diag_url = admin_url('admin.php?page=cc-diagnostics&tab=error-log');
            $err_ok = ($err_total === 0);
            $now_display = function_exists('current_time') ? current_time('H:i:s') : gmdate('H:i:s');
            $last_err_label = $err_last_ts > 0
                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $err_last_ts)
                : '';

            // Success notice after clearing old entries
            if (isset($_GET['cc_msg']) && $_GET['cc_msg'] === 'old_cleared'):
                ?>
                <div class="notice notice-success is-dismissible" style="margin:12px 0 0;">
                    <p><?php _e('Resolved log entries (older than 24&nbsp;h) have been cleared. Counters now reflect only recent activity.', 'content-core'); ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="cc-card" style="margin-top:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; font-size:15px; font-weight:600;">
                        <?php _e('System Status', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-<?php echo $err_ok ? 'healthy' : 'critical'; ?>">
                        <?php echo $err_ok ? esc_html__('OK', 'content-core') : esc_html__('Errors Detected', 'content-core'); ?>
                    </span>
                </div>

                <?php if ($err_ok): ?>
                    <div style="display:flex; align-items:center; gap:12px; color:var(--cc-health-healthy-text); padding:14px 0;">
                        <span class="dashicons dashicons-yes-alt" style="font-size:28px; width:28px; height:28px;"></span>
                        <div>
                            <strong
                                style="font-size:14px; display:block;"><?php _e('No errors in the last 24 hours', 'content-core'); ?></strong>
                            <span
                                style="font-size:12px; color:var(--cc-text-muted);"><?php _e('Content Core is running without issues.', 'content-core'); ?></span>
                        </div>
                    </div>
                    <div style="margin-top:8px; font-size:11px; color:var(--cc-text-muted);">
                        <?php
                        /* translators: %s = HH:MM:SS server time */
                        printf(esc_html__('Last checked: %s (server time)', 'content-core'), esc_html($now_display));
                        ?>
                    </div>
                <?php else: ?>
                    <div class="cc-grid-2" style="margin-bottom:16px;">
                        <div class="cc-data-group">
                            <span class="cc-data-label"><?php _e('Total (24h)', 'content-core'); ?></span>
                            <div class="cc-data-value"
                                style="font-size:22px; font-weight:700; color:<?php echo $err_total > 0 ? '#d63638' : 'var(--cc-health-healthy-text)'; ?>;">
                                <?php echo (int) $err_total; ?>
                            </div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-data-label"><?php _e('Fatals / Errors (24h)', 'content-core'); ?></span>
                            <div class="cc-data-value"
                                style="font-size:22px; font-weight:700; color:<?php echo $err_fatals > 0 ? '#d63638' : 'var(--cc-health-healthy-text)'; ?>;">
                                <?php echo (int) $err_fatals; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($err_warnings > 0): ?>
                        <div class="cc-data-group" style="margin-bottom:16px;">
                            <span class="cc-data-label"><?php _e('Warnings (24h)', 'content-core'); ?></span>
                            <div class="cc-data-value" style="font-size:18px; font-weight:700; color:#dba617;">
                                <?php echo (int) $err_warnings; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($last_err_label): ?>
                        <div class="cc-data-group" style="margin-bottom:16px;">
                            <span class="cc-data-label"><?php _e('Last error', 'content-core'); ?></span>
                            <div style="font-size:13px; font-weight:600; color:var(--cc-text);">
                                <?php echo esc_html($last_err_label); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="cc-divider"></div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; align-items:center;">
                    <a href="<?php echo esc_url($diag_url); ?>" class="button button-primary">
                        <span class="dashicons dashicons-admin-tools"
                            style="margin-top:3px; margin-right:5px; font-size:16px;"></span>
                        <?php _e('Open Diagnostics', 'content-core'); ?>
                    </a>
                    <?php if (!$err_ok): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('cc_error_log_action'); ?>
                            <input type="hidden" name="action" value="cc_clear_old_error_log">
                            <button type="submit" class="button"
                                onclick="return confirm('<?php echo esc_js(__('Clear all log entries older than 24 hours? Recent entries will be kept.', 'content-core')); ?>')"
                                style="color:#d63638; border-color:#d63638;">
                                <span class="dashicons dashicons-trash"
                                    style="margin-top:3px; margin-right:4px; font-size:16px;"></span>
                                <?php _e('Clear resolved entries', 'content-core'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>


        <!-- Section: Activity Log -->
        <div class="cc-card cc-card-full" style="margin-top:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0; font-size:16px; font-weight:700;">
                    <?php _e('Recent Activity Log', 'content-core'); ?>
                </h2>
                <span style="font-size:12px; color:var(--cc-text-muted);">
                    <?php _e('Showing last 50 administrative actions', 'content-core'); ?>
                </span>
            </div>

            <div style="overflow-x:auto;">
                <table class="wp-list-table widefat fixed striped" style="border:1px solid var(--cc-border); box-shadow:none;">
                    <thead>
                        <tr>
                            <th style="width:180px; font-weight:700;">
                                <?php _e('Timestamp', 'content-core'); ?>
                            </th>
                            <th style="width:120px; font-weight:700;">
                                <?php _e('User', 'content-core'); ?>
                            </th>
                            <th style="width:150px; font-weight:700;">
                                <?php _e('Action', 'content-core'); ?>
                            </th>
                            <th style="font-weight:700;">
                                <?php _e('Details', 'content-core'); ?>
                            </th>
                            <th style="width:100px; text-align:center; font-weight:700;">
                                <?php _e('Status', 'content-core'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $audit_service = new AuditService();
                        $logs = $audit_service->get_logs();

                        if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:var(--cc-text-muted);">
                                    <?php _e('No activity recorded yet.', 'content-core'); ?>
                                </td>
                            </tr>
                            <?php
                        else:
                            foreach ($logs as $log): ?>
                                <tr>
                                    <td style="font-size:12px;">
                                        <?php echo esc_html($log['timestamp']); ?>
                                    </td>
                                    <td style="font-weight:600;">
                                        <?php echo esc_html($log['user']); ?>
                                    </td>
                                    <td><code><?php echo esc_html($log['action']); ?></code></td>
                                    <td style="font-size:13px;">
                                        <?php echo esc_html($log['message']); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            class="cc-status-pill cc-status-<?php echo esc_attr($log['status'] === 'success' ? 'healthy' : ($log['status'] === 'warning' ? 'warning' : 'critical')); ?>"
                                            style="font-size:10px; padding:2px 8px;">
                                            <?php echo esc_html(ucfirst($log['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php
                            endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Error Log screen
     */
    public function render_error_log_page(): void
    {
        $logger = $GLOBALS['cc_error_logger'] ?? null;
        if (!$logger instanceof \ContentCore\Admin\ErrorLogger) {
            echo '<div class="wrap"><p>' . esc_html__('Error logger not available.', 'content-core') . '</p></div>';
            return;
        }
        $screen = new \ContentCore\Admin\ErrorLogScreen($logger);
        $screen->render();
    }

    /**
     * Render the REST API Info page
     */
    // -------------------------------------------------------------------------
    // Manage Terms page + action handler
    // -------------------------------------------------------------------------

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

    /** @deprecated No longer used — all actions go through REST API now. */
    public function handle_terms_manager_action(): void
    {
    }

    public function render_api_page(): void
    {
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1>
                    <?php _e('REST API Reference', 'content-core'); ?>
                </h1>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Introduction', 'content-core'); ?>
                </h2>
                <p>
                    <?php _e('Content Core provides dedicated, high-performance REST API endpoints for your headless application. All responses return clean, production-ready JSON.', 'content-core'); ?>
                </p>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Endpoints', 'content-core'); ?>
                </h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 300px;">
                                <?php _e('Endpoint', 'content-core'); ?>
                            </th>
                            <th>
                                <?php _e('Description', 'content-core'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/post/{type}/{id}</code>
                            </td>
                            <td>
                                <?php _e('Get a single post by ID and type, including all custom fields.', 'content-core'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/posts/{type}</code>
                            </td>
                            <td>
                                <?php _e('Query multiple posts of a specific type. Supports pagination.', 'content-core'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/options/{slug}</code>
                            </td>
                            <td>
                                <?php _e('Get all custom fields for a specific options page.', 'content-core'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Global Custom Fields Object', 'content-core'); ?>
                </h2>
                <p>
                    <?php _e('Content Core also attaches a "customFields" object to standard WordPress REST API post responses for easy integration.', 'content-core'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Handle clear expired transients via admin_post
     */
    public function handle_clear_expired_transients(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_expired_transients();

        $audit = new AuditService();
        $audit->log_action('clear_expired_transients', 'success', sprintf(__('Cleared %d expired transients.', 'content-core'), $res['count']));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=expired_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle clear ALL transients via admin_post
     */
    public function handle_clear_all_transients(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_all_transients();

        $audit = new AuditService();
        $audit->log_action('clear_all_transients', 'success', sprintf(__('Cleared ALL transients (%d items).', 'content-core'), $res['count']));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=all_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle clear plugin caches via admin_post
     */
    public function handle_clear_plugin_caches(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_content_core_caches();

        $audit = new AuditService();
        $audit->log_action('clear_plugin_caches', 'success', sprintf(__('Cleared Content Core plugin caches (%d items).', 'content-core'), $res['count']));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=cc_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle flush object cache via admin_post
     */
    public function handle_flush_object_cache(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $cache_service = new CacheService();
        $cache_service->update_last_action('object_cache', 0, 0);

        wp_cache_flush();

        $audit = new AuditService();
        $audit->log_action('flush_object_cache', 'success', __('Flushed persistent object cache.', 'content-core'));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=obj_flushed'));
        exit;
    }

    /**
     * Handle rewrite rules flushing via admin_post
     */
    public function handle_flush_rewrite_rules(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'), 403);
        }

        check_admin_referer('cc_flush_rules_nonce');

        flush_rewrite_rules();

        $audit = new AuditService();
        $audit->log_action('flush_rewrite_rules', 'success', __('Flushed WordPress rewrite rules.', 'content-core'));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=rules_flushed'));
        exit;
    }

    /**
     * Handle duplicate site options via admin_post
     */
    public function handle_duplicate_site_options(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_duplicate_site_options_nonce');

        $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
        if (empty($target_lang)) {
            wp_safe_redirect(admin_url('admin.php?page=content-core'));
            exit;
        }

        $cache_service = new CacheService();
        $target_lang_display = strtoupper($target_lang);

        if (!$cache_service->is_site_options_empty($target_lang)) {
            wp_die(sprintf(__('Site options for %s are not empty. Overwrite is not allowed.', 'content-core'), $target_lang_display));
        }

        $plugin = \ContentCore\Plugin::get_instance();
        $site_options_module = $plugin->get_module('site_options');

        $source_lang = 'de';
        if ($site_options_module && method_exists($site_options_module, 'get_options')) {
            $ml_module = $plugin->get_module('multilingual');

            if ($ml_module && method_exists($ml_module, 'is_active') && method_exists($ml_module, 'get_settings')) {
                if ($ml_module->is_active()) {
                    $settings = $ml_module->get_settings();
                    $source_lang = $settings['default_lang'] ?? 'de';
                }
            }

            $source_options = $site_options_module->get_options($source_lang);
            if (!empty($source_options)) {
                update_option("cc_site_options_{$target_lang}", $source_options);
            }
        }

        $audit = new AuditService();
        $audit->log_action('duplicate_site_options', 'success', sprintf(__('Duplicated site options from %s to %s.', 'content-core'), strtoupper($source_lang), $target_lang_display));

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=options_duplicated'));
        exit;
    }

    /**
     * Render the Diagnostics page (tabbed: Overview | Error Log)
     */
    public function render_diagnostics_page(): void
    {
        $cache_service = new CacheService();
        $report = $cache_service->get_consolidated_health_report();
        $subsystems = $report['subsystems'];
        $plugin = \ContentCore\Plugin::get_instance();
        $screen = get_current_screen();
        global $hook_suffix;

        // Determine active tab
        $active_tab = sanitize_key($_GET['tab'] ?? 'overview');
        $tab_base = admin_url('admin.php?page=cc-diagnostics');

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php _e('Diagnostics', 'content-core'); ?></h1>
                <div style="font-size:13px; color:var(--cc-text-muted);">
                    <?php echo esc_html(sprintf(__('Report generated at %s', 'content-core'), $report['checked_at'])); ?>
                </div>
            </div>

            <!-- Tab navigation -->
            <nav class="nav-tab-wrapper" style="margin-bottom:24px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'overview', $tab_base)); ?>"
                    class="nav-tab<?php echo $active_tab === 'overview' ? ' nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools"
                        style="font-size:16px; vertical-align:middle; margin-right:5px;"></span>
                    <?php _e('System Overview', 'content-core'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'error-log', $tab_base)); ?>"
                    class="nav-tab<?php echo $active_tab === 'error-log' ? ' nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-warning"
                        style="font-size:16px; vertical-align:middle; margin-right:5px;"></span>
                    <?php _e('Error Log', 'content-core'); ?>
                    <?php
                    $cc_logger_diag = $GLOBALS['cc_error_logger'] ?? null;
                    if ($cc_logger_diag instanceof \ContentCore\Admin\ErrorLogger) {
                        $diag_stats = $cc_logger_diag->get_stats(86400);
                        if ($diag_stats['total'] > 0) {
                            echo '<span style="display:inline-block; background:#d63638; color:#fff; border-radius:10px; font-size:10px; font-weight:700; padding:1px 7px; margin-left:6px; vertical-align:middle;">';
                            echo (int) $diag_stats['total'];
                            echo '</span>';
                        }
                    }
                    ?>
                </a>
            </nav>

            <?php if ($active_tab === 'error-log'): ?>

                <?php
                $cc_logger_diag = $GLOBALS['cc_error_logger'] ?? null;
                if ($cc_logger_diag instanceof \ContentCore\Admin\ErrorLogger) {
                    $error_log_screen = new \ContentCore\Admin\ErrorLogScreen($cc_logger_diag);
                    $error_log_screen->render_inline();
                } else {
                    echo '<div class="cc-card cc-card-full"><p>' . esc_html__('Error logger not available.', 'content-core') . '</p></div>';
                }
                ?>

            <?php else: ?>

                <div class="cc-dashboard-grid">
                    <!-- Section 1: Environment & Server -->
                    <div class="cc-card cc-card-full">
                        <h2 style="margin-bottom:20px; font-size:16px; font-weight:700;">
                            <?php _e('Environment & Server', 'content-core'); ?>
                        </h2>
                        <div class="cc-grid-3">
                            <div class="cc-data-group">
                                <span class="cc-data-label">
                                    <?php _e('Core Software', 'content-core'); ?>
                                </span>
                                <div class="cc-data-value">
                                    <strong>PHP:</strong> <code><?php echo PHP_VERSION; ?></code><br>
                                    <strong>WP:</strong> <code><?php echo get_bloginfo('version'); ?></code><br>
                                    <strong>CC:</strong> <code><?php echo CONTENT_CORE_VERSION; ?></code>
                                </div>
                            </div>
                            <div class="cc-data-group">
                                <span class="cc-data-label">
                                    <?php _e('WordPress Context', 'content-core'); ?>
                                </span>
                                <div class="cc-data-value">
                                    <strong>Screen ID:</strong> <code><?php echo $screen->id; ?></code><br>
                                    <strong>Base:</strong> <code><?php echo $screen->base; ?></code><br>
                                    <strong>Hook:</strong> <code><?php echo $hook_suffix; ?></code>
                                </div>
                            </div>
                            <div class="cc-data-group">
                                <span class="cc-data-label">
                                    <?php _e('Memory & Time', 'content-core'); ?>
                                </span>
                                <div class="cc-data-value">
                                    <strong>Limit:</strong> <code><?php echo ini_get('memory_limit'); ?></code><br>
                                    <strong>Exec:</strong> <code><?php echo ini_get('max_execution_time'); ?>s</code><br>
                                    <strong>Upload:</strong> <code><?php echo ini_get('upload_max_filesize'); ?></code>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Module Audit -->
                    <div class="cc-card">
                        <h2 style="margin-bottom:20px; font-size:16px; font-weight:700;">
                            <?php _e('Module Audit', 'content-core'); ?>
                        </h2>
                        <div
                            style="background:var(--cc-bg-soft); border-radius:8px; border:1px solid var(--cc-border); padding:16px;">
                            <ul style="margin:0; padding:0; list-style:none;">
                                <?php
                                $all_modules = $plugin->get_modules();
                                if ($all_modules) {
                                    ksort($all_modules);
                                    foreach ($all_modules as $id => $module): ?>
                                        <li
                                            style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--cc-border);">
                                            <span style="font-size:13px; font-weight:600;">
                                                <?php echo esc_html(ucwords(str_replace('_', ' ', $id))); ?>
                                            </span>
                                            <span class="cc-status-pill cc-status-healthy" style="font-size:10px;">
                                                <?php _e('Active', 'content-core'); ?>
                                            </span>
                                        </li>
                                        <?php
                                    endforeach;
                                } ?>

                                <?php
                                $missing = $plugin->get_missing_modules();
                                foreach ($missing as $id): ?>
                                    <li
                                        style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--cc-border); color:var(--cc-health-critical-text);">
                                        <span style="font-size:13px; font-weight:600;">
                                            <?php echo esc_html(ucwords(str_replace('_', ' ', $id))); ?>
                                        </span>
                                        <span class="cc-status-pill cc-status-critical" style="font-size:10px;">
                                            <?php _e('Failed', 'content-core'); ?>
                                        </span>
                                    </li>
                                    <?php
                                endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Section 3: REST API Probe -->
                    <div class="cc-card">
                        <h2 style="margin-bottom:20px; font-size:16px; font-weight:700;">
                            <?php _e('REST API Discovery', 'content-core'); ?>
                        </h2>
                        <div class="cc-data-group" style="margin-bottom:16px;">
                            <span class="cc-data-label">
                                <?php _e('Registered Routes', 'content-core'); ?>
                            </span>
                            <div
                                style="max-height:200px; overflow-y:auto; background:var(--cc-bg-soft); border-radius:4px; padding:10px; border:1px solid var(--cc-border); font-family:monospace; font-size:11px;">
                                <?php
                                $routes = \ContentCore\Modules\RestApi\RestApiModule::get_registered_routes();
                                if (empty($routes) && \ContentCore\Modules\RestApi\RestApiModule::get_last_error()):
                                    echo '<span style="color:#d63638;">' . esc_html(\ContentCore\Modules\RestApi\RestApiModule::get_last_error()) . '</span>';
                                elseif (empty($routes)):
                                    _e('No routes found in this namespace.', 'content-core');
                                else:
                                    foreach ($routes as $route):
                                        echo 'GET ' . esc_html($route) . '<br>';
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-data-label">
                                <?php _e('Probe Result', 'content-core'); ?>
                            </span>
                            <div class="cc-data-value">
                                <strong>Namespace:</strong>
                                <?php echo $subsystems['rest_api']['data']['namespace_registered'] ? 'Registered' : 'Missing'; ?><br>
                                <strong>Method:</strong>
                                Internal Audit
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Raw Report -->
                    <div class="cc-card cc-card-full">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                            <h2 style="margin:0; font-size:16px; font-weight:700;">
                                <?php _e('Raw Health Report', 'content-core'); ?>
                            </h2>
                            <button type="button" class="button button-secondary" onclick="copyToClipboard('cc-raw-report')">
                                <span class="dashicons dashicons-clipboard"
                                    style="font-size:16px; width:16px; height:16px; margin-top:2px;"></span>
                                <?php _e('Copy JSON', 'content-core'); ?>
                            </button>
                        </div>
                        <textarea id="cc-raw-report" readonly
                            style="width:100%; height:200px; font-family:monospace; font-size:12px; background:var(--cc-bg-soft); border:1px solid var(--cc-border); padding:15px;"><?php echo esc_textarea(json_encode($report, JSON_PRETTY_PRINT)); ?></textarea>
                        <p style="font-size:12px; color:var(--cc-text-muted); margin-top:10px;">
                            <?php _e('This JSON report contains all gathered health data. Useful for debugging or providing to support.', 'content-core'); ?>
                        </p>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <script>
            function copyToClipboard(id) {
                var copyText = document.getElementById(id);
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value).then(() => {
                    const btn = event.target || document.querySelector('[onclick="copyToClipboard(\'' + id + '\')"]');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<?php echo esc_js(__('Copied!', 'content - core')); ?>';
                    setTimeout(() => { btn.innerHTML = originalText; }, 2000);
                });
            }
        </script>
        <?php
    }

    /**
     * Handle health cache refresh via admin_post
     */
    public function handle_refresh_health(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(__('Invalid request method.', 'content-core'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_refresh_health_nonce');

        $cache_service = new CacheService();
        $cache_service->clear_health_cache();

        $audit = new AuditService();
        $audit->log_action('refresh_health', 'success', __('Refreshed system health diagnostic cache.', 'content-core'));

        wp_redirect(admin_url('admin.php?page=content-core&cc_action=health_refreshed'));
        exit;
    }

    /**
     * Fix missing language meta for all posts
     */
    public function handle_fix_missing_languages(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_fix_languages_nonce');

        $cache_service = new CacheService();
        $count = $cache_service->fix_missing_language_meta();

        $cache_service->clear_health_cache();

        wp_safe_redirect(add_query_arg([
            'page' => 'content-core',
            'cc_action' => 'meta_fixed',
            'cc_count' => $count
        ], admin_url('admin.php')));
        exit;
    }
}