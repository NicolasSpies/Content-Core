<?php
namespace ContentCore\Admin;

/**
 * Class Assets
 *
 * Centralizes asset registration and management for Content Core.
 */
class Assets
{
    /**
     * Initialize asset hooks
     */
    public function init(): void
    {
        // Setup missing directories/files if needed
        $this->ensure_minimal_files();

        // Register all assets as early as possible before enqueueing logic.
        add_action('admin_enqueue_scripts', [$this, 'register_all_assets'], 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_screen_layouts'], 10);
    }

    /**
     * Register all Content Core assets globally
     */
    public function register_all_assets(): void
    {
        // 1. CSS Assets (single visual entry point)
        $this->register_style('cc-admin-ui', 'assets/css/admin-theme/index.css', ['common']);

        // 2. JS Assets
        $this->register_script('cc-toast-js', 'assets/js/toast.js', []);
        $this->register_script('cc-admin-js', 'assets/js/admin.js', ['jquery']);
        $this->register_script('cc-settings-js', 'assets/js/settings.js', ['jquery', 'jquery-ui-sortable', 'cc-toast-js']);
        $this->register_script('cc-site-settings-app', 'assets/js/site-settings-app.js', ['wp-element', 'wp-api-fetch', 'wp-i18n', 'cc-toast-js']);
        $this->register_script('cc-schema-editor', 'assets/js/schema-editor.js', ['jquery', 'jquery-ui-sortable']);
    }

    /**
     * Conditionally enqueue layout packs based on the current admin screen
     */
    public function enqueue_screen_layouts(string $hook_suffix): void
    {
        $screen = get_current_screen();
        if (!$screen)
            return;

        // Ensure base elements/tokens are loaded (required by index.css usually, but we register specific layout dependencies here if needed).
        // Dependencies for layouts assume 'cc-admin-ui' provides the tokens/base.

        // 1. List Tables
        if (in_array($screen->base, ['edit', 'users', 'plugins', 'edit-comments'])) {
            $this->enqueue_style('cc-admin-layout-list', 'assets/css/admin-theme/10-layout-list-tables.css', ['cc-admin-ui']);
        }

        // 2. Media Library
        if ('upload' === $screen->base) {
            $this->enqueue_style('cc-admin-layout-media', 'assets/css/admin-theme/11-layout-media.css', ['cc-admin-ui']);
        }

        // 3. Post Editor (classic only)
        if ($this->is_classic_post_editor_screen($screen)) {
            $this->enqueue_style('cc-admin-layout-editor', 'assets/css/admin-theme/12-layout-editor.css', ['cc-admin-ui']);
        }

        // 4. Taxonomy Terms
        if ('edit-tags' === $screen->base || 'term' === $screen->base) {
            $this->enqueue_style('cc-admin-layout-taxonomy', 'assets/css/admin-theme/13-layout-taxonomy.css', ['cc-admin-ui']);
        }

        // 5. Settings Pages — includes WP native options pages, generic settings pages,
        //    and CC-specific submenu pages (screen ID uses "content-core_page_" prefix).
        if (
            strpos($screen->id, 'settings_page_') !== false
            || strpos($screen->id, 'toplevel_page_') !== false
            || strpos($screen->id, 'content-core_page_') !== false
            || 'options-general' === $screen->base
        ) {
            $this->enqueue_style('cc-admin-layout-settings', 'assets/css/admin-theme/14-layout-settings.css', ['cc-admin-ui']);
        }
    }

    /**
     * Keep editor layout CSS out of block editor and site editor contexts.
     */
    private function is_classic_post_editor_screen(\WP_Screen $screen): bool
    {
        if (!in_array($screen->base, ['post', 'post-new'], true)) {
            return false;
        }

        if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return false;
        }

        return true;
    }

    /**
     * Helper to safely register a stylesheet
     */
    private function register_style(string $handle, string $relative_path, array $deps = []): void
    {
        $version = $this->asset_version($relative_path);

        wp_register_style(
            $handle,
            CONTENT_CORE_PLUGIN_URL . $relative_path,
            $deps,
            $version
        );
    }

