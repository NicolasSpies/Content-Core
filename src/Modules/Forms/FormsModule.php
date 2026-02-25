<?php
namespace ContentCore\Modules\Forms;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\Forms\Admin\FormAdmin;
use ContentCore\Modules\Forms\Rest\FormRestController;

class FormsModule implements ModuleInterface
{
    private ?FormAdmin $admin = null;
    private ?FormRestController $rest = null;

    public function init(): void
    {
        if (is_admin()) {
            $this->admin = new FormAdmin();
            $this->admin->init();
        }

        $this->rest = new FormRestController();
        $this->rest->init();
    }
}