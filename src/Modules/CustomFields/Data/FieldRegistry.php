<?php
namespace ContentCore\Modules\CustomFields\Data;

class FieldRegistry
{

    /**
     * Cache of field groups assigned to a specific post type to avoid redundant DB queries.
     * Format: [ 'post_type' => [ WP_Post, WP_Post ] ]
     */
    private static array $group_cache = [];

    /**
     * Get all field groups that match the given context.
     *
     * @param array $context Evaluation context (post_id, post_type, page_template, etc)
     * @return \WP_Post[] Array of matching field group post objects.
     */
    public static function get_field_groups(array $context): array
    {
        // Use a simple hash of the context for caching
        $cache_key = md5(serialize($context));
        if (isset(self::$group_cache[$cache_key])) {
            return self::$group_cache[$cache_key];
        }

        $query = new \WP_Query([
            'post_type' => FieldGroupPostType::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $matched_groups = [];

        if ($query->have_posts()) {
            foreach ($query->posts as $group) {
                $rule_groups = get_post_meta($group->ID, '_cc_assignment_rules', true);

                // If no rules, default to not showing (prevents clutter)
                if (!is_array($rule_groups) || empty($rule_groups)) {
                    continue;
                }

                if (self::evaluate_rule_groups($rule_groups, $context)) {
                    $matched_groups[] = $group;
                }
            }
        }

        self::$group_cache[$cache_key] = $matched_groups;
        return $matched_groups;
    }

    /**
     * Evaluate rule groups using OR logic (returns true if any group matches)
     */
    private static function evaluate_rule_groups($rule_groups, array $context): bool
    {
        $rule_groups = self::normalize_rule_groups($rule_groups);

        if (empty($rule_groups) || !is_array($rule_groups)) {
            return false;
        }

        foreach ($rule_groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            if (self::evaluate_single_group($group, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize rule groups to handle legacy and malformed data safely.
     */
    private static function normalize_rule_groups($rule_groups): array
    {
        if (is_string($rule_groups) && $rule_groups !== '') {
            return [
                [
                    'rules' => [
                        ['type' => 'post_type', 'operator' => '==', 'value' => $rule_groups]
                    ]
                ]
            ];
        }

        if (!is_array($rule_groups)) {
            return [];
        }

        // Handle legacy format where assigned post types were stored directly in an associative array
        if (isset($rule_groups['post_type']) && is_string($rule_groups['post_type'])) {
            return [
                [
                    'rules' => [
                        ['type' => 'post_type', 'operator' => '==', 'value' => $rule_groups['post_type']]
                    ]
                ]
            ];
        }

        return $rule_groups;
    }

    /**
     * Evaluate a single rule group using AND logic (returns true if all rules match)
     */
    private static function evaluate_single_group(array $group, array $context): bool
    {
        $rules = $group['rules'] ?? [];
        if (empty($rules))
            return false;

        foreach ($rules as $rule) {
            if (!self::evaluate_rule($rule, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Evaluate a specific rule against the context
     */
    private static function evaluate_rule(array $rule, array $context): bool
    {
        $type = $rule['type'] ?? '';
        $value = $rule['value'] ?? '';

        switch ($type) {
            case 'post_type':
                // Check for standard post type OR options page assignment
                $current_pt = $context['post_type'] ?? '';
                $current_op = $context['options_page'] ?? '';
                return ($current_pt === $value || $current_op === $value);

            case 'page':
                return isset($context['post_id']) && (int)$context['post_id'] === (int)$value;

            case 'page_template':
                $current_template = $context['page_template'] ?? '';
                return $current_template === $value;

            case 'taxonomy_term':
                $taxonomy = $rule['taxonomy'] ?? '';
                $current_terms = $context['taxonomy_terms'][$taxonomy] ?? [];
                return in_array((int)$value, $current_terms, true);

            default:
                return false;
        }
    }

    /**
     * Get a flattened array of all field schemas matching a full context.
     *
     * @param array $context Evaluation context.
     * @return array Map of field schemas.
     */
    public static function get_fields_for_context(array $context): array
    {
        $groups = self::get_field_groups($context);
        $all_fields = [];

        foreach ($groups as $group) {
            $fields = get_post_meta($group->ID, '_cc_fields', true);
            if (is_array($fields)) {
                $fields = self::normalize_field_tree($fields);
                self::extract_fields_recursive($fields, $all_fields);
            }
        }

        return $all_fields;
    }

    /**
     * Helper to recursively extract flat datastore-compatible fields from the UI tree.
     */
    public static function extract_fields_recursive(array $fields, array &$all_fields): void
    {
        foreach ($fields as $field) {
            $type = $field['type'] ?? '';

            // If it's a section, just extract its children
            if ('section' === $type || 'ui_section' === $type) {
                if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                    self::extract_fields_recursive($field['sub_fields'], $all_fields);
                }
                continue;
            }

            // Add normal fields
            if (!empty($field['name'])) {
                $all_fields[$field['name']] = $field;
            }
        }
    }

    /**
     * Normalizes a potentially legacy "flat" array of fields into the new
     * nested "tree" structure where fields following a section become its children.
     */
    public static function normalize_field_tree(array $fields): array
    {
        // Check if it's already a tree (any root-level section already has an array of sub_fields).
        // If it does, we trust the JSON tree logic.
        $is_flat = false;
        foreach ($fields as $f) {
            if (($f['type'] === 'section' || $f['type'] === 'ui_section') && !isset($f['sub_fields'])) {
                $is_flat = true;
                break;
            }
        }

        if (!$is_flat) {
            return $fields;
        }

        // Convert Flat to Tree natively in memory
        $tree = [];
        $current_section = null;

        foreach ($fields as $field) {
            $type = $field['type'] ?? '';

            if ($type === 'section' || $type === 'ui_section') {
                if ($current_section !== null) {
                    $tree[] = $current_section;
                }
                $current_section = $field;
                $current_section['sub_fields'] = [];
            }
            else {
                if ($current_section !== null) {
                    $current_section['sub_fields'][] = $field;
                }
                else {
                    $tree[] = $field;
                }
            }
        }

        if ($current_section !== null) {
            $tree[] = $current_section;
        }

        return $tree;
    }

    /**
     * Helper to get taxonomy terms for a post
     */
    public static function get_context_taxonomy_terms(int $post_id): array
    {
        $post_type = get_post_type($post_id);
        if (!$post_type)
            return [];

        $taxonomies = get_object_taxonomies($post_type);
        $terms_map = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms)) {
                $terms_map[$taxonomy] = $terms;
            }
        }

        return $terms_map;
    }

    /**
     * Get a flattened array of all field schemas assigned to a specific post type.
     *
     * @param string $post_type The post type to fetch fields for.
     * @return array Flattened map of field schemas, keyed by field name.
     */
    public static function get_fields_for_post_type(string $post_type): array
    {
        return self::get_fields_for_context(['post_type' => $post_type]);
    }
}