<?php
namespace ContentCore;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\CustomFields\CustomFieldsModule;

class Plugin
{

    /**
     * Single instance of the class
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Registered modules
     *
     * @var array
     */
    private array $modules = [];
    private array $active_modules = [];
    private array $missing_modules = [];

    /**
     * Main Instance
     *
     * @return Plugin
     */
    public static function get_instance(): Plugin
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
    // Private to enforce singleton
    }

    /**
     * Initialize the plugin and load modules
     */
    public function init(): void
    {
        $this->register_modules();
        $this->init_modules();

        // Add defensive notice for missing dependencies
        add_action('admin_notices', [$this, 'render_missing_dependency_notices']);
    }

    /**
     * Register all active modules
     */
    private function register_modules(): void
    {
        // Initialize Admin UI helpers
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            $disable_editor = new \ContentCore\Admin\DisableEditor();
            $disable_editor->init();

            $assets = new \ContentCore\Admin\Assets();
            $assets->init();

            $admin_menu = new \ContentCore\Admin\AdminMenu();
            $admin_menu->init();
        }

        // Define module classes to dynamically load safely
        $module_classes = [
            'custom_fields' => CustomFieldsModule::class ,
            'rest_api' => \ContentCore\Modules\RestApi\RestApiModule::class ,
            'options_pages' => \ContentCore\Modules\OptionsPages\OptionsPagesModule::class ,
            'content_types' => \ContentCore\Modules\ContentTypes\ContentTypesModule::class ,
            'settings' => \ContentCore\Modules\Settings\SettingsModule::class ,
            'multilingual' => \ContentCore\Modules\Multilingual\MultilingualModule::class ,
            'media' => \ContentCore\Modules\Media\MediaModule::class ,
            'seo' => \ContentCore\Modules\Seo\SeoModule::class ,
            'language_mapping' => \ContentCore\Modules\LanguageMapping\LanguageMappingModule::class ,
            'forms' => \ContentCore\Modules\Forms\FormsModule::class ,
            'site_options' => \ContentCore\Modules\SiteOptions\SiteOptionsModule::class ,
        ];

        foreach ($module_classes as $id => $class_name) {
            try {
                if (class_exists($class_name)) {
                    $this->modules[$id] = new $class_name();
                }
            }
            catch (\Throwable $e) {
                // Error logged by caller if necessary

                // Capture actionable info for admin UI
                $this->missing_modules[] = sprintf(
                    '%s (Instantiation failed in %s:%d: %s)',
                    $class_name,
                    basename($e->getFile()),
                    $e->getLine(),
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Get a registered module instance
     */
    public function get_module(string $module_id): ?ModuleInterface
    {
        return $this->modules[$module_id] ?? null;
    }

    /**
     * Check if a module is registered and active
     */
    public function is_module_active(string $module_id): bool
    {
        return isset($this->modules[$module_id]);
    }

    /**
     * Run the init method on all registered modules
     */
    private function init_modules(): void
    {
        foreach ($this->modules as $module_id => $instance) {
            if ($instance instanceof ModuleInterface) {
                try {
                    $instance->init();
                    $this->active_modules[$module_id] = get_class($instance);
                }
                catch (\Throwable $e) {
                    // Error logged by caller if necessary

                    $this->missing_modules[] = sprintf(
                        '%s (Init failed in %s:%d: %s)',
                        get_class($instance),
                        basename($e->getFile()),
                        $e->getLine(),
                        $e->getMessage()
                    );
                    unset($this->modules[$module_id]);
                }
            }
            else {
                $this->missing_modules[] = (is_object($instance) ? get_class($instance) : $module_id) . ' (Invalid module)';
                unset($this->modules[$module_id]);
            }
        }
    }

    /**
     * Get active modules
     */
    public function get_active_modules(): array
    {
        return $this->active_modules;
    }

    /**
     * Get missing_modules
     */
    public function get_missing_modules(): array
    {
        return $this->missing_modules;
    }

    /**
     * Get version
     */
    public function get_version(): string
    {
        return '1.0.5';
    }

    /**
     * Render notices if modules are missing
     */
    public function render_missing_dependency_notices(): void
    {
        if (empty($this->missing_modules)) {
            return;
        }

        foreach ($this->missing_modules as $module) {
            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: %s: module class name */
                esc_html__('Content Core Warning: %s failed to load. Some features may be disabled.', 'content-core'),
                '<code>' . esc_html($module) . '</code>'
            );
            echo '</p></div>';
        }
    }
}