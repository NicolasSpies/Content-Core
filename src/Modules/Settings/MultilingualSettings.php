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
     * Renders the Multilingual configuration form section for Site Settings.
     *
     * @return void
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
