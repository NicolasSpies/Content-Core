<?php
namespace ContentCore\Modules\Multilingual\Admin;

use ContentCore\Modules\Multilingual\MultilingualModule;

class TranslationColumnManager
{
    private static array $registered_post_types = [];
    private static ?MultilingualModule $module = null;

    /**
     * Set the MultilingualModule instance for rendering.
     */
    public static function set_module(MultilingualModule $module): void
    {
        self::$module = $module;
    }

    /**
     * Register the translation column for a specific post type.
     */
    public static function register_for_post_type(string $post_type): void
    {
        if (in_array($post_type, self::$registered_post_types, true)) {
            return;
        }

        add_filter("manage_{$post_type}_posts_columns", [self::class , 'add_columns']);
        add_action("manage_{$post_type}_posts_custom_column", [self::class , 'render_column'], 10, 2);

        self::$registered_post_types[] = $post_type;
    }

    /**
     * Filter to add the Translations column.
     */
    public static function add_columns(array $columns): array
    {
        if (!self::$module || !self::$module->is_active()) {
            return $columns;
        }

        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['cc_translation'] = __('Translations', 'content-core');
            }
        }
        return $new_columns;
    }

    /**
     * Action to render the Translations column content.
     */
    public static function render_column(string $column_name, int $post_id): void
    {
        if ($column_name !== 'cc_translation' || !self::$module) {
            return;
        }

        // Defensive check: only render for the post type it was registered for
        $post_type = get_post_type($post_id);
        if (!in_array($post_type, self::$registered_post_types, true)) {
            return;
        }

        $settings = self::$module->get_settings();
        $current_lang = get_post_meta($post_id, '_cc_language', true) ?: $settings['default_lang'];

        // Try to get batch translations from LanguageListColumns
        $list_columns = self::$module->get_columns_handler();
        $translations = $list_columns ? $list_columns->get_batch_translations($post_id) : [];

        // Fallback to direct fetch if not pre-fetched
        if (empty($translations)) {
            $manager = self::$module->get_translation_manager();
            $group_id = get_post_meta($post_id, '_cc_translation_group', true);
            $translations = $group_id ? $manager->get_translations($group_id) : [];
        }

        echo '<div style="display: flex; gap: 8px; align-items: center; overflow: visible;">';
        foreach ($settings['languages'] as $l) {
            $code = $l['code'];
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

                $dash_class = 'dashicons-edit';
                $color = ($status === 'publish') ? '#2271b1' : '#646970';
                $status_obj = get_post_status_object($status);
                $status_label = $status_obj ? $status_obj->label : $status;

                echo '<a href="' . get_edit_post_link($t_id) . '" style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; text-decoration: none; color: ' . $color . ';" title="' . esc_attr(strtoupper($code) . ': ' . $status_label) . '">';
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
}