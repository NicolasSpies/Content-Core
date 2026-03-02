<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles SEO and site-wide image settings.
 */
class SeoSettings
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
     * Initialize SEO settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register(SettingsModule::SEO_KEY, [
            'default' => [
                'title' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'title_separator' => '—',
                'title_template' => '{page} {separator} {site}',
            ],
            'sanitize_callback' => [$this, 'sanitize_seo_settings'],
        ]);
    }

    /**
     * Sanitize SEO settings.
     */
    public function sanitize_seo_settings(array $settings): array
    {
        return [
            'title' => sanitize_text_field($settings['title'] ?? ''),
            'description' => sanitize_textarea_field($settings['description'] ?? ''),
            'title_separator' => sanitize_text_field($settings['title_separator'] ?? '—'),
            'title_template' => sanitize_text_field($settings['title_template'] ?? '{page} {separator} {site}'),
        ];
    }
}
