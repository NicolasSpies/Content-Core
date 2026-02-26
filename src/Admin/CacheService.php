<?php
namespace ContentCore\Admin;

use ContentCore\Plugin;

class CacheService
{
    const LAST_ACTIONS_OPTION = 'cc_cache_last_actions';
    const HEALTH_CACHE_TRANSIENT = 'cc_health_cache';
    const HEALTH_CACHE_TTL = 300;
    const BATCH_SIZE = 100;

    private function get_last_actions(): array
    {
        $actions = get_option(self::LAST_ACTIONS_OPTION, []);
        return is_array($actions) ? $actions : [];
    }

    private function update_last_action(string $action, int $count, int $bytes): void
    {
        $actions = $this->get_last_actions();
        $actions[$action] = [
            'count' => $count,
            'bytes' => $bytes,
            'timestamp' => current_time('mysql'),
        ];
        update_option(self::LAST_ACTIONS_OPTION, $actions);
    }

    public function get_last_action_info(string $action): ?array
    {
        $actions = $this->get_last_actions();
        return $actions[$action] ?? null;
    }

    /**
     * Get a snapshot of current cache sizes and counts.
     */
    public function get_snapshot(): array
    {
        global $wpdb;

        // 1. Regular Transients
        $transients = $wpdb->get_row("
            SELECT 
                COUNT(*) as count, 
                SUM(LENGTH(option_value)) as bytes 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_%' 
            AND option_name NOT LIKE '_transient_timeout_%'
        ", ARRAY_A);

        // 2. Expired Transients
        $now = time();
        $expired = $wpdb->get_results($wpdb->prepare("
            SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_timeout_%' 
            AND CAST(option_value AS UNSIGNED) < %d
        ", $now), ARRAY_A);

        $expired_count = count($expired);
        $expired_bytes = 0;
        if ($expired_count > 0) {
            $expired_names = array_map(function ($row) {
                return str_replace('_transient_timeout_', '_transient_', $row['option_name']);
            }, $expired);

            $placeholders = implode(',', array_fill(0, count($expired_names), '%s'));
            $expired_bytes = $wpdb->get_var($wpdb->prepare("
                SELECT SUM(LENGTH(option_value)) 
                FROM {$wpdb->options} 
                WHERE option_name IN ($placeholders)
            ", ...$expired_names));
        }

        // 3. Content Core Caches
        // We look for transients with 'cc_' or 'content_core_' prefix and options like 'cc_cache_'
        $cc_cache = $wpdb->get_row("
            SELECT 
                COUNT(*) as count, 
                SUM(LENGTH(option_value)) as bytes 
            FROM {$wpdb->options} 
            WHERE (option_name LIKE '_transient_cc_%' OR option_name LIKE '_transient_content_core_%')
            OR (option_name LIKE 'cc_cache_%' OR option_name LIKE 'cc_rest_cache_%' OR option_name LIKE 'cc_schema_cache_%')
        ", ARRAY_A);

        return [
            'transients' => [
                'count' => (int)($transients['count'] ?? 0),
                'bytes' => (int)($transients['bytes'] ?? 0),
            ],
            'expired' => [
                'count' => $expired_count,
                'bytes' => (int)($expired_bytes ?? 0),
            ],
            'cc_cache' => [
                'count' => (int)($cc_cache['count'] ?? 0),
                'bytes' => (int)($cc_cache['bytes'] ?? 0),
            ],
            'object_cache' => [
                'enabled' => wp_using_ext_object_cache(),
                'dropin' => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
            ]
        ];
    }

    /**
     * Clear only expired transients.
     */
    public function clear_expired_transients(): array
    {
        global $wpdb;
        $now = time();
        $start_snapshot = $this->get_snapshot();

        $count = 0;
        $deleted_bytes = 0;

        while (true) {
            $expired = $wpdb->get_results($wpdb->prepare("
                SELECT option_name, option_value
                FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_timeout_%' 
                AND CAST(option_value AS UNSIGNED) < %d
                LIMIT %d
            ", $now, self::BATCH_SIZE), ARRAY_A);

            if (empty($expired)) {
                break;
            }

            foreach ($expired as $row) {
                $timeout_key = $row['option_name'];
                $transient_key = str_replace('_transient_timeout_', '', $timeout_key);
                $transient_timeout_key = '_transient_timeout_' . str_replace('_transient_', '', $transient_key);

                $deleted_bytes += strlen($row['option_value']);

                delete_transient($transient_key);
                $count++;
            }

            if (count($expired) < self::BATCH_SIZE) {
                break;
            }
        }

        $end_snapshot = $this->get_snapshot();
        $result = [
            'count' => $count,
            'bytes' => max(0, $deleted_bytes)
        ];

        $this->update_last_action('expired_transients', $count, $result['bytes']);

        return $result;
    }

    /**
     * Clear ALL transients (dangerous).
     */
    public function clear_all_transients(): array
    {
        global $wpdb;
        $start_snapshot = $this->get_snapshot();
        $total_count = 0;
        $total_bytes = 0;

        // Delete regular transients in batches
        while (true) {
            $before_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%'");
            $before_bytes = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%'");

            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_%%' 
                OR option_name LIKE '_transient_timeout_%%'
                LIMIT %d
            ", self::BATCH_SIZE));

            $total_count += $deleted;
            if ($before_bytes) {
                $after_bytes = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%'");
                $total_bytes += max(0, (int)$before_bytes - (int)$after_bytes);
            }

            if ($deleted < self::BATCH_SIZE) {
                break;
            }
        }

        // Delete site transients in batches
        while (true) {
            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_site_transient_%%' 
                OR option_name LIKE '_site_transient_timeout_%%'
                LIMIT %d
            ", self::BATCH_SIZE));

            $total_count += $deleted;

            if ($deleted < self::BATCH_SIZE) {
                break;
            }
        }

        $result = [
            'count' => $total_count,
            'bytes' => $total_bytes
        ];

        $this->update_last_action('all_transients', $total_count, $total_bytes);

        return $result;
    }

    /**
     * Clear Content Core specific caches.
     */
    public function clear_content_core_caches(): array
    {
        global $wpdb;
        $start_snapshot = $this->get_snapshot();

        $count = 0;
        $deleted_bytes = 0;

        while (true) {
            $before_bytes = $wpdb->get_var("
                SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} 
                WHERE (option_name LIKE '_transient_cc_%' OR option_name LIKE '_transient_content_core_%')
                OR (option_name LIKE '_transient_timeout_cc_%' OR option_name LIKE '_transient_timeout_content_core_%')
                OR (option_name LIKE 'cc_cache_%' OR option_name LIKE 'cc_rest_cache_%' OR option_name LIKE 'cc_schema_cache_%')
            ");

            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE (option_name LIKE '_transient_cc_%%' OR option_name LIKE '_transient_content_core_%%')
                OR (option_name LIKE '_transient_timeout_cc_%%' OR option_name LIKE '_transient_timeout_content_core_%%')
                OR (option_name LIKE 'cc_cache_%%' OR option_name LIKE 'cc_rest_cache_%%' OR option_name LIKE 'cc_schema_cache_%%')
                LIMIT %d
            ", self::BATCH_SIZE));

            $count += $deleted;

            if ($before_bytes) {
                $after_bytes = $wpdb->get_var("
                    SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} 
                    WHERE (option_name LIKE '_transient_cc_%' OR option_name LIKE '_transient_content_core_%')
                    OR (option_name LIKE '_transient_timeout_cc_%' OR option_name LIKE '_transient_timeout_content_core_%')
                    OR (option_name LIKE 'cc_cache_%' OR option_name LIKE 'cc_rest_cache_%' OR option_name LIKE 'cc_schema_cache_%')
                ");
                $deleted_bytes += max(0, (int)$before_bytes - (int)$after_bytes);
            }

            if ($deleted < self::BATCH_SIZE) {
                break;
            }
        }

        $result = [
            'count' => $count,
            'bytes' => $deleted_bytes
        ];

        $this->update_last_action('cc_caches', $count, $deleted_bytes);

        return $result;
    }

    public function get_system_status(): array
    {
        $status = 'healthy';
        $issues = [];
        $php_version = PHP_VERSION;
        $wp_version = get_bloginfo('version');

        // 1. PHP Version check
        if (version_compare($php_version, '7.4', '<')) {
            $status = 'critical';
            $issues[] = sprintf(__('PHP version %s is below recommended 7.4.', 'content-core'), $php_version);
        }

        // 2. Module Load check
        $plugin = Plugin::get_instance();
        $missing = $plugin->get_missing_modules();
        if (!empty($missing)) {
            $status = 'warning';
            $issues[] = sprintf(__('%d module(s) failed to initialize.', 'content-core'), count($missing));
        }

        // 3. Optional: Check for important modules
        if (!$plugin->is_module_active('custom_fields')) {
            $status = ($status === 'healthy') ? 'warning' : $status;
            $issues[] = __('Custom Fields module is inactive.', 'content-core');
        }

        return [
            'status' => $status,
            'short_label' => sprintf(__('v%s', 'content-core'), CONTENT_CORE_VERSION),
            'message' => $status === 'healthy' ? __('System core is stable.', 'content-core') : __('System has configuration issues.', 'content-core'),
            'issues' => $issues,
            'data' => [
                'php' => $php_version,
                'wp' => $wp_version,
                'modules_missing' => $missing
            ]
        ];
    }

    public function get_multilingual_health(): array
    {
        global $wpdb;

        $plugin = Plugin::get_instance();
        $ml_module = $plugin->get_module('multilingual');

        $result = [
            'is_active' => false,
            'default_lang' => '',
            'enabled_languages' => [],
            'fallback_enabled' => false,
            'missing_lang_meta_count' => 0,
            'orphan_groups_count' => 0,
            'duplicate_collisions_count' => 0,
        ];

        if (!$ml_module || !method_exists($ml_module, 'is_active') || !$ml_module->is_active()) {
            return $result;
        }

        if (!method_exists($ml_module, 'get_settings')) {
            return $result;
        }

        $settings = $ml_module->get_settings();
        $result['is_active'] = true;
        $result['default_lang'] = $settings['default_lang'] ?? 'de';
        $result['enabled_languages'] = array_column($settings['languages'] ?? [], 'code');
        $result['fallback_enabled'] = !empty($settings['fallback_enabled']);

        $langs = $result['enabled_languages'];
        if (empty($langs)) {
            return $result;
        }

        $result['missing_lang_meta_count'] = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} p
            WHERE p.post_type NOT IN ('revision', 'attachment')
            AND p.post_status NOT IN ('trash', 'auto-draft')
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm
                WHERE pm.post_id = p.ID AND pm.meta_key = '_cc_language'
            )
            LIMIT 1000
        ");

        $result['orphan_groups_count'] = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT pm.meta_value as group_id
                FROM {$wpdb->postmeta} pm
                WHERE pm.meta_key = '_cc_translation_group'
                GROUP BY pm.meta_value
                HAVING COUNT(*) = 1
                LIMIT 100
            ) AS orphans
        ");

        $result['duplicate_collisions_count'] = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT pm.meta_value as group_id, pm2.meta_value as lang, COUNT(*) as cnt
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->postmeta} pm2 ON pm.meta_value = pm2.meta_value AND pm2.meta_key = '_cc_language'
                WHERE pm.meta_key = '_cc_translation_group'
                GROUP BY pm.meta_value, pm2.meta_value
                HAVING COUNT(*) > 1
                LIMIT 100
            ) AS duplicates
        ");

        return $result;
    }

    public function get_site_options_health(): array
    {
        global $wpdb;

        $plugin = Plugin::get_instance();
        $site_options_module = $plugin->get_module('site_options');

        $result = [
            'is_active' => false,
            'languages_with_options' => [],
            'languages_missing_options' => [],
            'translation_group_id_present' => false,
            'fallback_lang' => '',
        ];

        if (!$site_options_module) {
            return $result;
        }

        $result['is_active'] = true;

        if (method_exists($site_options_module, 'get_translation_group_id')) {
            $group_id = $site_options_module->get_translation_group_id();
            $result['translation_group_id_present'] = !empty($group_id);
        }

        $ml_module = $plugin->get_module('multilingual');
        $languages = ['de'];
        $fallback_lang = '';

        if ($ml_module && method_exists($ml_module, 'is_active') && method_exists($ml_module, 'get_settings')) {
            if ($ml_module->is_active()) {
                $settings = $ml_module->get_settings();
                $languages = array_column($settings['languages'] ?? [], 'code');
                $fallback_lang = $settings['fallback_lang'] ?? '';
            }
        }

        $result['fallback_lang'] = $fallback_lang;

        if (empty($languages)) {
            $languages = ['de'];
        }

        foreach ($languages as $lang) {
            if (method_exists($site_options_module, 'get_options')) {
                $options = $site_options_module->get_options($lang);
                if (!empty($options)) {
                    $result['languages_with_options'][] = $lang;
                }
                else {
                    $result['languages_missing_options'][] = $lang;
                }
            }
        }

        return $result;
    }

    public function get_forms_overview(): array
    {
        global $wpdb;

        $plugin = Plugin::get_instance();
        $forms_module = $plugin->get_module('forms');

        $result = [
            'is_active' => false,
            'total_forms' => 0,
            'total_entries' => 0,
            'protection' => [
                'honeypot' => false,
                'rate_limit' => false,
                'turnstile' => false,
            ],
            'last_entry_timestamp' => null,
        ];

        if (!$forms_module) {
            return $result;
        }

        $result['is_active'] = true;

        $result['total_forms'] = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cc_form' AND post_status != 'trash'
        ");

        $result['total_entries'] = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cc_form_entry' AND post_status != 'trash'
        ");

        $honeypot_forms = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'cc_form' AND p.post_status != 'trash'
            AND pm.meta_key = 'cc_form_honeypot' AND pm.meta_value = '1'
        ");
        $result['protection']['honeypot'] = (int)$honeypot_forms > 0;

        $rate_limit_forms = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'cc_form' AND p.post_status != 'trash'
            AND pm.meta_key = 'cc_form_rate_limit' AND pm.meta_value = '1'
        ");
        $result['protection']['rate_limit'] = (int)$rate_limit_forms > 0;

        $turnstile_forms = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'cc_form' AND p.post_status != 'trash'
            AND pm.meta_key = 'cc_form_turnstile' AND pm.meta_value = '1'
        ");
        $result['protection']['turnstile'] = (int)$turnstile_forms > 0;

        if ($result['total_entries'] > 0) {
            $result['last_entry_timestamp'] = $wpdb->get_var("
                SELECT MAX(p.post_date) FROM {$wpdb->posts} p
                WHERE p.post_type = 'cc_form_entry' AND post_status != 'trash'
            ");
        }

        return $result;
    }

    public function get_rest_api_health(): array
    {
        $result = [
            'namespace_registered' => false,
            'route_count' => 0,
            'base_url' => function_exists('rest_url') ? rest_url('content-core/v1') : '',
        ];

        try {
            if (!function_exists('rest_get_server')) {
                return $result;
            }

            $rest_server = rest_get_server();
            $namespaces = $rest_server->get_namespaces();

            $result['namespace_registered'] = in_array('content-core/v1', $namespaces, true);

            if ($result['namespace_registered']) {
                $routes = $rest_server->get_routes();
                foreach ($routes as $route => $handlers) {
                    if (strpos($route, 'content-core/v1') === 0) {
                        $result['route_count']++;
                    }
                }
            }
        }
        catch (\Throwable $e) {
        // Return default values on error
        }

        return $result;
    }

    /**
     * Unified Health Model Methods
     */

    public function get_multilingual_status(): array
    {
        $health = $this->get_multilingual_health();
        if (!$health['is_active']) {
            return [
                'status' => 'healthy',
                'short_label' => __('Inactive', 'content-core'),
                'message' => __('Multilingual module is not active or not required.', 'content-core'),
                'data' => $health
            ];
        }

        $status = 'healthy';
        $issues = [];

        if ($health['missing_lang_meta_count'] > 0 || $health['orphan_groups_count'] > 0) {
            $status = 'warning';
            $issues[] = sprintf(__('Found %d items with missing language meta.', 'content-core'), $health['missing_lang_meta_count']);
        }

        if ($health['duplicate_collisions_count'] > 0) {
            $status = 'critical';
            $issues[] = __('Critical translation collisions detected in database.', 'content-core');
        }

        return [
            'status' => $status,
            'short_label' => strtoupper($health['default_lang']),
            'message' => $status === 'healthy' ? __('Multilingual system is operational.', 'content-core') : $issues[0],
            'issues' => $issues,
            'data' => $health
        ];
    }

    public function get_site_options_status(): array
    {
        $health = $this->get_site_options_health();
        if (!$health['is_active']) {
            return [
                'status' => 'healthy',
                'short_label' => __('Inactive', 'content-core'),
                'message' => __('Site Options module is not active.', 'content-core'),
                'data' => $health
            ];
        }

        $status = 'healthy';
        $issues = [];

        if (!empty($health['languages_missing_options'])) {
            $status = 'warning';
            $issues[] = sprintf(__('Missing options for: %s', 'content-core'), implode(', ', $health['languages_missing_options']));
        }

        if (!$health['translation_group_id_present']) {
            $status = 'critical';
            $issues[] = __('Site Options Translation Group ID is missing.', 'content-core');
        }

        return [
            'status' => $status,
            'short_label' => sprintf(__('%d Configured', 'content-core'), count($health['languages_with_options'])),
            'message' => $status === 'healthy' ? __('Site options are correctly configured.', 'content-core') : $issues[0],
            'issues' => $issues,
            'data' => $health
        ];
    }

    public function get_forms_status(): array
    {
        $health = $this->get_forms_overview();
        if (!$health['is_active']) {
            return [
                'status' => 'healthy',
                'short_label' => __('Inactive', 'content-core'),
                'message' => __('Forms module is not active.', 'content-core'),
            ];
        }

        return [
            'status' => 'healthy',
            'short_label' => sprintf(__('%d Forms', 'content-core'), $health['total_forms']),
            'message' => sprintf(__('%d total entries recorded.', 'content-core'), $health['total_entries']),
            'data' => $health
        ];
    }

    public function get_rest_api_status(): array
    {
        $health = $this->get_rest_api_health();

        if (!$health['namespace_registered']) {
            return [
                'status' => 'critical',
                'short_label' => __('Offline', 'content-core'),
                'message' => __('REST API namespace not found. Please flush rewrite rules.', 'content-core'),
                'action_id' => 'cc_flush_rewrite_rules',
                'data' => $health
            ];
        }

        // Reachability check
        $start = microtime(true);
        $response = wp_remote_get($health['base_url'], [
            'timeout' => 5,
            'sslverify' => false
        ]);
        $duration = round((microtime(true) - $start) * 1000);

        $reachable = !is_wp_error($response);
        $http_code = $reachable ? wp_remote_retrieve_response_code($response) : 0;

        $status = 'healthy';
        $message = __('REST API is active and responding.', 'content-core');

        if (!$reachable) {
            $status = 'critical';
            $message = sprintf(__('API unreachable: %s', 'content-core'), $response->get_error_message());
        }
        elseif ($http_code !== 200) {
            $status = 'warning';
            $message = sprintf(__('API returned unexpected status code: %d', 'content-core'), $http_code);
        }

        return [
            'status' => $status,
            'short_label' => sprintf(__('%d Routes', 'content-core'), $health['route_count']),
            'message' => $message,
            'data' => array_merge($health, [
                'reachable' => $reachable,
                'http_code' => $http_code,
                'response_time' => $duration
            ])
        ];
    }

    public function get_consolidated_health_report(): array
    {
        $subsystems = [
            'system' => $this->get_system_status(),
            'multilingual' => $this->get_multilingual_status(),
            'site_options' => $this->get_site_options_status(),
            'forms' => $this->get_forms_status(),
            'rest_api' => $this->get_rest_api_status(),
        ];

        $overall_status = 'healthy';
        $issues = [];

        foreach ($subsystems as $key => $report) {
            if ($report['status'] === 'critical') {
                $overall_status = 'critical';
            }
            elseif ($report['status'] === 'warning' && $overall_status !== 'critical') {
                $overall_status = 'warning';
            }

            if ($report['status'] !== 'healthy') {
                $issues[] = $report['message'];
            }
        }

        return [
            'status' => $overall_status,
            'subsystems' => $subsystems,
            'issues' => $issues,
            'checked_at' => current_time('mysql'),
        ];
    }

    public function clear_health_cache(): void
    {
        delete_transient(self::HEALTH_CACHE_TRANSIENT);
    }

    /**
     * Check if site options for a specific language are empty.
     */
    public function is_site_options_empty(string $lang): bool
    {
        $options = get_option("cc_site_options_{$lang}", []);
        return empty($options);
    }
}