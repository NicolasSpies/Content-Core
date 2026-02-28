<?php
namespace ContentCore\Modules\Diagnostics\Checks;

use ContentCore\Modules\Diagnostics\Engine\HealthCheckInterface;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckResult;

class MultilingualIntegrityCheck implements HealthCheckInterface
{
    public function get_id(): string
    {
        return 'multilingual_integrity';
    }

    public function get_name(): string
    {
        return __('Multilingual Data Integrity', 'content-core');
    }

    public function get_category(): string
    {
        return 'multilingual';
    }

    public function run_check(): array
    {
        global $wpdb;
        $results = [];

        // 1. Detect Terms without a language
        $missing_lang_terms = $wpdb->get_results("
            SELECT t.term_id, t.name 
            FROM {$wpdb->terms} t
            LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id AND tm.meta_key = '_cc_language'
            WHERE tm.meta_value IS NULL 
            LIMIT 50
        ");

        if (!empty($missing_lang_terms)) {
            $count = count($missing_lang_terms);
            $suffix = $count >= 50 ? '+' : '';
            $results[] = new HealthCheckResult(
                'terms_missing_language',
                'critical',
                sprintf(__('Found %d%s logic terms missing the _cc_language meta key. This can cause rendering loops.', 'content-core'), $count, $suffix),
                true,
                ['type' => 'terms_missing_language', 'count' => $count]
            );
        }

        // 2. Detect Posts without a language (CC specific types)
        $cc_types = get_post_types(['public' => true], 'names');
        $types_in = "'" . implode("','", array_map('esc_sql', $cc_types)) . "'";

        $missing_lang_posts = $wpdb->get_results("
            SELECT p.ID, p.post_title 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_cc_language'
            WHERE p.post_type IN ({$types_in}) 
            AND p.post_status != 'auto-draft'
            AND p.post_status != 'trash'
            AND pm.meta_value IS NULL 
            LIMIT 50
        ");

        if (!empty($missing_lang_posts)) {
            $count = count($missing_lang_posts);
            $suffix = $count >= 50 ? '+' : '';
            $results[] = new HealthCheckResult(
                'posts_missing_language',
                'critical',
                sprintf(__('Found %d%s public posts missing the _cc_language meta key.', 'content-core'), $count, $suffix),
                true, // Auto-fix will inject the default language
                ['type' => 'posts_missing_language', 'count' => $count]
            );
        }

        // 3. Find duplicate UUIDs in groups
        // A single Translation Group UUID should never have two posts with the exact same language code
        $duplicate_langs_posts = $wpdb->get_results("
            SELECT pm1.meta_value as group_id, pm2.meta_value as lang, GROUP_CONCAT(p.ID) as post_ids, COUNT(*) as c
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_cc_translation_group'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_cc_language'
            WHERE p.post_status != 'trash' AND p.post_status != 'auto-draft'
            GROUP BY pm1.meta_value, pm2.meta_value
            HAVING c > 1
            LIMIT 20
        ");

        if (!empty($duplicate_langs_posts)) {
            $results[] = new HealthCheckResult(
                'duplicate_language_assignments',
                'warning',
                sprintf(__('Found %d translation groups containing duplicate language assignments. This may cause headless fallback loops.', 'content-core'), count($duplicate_langs_posts)),
                false // Too complex/destructive to auto-fix safely
            );
        }

        return $results;
    }

    public function get_fix_preview(string $issue_id, $context_data = null): ?array
    {
        $settings = \ContentCore\Plugin::get_instance()->get_module('multilingual')->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        if ($issue_id === 'terms_missing_language') {
            return [
                'description' => sprintf(__('This will assign the default language (%s) to up to 50 terms currently missing a language parameter. Only safe for initial migrations.', 'content-core'), $default_lang)
            ];
        }

        if ($issue_id === 'posts_missing_language') {
            return [
                'description' => sprintf(__('This will assign the default language (%s) to up to 50 posts currently missing a language parameter.', 'content-core'), $default_lang)
            ];
        }

        return null;
    }

    public function apply_fix(string $issue_id, $context_data = null)
    {
        global $wpdb;
        $settings = \ContentCore\Plugin::get_instance()->get_module('multilingual')->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        if ($issue_id === 'terms_missing_language') {
            $missing_lang_terms = $wpdb->get_col("
                SELECT t.term_id
                FROM {$wpdb->terms} t
                LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id AND tm.meta_key = '_cc_language'
                WHERE tm.meta_value IS NULL 
                LIMIT 50
            ");

            if (empty($missing_lang_terms))
                return true;

            foreach ($missing_lang_terms as $tid) {
                update_term_meta((int) $tid, '_cc_language', $default_lang);
                if (!get_term_meta((int) $tid, '_cc_translation_group', true)) {
                    update_term_meta((int) $tid, '_cc_translation_group', wp_generate_uuid4());
                }
            }
            return true;
        }

        if ($issue_id === 'posts_missing_language') {
            $cc_types = get_post_types(['public' => true], 'names');
            $types_in = "'" . implode("','", array_map('esc_sql', $cc_types)) . "'";

            $missing_lang_posts = $wpdb->get_col("
                SELECT p.ID
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_cc_language'
                WHERE p.post_type IN ({$types_in}) 
                AND p.post_status != 'auto-draft'
                AND p.post_status != 'trash'
                AND pm.meta_value IS NULL 
                LIMIT 50
            ");

            if (empty($missing_lang_posts))
                return true;

            foreach ($missing_lang_posts as $pid) {
                update_post_meta((int) $pid, '_cc_language', $default_lang);
                if (!get_post_meta((int) $pid, '_cc_translation_group', true)) {
                    update_post_meta((int) $pid, '_cc_translation_group', wp_generate_uuid4());
                }
            }
            return true;
        }

        return new \WP_Error('invalid_fix', 'This issue cannot be auto-fixed.');
    }
}
