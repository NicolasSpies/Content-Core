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
                'enabled' => false,
                'max_dimension_px' => 2000,
                'quality' => 70,
                'png_mode' => 'lossless',
                'delete_original' => false,
                'upload_limit_mb' => 25,
            ],
            'sanitize_callback' => [$this, 'sanitize_media_settings'],
        ]);
    }

    /**
     * Sanitize Media settings.
     */
    public function sanitize_media_settings(array $settings): array
    {
        $quality = isset($settings['quality']) ? $settings['quality'] : ($settings['jpeg_quality'] ?? 70);
        $max_dimension = $settings['max_dimension_px'] ?? $settings['max_width_px'] ?? 2000;
        $png_mode = $settings['png_mode'] ?? 'lossless';
        if (!in_array($png_mode, ['lossless', 'lossy'], true)) {
            $png_mode = 'lossless';
        }

        return [
            'enabled' => !empty($settings['enabled']) || !empty($settings['generate_webp']),
            'max_dimension_px' => max(100, absint($max_dimension)),
            'quality' => min(100, max(1, absint($quality))),
            'png_mode' => $png_mode,
            'delete_original' => !empty($settings['delete_original']),
            'upload_limit_mb' => absint($settings['upload_limit_mb'] ?? 25),
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
