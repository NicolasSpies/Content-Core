<?php
namespace ContentCore\Modules\Multilingual\Admin;

use ContentCore\Modules\Multilingual\MultilingualModule;
use ContentCore\Modules\Multilingual\Data\TermTranslationManager;

/**
 * Handles the Translations column in taxonomy term list tables.
 *
 * Responsibilities:
 *  - Filter term list to show only the current admin language.
 *  - Add a "Translations" column to all public taxonomies.
 *  - Render flag icons (exists / missing) for each language.
 *  - Generate "create translation" links with redirect_to back to the list.
 */
class TermLanguageColumns
{
    private MultilingualModule $module;
    private TermTranslationManager $manager;
    private array $batch_translations = [];

    public function __construct(MultilingualModule $module, TermTranslationManager $manager)
    {
        $this->module = $module;
        $this->manager = $manager;
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'register_hooks']);
    }

    public function register_hooks(): void
    {
        if (!$this->module->is_active()) {
            return;
        }

        $taxonomies = get_taxonomies(['public' => true]);

        foreach ($taxonomies as $taxonomy) {
            // Column header
            add_filter("manage_edit-{$taxonomy}_columns", [$this, 'add_column']);

            // Column content
            add_filter("manage_{$taxonomy}_custom_column", [$this, 'render_column'], 10, 3);

            // Language filter on the term query
            add_filter("get_terms_args", [$this, 'apply_language_filter'], 10, 2);

            // Filter terms results after fetch (for Translations column batch load)
            add_filter("terms_clauses", [$this, 'terms_clauses_filter'], 10, 3);
        }
    }

    // -------------------------------------------------------------------------
    // Column registration
    // -------------------------------------------------------------------------

    public function add_column(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $value) {
            $new[$key] = $value;
            if ($key === 'name') {
                $new['cc_translations'] = __('Translations', 'content-core');
            }
        }
        return $new;
    }

    // -------------------------------------------------------------------------
    // Language filter on the term list query
    // -------------------------------------------------------------------------

    public function apply_language_filter(array $args, $taxonomies): array
    {
        if (!is_admin()) {
            return $args;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit-tags') {
            return $args;
        }

        $settings = $this->module->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        $admin_lang = get_user_meta(get_current_user_id(), 'cc_admin_language', true);

        // 'all' means show every term regardless of language — skip this filter entirely.
        if ($admin_lang === 'all') {
            return $args;
        }

        // Empty = no preference set; fall back to the site default language.
        $current_lang = (!empty($admin_lang)) ? $admin_lang : $default_lang;

        // Build the meta_query restriction — strict match only.
        $existing_mq = $args['meta_query'] ?? [];

        $existing_mq[] = [
            'key' => '_cc_language',
            'value' => $current_lang,
        ];

        $args['meta_query'] = $existing_mq;
        return $args;
    }

    /**
     * After terms are fetched, prefetch translations for the Translations column.
     * Hooks into terms_clauses to intercept the final result set.
     */
    public function terms_clauses_filter(array $clauses, $taxonomies, array $args): array
    {
        // We use this only as a timing hook — actual prefetching happens later via the column render.
        // This is a no-op on the clauses themselves.
        return $clauses;
    }

    // -------------------------------------------------------------------------
    // Column content renderer
    // -------------------------------------------------------------------------

    /**
     * Render the Translations column for a single term.
     *
     * @param string $content    Existing column content.
     * @param string $column     Column slug.
     * @param int    $term_id    Term ID.
     */
    public function render_column(string $content, string $column, int $term_id): string
    {
        if ($column !== 'cc_translations') {
            return $content;
        }

        $settings = $this->module->get_settings();
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return $content;
        }
        $taxonomy = $term->taxonomy;

        // Lazy-load translations for this term
        if (!isset($this->batch_translations[$term_id])) {
            $batch = $this->manager->get_batch_translations([$term_id]);
            $this->batch_translations[$term_id] = $batch[$term_id] ?? [];
        }

        $translations_mapping = $this->batch_translations[$term_id];
        $term_lang = get_term_meta($term_id, '_cc_language', true) ?: ($settings['default_lang'] ?? 'de');

        // Build ordered language list (default first)
        $default_lang = $settings['default_lang'] ?? 'de';
        $ordered_languages = [];
        foreach ($settings['languages'] as $l) {
            if ($l['code'] === $default_lang)
                $ordered_languages[] = $l;
        }
        foreach ($settings['languages'] as $l) {
            if ($l['code'] !== $default_lang)
                $ordered_languages[] = $l;
        }

        $out = '<div class="cc-translation-column-wrap">';

        foreach ($ordered_languages as $l) {
            $code = $l['code'];
            $exists = false;
            $t_id = 0;

            if ($code === $term_lang) {
                $exists = true;
                $t_id = $term_id;
            } else {
                $check_id = isset($translations_mapping[$code]) ? (int) $translations_mapping[$code] : 0;
                if ($check_id > 0) {
                    $check = get_term($check_id, $taxonomy);
                    if ($check && !is_wp_error($check)) {
                        $exists = true;
                        $t_id = $check_id;
                    }
                }
            }

            $status = $exists ? 'unpublished' : 'missing';
            $class_attr = esc_attr('cc-flag cc-flag--' . $status);
            $flag_html = $this->module->get_flag_html($code, $l['flag_id'] ?? 0);

            if ($exists) {
                $edit_url = get_edit_term_link($t_id, $taxonomy);
                $out .= sprintf(
                    '<a href="%s" class="%s" title="%s">%s</a>',
                    esc_url($edit_url),
                    $class_attr,
                    esc_attr(sprintf(__('Edit %s', 'content-core'), strtoupper($code))),
                    $flag_html
                );
            } else {
                // Create term translation, redirect back to the term list
                $list_url = add_query_arg(['taxonomy' => $taxonomy, 'post_type' => get_current_screen()->post_type ?? 'post'], admin_url('edit-tags.php'));
                $create_url = add_query_arg([
                    'action' => 'cc_create_term_translation',
                    'term' => $term_id,
                    'taxonomy' => $taxonomy,
                    'lang' => $code,
                    'nonce' => wp_create_nonce('cc_create_term_translation_' . $term_id),
                    'redirect_to' => urlencode($list_url),
                ], admin_url('admin.php'));

                $out .= sprintf(
                    '<a href="%s" class="%s" title="%s">%s</a>',
                    esc_url($create_url),
                    $class_attr,
                    esc_attr(sprintf(__('Create %s Translation', 'content-core'), strtoupper($code))),
                    $flag_html
                );
            }
        }

        $out .= '</div>';
        return $out;
    }

    // -------------------------------------------------------------------------
    // Helper: prefetch for multiple terms (call before rendering a list)
    // -------------------------------------------------------------------------

    public function prefetch(array $term_ids): void
    {
        if (empty($term_ids))
            return;
        $batch = $this->manager->get_batch_translations($term_ids);
        foreach ($batch as $tid => $map) {
            $this->batch_translations[$tid] = $map;
        }
    }
}
