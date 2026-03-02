<?php
namespace ContentCore\Core;

/**
 * Class UpgradeService
 *
 * Handles plugin version upgrades and one-time data migrations.
 * Ensures migrations run only when needed and provides a centralized place for upgrade logic.
 */
class UpgradeService
{
    /** @var string The option key for the current database version of the plugin. */
    private const VERSION_OPTION = 'cc_version';

    /**
     * Initialize the upgrade service
     */
    public function init(): void
    {
        add_action('admin_init', [$this, 'maybe_upgrade']);
    }

    /**
     * Check if an upgrade is needed and run it if so.
     */
    public function maybe_upgrade(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $installed_version = get_option(self::VERSION_OPTION, '0.0.0');
        $current_version = \CONTENT_CORE_VERSION;

        if (version_compare($installed_version, $current_version, '<')) {
            $this->run_upgrade($installed_version, $current_version);
            update_option(self::VERSION_OPTION, $current_version);
        }
    }

    /**
     * Run all necessary upgrades based on version milestones.
     */
    private function run_upgrade(string $from, string $to): void
    {
        // Milestone: 1.6.0 (Legacy backfills moved here)
        if (version_compare($from, '1.6.4', '<')) {
            $this->migrate_forms_v1();
            $this->migrate_terms_v3();
        }

        // Add future version milestones here...
    }

    /**
     * One-time backfill for cc_form posts to ensure they have multilingual meta.
     * (Moved from MultilingualModule)
     */
    private function migrate_forms_v1(): void
    {
        if (get_option('cc_forms_migrated_v1')) {
            return;
        }

        $settings = get_option('cc_languages_settings', []);
        $default_lang = $settings['default_lang'] ?? 'de';

        $posts = get_posts([
            'post_type' => 'cc_form',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_cc_language',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_cc_translation_group',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        if (!empty($posts)) {
            foreach ($posts as $post_id) {
                if (!get_post_meta($post_id, '_cc_language', true)) {
                    update_post_meta($post_id, '_cc_language', $default_lang);
                }
                if (!get_post_meta($post_id, '_cc_translation_group', true)) {
                    update_post_meta($post_id, '_cc_translation_group', wp_generate_uuid4());
                }
            }
        }

        update_option('cc_forms_migrated_v1', time());
    }

    /**
     * One-time migration: backfill _cc_language and _cc_translation_group on all
     * legacy terms that pre-date the multilingual system.
     * (Moved from MultilingualModule)
     */
    private function migrate_terms_v3(): void
    {
        if (get_option('cc_terms_lang_migrated_v3')) {
            return;
        }

        $settings = get_option('cc_languages_settings', []);
        $default_lang = $settings['default_lang'] ?? 'de';

        $taxonomies = get_taxonomies(['public' => true], 'names');
        if (empty($taxonomies)) {
            return;
        }

        $terms = get_terms([
            'taxonomy' => array_values($taxonomies),
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_cc_language',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_cc_translation_group',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (is_array($terms)) {
            foreach ($terms as $term_id) {
                if (!get_term_meta($term_id, '_cc_language', true)) {
                    update_term_meta($term_id, '_cc_language', $default_lang);
                }
                if (!get_term_meta($term_id, '_cc_translation_group', true)) {
                    update_term_meta($term_id, '_cc_translation_group', wp_generate_uuid4());
                }
            }
        }

        update_option('cc_terms_lang_migrated_v3', time());
    }
}
