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
     * Initialize Visibility settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register(SettingsModule::OPTION_KEY, [
            'default' => [
                'admin' => [],
                'client' => [],
            ],
            'sanitize_callback' => [$this, 'sanitize_visibility_settings'],
        ]);

        $this->module->get_registry()->register(SettingsModule::ADMIN_BAR_KEY, [
            'default' => [
                'enabled' => false,
                'hide_wp_logo' => false,
                'hide_comments' => false,
                'hide_new_content' => false,
                'url' => '',
                'new_tab' => false,
            ],
            'sanitize_callback' => [$this, 'sanitize_admin_bar_settings'],
        ]);

        $this->module->get_registry()->register(SettingsModule::ORDER_KEY, [
            'default' => [
                'admin' => [],
                'client' => [],
            ],
        ]);
    }

    /**
     * Sanitize visibility settings.
     */
    public function sanitize_visibility_settings(array $settings): array
    {
        $sanitized = ['admin' => [], 'client' => []];
        if (isset($settings['admin']) && is_array($settings['admin'])) {
            foreach ($settings['admin'] as $slug => $visible) {
                $clean_slug = sanitize_text_field((string) $slug);
                if ($clean_slug === '') {
                    continue;
                }
                $sanitized['admin'][$clean_slug] = !empty($visible);
            }
        }
        if (isset($settings['client']) && is_array($settings['client'])) {
            foreach ($settings['client'] as $slug => $visible) {
                $clean_slug = sanitize_text_field((string) $slug);
                if ($clean_slug === '') {
                    continue;
                }
                $sanitized['client'][$clean_slug] = !empty($visible);
            }
        }

        // Always keep critical menu entries visible for administrators.
        foreach (SettingsModule::ADMIN_SAFETY_SLUGS as $slug) {
            $sanitized['admin'][$slug] = true;
        }

        // Keep dynamic/content post type list screens visible for both roles.
        foreach ($this->get_always_visible_post_type_menu_slugs() as $slug) {
            $sanitized['admin'][$slug] = true;
            $sanitized['client'][$slug] = true;
        }

        return $sanitized;
    }

    /**
     * Sanitize admin bar settings.
     */
    public function sanitize_admin_bar_settings(array $settings): array
    {
        return [
            'enabled' => !empty($settings['enabled']),
            'hide_wp_logo' => !empty($settings['hide_wp_logo']),
            'hide_comments' => !empty($settings['hide_comments']),
            'hide_new_content' => !empty($settings['hide_new_content']),
            'url' => esc_url_raw($settings['url'] ?? ''),
            'new_tab' => !empty($settings['new_tab']),
        ];
    }

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

            // Keep dynamic/content post type list screens visible.
            if (in_array($slug, $this->get_always_visible_post_type_menu_slugs(), true)) {
                continue;
            }

            remove_menu_page($slug);
        }

        // Remove the top separator when Dashboard is hidden to avoid a visual gap.
        if (!$this->menu_contains_slug('index.php')) {
            remove_menu_page('separator1');
        }

        // Normalize sidebar spacing and remove separator gaps around core plugin entries.
        $this->normalize_menu_separators();
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
            'meta' => [],
        ];

        if (!empty($settings['new_tab'])) {
            $args['meta']['target'] = '_blank';
            $args['meta']['rel'] = 'noopener noreferrer';
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

    /**
     * Menu slugs for user-facing custom post types that remain visible.
     */
    private function get_always_visible_post_type_menu_slugs(): array
    {
        $slugs = [];
        $post_types = get_post_types(['show_ui' => true], 'objects');
        if (!is_array($post_types)) {
            return $slugs;
        }

        foreach ($post_types as $post_type => $obj) {
            $slug = sanitize_key((string) $post_type);
            if ($slug === '' || in_array($slug, ['post', 'page', 'attachment', 'nav_menu_item'], true)) {
                continue;
            }
            if (strpos($slug, 'cc_') === 0 || strpos($slug, 'wp_') === 0) {
                continue;
            }

            $show_in_menu = isset($obj->show_in_menu) ? $obj->show_in_menu : true;
            if ($show_in_menu === false) {
                continue;
            }

            $slugs[] = 'edit.php?post_type=' . $slug;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Check whether a menu slug exists in the current global menu.
     */
    private function menu_contains_slug(string $slug): bool
    {
        global $menu;
        if (!is_array($menu)) {
            return false;
        }

        foreach ($menu as $item) {
            if (($item[2] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove unintended separator gaps in admin sidebar.
     */
    private function normalize_menu_separators(): void
    {
        global $menu;
        if (!is_array($menu) || empty($menu)) {
            return;
        }

        $items = array_values($menu);
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = (string) ($item[2] ?? '');
            $is_separator = $this->is_menu_separator($item);

            // Remove separator directly before key plugin entries.
            if (in_array($slug, ['content-core', 'cc-site-options'], true) && !empty($result)) {
                $prev = $result[count($result) - 1];
                if ($this->is_menu_separator($prev)) {
                    array_pop($result);
                }
            }

            // Skip leading separators and duplicate separators.
            if ($is_separator) {
                if (empty($result) || $this->is_menu_separator($result[count($result) - 1])) {
                    continue;
                }
            }

            $result[] = $item;
        }

        // Remove trailing separator if any.
        if (!empty($result) && $this->is_menu_separator($result[count($result) - 1])) {
            array_pop($result);
        }

        // Rebuild numeric positions to keep menu stable.
        $rebuilt = [];
        $pos = 1;
        foreach ($result as $item) {
            $rebuilt[$pos] = $item;
            $pos++;
        }
        $menu = $rebuilt;
    }

    /**
     * Detect WP admin separator entries.
     */
    private function is_menu_separator(array $item): bool
    {
        $slug = (string) ($item[2] ?? '');
        $classes = (string) ($item[4] ?? '');
        return strpos($slug, 'separator') === 0 || strpos($classes, 'wp-menu-separator') !== false;
    }
}
