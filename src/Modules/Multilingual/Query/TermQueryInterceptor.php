<?php
namespace ContentCore\Modules\Multilingual\Query;

class TermQueryInterceptor
{
    /**
     * @var callable
     */
    private $settings_getter;

    /**
     * @var callable
     */
    private $is_active_checker;

    public function __construct(callable $settings_getter, callable $is_active_checker)
    {
        $this->settings_getter = $settings_getter;
        $this->is_active_checker = $is_active_checker;
    }

    public function init(): void
    {
        add_filter('get_terms_args', [$this, 'apply_cc_term_order'], 30, 2);
        add_filter('get_terms_args', [$this, 'filter_terms_for_post_lang'], 20, 2);
    }

    /**
     * On post edit screens, restrict taxonomy terms shown in meta-boxes to
     * ONLY those matching the post's language (strict separation).
     *
     * Relies on the one-time migration (maybe_migrate_legacy_terms) having already
     * tagged all legacy terms with _cc_language = default language, so the strict
     * filter is safe for every term in the database.
     */
    public function filter_terms_for_post_lang(array $args, $taxonomies): array
    {
        if (!is_admin() || !call_user_func($this->is_active_checker)) {
            return $args;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'post-new'], true)) {
            return $args;
        }

        // Determine post language
        $post_id = (int) ($_GET['post'] ?? 0);
        $post_lang = '';
        if ($post_id > 0) {
            $post_lang = get_post_meta($post_id, '_cc_language', true);
        }

        $settings = call_user_func($this->settings_getter);
        $default_lang = $settings['default_lang'] ?? 'de';

        if (empty($post_lang)) {
            $post_lang = $default_lang;
        }

        // Don't double-apply
        if (isset($args['_cc_lang_filtered'])) {
            return $args;
        }
        $args['_cc_lang_filtered'] = true;

        // Normalize meta_query: WP core / theme.json queries can pass a non-array
        // value (e.g. an empty string), which causes "[] operator not supported for
        // strings" when we try to append to it.
        $raw_mq = $args['meta_query'] ?? [];
        $existing_mq = is_array($raw_mq) ? $raw_mq : [];

        // Strict match: only show terms tagged with this exact language.
        // All terms are guaranteed to have _cc_language after the one-time
        // migration (maybe_migrate_legacy_terms) runs on admin_init.
        $existing_mq[] = [
            'key' => '_cc_language',
            'value' => $post_lang,
        ];

        $args['meta_query'] = $existing_mq;
        return $args;
    }

    /**
     * Apply custom 'cc_order' to term queries.
     */
    public function apply_cc_term_order(array $args, $taxonomies): array
    {
        if (!is_admin() && !defined('REST_REQUEST')) {
            return $args;
        }
        add_filter('terms_clauses', [$this, 'inject_cc_term_order_clause'], 20, 3);
        return $args;
    }

    public function inject_cc_term_order_clause(array $clauses, $taxonomies, array $args): array
    {
        remove_filter('terms_clauses', [$this, 'inject_cc_term_order_clause'], 20);

        // Skip for count queries
        if (isset($args['fields']) && $args['fields'] === 'count') {
            return $clauses;
        }

        if (isset($args['orderby']) && !in_array($args['orderby'], ['name', 'term_id', 'id'])) {
            return $clauses;
        }

        global $wpdb;
        $tax_key = is_array($taxonomies) ? implode('_', $taxonomies) : (string) $taxonomies;
        $join_alias = 'ccmetasort_' . substr(md5($tax_key), 0, 4);

        $clauses['join'] .= " LEFT JOIN {$wpdb->termmeta} AS {$join_alias} ON (t.term_id = {$join_alias}.term_id AND {$join_alias}.meta_key = 'cc_order') ";

        // Clean up existing orderby
        $current_orderby = str_ireplace('ORDER BY', '', $clauses['orderby']);
        $current_orderby = trim($current_orderby, ', ');

        $new_fields = "{$join_alias}.meta_value+0 ASC, t.name";

        if (!empty($current_orderby)) {
            $new_fields .= ", " . $current_orderby;
        }

        // Explicitly include ORDER BY because WP Core prepends it PRIOR to this filter running.
        // Therefore, clauses['orderby'] must start with ORDER BY.
        $clauses['orderby'] = " ORDER BY " . $new_fields;

        // Clear 'order' to prevent ASC/DESC being appended after our custom sort.
        $clauses['order'] = '';

        return $clauses;
    }
}
