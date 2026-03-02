<?php
namespace ContentCore\Admin;

use ContentCore\Admin\CacheService;

/**
 * Class DashboardRenderer
 *
 * Encapsulates the rendering logic for the Content Core admin dashboard.
 */
class DashboardRenderer
{
    public function render(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $cache_service = new CacheService();
        $ml_module = $plugin->get_module('multilingual');

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
        $plugin_version = $plugin->get_version();
        ?>
        <div class="content-core-admin cc-page">
            <div class="cc-header">
                <h1><?php _e('Dashboard', 'content-core'); ?></h1>
                <p class="cc-page-description">
                    <?php _e('System status overview and quick access to Content Core modules.', 'content-core'); ?>
                </p>
            </div>

            <?php settings_errors('cc_dashboard'); ?>

            <!-- --- Top Row: Health & Stats --- -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-shield"></span>
                        <?php _e('System Health Summary', 'content-core'); ?>
                    </h2>
                    <div class="cc-card-actions">
                        <span
                            class="cc-status-pill cc-status-<?php echo esc_attr($health_report['overall_status'] ?? 'healthy'); ?>">
                            <?php echo esc_html($health_report['health_index'] ?? 100); ?> / 100
                        </span>
                    </div>
                </div>
                <div class="cc-card-body">
                    <div class="cc-grid cc-grid-5">
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Core Version', 'content-core'); ?></span>
                            <div class="cc-data-value">v<?php echo esc_html($plugin_version); ?></div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('PHP Version', 'content-core'); ?></span>
                            <div class="cc-data-value"><?php echo PHP_VERSION; ?></div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Object Cache', 'content-core'); ?></span>
                            <div class="cc-data-value">
                                <?php echo $snapshot['object_cache']['enabled'] ? '<span class="cc-status-pill cc-status-healthy">Active</span>' : '<span class="cc-status-pill cc-status-warning">Inactive</span>'; ?>
                            </div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Last Health Check', 'content-core'); ?></span>
                            <div class="cc-data-value"><?php echo esc_html($health_report['checked_at']); ?></div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Multilingual', 'content-core'); ?></span>
                            <div class="cc-data-value">
                                <?php if ($ml_module && method_exists($ml_module, 'is_active') && $ml_module->is_active() && method_exists($ml_module, 'get_active_languages')): ?>
                                    <span
                                        class="cc-status-pill cc-status-healthy"><?php echo count($ml_module->get_active_languages()); ?>
                                        <?php _e('Langs', 'content-core'); ?></span>
                                <?php else: ?>
                                    <span class="cc-status-pill cc-status-inactive"><?php _e('Inactive', 'content-core'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="cc-card-footer">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="cc_refresh_health">
                        <?php wp_nonce_field('cc_refresh_health_nonce'); ?>
                        <button type="submit" class="cc-button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh Health', 'content-core'); ?>
                        </button>
                    </form>
                    <a href="<?php echo admin_url('admin.php?page=cc-diagnostics'); ?>" class="cc-button-primary">
                        <?php _e('Detailed Diagnostics', 'content-core'); ?>
                    </a>
                </div>
            </div>

            <div class="cc-grid">
                <!-- Section: Active Modules -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-plugins-checked"></span>
                            <?php _e('Active Modules', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                            <?php
                            $active_modules = $plugin->get_active_modules();
                            foreach ($active_modules as $id => $class): ?>
                                <span class="cc-status-pill cc-status-healthy" style="padding:4px 10px;">
                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $id))); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Section: Connectivity & Subsystems -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-rest-api"></span>
                            <?php _e('Subsystem Status', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div class="cc-grid cc-grid-3" style="gap:16px;">
                            <?php foreach ($subsystems as $key => $sub): ?>
                                <div class="cc-data-group"
                                    style="padding:16px; border:1px solid var(--cc-border-light); border-radius:8px; background:var(--cc-bg-soft); text-align:center;">
                                    <span class="cc-field-label"
                                        style="display:block; margin-bottom:12px; font-size:11px; text-transform:uppercase; letter-spacing:0.05em;">
                                        <?php echo esc_html($sub['label'] ?? ucfirst(str_replace('_', ' ', $key))); ?>
                                    </span>
                                    <span class="cc-status-pill cc-status-<?php echo esc_attr($sub['status']); ?>"
                                        style="font-size:12px; padding:6px 14px;">
                                        <?php echo esc_html($sub['short_label']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- Section: Cache & Storage -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-database"></span>
                            <?php _e('Cache & Database', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div class="cc-grid">
                            <div class="cc-data-group">
                                <span class="cc-field-label"><?php _e('Total Transients', 'content-core'); ?></span>
                                <div class="cc-data-value">
                                    <?php echo (int) $snapshot['transients']['count']; ?>
                                    <small>(<?php echo $format_bytes($snapshot['transients']['bytes']); ?>)</small>
                                </div>
                            </div>
                            <div class="cc-data-group">
                                <span class="cc-field-label"><?php _e('Expired Data', 'content-core'); ?></span>
                                <div class="cc-data-value"
                                    style="color:<?php echo $snapshot['expired']['count'] > 0 ? 'var(--cc-error)' : 'inherit'; ?>">
                                    <?php echo (int) $snapshot['expired']['count']; ?>
                                    <small>(<?php echo $format_bytes($snapshot['expired']['bytes']); ?>)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="cc-card-footer">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="cc_clear_plugin_caches">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="cc-button-secondary">
                                <?php _e('Clear CC Cache', 'content-core'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="cc_clear_expired_transients">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="cc-button-secondary">
                                <?php _e('Clear Expired', 'content-core'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="cc_rebuild_runtime_cache">
                            <?php wp_nonce_field('cc_rebuild_cache_nonce'); ?>
                            <button type="submit" class="cc-button-primary">
                                <span class="dashicons dashicons-hammer" style="margin-right:4px;"></span>
                                <?php _e('Rebuild Runtime Cache', 'content-core'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Section: Activity Feed -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-backup"></span>
                            <?php _e('Recent Activity', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body" style="padding:0;">
                        <?php
                        $audit_service = new \ContentCore\Admin\AuditService();
                        $logs = array_slice($audit_service->get_logs(), 0, 5);
                        if (empty($logs)): ?>
                            <div style="padding:32px; text-align:center; color:var(--cc-text-muted);">
                                <?php _e('No activity recorded.', 'content-core'); ?>
                            </div>
                        <?php else: ?>
                            <div class="cc-table-wrap">
                                <table class="cc-table cc-table-flush">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Action', 'content-core'); ?></th>
                                            <th><?php _e('Time', 'content-core'); ?></th>
                                            <th style="text-align:right;"><?php _e('Status', 'content-core'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?php echo esc_html($log['action']); ?></td>
                                                <td style="color:var(--cc-text-muted); font-size:12px;">
                                                    <?php echo esc_html($log['timestamp']); ?>
                                                </td>
                                                <td style="text-align:right;">
                                                    <span
                                                        class="cc-status-pill cc-status-<?php echo ($log['status'] ?? '') === 'success' ? 'healthy' : 'warning'; ?>"
                                                        style="font-size:10px;">
                                                        <?php echo strtoupper($log['status'] ?? 'INFO'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Section: Quick Actions -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php _e('Quick Access', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div class="cc-grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
                            <a href="<?php echo admin_url('admin.php?page=cc-site-options'); ?>" class="cc-button-secondary"
                                style="justify-content:center;">
                                <span class="dashicons dashicons-admin-settings"></span>
                                <?php _e('Site Options', 'content-core'); ?>
                            </a>
                            <a href="<?php echo admin_url('edit.php?post_type=cc_form'); ?>" class="cc-button-secondary"
                                style="justify-content:center;">
                                <span class="dashicons dashicons-feedback"></span>
                                <?php _e('Manage Forms', 'content-core'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=cc-multilingual'); ?>" class="cc-button-secondary"
                                style="justify-content:center;">
                                <span class="dashicons dashicons-translation"></span>
                                <?php _e('Languages', 'content-core'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=cc-seo'); ?>" class="cc-button-secondary"
                                style="justify-content:center;">
                                <span class="dashicons dashicons-google"></span>
                                <?php _e('SEO Settings', 'content-core'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

}
