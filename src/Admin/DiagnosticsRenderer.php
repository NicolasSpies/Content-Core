<?php
namespace ContentCore\Admin;

use ContentCore\Admin\CacheService;
use ContentCore\Modules\Diagnostics\RuntimeAuditRenderer;

/**
 * Class DiagnosticsRenderer
 *
 * Encapsulates the rendering logic for the Diagnostics page.
 */
class DiagnosticsRenderer
{
    /**
     * Render the Diagnostics page
     */
    public function render(): void
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
        <div class="cc-page">
            <div class="cc-header">
                <div>
                    <h1>
                        <?php _e('Diagnostics', 'content-core'); ?>
                    </h1>
                    <p class="cc-header-desc">
                        <?php echo esc_html(sprintf(__('Report generated at %s', 'content-core'), $report['checked_at'])); ?>
                    </p>
                </div>
            </div>

            <!-- Tab navigation -->
            <nav class="cc-tabs">
                <a href="<?php echo esc_url(add_query_arg('tab', 'overview', $tab_base)); ?>"
                    class="cc-tab<?php echo $active_tab === 'overview' ? ' is-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php _e('System Overview', 'content-core'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'error-log', $tab_base)); ?>"
                    class="cc-tab<?php echo $active_tab === 'error-log' ? ' is-active' : ''; ?>">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e('Error Log', 'content-core'); ?>
                    <?php
                    $cc_logger_diag = \ContentCore\Plugin::get_instance()->get_error_logger();
                    if ($cc_logger_diag instanceof \ContentCore\Admin\ErrorLogger) {
                        $diag_stats = $cc_logger_diag->get_stats(86400);
                        if ($diag_stats['total'] > 0) {
                            echo '<span class="cc-tab-pill">';
                            echo (int) $diag_stats['total'];
                            echo '</span>';
                        }
                    }
                    ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'system-health', $tab_base)); ?>"
                    class="cc-tab<?php echo $active_tab === 'system-health' ? ' is-active' : ''; ?>">
                    <span class="dashicons dashicons-shield"></span>
                    <?php _e('System Health', 'content-core'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'runtime-audit', $tab_base)); ?>"
                    class="cc-tab<?php echo $active_tab === 'runtime-audit' ? ' is-active' : ''; ?>">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php _e('Runtime Audit', 'content-core'); ?>
                </a>
            </nav>

            <?php if ($active_tab === 'system-health'): ?>
                <div class="cc-grid">
                    <?php foreach ($subsystems as $id => $sub):
                        $status_class = 'cc-status-healthy';
                        if ($sub['status'] === 'warning')
                            $status_class = 'cc-status-warning';
                        if ($sub['status'] === 'critical')
                            $status_class = 'cc-status-critical';
                        ?>
                        <div class="cc-card">
                            <div class="cc-card-header">
                                <h2><?php echo esc_html($sub['label']); ?></h2>
                                <span class="cc-status-pill <?php echo $status_class; ?>">
                                    <?php echo esc_html(strtoupper($sub['status'])); ?>
                                </span>
                            </div>
                            <div class="cc-card-body">
                                <p style="font-size:13px; margin-bottom:12px; color:var(--cc-text-muted);">
                                    <?php echo esc_html($sub['message']); ?>
                                </p>

                                <?php if (!empty($sub['data'])): ?>
                                    <div class="cc-divider"></div>
                                    <div class="cc-data-list">
                                        <?php foreach ($sub['data'] as $key => $val): ?>
                                            <div class="cc-data-item">
                                                <span
                                                    class="cc-data-label-sm"><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></span>
                                                <code style="font-size:11px;"><?php
                                                if (is_bool($val))
                                                    echo $val ? 'true' : 'false';
                                                elseif (is_array($val))
                                                    echo 'Array[' . count($val) . ']';
                                                else
                                                    echo esc_html((string) $val);
                                                ?></code>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($sub['actions'])): ?>
                                    <div style="margin-top:16px; display:flex; gap:8px;">
                                        <?php foreach ($sub['actions'] as $action): ?>
                                            <button type="button" class="cc-button-secondary"
                                                onclick="if(confirm('<?php echo esc_js($action['confirm'] ?? ''); ?>')) { ccHandleDiagnosticAction('<?php echo esc_js($action['callback']); ?>'); }">
                                                <?php echo esc_html($action['label']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <script>
                    function ccHandleDiagnosticAction(callback) {
                        fetch('<?php echo esc_url(rest_url('content-core/v1/cache/rebuild')); ?>', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
                            body: JSON.stringify({ action: callback })
                        })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    window.location.reload();
                                } else {
                                    alert(data.message || 'Action failed');
                                }
                            })
                            .catch(err => alert('Network error'));
                    }
                </script>
            <?php elseif ($active_tab === 'runtime-audit'): ?>
                <?php RuntimeAuditRenderer::render(); ?>
            <?php elseif ($active_tab === 'error-log'): ?>
                <?php
                $cc_logger_diag = \ContentCore\Plugin::get_instance()->get_error_logger();
                if ($cc_logger_diag instanceof \ContentCore\Admin\ErrorLogger) {
                    $error_log_screen = new \ContentCore\Admin\ErrorLogScreen($cc_logger_diag);
                    $error_log_screen->render_inline();
                } else {
                    echo '<div class="cc-card"><div class="cc-card-body"><p>' . esc_html__('Error logger not available.', 'content-core') . '</p></div></div>';
                }
                ?>
            <?php else: ?>
                <div class="cc-grid">
                    <!-- Section 1: Environment & Server -->
                    <div class="cc-card cc-grid-full">
                        <div class="cc-card-header">
                            <h2>
                                <?php _e('Environment & Server', 'content-core'); ?>
                            </h2>
                        </div>
                        <div class="cc-card-body">
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
                    </div>

                    <!-- Section 2: Fresh Install Readiness -->
                    <div class="cc-card cc-grid-full">
                        <div class="cc-card-header">
                            <h2>
                                <?php _e('Fresh Install Readiness', 'content-core'); ?>
                            </h2>
                        </div>
                        <div class="cc-card-body">
                            <div class="cc-data-list">
                                <?php foreach ($this->build_fresh_install_checks($subsystems) as $check): ?>
                                    <div class="cc-data-item">
                                        <span class="cc-data-label-sm"><?php echo esc_html($check['label']); ?></span>
                                        <span class="cc-status-pill cc-status-<?php echo esc_attr($check['status']); ?>">
                                            <?php echo esc_html(strtoupper($check['status'])); ?>
                                        </span>
                                        <code style="font-size:11px;"><?php echo esc_html($check['detail']); ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Module Audit -->
                    <div class="cc-card">
                        <div class="cc-card-header">
                            <h2>
                                <?php _e('Module Audit', 'content-core'); ?>
                            </h2>
                        </div>
                        <div class="cc-card-body">
                            <div class="cc-data-list">
                                <?php
                                $all_modules = $plugin->get_modules();
                                if ($all_modules) {
                                    ksort($all_modules);
                                    foreach ($all_modules as $id => $module): ?>
                                        <div class="cc-data-item">
                                            <span class="cc-data-label-sm">
                                                <?php echo esc_html(ucwords(str_replace('_', ' ', $id))); ?>
                                            </span>
                                            <span class="cc-status-pill cc-status-healthy">
                                                <?php _e('Active', 'content-core'); ?>
                                            </span>
                                        </div>
                                        <?php
                                    endforeach;
                                } ?>

                                <?php
                                $missing = $plugin->get_missing_modules();
                                foreach ($missing as $id): ?>
                                    <div class="cc-data-item">
                                        <span class="cc-data-label-sm" style="color:var(--cc-error);">
                                            <?php echo esc_html(ucwords(str_replace('_', ' ', $id))); ?>
                                        </span>
                                        <span class="cc-status-pill cc-status-critical">
                                            <?php _e('Failed', 'content-core'); ?>
                                        </span>
                                    </div>
                                    <?php
                                endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: REST API Probe -->
                    <div class="cc-card">
                        <div class="cc-card-header">
                            <h2>
                                <?php _e('REST API Discovery', 'content-core'); ?>
                            </h2>
                        </div>
                        <div class="cc-card-body">
                            <div class="cc-data-group">
                                <span class="cc-data-label">
                                    <?php _e('Registered Routes', 'content-core'); ?>
                                </span>
                                <div class="cc-code-block" style="max-height:200px; overflow-y:auto;">
                                    <?php
                                    $routes = \ContentCore\Modules\RestApi\RestApiModule::get_registered_routes();
                                    if (empty($routes) && \ContentCore\Modules\RestApi\RestApiModule::get_last_error()):
                                        echo '<span style="color:var(--cc-error);">' . esc_html(\ContentCore\Modules\RestApi\RestApiModule::get_last_error()) . '</span>';
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
                            <div class="cc-divider"></div>
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
                    </div>

                    <!-- Section 5: Raw Report -->
                    <div class="cc-card cc-grid-full">
                        <div class="cc-card-header">
                            <h2>
                                <?php _e('Raw Health Report', 'content-core'); ?>
                            </h2>
                            <button type="button" class="cc-button-secondary" onclick="copyToClipboard('cc-raw-report')">
                                <span class="dashicons dashicons-clipboard"></span>
                                <?php _e('Copy JSON', 'content-core'); ?>
                            </button>
                        </div>
                        <div class="cc-card-body">
                            <textarea id="cc-raw-report" readonly class="cc-code-area"
                                style="height:200px;"><?php echo esc_textarea(json_encode($report, JSON_PRETTY_PRINT)); ?></textarea>
                            <p class="cc-help">
                                <?php _e('This JSON report contains all gathered health data. Useful for debugging or providing to support.', 'content-core'); ?>
                            </p>
                        </div>
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
                    if (btn) {
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<?php echo esc_js(__('Copied!', 'content-core')); ?>';
                        setTimeout(() => { btn.innerHTML = originalText; }, 2000);
                    }
                });
            }
        </script>
        <?php
    }

    private function build_fresh_install_checks(array $subsystems): array
    {
        $checks = [];
        $settings_module = \ContentCore\Plugin::get_instance()->get_module('settings');
        $rest_ok = !empty($subsystems['rest_api']['data']['namespace_registered']);
        $routes = (int) ($subsystems['rest_api']['data']['route_count'] ?? 0);

        $checks[] = [
            'label' => __('REST namespace', 'content-core'),
            'status' => $rest_ok ? 'healthy' : 'critical',
            'detail' => $rest_ok ? sprintf(__('%d routes registered', 'content-core'), $routes) : __('Namespace missing', 'content-core'),
        ];

        $keys = [
            \ContentCore\Modules\Settings\SettingsModule::SEO_KEY,
            \ContentCore\Modules\Settings\SettingsModule::COOKIE_KEY,
            'cc_site_images',
            'cc_languages_settings',
        ];

        $schema_ok = true;
        foreach ($keys as $key) {
            if (!($settings_module instanceof \ContentCore\Modules\Settings\SettingsModule)) {
                $schema_ok = false;
                break;
            }
            $schema = $settings_module->get_registry()->get_schema($key);
            if (!is_array($schema) || !isset($schema['default']) || !is_array($schema['default'])) {
                $schema_ok = false;
                break;
            }
        }

        $checks[] = [
            'label' => __('Settings defaults', 'content-core'),
            'status' => $schema_ok ? 'healthy' : 'critical',
            'detail' => $schema_ok ? __('All core settings have defaults', 'content-core') : __('Missing schema/defaults', 'content-core'),
        ];

        $asset_paths = [
            \CONTENT_CORE_PATH . 'assets/js/toast.js',
            \CONTENT_CORE_PATH . 'assets/js/settings.js',
            \CONTENT_CORE_PATH . 'assets/css/admin.css',
        ];
        $assets_ok = true;
        foreach ($asset_paths as $path) {
            if (!file_exists($path)) {
                $assets_ok = false;
                break;
            }
        }

        $checks[] = [
            'label' => __('Required assets', 'content-core'),
            'status' => $assets_ok ? 'healthy' : 'critical',
            'detail' => $assets_ok ? __('All required files found', 'content-core') : __('One or more files missing', 'content-core'),
        ];

        $languages = get_option('cc_languages_settings', []);
        $language_format_ok = true;
        if (is_array($languages) && isset($languages['languages'])) {
            $language_format_ok = is_array($languages['languages']);
        }
        $checks[] = [
            'label' => __('Legacy language format', 'content-core'),
            'status' => $language_format_ok ? 'healthy' : 'warning',
            'detail' => $language_format_ok ? __('No legacy mismatch detected', 'content-core') : __('Languages payload requires normalization', 'content-core'),
        ];

        $checks[] = [
            'label' => __('Permissions', 'content-core'),
            'status' => current_user_can('manage_options') ? 'healthy' : 'critical',
            'detail' => current_user_can('manage_options') ? __('Current user can manage plugin settings', 'content-core') : __('Missing manage_options capability', 'content-core'),
        ];

        return $checks;
    }
}
