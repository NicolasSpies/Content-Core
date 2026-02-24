<?php
namespace ContentCore\Modules\CustomFields;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\CustomFields\Data\FieldGroupPostType;

class CustomFieldsModule implements ModuleInterface
{

    /**
     * Initialize the Custom Fields module
     *
     * @return void
     */
    public function init(): void
    {
        $this->register_post_types();
        $this->register_admin();
    }

    /**
     * Register required post types
     */
    private function register_post_types(): void
    {
        $field_group_cpt = new FieldGroupPostType();
        $field_group_cpt->register();
    }

    /**
     * Register admin interfaces
     */
    private function register_admin(): void
    {
        if (is_admin()) {
            $admin_group = new \ContentCore\Modules\CustomFields\Admin\FieldGroupAdmin();
            $admin_group->register();

            $admin_post_edit = new \ContentCore\Modules\CustomFields\Admin\PostEditAdmin();
            $admin_post_edit->register();
        }
    }
}