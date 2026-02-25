<?php
namespace ContentCore\Modules\LanguageMapping;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\LanguageMapping\Admin\LanguageMappingAdmin;

class LanguageMappingModule implements ModuleInterface
{
    private ?LanguageMappingAdmin $admin = null;

    public function init(): void
    {
        if (is_admin()) {
            $this->admin = new LanguageMappingAdmin($this);
            $this->admin->register();
        }
    }

    /**
     * Get all public taxonomies created via Content Core
     */
    public function get_cc_taxonomies(): array
    {
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $cc_taxonomies = [];

        foreach ($taxonomies as $tax) {
            // We want to identify taxonomies created via Content Core.
            // Content Core taxonomies are registered via ContentTypesModule,
            // which usually retrieves them from cc_taxonomy_def posts.
            // For now, we'll check if the taxonomy matches one of the expected slugs
            // or if it has a specific flag if we were to add one.
            // Since we don't have a reliable way to check "origin" without more data,
            // we'll list all public taxonomies but exclude standard WP ones.
            if (in_array($tax->name, ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format'], true)) {
                continue;
            }
            $cc_taxonomies[] = $tax;
        }

        return $cc_taxonomies;
    }
}