<?php
namespace ContentCore\Modules\OptionsPages;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\OptionsPages\Data\OptionsPagePostType;

class OptionsPagesModule implements ModuleInterface
{

    /**
     * Initialize the Options Pages module
     *
     * @return void
     */
    public function init(): void
    {
        // Register the underlying Custom Post Type that stores the definitions
        add_action('init', [$this, 'register_post_type']);

        // Register Admin Hook
        if (is_admin()) {
            $admin = new \ContentCore\Modules\OptionsPages\Admin\OptionsPageAdmin();
            $admin->register();
        }
    }

    /**
     * Register the CPT
     */
    public function register_post_type(): void
    {
        $post_type = new OptionsPagePostType();
        $post_type->register();
    }

}