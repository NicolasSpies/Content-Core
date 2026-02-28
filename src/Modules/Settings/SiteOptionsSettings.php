<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles site options schema settings.
 */
class SiteOptionsSettings
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
