<?php
namespace ContentCore\Modules\Multilingual\Admin;

use ContentCore\Modules\Multilingual\MultilingualModule;

class LanguageEditor
{
    private $module;

    public function __construct(MultilingualModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'add_language_meta_box']);
        add_action('save_post', [$this, 'save_language_selection']);

        // Admin-only taxonomy hooks
        add_action('admin_init', [$this, 'register_taxonomy_hooks']);
    }

    public function register_taxonomy_hooks(): void
    {
        $taxonomies = get_taxonomies(['public' => true]);
        foreach ($taxonomies as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", [$this, 'render_term_add_language_field']);
            add_action("{$taxonomy}_edit_form_fields", [$this, 'render_term_edit_language_field'], 10, 2);
        }
    }

    public function add_language_meta_box(string $post_type): void
    {
        if (!post_type_supports($post_type, 'cc-multilingual')) {
            return;
        }

        add_meta_box(
            'cc-language-box',
            __('Language', 'content-core'),
            [$this, 'render_language_meta_box'],
            $post_type,
            'side',
            'high'
        );
    }

    public function render_language_meta_box(\WP_Post $post): void
    {
        if (!$this->module->is_active()) {
            echo '<p>' . __('Multilingual system is not active.', 'content-core') . '</p>';
            return;
        }

        $settings = $this->module->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        wp_nonce_field('cc_save_language', 'cc_language_nonce');

        // On the "Add New" screen, the post is a temporary auto-draft with no real ID.
        // We must not show the language dropdown yet — that would allow accidental
        // misassignment before the default-language logic has run.
        // Instead, silently pre-assign the default language via a hidden field.
        $is_new_post = ($post->post_status === 'auto-draft' || $post->ID === 0);

        if ($is_new_post) {
            echo '<input type="hidden" name="cc_language" value="' . esc_attr($default_lang) . '">';
            echo '<p style="color: #646970; font-size: 12px; margin: 0;">';
            echo esc_html(sprintf(
                __('This item will be created in the default language (%s). You can change the language after saving.', 'content-core'),
                strtoupper($default_lang)
            ));
            echo '</p>';
            return;
        }

        $current_lang = get_post_meta($post->ID, '_cc_language', true);
        if (!$current_lang) {
            $current_lang = $default_lang;
        }

        $group_id = get_post_meta($post->ID, '_cc_translation_group', true);

        echo '<div class="cc-language-selector" style="margin-bottom: 20px;">';
        echo '<label style="display: block; margin-bottom: 8px; font-weight: 600;">' . __('Current Language', 'content-core') . '</label>';
        echo '<select name="cc_language" style="width: 100%;">';
        foreach ($settings['languages'] as $l) {
            echo '<option value="' . esc_attr($l['code']) . '" ' . selected($current_lang, $l['code'], false) . '>' . esc_html($l['label']) . ' (' . strtoupper(esc_html($l['code'])) . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        if ($group_id) {
            echo '<div style="margin-bottom: 20px; padding: 10px; background: #f0f0f1; border: 1px solid #ccd0d4; border-radius: 4px;">';
            echo '<strong style="display: block; margin-bottom: 4px; font-size: 12px; color: #50575e;">' . __('Translation Group ID', 'content-core') . '</strong>';
            echo '<code style="display: block; font-size: 11px; word-break: break-all; background: transparent; padding: 0;">' . esc_html($group_id) . '</code>';
            echo '</div>';

            $translations = $this->module->get_translation_manager()->get_translations($group_id);
            echo '<div class="cc-translations-list">';
            echo '<label style="display: block; margin-bottom: 8px; font-weight: 600;">' . __('Translations', 'content-core') . '</label>';
            echo '<ul style="margin: 0; padding: 0; list-style: none;">';
            foreach ($settings['languages'] as $l) {
                $lang = $l['code'];
                $flag_id = $l['flag_id'] ?? 0;

                if ($lang === $current_lang) {
                    continue;
                }

                echo '<li style="margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f0f0f1; padding-bottom: 6px;">';

                echo '<div style="display: flex; align-items: center; gap: 8px;">';
                echo $this->module->get_flag_html($lang, $flag_id);
                echo '<span style="font-size: 13px; font-weight: 500;">' . esc_html($l['label']) . '</span>';
                echo '</div>';

                if (isset($translations[$lang])) {
                    $translated_post = get_post($translations[$lang]);
                    $status_color = $translated_post->post_status === 'publish' ? '#2271b1' : '#646970';
                    $status_label = get_post_status_object($translated_post->post_status)->label;

                    echo '<a href="' . get_edit_post_link($translations[$lang]) . '" style="text-decoration: none; display: flex; align-items: center; gap: 4px; color: ' . $status_color . ';" title="' . esc_attr($translated_post->post_title) . '">';
                    echo '<span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px;"></span>';
                    echo '<span style="font-size: 11px; font-weight: 500;">' . esc_html($status_label) . '</span>';
                    echo '</a>';
                } else {
                    $create_url = add_query_arg([
                        'action' => 'cc_create_translation',
                        'post' => $post->ID,
                        'lang' => $lang,
                        'nonce' => wp_create_nonce('cc_create_translation_' . $post->ID)
                    ], admin_url('admin.php'));

                    echo '<a href="' . esc_url($create_url) . '" class="button button-small" style="display: flex; align-items: center; gap: 2px;"><span class="dashicons dashicons-plus-alt2" style="font-size: 15px; margin-top: 1px; width: 15px; height: 15px;"></span> ' . __('Create', 'content-core') . '</a>';
                }
                echo '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    public function save_language_selection(int $post_id): void
    {
        if (!isset($_POST['cc_language_nonce']) || !wp_verify_nonce($_POST['cc_language_nonce'], 'cc_save_language')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['cc_language'])) {
            update_post_meta($post_id, '_cc_language', sanitize_text_field($_POST['cc_language']));
        }
    }

    /**
     * Render language field on "Add New Term" screen.
     * We do NOT show a dropdown — new terms are always assigned to the default language,
     * matching the same behaviour as Posts ("Add New" = default lang, selector only after save).
     */
    public function render_term_add_language_field(string $taxonomy): void
    {
        if (!$this->module->is_active())
            return;
        $settings = $this->module->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';
        ?>
        <div class="form-field term-group" style="display:none;">
            <input type="hidden" name="cc_term_language" value="<?php echo esc_attr($default_lang); ?>">
        </div>
        <div class="form-field">
            <p class="description" style="color:#646970; font-style:italic; margin-top:4px;">
                <?php printf(
                    esc_html__('This term will be created in the default language (%s). You can add translations after saving.', 'content-core'),
                    strtoupper($default_lang)
                ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render language field on "Edit Term" screen
     */
    public function render_term_edit_language_field(\WP_Term $term, string $taxonomy): void
    {
        if (!$this->module->is_active())
            return;
        $settings = $this->module->get_settings();
        $current_lang = get_term_meta($term->term_id, '_cc_language', true) ?: $settings['default_lang'];
        ?>
        <tr class="form-field term-group-wrap">
            <th scope="row"><label for="cc_term_language">
                    <?php _e('Language', 'content-core'); ?>
                </label></th>
            <td>
                <select name="cc_term_language" id="cc_term_language">
                    <?php foreach ($settings['languages'] as $l): ?>
                        <option value="<?php echo esc_attr($l['code']); ?>" <?php selected($current_lang, $l['code']); ?>>
                            <?php echo esc_html($l['label']); ?>
                        </option>
                        <?php
                    endforeach; ?>
                </select>
                <p class="description">
                    <?php _e('The language assigned to this term.', 'content-core'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}