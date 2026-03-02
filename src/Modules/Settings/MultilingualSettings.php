<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles multilingual-related settings and logic.
 */
class MultilingualSettings
{
    /**
     * @var SettingsModule
     */
    private $module;

    /**
     * @param SettingsModule $module
     */
    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Initialize Multilingual settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register('cc_languages_settings', [
            'default' => [
                'enabled' => false,
                'default_lang' => 'de',
                'languages' => [
                    [
                        'code' => 'de',
                        'label' => 'Deutsch',
                        'flag_id' => 0
                    ]
                ],
                'fallback_enabled' => false,
                'fallback_lang' => 'de',
                'permalink_enabled' => false,
                'permalink_bases' => [],
                'taxonomy_bases' => [],
                'enable_rest_seo' => false,
                'enable_headless_fallback' => false,
                'enable_localized_taxonomies' => false,
                'enable_sitemap_endpoint' => false,
            ],
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitize multilingual settings.
     */
    public function sanitize_settings(array $settings): array
    {
        // Basic sanitization, can be expanded
        $settings['enabled'] = !empty($settings['enabled']);
        $settings['fallback_enabled'] = !empty($settings['fallback_enabled']);
        $settings['permalink_enabled'] = !empty($settings['permalink_enabled']);
        $settings['enable_rest_seo'] = !empty($settings['enable_rest_seo']);
        $settings['enable_headless_fallback'] = !empty($settings['enable_headless_fallback']);
        $settings['enable_localized_taxonomies'] = !empty($settings['enable_localized_taxonomies']);
        $settings['enable_sitemap_endpoint'] = !empty($settings['enable_sitemap_endpoint']);

        return $settings;
    }

    /**
     * Renders the Multilingual configuration form section for Site Settings.
     */
    public function maybe_render_form_section(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml_mod = $plugin->get_module('multilingual');
        if (!$ml_mod) {
            return;
        }

        \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render($this->module);
    }
}
