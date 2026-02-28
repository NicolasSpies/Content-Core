<?php
namespace ContentCore\Modules\Multilingual\Data;

use ContentCore\Modules\Multilingual\MultilingualModule;

/**
 * Handles multilingual term meta: cc_lang, cc_translation_group.
 *
 * Data model (termmeta only):
 *   _cc_language          => string  (e.g. 'de', 'fr', 'en')
 *   _cc_translation_group => string  (UUID shared across all translations)
 */
class TermTranslationManager
{
    /** @var callable */
    private $settings_getter;

    private array $group_cache = [];

    public function __construct(callable $settings_getter)
    {
        $this->settings_getter = $settings_getter;
    }

    // -------------------------------------------------------------------------
    // Core term queries
    // -------------------------------------------------------------------------

    /**
     * Return all translations in a group as [lang => term_id].
     */
    public function get_translations(string $group_id): array
    {
        if (isset($this->group_cache[$group_id])) {
            return $this->group_cache[$group_id];
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.term_id, lang.meta_value AS lang
             FROM {$wpdb->termmeta} tm
             JOIN {$wpdb->termmeta} lang ON lang.term_id = tm.term_id
             WHERE tm.meta_key   = '_cc_translation_group'
             AND   tm.meta_value = %s
             AND   lang.meta_key = '_cc_language'",
            $group_id
        ));

        $map = [];
        foreach ($results as $row) {
            $map[$row->lang] = (int) $row->term_id;
        }

        $this->group_cache[$group_id] = $map;
        return $map;
    }

    /**
     * Batch-fetch translation maps for multiple term IDs.
     * Returns [term_id => [lang => translated_term_id]]
     */
    public function get_batch_translations(array $term_ids): array
    {
        if (empty($term_ids)) {
            return [];
        }

        global $wpdb;
        $ids_string = implode(',', array_map('intval', $term_ids));

        $mapping = [];
        foreach ($term_ids as $tid) {
            $mapping[$tid] = [];
        }

        // Step 1: get group IDs for all requested terms
        $group_rows = $wpdb->get_results("
            SELECT term_id, meta_value AS group_id
            FROM {$wpdb->termmeta}
            WHERE meta_key = '_cc_translation_group'
            AND term_id IN ($ids_string)
        ", 'ARRAY_A');

        $term_to_group = [];
        $group_ids = [];
        foreach ($group_rows as $row) {
            $term_to_group[(int) $row['term_id']] = $row['group_id'];
            $group_ids[] = $row['group_id'];
        }

        if (empty($group_ids)) {
            return $mapping;
        }

        // Step 2: for all found groups, get all members (lang => term_id)
        $group_ids = array_unique($group_ids);
        $placeholders = implode(',', array_fill(0, count($group_ids), '%s'));
        $group_to_members = [];

        $member_rows = $wpdb->get_results($wpdb->prepare("
            SELECT g.term_id, g.meta_value AS group_id, l.meta_value AS lang
            FROM {$wpdb->termmeta} g
            JOIN {$wpdb->termmeta} l ON l.term_id = g.term_id AND l.meta_key = '_cc_language'
            WHERE g.meta_key   = '_cc_translation_group'
            AND   g.meta_value IN ($placeholders)
        ", ...$group_ids));

        foreach ($member_rows as $row) {
            $group_to_members[$row->group_id][$row->lang] = (int) $row->term_id;
        }

        // Cache groups and fill mapping
        foreach ($group_to_members as $gid => $members) {
            $this->group_cache[$gid] = $members;
        }

        foreach ($term_ids as $tid) {
            if (isset($term_to_group[$tid])) {
                $gid = $term_to_group[$tid];
                $mapping[$tid] = $group_to_members[$gid] ?? [];
            }
        }

        return $mapping;
    }

    // -------------------------------------------------------------------------
    // Term translation creation
    // -------------------------------------------------------------------------

    /**
     * Create a new term translation in $target_lang linked to the same group as $source_term_id.
     *
     * @return int|\WP_Error  New term_id or error.
     */
    public function create_term_translation(int $source_term_id, string $target_lang, string $taxonomy)
    {
        $source_term = get_term($source_term_id, $taxonomy);
        if (!$source_term || is_wp_error($source_term)) {
            return new \WP_Error('source_not_found', __('Source term not found.', 'content-core'));
        }

        // Ensure the source has a group
        $group_id = get_term_meta($source_term_id, '_cc_translation_group', true);
        if (!$group_id) {
            $group_id = wp_generate_uuid4();
            update_term_meta($source_term_id, '_cc_translation_group', $group_id);

            // Also make sure source has a language
            if (!get_term_meta($source_term_id, '_cc_language', true)) {
                $settings = call_user_func($this->settings_getter);
                $default_lang = $settings['default_lang'] ?? 'de';
                update_term_meta($source_term_id, '_cc_language', $default_lang);
            }
        }

        // Check if translation already exists
        $existing = $this->get_translations($group_id);
        if (isset($existing[$target_lang])) {
            // Already exists — just return the existing term_id
            return $existing[$target_lang];
        }

        // Build a unique name and slug for the new term
        $base_name = $source_term->name . ' (' . strtoupper($target_lang) . ')';
        $base_slug = sanitize_title($source_term->slug . '-' . $target_lang);

        $result = wp_insert_term($base_name, $taxonomy, ['slug' => $base_slug]);
        if (is_wp_error($result)) {
            return $result;
        }

        $new_term_id = (int) $result['term_id'];

        update_term_meta($new_term_id, '_cc_language', $target_lang);
        update_term_meta($new_term_id, '_cc_translation_group', $group_id);

        // Clear cached group
        unset($this->group_cache[$group_id]);

        return $new_term_id;
    }
}
