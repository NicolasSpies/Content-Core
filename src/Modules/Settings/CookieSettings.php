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

}
