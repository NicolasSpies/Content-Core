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
        $rest_api_status = (is_array($subsystems['rest_api'] ?? null)) ? $subsystems['rest_api'] : [];
        $rest_api_data = is_array($rest_api_status['data'] ?? null) ? $rest_api_status['data'] : [];
        $rest_api_base_url = (string) ($rest_api_data['base_url'] ?? '');
        $rest_api_route_count = (int) ($rest_api_data['route_count'] ?? 0);
        $rest_api_namespace = (string) parse_url($rest_api_base_url, PHP_URL_PATH);
        $plugin_version = $plugin->get_version();
        $settings_scan = $this->build_settings_completeness_scan();
        ?>
        <div class="content-core-admin cc-page">
            <div class="cc-header">
                <h1><?php _e('Dashboard', 'content-core'); ?></h1>
                <p class="cc-page-description">
                    <?php _e('System status overview for Content Core modules.', 'content-core'); ?>
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
                    <div class="cc-health-header-actions">
                        <span
                            class="cc-status-pill cc-health-score cc-status-<?php echo esc_attr($health_report['overall_status'] ?? 'healthy'); ?>">
                            <?php echo esc_html($health_report['health_index'] ?? 100); ?> / 100
                        </span>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
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
                <div class="cc-card-body">
                    <div class="cc-grid cc-grid-3">
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Core Version', 'content-core'); ?></span>
                            <div class="cc-data-value">v<?php echo esc_html($plugin_version); ?></div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('PHP Version', 'content-core'); ?></span>
                            <div class="cc-data-value"><?php echo PHP_VERSION; ?></div>
                        </div>
                        <div class="cc-data-group">
                            <span class="cc-field-label"><?php _e('Last Health Check', 'content-core'); ?></span>
                            <div class="cc-data-value"><?php echo esc_html($health_report['checked_at']); ?></div>
                        </div>
                    </div>
                    <div class="cc-health-rest-status">
                        <span class="cc-field-label"><?php _e('REST API Status', 'content-core'); ?></span>
                        <div class="cc-health-rest-status-row">
                            <span
                                class="cc-status-pill cc-status-<?php echo esc_attr((string) ($rest_api_status['status'] ?? 'warning')); ?>">
                                <?php echo esc_html((string) ($rest_api_status['short_label'] ?? __('Unknown', 'content-core'))); ?>
                            </span>
                            <span class="cc-health-rest-meta">
                                <?php echo esc_html(sprintf(__('%d routes', 'content-core'), $rest_api_route_count)); ?>
                            </span>
                            <?php if ($rest_api_namespace !== ''): ?>
                                <code class="cc-health-rest-namespace"><?php echo esc_html($rest_api_namespace); ?></code>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cc-api-info')); ?>"
                                class="cc-button-secondary cc-health-rest-link">
                                <?php _e('Open REST API Reference', 'content-core'); ?>
                            </a>
                        </div>
                    </div>

                </div>
            </div>

            <div class="cc-grid">
                <!-- Section: System & Runtime Status -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-rest-api"></span>
                            <?php _e('System & Runtime Status', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div class="cc-grid cc-grid-3 cc-runtime-grid">
                            <?php foreach ($subsystems as $key => $sub): ?>
                                <div class="cc-data-group cc-runtime-tile">
                                    <span class="cc-field-label cc-runtime-tile-label">
                                        <?php echo esc_html($sub['label'] ?? ucfirst(str_replace('_', ' ', $key))); ?>
                                    </span>
                                    <?php
                                    $ml_data = ($key === 'multilingual' && !empty($sub['data']) && is_array($sub['data'])) ? $sub['data'] : [];
                                    $ml_default = strtoupper((string) ($ml_data['default_lang'] ?? ''));
                                    $ml_langs = array_map(function ($lang) {
                                        return strtoupper((string) $lang);
                                    }, (array) ($ml_data['enabled_languages'] ?? []));
                                    $ml_coverage = (array) ($ml_data['coverage_by_language'] ?? []);
                                    $ml_langs = array_values(array_filter(array_unique($ml_langs)));

                                    if (!empty($ml_default) && in_array($ml_default, $ml_langs, true)) {
                                        $ml_langs = array_merge([$ml_default], array_values(array_diff($ml_langs, [$ml_default])));
                                    }
                                    ?>
                                    <?php if ($key === 'multilingual' && !empty($ml_langs)): ?>
                                        <div class="cc-language-pill-group">
                                            <?php foreach ($ml_langs as $lang): ?>
                                                <?php
                                                $lang_key = strtolower($lang);
                                                $coverage = is_array($ml_coverage[$lang_key] ?? null) ? $ml_coverage[$lang_key] : [];
                                                $coverage_status = (string) ($coverage['status'] ?? 'healthy');
                                                $coverage_total = (int) ($coverage['total'] ?? 0);
                                                $coverage_translated = (int) ($coverage['translated'] ?? 0);
                                                $coverage_by_type = is_array($coverage['by_type'] ?? null) ? $coverage['by_type'] : [];

                                                $lang_class = 'cc-status-healthy';
                                                if ($coverage_status === 'warning') {
                                                    $lang_class = 'cc-status-warning';
                                                } elseif ($coverage_status === 'critical') {
                                                    $lang_class = 'cc-status-error';
                                                }

                                                $title = '';
                                                if ($coverage_total > 0) {
                                                    $type_parts = [];
                                                    foreach ($coverage_by_type as $type => $type_data) {
                                                        $type_total = (int) ($type_data['total'] ?? 0);
                                                        if ($type_total <= 0) {
                                                            continue;
                                                        }
                                                        $type_parts[] = sprintf(
                                                            '%s %d/%d',
                                                            strtoupper((string) $type),
                                                            (int) ($type_data['translated'] ?? 0),
                                                            $type_total
                                                        );
                                                    }

                                                    $title = sprintf(
                                                        __('%1$s: %2$d/%3$d published translations', 'content-core'),
                                                        $lang,
                                                        $coverage_translated,
                                                        $coverage_total
                                                    );
                                                    if (!empty($type_parts)) {
                                                        $title .= ' | ' . implode(', ', $type_parts);
                                                    }
                                                }
                                                ?>
                                                <span class="cc-status-pill cc-runtime-lang-pill <?php echo esc_attr($lang_class); ?>"
                                                    <?php echo $title !== '' ? 'title="' . esc_attr($title) . '"' : ''; ?>>
                                                    <?php echo esc_html($lang); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="cc-status-pill cc-runtime-status-pill cc-status-<?php echo esc_attr($sub['status']); ?>">
                                            <?php echo esc_html($sub['short_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php
                            $object_cache_enabled = !empty($snapshot['object_cache']['enabled']);
                            $object_cache_dropin = !empty($snapshot['object_cache']['dropin']);
                            $object_cache_status = $object_cache_enabled ? 'healthy' : 'warning';
                            $object_cache_label = $object_cache_enabled ? __('Active', 'content-core') : __('Not configured', 'content-core');
                            if (!$object_cache_enabled && $object_cache_dropin) {
                                $object_cache_label = __('Drop-in found', 'content-core');
                            }
                            ?>
                            <div class="cc-data-group cc-runtime-tile">
                                <span class="cc-field-label cc-runtime-tile-label">
                                    <?php _e('Object Cache', 'content-core'); ?>
                                </span>
                                <span
                                    class="cc-status-pill cc-runtime-status-pill cc-status-<?php echo esc_attr($object_cache_status); ?>">
                                    <?php echo esc_html($object_cache_label); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section: Settings Completeness -->
                <div class="cc-card">
                    <div class="cc-card-header">
                        <h2>
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Settings Completeness', 'content-core'); ?>
                        </h2>
                    </div>
                    <div class="cc-card-body">
                        <div class="cc-grid cc-grid-2 cc-settings-grid">
                            <?php foreach ($settings_scan as $row):
                                $status = $row['status'];
                                $status_label = $status === 'success' ? __('Success', 'content-core') : __('Missing', 'content-core');
                                $badge_class = $status === 'success' ? 'cc-status-healthy' : 'cc-status-warning';
                                $missing_text = !empty($row['missing']) ? implode(', ', $row['missing']) : __('None', 'content-core');
                                $link_url = isset($row['url']) ? (string) $row['url'] : '';
                                ?>
                                <div class="cc-data-group cc-settings-card">
                                    <?php if ($link_url !== ''): ?>
                                        <a class="cc-settings-card-link" href="<?php echo esc_url($link_url); ?>">
                                        <?php endif; ?>
                                        <div class="cc-settings-card-head">
                                            <span class="cc-field-label cc-settings-card-label">
                                                <?php echo esc_html($row['label']); ?>
                                            </span>
                                            <span class="cc-status-pill <?php echo esc_attr($badge_class); ?> cc-settings-card-pill">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        </div>
                                        <div
                                            class="cc-settings-card-missing <?php echo $status === 'success' ? 'is-success' : 'is-missing'; ?>">
                                            <?php echo esc_html($missing_text); ?>
                                        </div>
                                        <?php if ($link_url !== ''): ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($link_url === ''): ?>
                                        <div class="cc-settings-card-head">
                                            <span class="cc-field-label cc-settings-card-label">
                                                <?php echo esc_html($row['label']); ?>
                                            </span>
                                            <span class="cc-status-pill <?php echo esc_attr($badge_class); ?> cc-settings-card-pill">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        </div>
                                        <div
                                            class="cc-settings-card-missing <?php echo $status === 'success' ? 'is-success' : 'is-missing'; ?>">
                                            <?php echo esc_html($missing_text); ?>
                                        </div>
                                    <?php endif; ?>
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
                                <div
                                    class="cc-data-value <?php echo (int) ($snapshot['expired']['count'] ?? 0) > 0 ? 'cc-cache-expired-has-data' : ''; ?>">
                                    <?php echo (int) $snapshot['expired']['count']; ?>
                                    <small>(<?php echo $format_bytes($snapshot['expired']['bytes']); ?>)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="cc-card-footer">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="cc_clear_plugin_caches">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="cc-button-secondary">
                                <?php _e('Clear CC Cache', 'content-core'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="cc_clear_expired_transients">
                            <?php wp_nonce_field('cc_cache_nonce'); ?>
                            <button type="submit" class="cc-button-secondary">
                                <?php _e('Clear Expired', 'content-core'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="cc_rebuild_runtime_cache">
                            <?php wp_nonce_field('cc_rebuild_cache_nonce'); ?>
                            <button type="submit" class="cc-button-primary">
                                <span class="dashicons dashicons-hammer"></span>
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
                    <div class="cc-card-body">
                        <?php
                        $audit_service = new \ContentCore\Admin\AuditService();
                        $logs = array_filter($audit_service->get_logs(), function ($log) {
                            $action = (string) ($log['action'] ?? '');
                            return !in_array($action, ['refresh_health'], true);
                        });
                        $logs = array_slice(array_values($logs), 0, 5);
                        if (empty($logs)): ?>
                            <div>
                                <?php _e('No activity recorded.', 'content-core'); ?>
                            </div>
                        <?php else: ?>
                            <div class="cc-table-wrap">
                                <table class="cc-table cc-table-flush">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Action', 'content-core'); ?></th>
                                            <th><?php _e('Time', 'content-core'); ?></th>
                                            <th><?php _e('Status', 'content-core'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?php echo esc_html($log['action']); ?></td>
                                                <td>
                                                    <?php echo esc_html($log['timestamp']); ?>
                                                </td>
                                                <td>
                                                    <span
                                                        class="cc-status-pill cc-status-<?php echo ($log['status'] ?? '') === 'success' ? 'healthy' : 'warning'; ?>"
                                                       >
                                                        <?php echo esc_html(ucfirst($log['status'] ?? 'Info')); ?>
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

            </div>
        </div>
        <?php
    }

    /**
     * Build a completeness scan for React settings modules.
     */
    private function build_settings_completeness_scan(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $settings_module = $plugin->get_module('settings');
        if (!($settings_module instanceof \ContentCore\Modules\Settings\SettingsModule)) {
            return [];
        }

        $registry = $settings_module->get_registry();

        $seo = $registry->get(\ContentCore\Modules\Settings\SettingsModule::SEO_KEY);
        $images = $registry->get('cc_site_images');
        $cookie = $registry->get(\ContentCore\Modules\Settings\SettingsModule::COOKIE_KEY);
        $languages = $registry->get('cc_languages_settings');
        $site_options_missing = $this->collect_site_options_missing();

        $scan = [
            [
                'label' => __('SEO', 'content-core'),
                'url' => admin_url('admin.php?page=cc-seo'),
                'missing' => $this->collect_missing($seo, [
                    'title' => 'title',
                    'description' => 'description',
                ]),
            ],
            [
                'label' => __('Cookie Banner', 'content-core'),
                'url' => admin_url('admin.php?page=cc-cookie-banner'),
                'missing' => !empty($cookie['enabled']) ? $this->collect_missing($cookie, [
                    'bannerTitle' => 'banner title',
                    'bannerText' => 'banner text',
                    'labels.acceptAll' => 'accept label',
                    'labels.rejectAll' => 'reject label',
                    'labels.settings' => 'settings label',
                ]) : [],
            ],
            [
                'label' => __('Multilingual', 'content-core'),
                'url' => admin_url('admin.php?page=cc-multilingual'),
                'missing' => !empty($languages['enabled']) ? $this->collect_multilingual_missing($languages) : [],
            ],
            [
                'label' => __('Site Profile', 'content-core'),
                'url' => admin_url('admin.php?page=cc-site-options'),
                'missing' => $site_options_missing,
            ],
        ];

        foreach ($scan as &$row) {
            $row['status'] = empty($row['missing']) ? 'success' : 'missing';
        }

        if ((int) ($images['social_icon_id'] ?? 0) <= 0 && isset($scan[0])) {
            $scan[0]['missing'][] = 'favicon';
            $scan[0]['status'] = 'missing';
        }

        return $scan;
    }

    /**
     * Resolve required paths and return missing labels.
     */
    private function collect_missing(array $data, array $requirements, bool $numeric_as_required = false): array
    {
        $missing = [];
        foreach ($requirements as $path => $label) {
            $value = $this->get_path_value($data, $path);
            if ($this->is_missing_value($value, $numeric_as_required)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    private function collect_multilingual_missing(array $languages): array
    {
        $missing = [];
        if ($this->is_missing_value($languages['default_lang'] ?? null)) {
            $missing[] = 'default language';
        }

        $list = $languages['languages'] ?? [];
        if (!is_array($list) || empty($list)) {
            $missing[] = 'languages';
            return $missing;
        }

        foreach ($list as $idx => $lang) {
            if (!is_array($lang)) {
                $missing[] = 'language #' . ($idx + 1);
                continue;
            }
            if ($this->is_missing_value($lang['code'] ?? null)) {
                $missing[] = 'language code #' . ($idx + 1);
            }
            if ($this->is_missing_value($lang['label'] ?? null)) {
                $missing[] = 'language label #' . ($idx + 1);
            }
        }

        return array_values(array_unique($missing));
    }

    private function get_path_value(array $data, string $path)
    {
        $parts = explode('.', $path);
        $current = $data;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private function collect_site_options_missing(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $site_options_module = $plugin->get_module('site_options');
        if (!($site_options_module instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule)) {
            return ['module inactive'];
        }

        $ml_module = $plugin->get_module('multilingual');
        $languages = ['de'];
        if (
            $ml_module instanceof \ContentCore\Modules\Multilingual\MultilingualModule &&
            $ml_module->is_active()
        ) {
            $settings = $ml_module->get_settings();
            $codes = [];
            foreach ((array) ($settings['languages'] ?? []) as $key => $language) {
                if (is_array($language) && !empty($language['code'])) {
                    $codes[] = sanitize_key((string) $language['code']);
                    continue;
                }
                if (is_string($language) && $language !== '') {
                    $codes[] = sanitize_key($language);
                    continue;
                }
                if (is_string($key) && $key !== '') {
                    $codes[] = sanitize_key($key);
                }
            }
            $codes = array_values(array_filter(array_unique($codes)));
            if (!empty($codes)) {
                $languages = $codes;
            }
        }

        $missing = [];
        foreach ($languages as $lang) {
            $options = $site_options_module->get_options((string) $lang);
            if (empty($options)) {
                $missing[] = 'options ' . strtoupper((string) $lang);
            }
        }

        return $missing;
    }

    private function is_missing_value($value, bool $numeric_as_required = false): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if ($numeric_as_required && (is_int($value) || is_float($value))) {
            return (int) $value <= 0;
        }

        return false;
    }
}
