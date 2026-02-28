<?php
namespace ContentCore\Modules\Multilingual\Sync;

use ContentCore\Modules\Multilingual\Data\TranslationManager;

class PostSyncManager
{
    /** @var callable */
    private $settings_getter;

    /** @var TranslationManager */
    private $translation_manager;

    /** @var callable */
    private $is_active_check;

    public function __construct(callable $settings_getter, callable $is_active_check, TranslationManager $translation_manager)
    {
        $this->settings_getter = $settings_getter;
        $this->is_active_check = $is_active_check;
        $this->translation_manager = $translation_manager;
    }

    private function is_active(): bool
    {
        return call_user_func($this->is_active_check);
    }

    public function init(): void
    {
        add_action('admin_action_cc_create_translation', [$this, 'handle_create_translation']);
        add_action('wp_insert_post', [$this, 'force_default_language_on_insert'], 10, 3);
        add_action('save_post', [$this, 'handle_post_save'], 10, 2);
    }

    /**
     * Handle the admin action to translate a post from the list table.
     */
    public function handle_create_translation(): void
    {
        check_admin_referer('cc_create_translation_' . $_GET['post'], 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to create translations.', 'content-core'));
        }

        $source_id = (int) $_GET['post'];
        $target_lang = sanitize_text_field($_GET['lang']);

        $new_id = $this->translation_manager->create_translation($source_id, $target_lang);

        if (is_wp_error($new_id)) {
            wp_die($new_id->get_error_message());
        }

        // Sync term groups immediately on translation creation
        $groupsByTax = $this->cc_get_selected_term_groups($source_id);
        $this->cc_set_terms_for_post_from_groups($new_id, $target_lang, $groupsByTax);

        // If a redirect_to was provided (e.g. pointing back to the list table), use it;
        // otherwise fall back to the edit screen for the new translation.
        if (!empty($_GET['redirect_to'])) {
            $redirect_to = esc_url_raw(urldecode($_GET['redirect_to']));
        } else {
            $redirect_to = get_edit_post_link($new_id, 'raw');
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    public function force_default_language_on_insert(int $post_id, \WP_Post $post, bool $update): void
    {
        static $inserting = false;
        if ($inserting) {
            return;
        }

        if (!post_type_supports($post->post_type, 'cc-multilingual')) {
            return;
        }

        if (!$this->is_active()) {
            return;
        }

        if ($post->post_status === 'auto-draft' || $post->post_status === 'draft') {
            $existing_lang = get_post_meta($post_id, '_cc_language', true);
            if (empty($existing_lang)) {
                $settings = call_user_func($this->settings_getter);
                $default_lang = $settings['default_lang'] ?? 'de';

                $inserting = true;
                update_post_meta($post_id, '_cc_language', $default_lang);

                // Also ensure translation group is set immediately so it isn't orphaned
                if (!get_post_meta($post_id, '_cc_translation_group', true)) {
                    update_post_meta($post_id, '_cc_translation_group', wp_generate_uuid4());
                }
                $inserting = false;
            }
        }
    }

    public function handle_post_save(int $post_id, \WP_Post $post): void
    {
        // Prevent infinite loops during cross-language term sync
        static $syncing = false;
        if ($syncing) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if ($post->post_status === 'auto-draft' || $post->post_type === 'revision')
            return;
        if (!post_type_supports($post->post_type, 'cc-multilingual'))
            return;

        if (!$this->is_active())
            return;

        // Ensure default language on new posts
        if (!get_post_meta($post_id, '_cc_language', true)) {
            $settings = call_user_func($this->settings_getter);
            $admin_lang = get_user_meta(get_current_user_id(), 'cc_admin_language', true);
            $lang = (!empty($admin_lang) && $admin_lang !== 'all') ? $admin_lang : ($settings['default_lang'] ?? 'de');
            update_post_meta($post_id, '_cc_language', $lang);
        }

        // Ensure translation group ID
        if (!get_post_meta($post_id, '_cc_translation_group', true)) {
            update_post_meta($post_id, '_cc_translation_group', wp_generate_uuid4());
        }

        // Auto-generate slug if empty and title exists
        if (empty($post->post_name) && !empty($post->post_title)) {
            $desired_slug = sanitize_title($post->post_title);
            $unique_slug = wp_unique_post_slug(
                $desired_slug,
                $post_id,
                $post->post_status,
                $post->post_type,
                $post->post_parent
            );

            if ($unique_slug !== $post->post_name) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    ['post_name' => $unique_slug],
                    ['ID' => $post_id]
                );
                // Clean cache
                clean_post_cache($post_id);
            }
        }

        // Synchronize taxonomy terms across translated posts
        $syncing = true;
        try {
            $post_group_id = get_post_meta($post_id, '_cc_translation_group', true);
            if ($post_group_id) {
                $translations = $this->translation_manager->get_translations($post_group_id);
                if (!empty($translations) && count($translations) > 1) {
                    $groupsByTax = $this->cc_get_selected_term_groups($post_id);
                    foreach ($translations as $lang => $translated_post_id) {
                        if ($translated_post_id === $post_id) {
                            continue;
                        }
                        $this->cc_set_terms_for_post_from_groups($translated_post_id, $lang, $groupsByTax);
                    }
                }
            }
        } finally {
            $syncing = false;
        }
    }

    /**
     * Term Sync Logic for Post Translations
     */
    public function cc_get_selected_term_groups(int $post_id): array
    {
        $selected_groups = [];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach ($taxonomies as $tax_name => $tax_obj) {
            $terms = get_the_terms($post_id, $tax_name);
            $groups = [];

            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $group = get_term_meta($term->term_id, '_cc_translation_group', true);
                    if ($group) {
                        $groups[] = $group;
                    }
                }
            }

            // Always set the key, even if empty, so we know to clear translations
            $selected_groups[$tax_name] = $groups;
        }
        return $selected_groups;
    }

    public function cc_terms_for_language_from_groups(string $tax_name, string $lang, array $groups): array
    {
        global $wpdb;
        if (empty($groups))
            return [];

        $placeholders = implode(',', array_fill(0, count($groups), '%s'));
        $query = "
            SELECT t.term_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->termmeta} tm_group ON t.term_id = tm_group.term_id AND tm_group.meta_key = '_cc_translation_group'
            INNER JOIN {$wpdb->termmeta} tm_lang ON t.term_id = tm_lang.term_id AND tm_lang.meta_key = '_cc_language'
            WHERE tt.taxonomy = %s
            AND tm_lang.meta_value = %s
            AND tm_group.meta_value IN ($placeholders)
        ";

        $args = array_merge([$tax_name, $lang], $groups);
        $results = $wpdb->get_col($wpdb->prepare($query, ...$args));
        return array_map('intval', $results);
    }

    public function cc_set_terms_for_post_from_groups(int $post_id, string $lang, array $groupsByTax): void
    {
        foreach ($groupsByTax as $tax_name => $groups) {
            if (empty($groups)) {
                wp_set_object_terms($post_id, [], $tax_name, false);
            } else {
                $target_term_ids = $this->cc_terms_for_language_from_groups($tax_name, $lang, $groups);
                wp_set_object_terms($post_id, $target_term_ids, $tax_name, false);
            }
        }
    }
}
