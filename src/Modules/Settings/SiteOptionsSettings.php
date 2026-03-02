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
     * Initialize Site Options settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register('cc_site_options_schema', [
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_schema'],
        ]);
    }

    /**
     * Sanitize Site Options schema.
     */
    public function sanitize_schema(array $schema): array
    {
        // Recursively sanitize the schema tree
        return $schema; // TODO: Implement deeper validation if needed
    }

    /**
     * @param SettingsModule $module
     */
    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }
}
