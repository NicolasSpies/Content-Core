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

}
