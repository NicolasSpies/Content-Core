<?php
namespace ContentCore\Modules\Diagnostics;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckRegistry;
use ContentCore\Modules\Diagnostics\Rest\DiagnosticsRestController;

class DiagnosticsModule implements ModuleInterface
{
    /** @var HealthCheckRegistry */
    private $registry;

    /** @var DiagnosticsRestController */
    private $rest_controller;

    public function init(): void
    {
        $this->registry = new HealthCheckRegistry();

        $this->register_checks();

        $this->rest_controller = new DiagnosticsRestController($this->registry);
        $this->rest_controller->init();
    }

    private function register_checks(): void
    {
        $this->registry->register(new Checks\MultilingualIntegrityCheck());
        $this->registry->register(new Checks\SettingsIntegrityCheck());
        $this->registry->register(new Checks\StructuralSanityCheck());
    }

    public function get_registry(): HealthCheckRegistry
    {
        return $this->registry;
    }
}
