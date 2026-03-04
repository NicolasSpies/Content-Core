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
            echo '<p>';
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
        $allow_custom_slug = (bool) get_post_meta($post->ID, '_cc_allow_custom_slug', true);

        $group_id = get_post_meta($post->ID, '_cc_translation_group', true);

        echo '<div class="cc-language-selector">';
        echo '<label>' . __('Current Language', 'content-core') . '</label>';
        echo '<select name="cc_language">';
        foreach ($settings['languages'] as $l) {
            echo '<option value="' . esc_attr($l['code']) . '" ' . selected($current_lang, $l['code'], false) . '>' . esc_html($l['label']) . ' (' . strtoupper(esc_html($l['code'])) . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        if ($current_lang !== $default_lang) {
            echo '<div class="cc-language-selector">';
            echo '<label>';
            echo '<input type="hidden" name="cc_allow_custom_slug" value="0">';
            echo '<input type="checkbox" name="cc_allow_custom_slug" value="1" ' . checked($allow_custom_slug, true, false) . '>';
            echo esc_html__('Allow custom slug for this translation', 'content-core');
            echo '</label>';
            echo '<p>';
            echo esc_html__('Disabled by default. When disabled, slug is auto-synced from this translation title on save.', 'content-core');
            echo '</p>';
            echo '</div>';
        }

        if ($group_id) {
            echo '<div>';
            echo '<strong>' . __('Translation Group ID', 'content-core') . '</strong>';
            echo '<code>' . esc_html($group_id) . '</code>';
            echo '</div>';

            $translations = $this->module->get_translation_manager()->get_translations($group_id);
            echo '<div class="cc-translations-list">';
            echo '<label>' . __('Translations', 'content-core') . '</label>';
            echo '<ul>';
            foreach ($settings['languages'] as $l) {
                $lang = $l['code'];
                $flag_id = $l['flag_id'] ?? 0;

                if ($lang === $current_lang) {
                    continue;
                }

                echo '<li>';

                echo '<div>';
                echo $this->module->get_flag_html($lang, $flag_id);
                echo '<span>' . esc_html($l['label']) . '</span>';
                echo '</div>';

                if (isset($translations[$lang])) {
                    $translated_post = get_post($translations[$lang]);
                    $status_color = $translated_post->post_status === 'publish' ? '#2271b1' : '#646970';
                    $status_label = get_post_status_object($translated_post->post_status)->label;

                    echo '<a href="' . get_edit_post_link($translations[$lang]) . '" title="' . esc_attr($translated_post->post_title) . '">';
                    echo '<span class="dashicons dashicons-edit"></span>';
                    echo '<span>' . esc_html($status_label) . '</span>';
                    echo '</a>';
                } else {
                    $create_url = add_query_arg([
                        'action' => 'cc_create_translation',
                        'post' => $post->ID,
                        'lang' => $lang,
                        'nonce' => wp_create_nonce('cc_create_translation_' . $post->ID)
                    ], admin_url('admin.php'));

                    echo '<a href="' . esc_url($create_url) . '" class="button button-small"><span class="dashicons dashicons-plus-alt2"></span> ' . __('Create', 'content-core') . '</a>';
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

        $settings = $this->module->get_settings();
        $default_lang = sanitize_key((string) ($settings['default_lang'] ?? 'de'));
        $current_lang = sanitize_key((string) get_post_meta($post_id, '_cc_language', true));
        if ($current_lang === '') {
            $current_lang = $default_lang;
        }

        if ($current_lang !== $default_lang) {
            $allow_custom_slug = !empty($_POST['cc_allow_custom_slug']) && (string) $_POST['cc_allow_custom_slug'] === '1';
            update_post_meta($post_id, '_cc_allow_custom_slug', $allow_custom_slug ? '1' : '0');
        } else {
            delete_post_meta($post_id, '_cc_allow_custom_slug');
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
        <div class="form-field term-group">
            <input type="hidden" name="cc_term_language" value="<?php echo esc_attr($default_lang); ?>">
        </div>
        <div class="form-field">
            <p class="description">
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
