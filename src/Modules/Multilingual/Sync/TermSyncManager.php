<?php
namespace ContentCore\Modules\Multilingual\Sync;

use ContentCore\Modules\Multilingual\Data\TermTranslationManager;

class TermSyncManager
{
    /** @var callable */
    private $settings_getter;

    /** @var TermTranslationManager */
    private $term_translation_manager;

    public function __construct(callable $settings_getter, TermTranslationManager $term_translation_manager)
    {
        $this->settings_getter = $settings_getter;
        $this->term_translation_manager = $term_translation_manager;
    }

    public function init(): void
    {
        add_action('edited_term', [$this, 'handle_term_save'], 10, 3);
        add_action('create_term', [$this, 'handle_term_save'], 10, 3);
        add_action('admin_action_cc_create_term_translation', [$this, 'handle_create_term_translation']);
    }

    public function handle_term_save(int $term_id, int $tt_id, string $taxonomy): void
    {
        // Persist language selection from the edit-term form.
        if (isset($_POST['cc_term_language'])) {
            update_term_meta($term_id, '_cc_language', sanitize_text_field($_POST['cc_term_language']));
        }

        // Auto-assign default language if still missing (first save on Add New).
        if (!get_term_meta($term_id, '_cc_language', true)) {
            $settings = call_user_func($this->settings_getter);
            update_term_meta($term_id, '_cc_language', $settings['default_lang'] ?? 'de');
        }

        // Ensure every term has a translation group.
        if (!get_term_meta($term_id, '_cc_translation_group', true)) {
            update_term_meta($term_id, '_cc_translation_group', wp_generate_uuid4());
        }
    }

    /**
     * Handle flag click to create a new term translation.
     * After creation, redirect back to the originating list table.
     */
    public function handle_create_term_translation(): void
    {
        $term_id = (int) ($_GET['term'] ?? 0);
        $taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
        $lang = sanitize_text_field($_GET['lang'] ?? '');

        check_admin_referer('cc_create_term_translation_' . $term_id, 'nonce');

        if (!current_user_can('manage_categories')) {
            wp_die(__('You do not have permission to create term translations.', 'content-core'));
        }

        $new_term_id = $this->term_translation_manager->create_term_translation($term_id, $lang, $taxonomy);

        if (is_wp_error($new_term_id)) {
            wp_die($new_term_id->get_error_message());
        }

        // Redirect back to the list (or to the edit screen if no redirect_to given)
        if (!empty($_GET['redirect_to'])) {
            $redirect = esc_url_raw(urldecode($_GET['redirect_to']));
        } else {
            $redirect = get_edit_term_link($new_term_id, $taxonomy);
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
