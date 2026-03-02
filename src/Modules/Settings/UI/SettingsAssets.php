<?php
namespace ContentCore\Modules\Settings\UI;

/**
 * Class SettingsAssets
 * 
 * Handles all asset enqueuing for Content Core settings pages.
 */
class SettingsAssets
{
    /**
     * Enqueue all required settings assets
     */
    public function enqueue(string $hook): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml = $plugin->get_module('multilingual');
        $catalog = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml::get_language_catalog() : [];

        $screen = get_current_screen();
        if (!$screen)
            return;

        // More robust check: use screen ID instead of hook name which can vary
        $is_cc_settings = (strpos($screen->id, 'cc-') !== false || strpos($screen->id, 'content-core') !== false);

        if (!$is_cc_settings) {
            return;
        }

        // Generic settings UI assets (shared)
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_script('cc-settings-js');

        $rest_base = rest_url('content-core/v1/settings');
        wp_localize_script('cc-settings-js', 'CC_SETTINGS', [
            'restUrl' => $rest_base,
            'nonce' => wp_create_nonce('wp_rest'),
            'catalog' => $catalog,
            'strings' => [
                'langAdded' => __('Language already added.', 'content-core'),
                'confirmRemoveLang' => __('Remove this language?', 'content-core'),
                'selectFlag' => __('Select Flag Image', 'content-core'),
                'useImage' => __('Use this image', 'content-core'),
                'selectOGImage' => __('Select Default OG Image', 'content-core'),
            ]
        ]);

        // Determine if we should load the React Application
        if ($this->should_load_react($hook)) {
            $this->enqueue_react_app($rest_base, $ml);
        }
    }

    /**
     * Check if React app should be loaded based on the current hook
     */
    private function should_load_react(string $hook): bool
    {
        $react_pages = [
            'cc_site_settings', // Standard WP hooks often use underscores for slugs
            'cc-site-settings',
            'cc-multilingual',
            'cc-seo',
            'cc-site-images',
            'cc-cookie-banner',
            'cc-branding',
            'cc-diagnostics',
            'cc-site-options',
            'cc-visibility',
            'cc-media',
            'cc-redirect',
            'cc-manage-terms'
        ];

        foreach ($react_pages as $page) {
            if (strpos($hook, $page) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue the React Application and its dependencies
     */
    private function enqueue_react_app(string $rest_base, $ml): void
    {
        wp_enqueue_script('cc-site-settings-app');

        $page_slug = sanitize_text_field($_GET['page'] ?? '');
        $active_tab = $this->get_active_tab($page_slug);

        wp_localize_script('cc-site-settings-app', 'CC_SITE_SETTINGS', [
            'nonce' => wp_create_nonce('wp_rest'),
            'restBase' => $rest_base . '/site',
            'diagnosticsRestBase' => rest_url('content-core/v1/diagnostics'),
            'siteUrl' => untrailingslashit(home_url()),
            'defaultTitle' => get_bloginfo('name'),
            'defaultDesc' => get_bloginfo('description'),
            'siteOptionsUrl' => admin_url('admin.php?page=cc-site-options'),
            'activeTab' => $active_tab,
        ]);

        // Register and localize the Site Options Schema Editor JS
        wp_enqueue_script('cc-schema-editor');

        $languages = ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) ? $ml->get_settings()['languages'] : [];

        wp_localize_script('cc-schema-editor', 'ccSchemaEditorConfig', [
            'languages' => $languages,
            'strings' => [
                'sectionTitle' => __('Group Title', 'content-core'),
                'addField' => __('+ Add Field', 'content-core'),
                'confirmRemoveSection' => __('Remove this entire section and all its fields?', 'content-core'),
                'stableKey' => __('Stable Key', 'content-core'),
                'type' => __('Type', 'content-core'),
                'visible' => __('Visible', 'content-core'),
                'editable' => __('Editable', 'content-core'),
                'label' => __('Label', 'content-core'),
                'confirmRemoveField' => __('Remove this field?', 'content-core'),
            ]
        ]);
    }

    /**
     * Determine active tab for React app
     */
    private function get_active_tab(string $page_slug): string
    {
        $tabs = [
            'cc-site-images' => 'images',
            'cc-cookie-banner' => 'cookie',
            'cc-multilingual' => 'multilingual',
            'cc-site-options' => 'site-options',
            'cc-branding' => 'branding',
        ];

        return $tabs[$page_slug] ?? 'seo';
    }
}
