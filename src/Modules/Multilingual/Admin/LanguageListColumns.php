<?php
namespace ContentCore\Modules\Multilingual\Admin;

use ContentCore\Modules\Multilingual\MultilingualModule;

class LanguageListColumns
{
    private $module;
    private array $batch_translations = [];

    public function __construct(MultilingualModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'register_admin_hooks']);
    }

    public function register_admin_hooks(): void
    {
        add_action('pre_get_posts', [$this, 'apply_filters']);
        add_filter('the_posts', [$this, 'prefetch_translations'], 10, 2);
        add_action("restrict_manage_posts", [$this, "add_filters"]);

        $post_types = get_post_types(["show_ui" => true]);
        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type, 'cc-multilingual')) {
                // Determine hook names based on post type
                if ($post_type === 'post') {
                    $column_filter = "manage_posts_columns";
                    $column_action = "manage_posts_custom_column";
                } elseif ($post_type === 'page') {
                    $column_filter = "manage_pages_columns";
                    $column_action = "manage_pages_custom_column";
                } else {
                    $column_filter = "manage_{$post_type}_posts_columns";
                    $column_action = "manage_{$post_type}_posts_custom_column";
                }

                add_filter($column_filter, [$this, 'add_columns']);
                add_action($column_action, [$this, 'render_column'], 10, 2);
            }
        }
    }

    /**
     * Get the batch translations for a specific post.
     */
    public function get_batch_translations(int $post_id): array
    {
        return $this->batch_translations[$post_id] ?? [];
    }

    /**
     * Prefetch translations for all posts in the current view to avoid N+1 queries.
     */
    public function prefetch_translations(array $posts, \WP_Query $query): array
    {
        if (!is_admin() || empty($posts)) {
            return $posts;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_post_type = $screen ? $screen->post_type : (sanitize_text_field($_GET['post_type'] ?? 'post'));

        $by_type = [];
        foreach ($posts as $post) {
            $type = '';
            $id = 0;

            if ($post instanceof \WP_Post) {
                $type = $post->post_type;
                $id = $post->ID;
            } elseif (is_object($post) && isset($post->ID)) {
                $type = $post->post_type ?? $screen_post_type;
                $id = $post->ID;
            } elseif (is_array($post) && isset($post['ID'])) {
                $type = $post['post_type'] ?? $screen_post_type;
                $id = $post['ID'];
            }

            if ($type && $id) {
                $by_type[$type][] = $id;
            }
        }

        $all_batch = [];
        foreach ($by_type as $type => $ids) {
            $batch = $this->module->get_translation_manager()->get_batch_translations($ids, $type);
            if (!empty($batch)) {
                $all_batch = array_replace($all_batch, $batch);
            }
        }

        $this->batch_translations = $all_batch;

        return $posts;
    }

    public function add_filters(string $post_type): void
    {
        if (!post_type_supports($post_type, 'cc-multilingual') || !$this->module->is_active()) {
            return;
        }

        // Explicitly suppressed: The language filter dropdown is removed in favor of 
        // Default Language enforcement and direct Translation Column interaction.
    }

    public function apply_filters($query): void
    {
        if (!is_admin() || !$query->is_main_query() || !$this->module->is_active()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return;
        }

        $post_type = $query->get('post_type') ?: 'post';

        $excluded_types = [
            'cc_field_group',
            'cc_post_type_def',
            'cc_taxonomy_def',
            'cc_options_page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation'
        ];

        if (in_array($post_type, $excluded_types, true)) {
            if ($post_type === 'cc_post_type_def' || $post_type === 'cc_taxonomy_def') {
                $query->set('post_status', 'any');
                $query->set('posts_per_page', -1);
                $query->set('orderby', 'date');
                $query->set('order', 'DESC');
            }
            return;
        }

        if (!post_type_supports($post_type, 'cc-multilingual')) {
            return;
        }

        $valid_ui_types = get_post_types(['show_ui' => true]);
        if (!in_array($post_type, $valid_ui_types, true)) {
            return;
        }

        $settings = $this->module->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        // 1. Check URL parameter first (Explicit selection)
        $current_lang = sanitize_text_field($_GET['cc_lang'] ?? '');

        // 2. If 'all', return to show everything
        if ($current_lang === 'all') {
            return;
        }

        // 3. If empty (initial page load), force default language
        if (empty($current_lang)) {
            $current_lang = $default_lang;
        }

        $meta_query = $query->get('meta_query') ?: [];

        if ($current_lang === $settings['default_lang']) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_cc_language',
                    'value' => sanitize_text_field($current_lang),
                ],
                [
                    'key' => '_cc_language',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        } else {
            $meta_query[] = [
                'key' => '_cc_language',
                'value' => sanitize_text_field($current_lang),
            ];
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * Filter to add the Translations column.
     */
    public function add_columns(array $columns): array
    {
        if (!$this->module->is_active()) {
            return $columns;
        }

        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['cc_translations'] = __('Translations', 'content-core');
            }
        }
        return $new_columns;
    }

    /**
     * Action to render the Translations column content.
     */
    public function render_column(string $column_name, int $post_id): void
    {
        if ($column_name !== 'cc_translations') {
            return;
        }

        $settings = $this->module->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';
        $post_lang = get_post_meta($post_id, '_cc_language', true);

        // If meta is missing, fallback to default or try to determine
        if (empty($post_lang)) {
            $post_lang = $default_lang;
        }

        $translations_mapping = $this->batch_translations[$post_id] ?? null;

        if ($translations_mapping === null) {
            // Fallback: If prefetch missed this post, fetch it on-demand and cache it
            $batch = $this->module->get_translation_manager()->get_batch_translations([$post_id]);
            $translations_mapping = $batch[$post_id] ?? [];
            $this->batch_translations[$post_id] = $translations_mapping;
        }

        // 1. Deterministic Ordering: Default first, then others in configured order
        $ordered_languages = [];
        // First find and add the default lang
        foreach ($settings['languages'] as $lang) {
            if ($lang['code'] === $default_lang) {
                $ordered_languages[] = $lang;
                break;
            }
        }
        // Then add others
        foreach ($settings['languages'] as $lang) {
            if ($lang['code'] !== $default_lang) {
                $ordered_languages[] = $lang;
            }
        }

        echo '<div class="cc-translation-column-wrap">';
        foreach ($ordered_languages as $l) {
            $code = $l['code'];
            $exists = false;
            $t_id = 0;

            if ($code === $post_lang) {
                $exists = true;
                $t_id = $post_id;
            } else {
                $check_id = isset($translations_mapping[$code]) ? (int) $translations_mapping[$code] : 0;
                if ($check_id > 0) {
                    $status = get_post_status($check_id);
                    if ($status !== false && $status !== 'trash') {
                        $exists = true;
                        $t_id = $check_id;
                    }
                }
            }

            $classes = ['cc-flag'];
            $classes[] = $exists ? 'cc-flag--exists' : 'cc-flag--missing';
            $class_attr = implode(' ', array_map('esc_attr', $classes));

            $flag_html = $this->module->get_flag_html($code, $l['flag_id'] ?? 0);

            if ($exists) {
                $edit_url = get_edit_post_link($t_id);
                printf(
                    '<a href="%s" class="%s" title="%s">%s</a>',
                    esc_url($edit_url),
                    $class_attr,
                    esc_attr(sprintf(__('Edit %s', 'content-core'), strtoupper($code))),
                    $flag_html
                );
            } else {
                $create_url = add_query_arg([
                    'action' => 'cc_create_translation',
                    'post' => $post_id,
                    'lang' => $code,
                    'nonce' => wp_create_nonce('cc_create_translation_' . $post_id)
                ], admin_url('admin.php'));

                printf(
                    '<a href="%s" class="%s" title="%s">%s</a>',
                    esc_url($create_url),
                    $class_attr,
                    esc_attr(sprintf(__('Create %s Translation', 'content-core'), strtoupper($code))),
                    $flag_html
                );
            }
        }
        echo '</div>';
    }
}