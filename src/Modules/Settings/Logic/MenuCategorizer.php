<?php
namespace ContentCore\Modules\Settings\Logic;

/**
 * Class MenuCategorizer
 *
 * Handles the retrieval and categorization of WordPress admin menu items.
 */
class MenuCategorizer
{
    /** @var \ContentCore\Modules\Settings\SettingsModule */
    private $module;

    public function __construct(\ContentCore\Modules\Settings\SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Get all registered menu items from the visibility settings cache.
     */
    public function get_all_menu_items(): array
    {
        $visibility = $this->module->get_submodule('visibility');
        if (!$visibility instanceof \ContentCore\Modules\Settings\VisibilitySettings) {
            return [];
        }

        $menu = $visibility->get_full_menu_cache();
        $items = [];

        if (!is_array($menu)) {
            return $items;
        }

        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            $title = $item[0] ?? '';

            if (empty($slug) || empty($title))
                continue;
            if (strpos($item[4] ?? '', 'wp-menu-separator') !== false)
                continue;

            $clean = wp_strip_all_tags($title);
            if (empty($clean))
                continue;

            $items[$slug] = $clean;
        }

        return $items;
    }

    /**
     * Categorize menu items into Core, Appearance, System, and Other.
     */
    public function categorize_items(array $items): array
    {
        $core = [];
        $appearance = [];
        $system = [];
        $other = [];

        $appearance_slugs = ['themes.php'];
        $system_slugs = ['plugins.php', 'users.php', 'tools.php', 'options-general.php'];
        $skip_slugs = ['content-core'];

        foreach ($items as $slug => $title) {
            if (in_array($slug, $skip_slugs, true)) {
                continue;
            }
            if ($this->is_content_slug($slug)) {
                $core[$slug] = $title;
            } elseif (in_array($slug, $appearance_slugs, true)) {
                $appearance[$slug] = $title;
            } elseif (in_array($slug, $system_slugs, true)) {
                $system[$slug] = $title;
            } else {
                $other[$slug] = $title;
            }
        }

        return [
            'Core' => $core,
            'Appearance' => $appearance,
            'System' => $system,
            'Other / Third Party' => $other,
        ];
    }

    /**
     * Determine if a slug represents a content / post-type list screen.
     */
    private function is_content_slug(string $slug): bool
    {
        // Built-in content screens
        if (in_array($slug, ['index.php', 'edit.php', 'upload.php', 'edit-comments.php'], true)) {
            return true;
        }

        // Pages
        if ($slug === 'edit.php?post_type=page') {
            return true;
        }

        // Custom post type list screens (edit.php?post_type=…)
        if (strpos($slug, 'edit.php?post_type=') === 0) {
            $pt = str_replace('edit.php?post_type=', '', $slug);
            // Exclude Content Core internal CPTs
            if (strpos($pt, 'cc_') === 0) {
                return false;
            }
            // Check if it's a real public CPT
            $obj = get_post_type_object($pt);
            if ($obj && $obj->public && $obj->show_in_menu) {
                return true;
            }
        }

        return false;
    }
}
