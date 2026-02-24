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
        add_action('pre_get_posts', [$this, 'apply_filters']);
        add_action('the_posts', [$this, 'prefetch_translations'], 10, 2);
    }

    public function register_admin_hooks(): void
    {
        // General fallback column hooks
        add_action('manage_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('manage_pages_custom_column', [$this, 'render_column'], 10, 2);

        $post_types = get_post_types(['public' => true]);
        foreach ($post_types as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [$this, 'add_columns']);
            if ($post_type !== 'post' && $post_type !== 'page') {
                add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_column'], 10, 2);
            }
            add_filter("{$post_type}_row_actions", [$this, 'add_row_actions'], 10, 2);
        }

        add_action('restrict_manage_posts', [$this, 'add_filters']);
    }

    /**
     * Prefetch translations for all posts in the current view to avoid N+1 queries.
     */
    public function prefetch_translations(array $posts, \WP_Query $query): array
    {
        if (!is_admin() || !$query->is_main_query() || empty($posts)) {
            return $posts;
        }

        $post_ids = array_map(function ($p) {
            return $p->ID;
        }, $posts);
        $this->batch_translations = $this->module->get_translation_manager()->get_batch_translations($post_ids);

        return $posts;
    }

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

    public function render_column(string $column, int $post_id): void
    {
        if ($column !== 'cc_translations') {
            return;
        }

        $settings = $this->module->get_settings();
        $current_lang = get_post_meta($post_id, '_cc_language', true) ?: $settings['default_lang'];
        $translations = $this->batch_translations[$post_id] ?? [];

        echo '<div style="display: flex; gap: 8px; align-items: center; overflow: visible;">';
        foreach ($settings['languages'] as $l) {
            $code = $l['code'];
            $flag_id = $l['flag_id'] ?? 0;
            $is_current = ($code === $current_lang);

            if ($is_current) {
                echo '<span style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; text-decoration: none;" title="' . esc_attr__('Current Language', 'content-core') . '">';
                echo '<span class="dashicons dashicons-yes" style="color: #00a32a; font-size: 18px;"></span>';
                echo '</span>';
            }
            elseif (isset($translations[$code])) {
                $t_id = $translations[$code];
                $t_post = get_post($t_id);
                $status = $t_post ? $t_post->post_status : 'unknown';

                // If it's published, normal icon. If draft, lighter icon.
                $dash_class = 'dashicons-edit';
                $color = ($status === 'publish') ? '#2271b1' : '#646970';

                echo '<a href="' . get_edit_post_link($t_id) . '" style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; text-decoration: none; color: ' . $color . ';" title="' . esc_attr(strtoupper($code) . ': ' . get_post_status_object($status)->label) . '">';
                echo '<span class="dashicons ' . $dash_class . '" style="font-size: 16px;"></span>';
                echo '</a>';
            }
            else {
                $create_url = add_query_arg([
                    'action' => 'cc_create_translation',
                    'post' => $post_id,
                    'lang' => $code,
                    'nonce' => wp_create_nonce('cc_create_translation_' . $post_id)
                ], admin_url('admin.php'));

                echo '<a href="' . esc_url($create_url) . '" style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; text-decoration: none; color: #b5bcc2; transition: color 0.2s;" onmouseover="this.style.color=\'#2271b1\'" onmouseout="this.style.color=\'#b5bcc2\'" title="' . esc_attr(sprintf(__('Create %s translation', 'content-core'), strtoupper($code))) . '">';
                echo '<span class="dashicons dashicons-plus-alt2" style="font-size: 16px;"></span>';
                echo '</a>';
            }
        }
        echo '</div>';
    }

    public function add_row_actions(array $actions, \WP_Post $post): array
    {
        if (!$this->module->is_active() || !post_type_supports($post->post_type, 'cc-multilingual')) {
            return $actions;
        }

        $settings = $this->module->get_settings();
        $current_lang = get_post_meta($post->ID, '_cc_language', true) ?: $settings['default_lang'];
        $translations = $this->batch_translations[$post->ID] ?? [];

        foreach ($settings['languages'] as $l) {
            $code = $l['code'];
            if ($code === $current_lang || isset($translations[$code])) {
                continue;
            }

            $create_url = add_query_arg([
                'action' => 'cc_create_translation',
                'post' => $post->ID,
                'lang' => $code,
                'nonce' => wp_create_nonce('cc_create_translation_' . $post->ID)
            ], admin_url('admin.php'));

            $actions['translate_' . $code] = '<a href="' . $create_url . '">' . sprintf(__('Translate to %s', 'content-core'), strtoupper($code)) . '</a>';
        }

        return $actions;
    }

    public function add_filters(string $post_type): void
    {
        if (!post_type_supports($post_type, 'cc-multilingual') || !$this->module->is_active()) {
            return;
        }

        $settings = $this->module->get_settings();
        $current = get_user_meta(get_current_user_id(), 'cc_admin_language', true) ?: 'all';

        echo '<select name="cc_lang_filter" id="cc_admin_list_lang_filter" onchange="window.location.href=this.value">';

        $all_url = add_query_arg([
            'action' => 'cc_switch_admin_language',
            'lang' => 'all',
            'nonce' => wp_create_nonce('cc_switch_admin_language')
        ], admin_url('admin.php'));
        echo '<option value="' . esc_url($all_url) . '" ' . selected($current, 'all', false) . '>' . __('All Languages', 'content-core') . '</option>';

        foreach ($settings['languages'] as $lang) {
            $lang_url = add_query_arg([
                'action' => 'cc_switch_admin_language',
                'lang' => $lang['code'],
                'nonce' => wp_create_nonce('cc_switch_admin_language')
            ], admin_url('admin.php'));
            echo '<option value="' . esc_url($lang_url) . '" ' . selected($current, $lang['code'], false) . '>' . esc_html($lang['label']) . ' (' . strtoupper(esc_html($lang['code'])) . ')</option>';
        }
        echo '</select>';
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

        // Stable Inclusion Rule: Filter list tables where `show_ui` is true natively,
        // strictly excluding internal architectural schemas that shouldn't be isolated contextually.
        $post_type = $query->get('post_type') ?: 'post';

        $excluded_types = [
            'cc_field_group',
            'cc_post_type',
            'cc_taxonomy',
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
            return;
        }

        // Only filter post types currently registered to be shown in the UI natively.
        $valid_ui_types = get_post_types(['show_ui' => true]);
        if (!in_array($post_type, $valid_ui_types, true)) {
            return;
        }

        $settings = $this->module->get_settings();
        $current_lang = get_user_meta(get_current_user_id(), 'cc_admin_language', true);

        // Polylang parity: Validate context strictly, fallback to default.
        if (!$current_lang || !in_array($current_lang, array_column($settings['languages'], 'code'))) {
            $current_lang = $settings['default_lang'] ?? 'de';
        }

        if ($current_lang === 'all') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Polylang parity: If the chosen context matches the global Default Language,
        // we surface posts explicitly marked as that language OR unmarked legacy posts,
        // without altering their database state.
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
        }
        else {
            // Strict match for translated contexts natively.
            $meta_query[] = [
                'key' => '_cc_language',
                'value' => sanitize_text_field($current_lang),
            ];
        }

        $query->set('meta_query', $meta_query);
    }
}