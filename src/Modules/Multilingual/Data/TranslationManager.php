<?php
namespace ContentCore\Modules\Multilingual\Data;

use ContentCore\Modules\Multilingual\MultilingualModule;

class TranslationManager
{
    private $module;
    private $group_cache = [];

    public function __construct(MultilingualModule $module)
    {
        $this->module = $module;
    }

    /**
     * Create a translation of a post into another language.
     * 
     * @param int    $source_post_id The original post ID.
     * @param string $target_lang    The target language code.
     * @return int|\WP_Error         The new post ID or error.
     */
    public function create_translation(int $source_post_id, string $target_lang)
    {
        $source_post = get_post($source_post_id);
        if (!$source_post) {
            return new \WP_Error('source_not_found', __('Source post not found.', 'content-core'));
        }

        $group_id = get_post_meta($source_post_id, '_cc_translation_group', true);
        if (!$group_id) {
            $group_id = wp_generate_uuid4();
            update_post_meta($source_post_id, '_cc_translation_group', $group_id);
        }

        // Check if translation already exists
        $existing = $this->get_translation_in_lang($group_id, $target_lang);
        if ($existing) {
            return new \WP_Error('translation_exists', __('Translation already exists.', 'content-core'));
        }

        // Clone post
        $new_post_args = [
            'post_title' => $source_post->post_title . ' - ' . strtoupper($target_lang),
            'post_content' => $source_post->post_content,
            'post_excerpt' => $source_post->post_excerpt,
            'post_type' => $source_post->post_type,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
            'post_parent' => $source_post->post_parent,
            'menu_order' => $source_post->menu_order,
            'page_template' => get_post_meta($source_post_id, '_wp_page_template', true),
        ];

        $new_post_id = wp_insert_post($new_post_args);
        if (is_wp_error($new_post_id)) {
            return $new_post_id;
        }

        // Ensure proper slug matches title right away
        $current_slug = get_post_field('post_name', $new_post_id);
        if (empty($current_slug) && !empty($new_post_args['post_title'])) {
            $desired_slug = sanitize_title($new_post_args['post_title']);
            $unique_slug = wp_unique_post_slug(
                $desired_slug,
                $new_post_id,
                'draft',
                $new_post_args['post_type'],
                $new_post_args['post_parent']
            );
            wp_update_post([
                'ID' => $new_post_id,
                'post_name' => $unique_slug
            ]);
        }

        // Copy Featured Image
        $thumbnail_id = get_post_meta($source_post_id, '_thumbnail_id', true);
        if ($thumbnail_id) {
            update_post_meta($new_post_id, '_thumbnail_id', $thumbnail_id);
        }

        // Set multilingual meta
        update_post_meta($new_post_id, '_cc_language', $target_lang);
        update_post_meta($new_post_id, '_cc_translation_group', $group_id);

        // Clear cache for this group
        unset($this->group_cache[$group_id]);

        // Clone Content Core Meta (Deterministic Schema-Driven Formatted Copy)
        if (class_exists('\\ContentCore\\Modules\\CustomFields\\Data\\FieldRegistry')) {
            $context = [
                'post_id' => $source_post_id,
                'post_type' => $source_post->post_type,
                'page_template' => get_post_meta($source_post_id, '_wp_page_template', true),
                'taxonomy_terms' => \ContentCore\Modules\CustomFields\Data\FieldRegistry::get_context_taxonomy_terms($source_post_id),
            ];

            $schema_fields = \ContentCore\Modules\CustomFields\Data\FieldRegistry::get_fields_for_context($context);

            foreach ($schema_fields as $field_name => $field_config) {
                $raw_value = get_post_meta($source_post_id, $field_name, true);
                if ($raw_value !== '') {
                    update_post_meta($new_post_id, $field_name, $raw_value);
                }
            }
        }

        return $new_post_id;
    }

    /**
     * Get all translations for a group.
     * 
     * @param string $group_id
     * @return array Array of post IDs indexed by language code.
     */
    public function get_translations(string $group_id): array
    {
        if (isset($this->group_cache[$group_id])) {
            return $this->group_cache[$group_id];
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as lang 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_cc_language' 
             AND post_id IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_cc_translation_group' AND meta_value = %s
             )",
            $group_id
        ));

        $translations = [];
        foreach ($results as $row) {
            $translations[$row->lang] = (int)$row->post_id;
        }

        $this->group_cache[$group_id] = $translations;
        return $translations;
    }

    /**
     * Get translation ID for a group in a specific language.
     */
    public function get_translation_in_lang(string $group_id, string $lang): ?int
    {
        $translations = $this->get_translations($group_id);
        return $translations[$lang] ?? null;
    }

    /**
     * Get translation mappings for multiple posts at once.
     * 
     * @param array $post_ids
     * @return array [post_id => [lang => tid]]
     */
    public function get_batch_translations(array $post_ids): array
    {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;
        $ids_string = implode(',', array_map('intval', $post_ids));

        // 1. Get groups for these posts
        $groups = $wpdb->get_results("
            SELECT post_id, meta_value as group_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_cc_translation_group' 
            AND post_id IN ($ids_string)
        ", OBJECT_K);

        if (empty($groups)) {
            return [];
        }

        $group_ids = array_unique(array_column($groups, 'group_id'));
        $group_placeholders = implode(',', array_fill(0, count($group_ids), '%s'));

        // 2. Get all posts belonging to these groups
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT g.meta_value as group_id, p.post_id, p.meta_value as lang
            FROM {$wpdb->postmeta} g
            JOIN {$wpdb->postmeta} p ON g.post_id = p.post_id
            WHERE g.meta_key = '_cc_translation_group' AND g.meta_value IN ($group_placeholders)
            AND p.meta_key = '_cc_language'
        ", ...$group_ids));

        // 3. Map back to original post IDs
        $mapping = [];
        $group_to_translations = [];
        foreach ($results as $row) {
            $group_to_translations[$row->group_id][$row->lang] = (int)$row->post_id;
        }

        // Fill cache
        foreach ($group_to_translations as $gid => $trans) {
            $this->group_cache[$gid] = $trans;
        }

        foreach ($post_ids as $pid) {
            if (isset($groups[$pid])) {
                $gid = $groups[$pid]->group_id;
                $mapping[$pid] = $group_to_translations[$gid] ?? [];
            }
            else {
                $mapping[$pid] = [];
            }
        }

        return $mapping;
    }
}