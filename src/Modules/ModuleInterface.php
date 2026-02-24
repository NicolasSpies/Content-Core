<?php
namespace ContentCore\Modules;

interface ModuleInterface
{
    /**
     * Initialize the module and hook into WordPress.
     *
     * @return void
     */
    public function init(): void;
}