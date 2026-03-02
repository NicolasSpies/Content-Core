<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles media-related settings and logic.
 */
class MediaSettings
{
    /**
     * @var SettingsModule
     */
    private $module;

    /**
     * Initialize Media settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register(SettingsModule::MEDIA_KEY, [
            'default' => [
                'upload_limit_mb' => 25,
                'jpeg_quality' => 82,
                'clean_filenames' => true,
                'generate_webp' => false,
            ],
            'sanitize_callback' => [$this, 'sanitize_media_settings'],
        ]);
    }

    /**
     * Sanitize Media settings.
     */
    public function sanitize_media_settings(array $settings): array
    {
        return [
            'upload_limit_mb' => absint($settings['upload_limit_mb'] ?? 25),
            'jpeg_quality' => min(100, max(1, absint($settings['jpeg_quality'] ?? 82))),
            'clean_filenames' => !empty($settings['clean_filenames']),
            'generate_webp' => !empty($settings['generate_webp']),
        ];
    }

    /**
     * @param SettingsModule $module
     */
    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Filter: upload_size_limit
     *
     * @param  int $size
     * @return int
     */
    public function apply_upload_size_limit(int $size): int
    {
        $media = $this->module->get_registry()->get(SettingsModule::MEDIA_KEY);
        $limit = $media['upload_limit_mb'] ?? 25;

        if (!is_numeric($limit) || (int) $limit < 1) {
            return $size;
        }

        $limit_bytes = (int) $limit * 1048576;
        return min($size, $limit_bytes);
    }

}
