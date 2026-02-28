<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles menu visibility, ordering, and admin bar settings.
 */
class VisibilitySettings
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
     * @var array Cache of the full, unmodified menu
     */
    private array $full_menu_cache = [];

    /**
     * Remove menu pages based on visibility settings.
     *
     * @return void
     */
    public function apply_menu_visibility(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $menu;
        if (is_array($menu) && empty($this->full_menu_cache)) {
            $this->full_menu_cache = $menu;
        }

        $settings = $this->module->get_registry()->get(SettingsModule::OPTION_KEY);
        $hidden = $this->get_hidden_slugs($settings);

        foreach ($hidden as $slug) {
            // Admin Safety Override: Always retain these regardless of toggle.
            if (in_array($slug, SettingsModule::ADMIN_SAFETY_SLUGS, true)) {
                continue;
            }

            remove_menu_page($slug);
        }
    }

    /**
     * Returns the pre-filtered original global $menu.
     */
    public function get_full_menu_cache(): array
    {
        global $menu;
        return !empty($this->full_menu_cache) ? $this->full_menu_cache : (is_array($menu) ? $menu : []);
    }

    /**
     * Reorder menu items based on settings.
     *
     * @return void
     */
    public function apply_menu_order(): void
    {
        global $menu;

        if (!is_array($menu) || empty($menu)) {
            return;
        }

        $saved_order = $this->module->get_registry()->get(SettingsModule::ORDER_KEY);
        if (empty($saved_order)) {
            return;
        }

        $is_admin = current_user_can('manage_options');
        $role_key = $is_admin ? 'admin' : 'client';
        $order = $saved_order[$role_key] ?? [];

        if (empty($order)) {
            return;
        }

        // Build map: slug => menu item
        $slug_map = [];
        foreach ($menu as $key => $item) {
            $slug = $item[2] ?? '';
            if (!empty($slug)) {
                $slug_map[$slug] = $item;
            }
        }

        // Rebuild $menu: ordered items first, then unordered items, preserving separators
        $new_menu = [];
        $position = 1;

        // Place ordered items first
        foreach ($order as $slug) {
            if (isset($slug_map[$slug])) {
                $new_menu[$position] = $slug_map[$slug];
                unset($slug_map[$slug]);
                $position++;
            }
        }

        // Append remaining items (preserves items added by other plugins)
        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            if (isset($slug_map[$slug])) {
                $new_menu[$position] = $item;
                unset($slug_map[$slug]);
                $position++;
            }
        }

        $menu = $new_menu;
    }

    /**
     * Remove admin bar nodes based on settings.
     *
     * @return void
     */
    public function apply_admin_bar_visibility(): void
    {
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof \WP_Admin_Bar)) {
            return;
        }

        $settings = $this->module->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
        if (empty($settings)) {
            return;
        }

        if (!empty($settings['hide_wp_logo'])) {
            $wp_admin_bar->remove_node('wp-logo');
        }
        if (!empty($settings['hide_comments'])) {
            $wp_admin_bar->remove_node('comments');
        }
        if (!empty($settings['hide_new_content'])) {
            $wp_admin_bar->remove_node('new-content');
        }
    }

    /**
     * Override the admin bar site-name node href (and optionally target).
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public function apply_admin_bar_site_link(\WP_Admin_Bar $wp_admin_bar): void
    {
        $settings = $this->module->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
        if (empty($settings['enabled']) || empty($settings['url'])) {
            return;
        }

        $node = $wp_admin_bar->get_node('site-name');
        if (!$node) {
            return;
        }

        $args = [
            'id' => 'site-name',
            'href' => $settings['url'],
        ];

        if (!empty($settings['new_tab'])) {
            add_action('admin_footer', function () {
                ?>
                <script>
                    (function () {
                        var el = document.querySelector('#wp-admin-bar-site-name > a');
                        if (el) {
                            el.setAttribute('target', '_blank');
                            el.setAttribute('rel', 'noopener');
                        }
                    })();
                </script>
                <?php
            });
        }

        $wp_admin_bar->add_node($args);
    }

    /**
     * Parse order input from JSON.
     *
     * @param string $raw
     * @return array
     */
    private function parse_order_input(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode(stripslashes($raw), true);
        if (!is_array($decoded)) {
            return [];
        }

        // Sanitize each slug in the order array
        return array_values(array_filter(array_map('sanitize_key', $decoded)));
    }

    /**
     * Helper to get hidden slugs.
     *
     * @param array $settings
     * @return array
     */
    private function get_hidden_slugs(array $settings): array
    {
        if (empty($settings)) {
            return [];
        }

        $items = $settings['admin'] ?? [];
        $hidden = [];

        foreach ($items as $slug => $visible) {
            if (!$visible) {
                $hidden[] = $slug;
            }
        }

        return $hidden;
    }
}
