<?php
namespace ContentCore\Admin;

/**
 * Class CacheService
 *
 * Handles transient and plugin-specific cache measurement and cleanup.
 */
class CacheService
{
    const LAST_ACTIONS_OPTION = 'cc_cache_last_actions';
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
}