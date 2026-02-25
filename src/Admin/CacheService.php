<?php
namespace ContentCore\Admin;

/**
 * Class CacheService
 *
 * Handles transient and plugin-specific cache measurement and cleanup.
 */
class CacheService
{
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

        $expired = $wpdb->get_results($wpdb->prepare("
            SELECT option_name 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_timeout_%' 
            AND CAST(option_value AS UNSIGNED) < %d
        ", $now), ARRAY_A);

        $count = 0;
        foreach ($expired as $row) {
            $timeout_key = $row['option_name'];
            $transient_key = str_replace('_transient_timeout_', '', $timeout_key);
            delete_transient($transient_key);
            $count++;
        }

        $end_snapshot = $this->get_snapshot();
        return [
            'count' => $count,
            'bytes' => max(0, $start_snapshot['expired']['bytes'] - $end_snapshot['expired']['bytes'])
        ];
    }

    /**
     * Clear ALL transients (dangerous).
     */
    public function clear_all_transients(): array
    {
        global $wpdb;
        $start_snapshot = $this->get_snapshot();

        // Delete regular transients
        $count = $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_%' 
            OR option_name LIKE '_transient_timeout_%'
        ");

        // Delete site transients
        $site_count = $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_site_transient_%' 
            OR option_name LIKE '_site_transient_timeout_%'
        ");

        $end_snapshot = $this->get_snapshot();
        return [
            'count' => (int)$count + (int)$site_count,
            'bytes' => max(0, $start_snapshot['transients']['bytes'] - $end_snapshot['transients']['bytes'])
        ];
    }

    /**
     * Clear Content Core specific caches.
     */
    public function clear_content_core_caches(): array
    {
        global $wpdb;
        $start_snapshot = $this->get_snapshot();

        // Delete CC transients and options
        $count = $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE (option_name LIKE '_transient_cc_%' OR option_name LIKE '_transient_content_core_%')
            OR (option_name LIKE '_transient_timeout_cc_%' OR option_name LIKE '_transient_timeout_content_core_%')
            OR (option_name LIKE 'cc_cache_%' OR option_name LIKE 'cc_rest_cache_%' OR option_name LIKE 'cc_schema_cache_%')
        ");

        $end_snapshot = $this->get_snapshot();
        return [
            'count' => (int)$count,
            'bytes' => max(0, $start_snapshot['cc_cache']['bytes'] - $end_snapshot['cc_cache']['bytes'])
        ];
    }
}