    /**
     * Helper to safely enqueue a stylesheet using our asset versioning
     */
    private function enqueue_style(string $handle, string $relative_path, array $deps = []): void
    {
        $version = $this->asset_version($relative_path);

        wp_enqueue_style(
            $handle,
            CONTENT_CORE_PLUGIN_URL . $relative_path,
            $deps,
            $version
        );
    }

    private function asset_version(string $relative_path): string
    {
        $file_path = CONTENT_CORE_PLUGIN_DIR . ltrim($relative_path, '/');
        return (string) (file_exists($file_path) ? filemtime($file_path) : CONTENT_CORE_VERSION);
    }

    /**
     * Helper to safely register a script
     */
    private function register_script(string $handle, string $relative_path, array $deps = []): void
    {
        $file_path = CONTENT_CORE_PLUGIN_DIR . $relative_path;
        $version = file_exists($file_path) ? filemtime($file_path) : CONTENT_CORE_VERSION;

        wp_register_script(
            $handle,
            CONTENT_CORE_PLUGIN_URL . $relative_path,
            $deps,
            $version,
            true
        );
    }

    /**
     * Phase 5 requirement: Ensure folders and minimal files exist so health checks don't fail setup.
     */
    private function ensure_minimal_files(): void
    {
        $assets_dir = CONTENT_CORE_PLUGIN_DIR . 'assets';
        $css_dir = $assets_dir . '/css';
        $admin_theme_dir = $css_dir . '/admin-theme';
        $js_dir = $assets_dir . '/js';

        try {
            // Create CSS directory with error handling
            if (!is_dir($css_dir)) {
                if (!@mkdir($css_dir, 0755, true) && !is_dir($css_dir)) {
                    throw new \Exception("Failed to create CSS directory: {$css_dir}");
                }
            }

            // Create JS directory with error handling
            if (!is_dir($js_dir)) {
                if (!@mkdir($js_dir, 0755, true) && !is_dir($js_dir)) {
                    throw new \Exception("Failed to create JS directory: {$js_dir}");
                }
            }

            // Create admin theme directory with error handling
            if (!is_dir($admin_theme_dir)) {
                if (!@mkdir($admin_theme_dir, 0755, true) && !is_dir($admin_theme_dir)) {
                    throw new \Exception("Failed to create admin theme directory: {$admin_theme_dir}");
                }
            }

            $files = [
                $css_dir . '/admin-theme/01-tokens.css' => '/* Content Core Admin Theme - Layer 1 Tokens */',
                $css_dir . '/admin-theme/02-base-reset.css' => '/* Content Core Admin Theme - Layer 2 Base Reset */',
                $css_dir . '/admin-theme/03-layout.css' => '/* Content Core Admin Theme - Layer 3 Layout */',
                $css_dir . '/admin-theme/04-components.css' => '/* Content Core Admin Theme - Layer 4 Components */',
                $css_dir . '/admin-theme/05-wp-bridge.css' => '/* Content Core Admin Theme - Layer 5 WP Bridge */',
                $css_dir . '/admin-theme/06-control-overrides.css' => '/* Content Core Admin Theme - Unlayered Control Overrides */',
                $css_dir . '/admin-theme/index.css' => "@layer tokens, reset-bridge, primitives, components, wp-bridge, utilities, overrides;\n@import url('./01-tokens.css') layer(tokens);\n@import url('./02-base-reset.css') layer(reset-bridge);\n@import url('./03-layout.css') layer(primitives);\n@import url('./04-components.css') layer(components);\n@import url('./05-wp-bridge.css') layer(wp-bridge);\n@import url('./06-control-overrides.css');\n",
                $js_dir . '/admin.js' => '/* Minimal Content Core Admin JS */console.log("wp-content/plugins/Content-Core/assets/js/admin.js loaded");'
            ];

            foreach ($files as $file => $content) {
                $must_overwrite = false;
                if ($must_overwrite || !file_exists($file)) {
                    $result = @file_put_contents($file, $content);
                    if ($result === false) {
                        throw new \Exception("Failed to write file: {$file}");
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break plugin initialization
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error('Content Core: ' . $e->getMessage(), E_USER_WARNING);
            }
            if (class_exists('\ContentCore\Logger')) {
                \ContentCore\Logger::warning('Asset initialization failed: ' . $e->getMessage());
            }
        }
    }
}
