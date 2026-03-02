<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles cookie banner settings.
 */
class CookieSettings
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
     * Initialize Cookie settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register(SettingsModule::COOKIE_KEY, [
            'default' => [
                'enabled' => false,
                'bannerTitle' => __('We use cookies', 'content-core'),
                'bannerText' => __('We use cookies to enhance your experience.', 'content-core'),
                'policyUrl' => '',
                'labels' => [
                    'acceptAll' => __('Accept All', 'content-core'),
                    'rejectAll' => __('Reject All', 'content-core'),
                    'settings' => __('Settings', 'content-core'),
                ],
                'categories' => [
                    'essential' => true,
                    'analytics' => false,
                    'marketing' => false,
                ],
            ],
            'sanitize_callback' => [$this, 'sanitize_cookie_settings'],
        ]);
    }

    /**
     * Sanitize Cookie settings.
     */
    public function sanitize_cookie_settings(array $settings): array
    {
        return [
            'enabled' => !empty($settings['enabled']),
            'bannerTitle' => sanitize_text_field($settings['bannerTitle'] ?? ''),
            'bannerText' => sanitize_textarea_field($settings['bannerText'] ?? ''),
            'policyUrl' => esc_url_raw($settings['policyUrl'] ?? ''),
            'labels' => [
                'acceptAll' => sanitize_text_field($settings['labels']['acceptAll'] ?? ''),
                'rejectAll' => sanitize_text_field($settings['labels']['rejectAll'] ?? ''),
                'settings' => sanitize_text_field($settings['labels']['settings'] ?? ''),
            ],
            'categories' => [
                'essential' => true,
                'analytics' => !empty($settings['categories']['analytics']),
                'marketing' => !empty($settings['categories']['marketing']),
            ],
        ];
    }
}
