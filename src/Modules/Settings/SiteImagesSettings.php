<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles site-wide image settings like Favicons and Social Icons.
 */
class SiteImagesSettings
{
    /** @var SettingsModule */
    private $module;

    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        $this->module->get_registry()->register('cc_site_images', [
            'default' => [
                'social_icon_id' => 0,
                'og_default_id' => 0,
                'apple_touch_id' => 0,
            ],
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings(array $settings): array
    {
        return [
            'social_icon_id' => absint($settings['social_icon_id'] ?? 0),
            'og_default_id' => absint($settings['og_default_id'] ?? 0),
            'apple_touch_id' => absint($settings['apple_touch_id'] ?? 0),
        ];
    }
}
