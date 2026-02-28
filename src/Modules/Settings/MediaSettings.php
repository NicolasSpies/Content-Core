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
